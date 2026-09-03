<?php
/**
 * Régression : `SeasonalCampaignManager::runDueCampaigns()` n'avait
 * AUCUN plafond sur le nombre de clients réellement traités par
 * passage de cron — `getEligibleCustomers()` n'a explicitement aucun
 * `LIMIT` (choix délibéré round 256, préférant une revérification
 * périodique d'`is_active` à un plafond de lot). `claimSend()` réserve
 * un client (`INSERT IGNORE` dans `neria_behavioral_sent`) AVANT
 * `Mail::Send()` ; un crash brutal du process PHP (dépassement
 * `memory_limit`/`max_execution_time`, ni `catch` ni `finally` garantis
 * dans ce cas précis) survenant pendant l'envoi laissait la réservation
 * orpheline pour le RESTE DE L'ANNÉE CIVILE (clé de dédup incluant
 * `ref_id = $year`) — plus la boucle est longue (gros ciblage), plus la
 * fenêtre d'exposition à ce risque grandit.
 *
 * Bug identifié le 02/09/2026 (round 289, audit "détection d'épuisement
 * mémoire/temps dans les crons longs").
 *
 * Corrigé le 03/09/2026 (round 289, sur confirmation explicite de
 * l'utilisateur après discussion de portée — cf. mémoire projet
 * project_neria_seasonal_reservation_orphan_no_state_column, option (b)
 * retenue plutôt qu'une colonne d'état sur neria_behavioral_sent, jugée
 * trop lourde) : le lot RÉELLEMENT consommé par `runDueCampaigns()` est
 * désormais plafonné à `MAX_BATCH_PER_RUN` (500, même valeur que
 * `BehavioralCronManager::MAX_BATCH_PER_RUN`) via `array_slice()` —
 * PAS `getEligibleCustomers()` elle-même, pour que `countEligible()`
 * (aperçu BO du nombre de destinataires) reste exact. Les clients au-delà
 * du plafond restent éligibles (jamais insérés dans
 * `neria_behavioral_sent`) et sont repris au prochain passage du cron.
 *
 * Test structurel : reproduire un vrai lot de 501+ clients réels avec
 * envoi SMTP effectif serait disproportionné et enverrait de vrais
 * emails de test (même contrainte que test_417/test_492) — vérifie que
 * le plafonnement (avec log Watchdog informatif) est bien appliqué
 * APRÈS getEligibleCustomers() et AVANT la boucle d'envoi/réservation.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SeasonalCampaignManager.php');

    neria_assert(
        strpos($src, 'const MAX_BATCH_PER_RUN = 500;') !== false,
        "SeasonalCampaignManager::MAX_BATCH_PER_RUN n'est plus défini à 500 — régression du bug corrigé le 03/09/2026 (round 289)"
    );

    $posGet = strpos($src, '$customers = $this->getEligibleCustomers($campaign);');
    neria_assert($posGet !== false, 'appel à getEligibleCustomers() introuvable — jeu de test invalide');

    $posLoop = strpos($src, 'foreach ($customers as $customer) {', $posGet);
    neria_assert($posLoop !== false, "boucle d'envoi introuvable — jeu de test invalide");

    $between = substr($src, $posGet, $posLoop - $posGet);

    neria_assert(
        strpos($between, 'array_slice($customers, 0, self::MAX_BATCH_PER_RUN)') !== false,
        "SeasonalCampaignManager::runDueCampaigns() ne plafonne plus le lot consommé via array_slice(MAX_BATCH_PER_RUN) — régression du bug corrigé le 03/09/2026 (round 289) : un ciblage large redeviendrait exposé à une fenêtre de crash prolongée, avec réservation orpheline possible pour le reste de l'année"
    );
    neria_assert(
        strpos($between, "watchdog.seasonal_batch_capped") !== false,
        "SeasonalCampaignManager::runDueCampaigns() ne journalise plus le plafonnement du lot — régression du bug corrigé le 03/09/2026 (round 289) : le marchand n'aurait plus aucune trace qu'une partie du ciblage a été reportée au prochain passage"
    );

    // countEligible() doit continuer à utiliser getEligibleCustomers() SANS
    // plafond — la valeur de MAX_BATCH_PER_RUN ne doit apparaître nulle part
    // dans son corps (le plafond n'est appliqué que côté runDueCampaigns()).
    $posCountEligible = strpos($src, 'public function countEligible(array $campaign): int');
    neria_assert($posCountEligible !== false, 'countEligible() introuvable — jeu de test invalide');
    $posNextMethod = strpos($src, "\n    public function ", $posCountEligible + 10);
    $countEligibleBody = $posNextMethod !== false
        ? substr($src, $posCountEligible, $posNextMethod - $posCountEligible)
        : substr($src, $posCountEligible, 1500);
    neria_assert(
        strpos($countEligibleBody, 'MAX_BATCH_PER_RUN') === false,
        "SeasonalCampaignManager::countEligible() (aperçu BO du nombre de destinataires) applique désormais le plafond de lot — régression : l'aperçu affiché au marchand deviendrait inexact (sous-compterait les campagnes ciblant plus de 500 clients) au lieu de refléter le nombre réel d'éligibles"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($translations), 'admin_translations.json illisible ou invalide');
    $locales = ['fr','en','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl','gb'];
    foreach ($locales as $l) {
        neria_assert(
            isset($translations['watchdog.seasonal_batch_capped'][$l]) && $translations['watchdog.seasonal_batch_capped'][$l] !== '',
            "la clé watchdog.seasonal_batch_capped est absente ou vide pour la locale '{$l}' dans admin_translations.json"
        );
    }

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::runDueCampaigns() plafonne désormais le lot réellement consommé par passage de cron (MAX_BATCH_PER_RUN=500), sans affecter l'exactitude de countEligible() — bug corrigé le 03/09/2026 (round 289)",
    ];
}
