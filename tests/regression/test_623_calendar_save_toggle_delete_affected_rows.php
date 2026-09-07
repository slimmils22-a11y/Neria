<?php
/**
 * Régression : les actions BO `save_calendar_event`/`toggle_calendar_event`/
 * `delete_calendar_event` (neria.php) exécutaient un UPDATE/DELETE scopé
 * `id_shop` sans jamais vérifier son effet réel — Db::execute() renvoie
 * true même à 0 ligne affectée (cal_id inexistant ou appartenant à une
 * autre boutique). Le message de succès s'affichait donc inconditionnellement
 * dès que cal_id était numériquement positif — même pattern que
 * restore_translation/restore_variant_b/add_calendar_event (round 310) et
 * quote_mark_won/quote_delete/lifespan_delete (round 311), retrouvé dans une
 * zone différente du même fichier (les 3 seules actions calendrier n'ayant
 * pas reçu ce même correctif à l'époque).
 *
 * Corrigé le 07/09/2026 (round 317) :
 * - save_calendar_event : existence vérifiée AVANT l'UPDATE (un UPDATE
 *   resoumettant la même valeur send_days_before donnerait Affected_Rows()=0
 *   bien que la ligne existe réellement — fausse ambiguïté, même pattern que
 *   SeasonalCampaignManager::update(), round 311).
 * - toggle_calendar_event/delete_calendar_event : Affected_Rows() vérifié
 *   après l'UPDATE/DELETE (fiable ici — is_active=1-is_active change
 *   TOUJOURS la valeur ; DELETE n'a pas d'ambiguïté possible).
 * neria_error (calendar.event_not_found) assigné dans les 3 cas d'échec.
 *
 * Test structurel : vérifie la présence du garde-fou pour chacune des 3
 * actions.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posSave = strpos($src, "Tools::getValue('neria_action') === 'save_calendar_event'");
    neria_assert($posSave !== false, 'action save_calendar_event introuvable — jeu de test invalide');
    $bodySave = substr($src, $posSave, 2200);
    neria_assert(
        strpos($bodySave, 'SELECT 1 FROM `') !== false,
        "save_calendar_event ne vérifie plus l'existence de la ligne AVANT l'UPDATE — régression du bug corrigé le 07/09/2026 (round 317) : le message de succès s'afficherait de nouveau même pour un cal_id inexistant ou d'une autre boutique"
    );
    neria_assert(
        strpos($bodySave, "AdminTranslator::t('calendar.event_not_found')") !== false,
        "save_calendar_event n'assigne plus de neria_error dédié pour le cas ligne introuvable — régression du bug corrigé le 07/09/2026 (round 317)"
    );

    foreach (['toggle_calendar_event', 'delete_calendar_event'] as $action) {
        $pos = strpos($src, "Tools::getValue('neria_action') === '{$action}'");
        neria_assert($pos !== false, "action {$action} introuvable — jeu de test invalide");
        $body = substr($src, $pos, 1400);
        neria_assert(
            strpos($body, 'Affected_Rows()') !== false,
            "{$action} ne vérifie plus Affected_Rows() après son UPDATE/DELETE — régression du bug corrigé le 07/09/2026 (round 317) : le message de succès s'afficherait de nouveau même pour un cal_id inexistant ou d'une autre boutique"
        );
        neria_assert(
            strpos($body, "AdminTranslator::t('calendar.event_not_found')") !== false,
            "{$action} n'assigne plus de neria_error dédié pour le cas 0 ligne affectée — régression du bug corrigé le 07/09/2026 (round 317)"
        );
    }

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        isset($translations['calendar.event_not_found']) && count($translations['calendar.event_not_found']) === 19,
        "clé calendar.event_not_found manquante ou incomplète dans admin_translations.json (19 langues attendues)"
    );

    return [
        'pass'    => true,
        'message' => "save_calendar_event/toggle_calendar_event/delete_calendar_event vérifient bien l'effet réel avant d'afficher un message de succès — bug corrigé le 07/09/2026 (round 317)",
    ];
}
