<?php
/**
 * Régression (audit P9 du 25/09/2026 — export/import CSV des traductions) : l'export protège contre l'injection de
 * formule tableur en préfixant d'une apostrophe toute valeur commençant par = + - @ (tab, CR), en affirmant ne pas
 * altérer la valeur « réimportée ». L'import ne retirait pourtant jamais cette apostrophe : un texte comme
 * « -10 % pour vous » ou « +5 points offerts » revenait en base sous la forme « '-10 % pour vous » après un export
 * puis un import, et l'apostrophe partait dans les e-mails (traductions et variante B).
 *
 * Corrigé : csvFormulaRestore() (inverse de csvFormulaSafe()) appliqué aux deux imports.
 *
 * Test comportemental : vrai fputcsv/fgetcsv (séparateur « ; », BOM UTF-8) sur des valeurs difficiles — formules,
 * arabe, japonais, guillemets, point-virgule, retour à la ligne — avec les deux fonctions du back-office ;
 * chaque valeur doit revenir strictement identique ; l'export garde sa protection ; les deux imports l'utilisent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $safe = new ReflectionMethod($module, 'csvFormulaSafe');
    $safe->setAccessible(true);
    $restore = new ReflectionMethod($module, 'csvFormulaRestore');
    $restore->setAccessible(true);

    $values = [
        '-10 % pour vous', '+5 points offerts', '=SUM(A1)', '@promo', "\tindentée", "'déjà quoté", "'=littéral avec apostrophe",
        'مرحبا بكم — خصم ٢٠٪', '春のセール — 全品10%オフ', 'Il a dit « oui » ; puis "non"', "ligne 1\nligne 2; fin", '', 'x', "'",
        '<strong>-15 %</strong>', '– tiret cadratin', '100 % sûr',
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'neria861');
    $out = fopen($tmp, 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['template', 'lang', 'key', 'value', 'is_custom'], ';');
    foreach ($values as $i => $v) {
        fputcsv($out, ['win_back', 'fr', 'k' . $i, $safe->invoke($module, $v), '1'], ';');
    }
    fclose($out);

    $in = fopen($tmp, 'r');
    $bom = fread($in, 3);
    neria_assert($bom === chr(0xEF) . chr(0xBB) . chr(0xBF), 'BOM UTF-8 absent');
    fgetcsv($in, 0, ';');
    $back = [];
    while (($line = fgetcsv($in, 0, ';')) !== false) {
        $back[$line[2]] = $restore->invoke($module, (string) $line[3]);
    }
    fclose($in);
    @unlink($tmp);

    foreach ($values as $i => $v) {
        neria_assert(
            ($back['k' . $i] ?? null) === $v,
            'Aller-retour CSV altéré pour « ' . str_replace("\n", '\n', $v) . ' » : revenu « ' . str_replace("\n", '\n', (string) ($back['k' . $i] ?? '(absent)')) . ' » — régression du bug corrigé le 25/09/2026'
        );
    }
    neria_assert($safe->invoke($module, '=HYPERLINK("x")') === "'=HYPERLINK(\"x\")", "L'export ne protège plus contre l'injection de formule");

    $src = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(substr_count($src, '$this->csvFormulaRestore((string) $line[3])') === 2, "Les deux imports CSV (traductions et variante B) n'utilisent pas csvFormulaRestore()");

    return [
        'pass'    => true,
        'message' => "L'export puis l'import CSV des traductions rendent chaque valeur strictement identique (formules, arabe, japonais, guillemets, retours à la ligne), l'export garde sa protection anti-formule — bug corrigé le 25/09/2026",
    ];
}
