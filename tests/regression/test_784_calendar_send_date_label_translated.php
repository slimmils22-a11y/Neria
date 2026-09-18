<?php
/**
 * Contenu : calendar.tpl affichait « → envoi le <date> » en français codé en
 * dur, visible tel quel dans les 18 autres langues du BO (repéré en revue
 * manuelle du bloc 4, 18/09/2026). Corrigé : clé calendar.send_on_prefix
 * traduite en 19 langues. Le test vérifie le template et les 19 traductions.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir  = _PS_MODULE_DIR_ . 'neria/';
    $tpl  = (string) file_get_contents($dir . 'views/templates/admin/calendar.tpl');
    neria_assert(strpos($tpl, '→ envoi le') === false, "calendar.tpl contient de nouveau « envoi le » codé en dur");
    neria_assert(strpos($tpl, "neria_admin key='calendar.send_on_prefix'") !== false, "calendar.tpl n'utilise plus calendar.send_on_prefix");

    $data = json_decode((string) file_get_contents($dir . 'data/admin_translations.json'), true);
    $tr   = $data['calendar.send_on_prefix'] ?? [];
    neria_assert(count($tr) >= 19, "calendar.send_on_prefix incomplète (" . count($tr) . " langues)");
    foreach ($tr as $lang => $v) {
        neria_assert(trim((string) $v) !== '', "calendar.send_on_prefix vide pour '{$lang}'");
        if ($lang !== 'fr') {
            neria_assert(stripos((string) $v, 'envoi le') === false, "calendar.send_on_prefix['{$lang}'] est resté en français");
        }
    }

    return ['pass' => true, 'message' => "calendar.tpl : libellé « envoi le » traduit via calendar.send_on_prefix (19 langues) — bloc 4 (18/09/2026)"];
}
