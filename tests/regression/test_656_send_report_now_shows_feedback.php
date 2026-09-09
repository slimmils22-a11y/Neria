<?php
/**
 * Régression : neria.php::send_report_now n'assignait NI neria_success NI
 * neria_error — seul handler d'envoi immédiat du fichier à ne fournir
 * AUCUN retour visuel. MonthlyReportManager::sendReport() renvoie
 * pourtant un bool signifiant explicitement l'échec (aucun destinataire
 * valide via getRecipients(), ou GET_LOCK() déjà tenu par un envoi
 * concurrent) — jamais vérifié par le handler.
 *
 * Bug réel : un marchand configure neria_report_recipients vide ou
 * invalide (aucune validation de format côté save_report_config, juste
 * strip_tags), puis clique "Envoyer maintenant" pour tester avant
 * l'échéance mensuelle automatique. sendReport() retourne false, aucun
 * email ne part, et la page se recharge SANS AUCUN message — le marchand
 * croit à tort que tout s'est bien passé, alors que le rapport mensuel
 * réel de fin de mois échouera silencieusement de la même façon.
 *
 * Corrigé le 09/09/2026 (round 327) : le retour de sendReport() est
 * désormais vérifié, avec un message de succès (msg.report_sent) ou
 * d'erreur (msg.report_send_failed) explicite.
 *
 * Test structurel (code inline dans le contrôleur admin, nécessitant un
 * contexte AdminController/Employee complet pour être invoqué réellement
 * — même limitation documentée par test_635/test_640/test_648) : vérifie
 * la présence du garde dans le code source + les 2 clés de traduction.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $srcRaw = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($srcRaw !== false, 'Impossible de lire neria.php');
    $src = str_replace("\r", '', $srcRaw);

    $posAction = strpos($src, "Tools::getValue('neria_action') === 'send_report_now'");
    neria_assert($posAction !== false, "Action 'send_report_now' introuvable — jeu de test invalide");

    $body = substr($src, $posAction, 1400);

    neria_assert(
        strpos($body, 'if ($rm->sendReport($year, $month)) {') !== false,
        "neria.php::send_report_now ne vérifie plus le retour de sendReport() — régression du bug corrigé le 09/09/2026 (round 327) : un échec (aucun destinataire valide, envoi déjà en cours) redeviendrait silencieux, sans aucun message pour le marchand"
    );
    neria_assert(
        strpos($body, "AdminTranslator::t('msg.report_sent')") !== false
        && strpos($body, "AdminTranslator::t('msg.report_send_failed')") !== false,
        "neria.php::send_report_now n'affiche plus de message de succès ET d'échec distincts — régression du bug corrigé le 09/09/2026 (round 327)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        isset($translations['msg.report_sent']) && count($translations['msg.report_sent']) === 19,
        "clé msg.report_sent manquante ou incomplète dans admin_translations.json (19 langues attendues)"
    );
    neria_assert(
        isset($translations['msg.report_send_failed']) && count($translations['msg.report_send_failed']) === 19,
        "clé msg.report_send_failed manquante ou incomplète dans admin_translations.json (19 langues attendues)"
    );

    return [
        'pass'    => true,
        'message' => "neria.php::send_report_now affiche bien un message de succès ou d'échec explicite selon le retour réel de sendReport(), au lieu d'aucun retour visuel — bug corrigé le 09/09/2026 (round 327)",
    ];
}
