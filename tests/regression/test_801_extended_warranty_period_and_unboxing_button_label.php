<?php
/**
 * Bloc 5 (19/09/2026), relecture des emails reçus sur ps-test :
 * (1) extended_warranty : « valid for a specified period » sans jamais
 *     préciser la période — la confirmation d'une garantie doit porter sa
 *     durée. Champs FACULTATIFS {warranty_period} / {warranty_end_date}
 *     (formulaire d'envoi manuel + template) : vides, l'email reste identique ;
 * (2) unboxing_guide : le bouton pointe vers la page contact, son libellé
 *     « Share the emotion » (évoque les réseaux sociaux) devient « Tell us your
 *     first impressions » (19 langues).
 *
 * Test comportemental réel : extended_warranty est COMPILÉ avec puis sans les
 * champs — présents : libellé + valeur dans le HTML et le TXT ; absents : ni
 * libellé ni résidu de syntaxe. Libellés en base dans 19 langues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);
    $method   = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);
    $engine   = new TranslationEngine($module);

    foreach (['extended_warranty_period_label', 'extended_warranty_end_label'] as $k) {
        foreach (['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'] as $l) {
            neria_assert((string) $engine->get('extended_warranty', $k, $l, 1) !== '', "[{$l}] libellé {$k} absent en base (import des traductions non fait ?)");
        }
    }
    neria_assert((string) $engine->get('unboxing_guide', 'unboxing_btn', 'en', 1) === 'Tell us your first impressions', "libellé du bouton unboxing_guide (en) inattendu");
    neria_assert(stripos((string) $engine->get('unboxing_guide', 'unboxing_btn', 'fr', 1), 'impressions') !== false, "libellé du bouton unboxing_guide (fr) inattendu");

    $base  = ['{firstname}' => 'Slim', '{time_greeting}' => 'Hello', '{product_name}' => 'Coussin', '{order_name}' => 'A1'];
    $files = [];
    $compile = function (string $suffix, array $extra) use ($renderer, $method, $base, &$files): array {
        $out = 'regtest801_' . $suffix;
        $res = $method->invoke($renderer, 'extended_warranty', 'en', 'en', $base + $extra, false, false, $out);
        neria_assert($res !== null, "compilation extended_warranty ({$suffix}) échouée");
        $h = _PS_MODULE_DIR_ . 'neria/mails/en/' . $out . '.html';
        $t = _PS_MODULE_DIR_ . 'neria/mails/en/' . $out . '.txt';
        $files[] = $h;
        $files[] = $t;
        return [(string) file_get_contents($h), is_file($t) ? (string) file_get_contents($t) : ''];
    };
    try {
        [$hWith, $tWith] = $compile('with', ['{warranty_period}' => '3 years', '{warranty_end_date}' => '12/31/2029']);
        neria_assert(strpos($hWith, 'Warranty period') !== false && strpos($hWith, '3 years') !== false, "HTML : durée de garantie absente alors que fournie");
        neria_assert(strpos($hWith, 'Valid until') !== false && strpos($hWith, '12/31/2029') !== false, "HTML : date de fin absente alors que fournie");
        neria_assert(strpos($hWith, 'Warranty period:') !== false, "HTML : ponctuation « Warranty period: » attendue (sans espace hors français)");
        if ($tWith !== '') {
            neria_assert(strpos($tWith, '3 years') !== false && strpos($tWith, '12/31/2029') !== false, "TXT : durée/date absentes alors que fournies");
        }

        [$hNone, $tNone] = $compile('none', []);
        neria_assert(strpos($hNone, 'Warranty period') === false && strpos($hNone, 'Valid until') === false, "HTML : libellés affichés alors qu'aucune valeur n'est fournie");
        neria_assert(strpos($hNone, '{if') === false && strpos($hNone, '{warranty') === false, "HTML : résidu de syntaxe dans l'email");
        if ($tNone !== '') {
            neria_assert(strpos($tNone, 'Warranty period') === false && strpos($tNone, '{if') === false && strpos($tNone, '{warranty') === false, "TXT : libellé ou résidu de syntaxe sans valeur fournie");
        }
    } finally {
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    $msm = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
    neria_assert(strpos($msm, "'warranty_period' => [") !== false && strpos($msm, "'warranty_end_date' => [") !== false, "libellés du formulaire d'envoi manuel absents");

    return ['pass' => true, 'message' => "extended_warranty porte durée/date de fin facultatives (masquées si vides) et le bouton d'unboxing_guide invite à écrire (19 langues) — bloc 5 (19/09/2026)"];
}
