<?php
/**
 * Régression (P8b, 26/09/2026) : trois messages du back-office étaient écrits en français dans le code, quelle que soit la
 * langue de l'employé : le bandeau du scan de code (deux textes) et l'erreur de l'envoi du journal Watchdog par e-mail
 * (« La fonction mail() a retourné false »).
 *
 * Corrigé : clés help.code_scan_done_issues / help.code_scan_done_ok / help.log_email_mail_false, 19 langues.
 *
 * Test : plus aucune phrase française littérale à ces endroits, clés présentes et distinctes en japonais.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    neria_test_module();
    $root = _PS_MODULE_DIR_ . 'neria/';
    $main = (string) file_get_contents($root . 'neria.php');
    foreach (['Scan de code terminé', 'La fonction mail() a retourné false'] as $fr) {
        neria_assert(!str_contains($main, $fr), "Phrase française littérale « {$fr} » de nouveau dans neria.php");
    }
    foreach (["AdminTranslator::t('help.code_scan_done_issues')", "AdminTranslator::t('help.code_scan_done_ok')", "AdminTranslator::t('help.log_email_mail_false')"] as $call) {
        neria_assert(str_contains($main, $call), "Appel traduit absent : {$call}");
    }
    $tr = json_decode((string) file_get_contents($root . 'data/admin_translations.json'), true);
    foreach (['help.code_scan_done_issues', 'help.code_scan_done_ok', 'help.log_email_mail_false'] as $k) {
        neria_assert(count(array_filter($tr[$k] ?? [])) === 19, "{$k} n'existe pas dans les 19 langues");
        neria_assert($tr[$k]['ja'] !== $tr[$k]['fr'] && $tr[$k]['en'] !== $tr[$k]['fr'], "{$k} non traduit");
    }

    return ['pass' => true, 'message' => "Bandeau du scan de code et erreur d'envoi du journal traduits en 19 langues (plus de français en dur) — corrigé le 26/09/2026"];
}
