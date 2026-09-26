<?php
/**
 * Régression (P8, 26/09/2026, constatée dans le vrai back-office de ps-test en anglais) : le badge « source de la date » des
 * occasions à venir (onglet Configuration) affichait la valeur interne française « pre-calcule » / « calcule » / « manuel »
 * quelle que soit la langue du back-office.
 * Corrigé : le modèle traduit les 3 sources (clés calendar.source_*, 19 langues) ; getDateSource() garde ses valeurs internes.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $tpl = str_replace("\r\n", "\n", (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/configure.tpl'));
    neria_assert(!preg_match('/^\s*\{\$event\.date_source\}\s*$/m', $tpl), 'configure.tpl affiche de nouveau la source de date brute (française)');
    foreach (['manual', 'computed', 'precomputed'] as $k) {
        neria_assert(str_contains($tpl, "key='calendar.source_$k'"), "configure.tpl n'utilise plus calendar.source_$k");
    }
    $tr = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['manual', 'computed', 'precomputed'] as $k) {
        neria_assert(count($tr["calendar.source_$k"] ?? []) === 19, "calendar.source_$k doit avoir 19 langues");
    }

    return ['pass' => true, 'message' => 'La source des dates des occasions à venir est traduite (19 langues) — corrigé le 26/09/2026'];
}
