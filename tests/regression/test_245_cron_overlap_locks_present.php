<?php
/**
 * Régression : trois blocs de runBackgroundJobs() identifiés sans
 * protection anti-chevauchement (round 154, audit dédié) doivent avoir
 * reçu un GET_LOCK() — même patron déjà en place pour la queue d'envoi,
 * la queue webhook, le cron comportemental, le calendrier et le rapport
 * mensuel.
 *
 * Bugs réels corrigés le 09/08/2026 (round 154) :
 * - WatchdogManager::sendDailyDigestIfDue() : deux déclenchements
 *   concurrents (hookDisplayHeader front + vrai cron serveur, ou deux
 *   visiteurs simultanés) pouvaient tous deux lire un lastDigest périmé
 *   avant que l'un des deux n'ait écrit sa mise à jour — le marchand
 *   recevait le digest quotidien en double.
 * - DomainReputationManager::runFullCheck() : chevauchement possible
 *   pendant la fenêtre de cache périmée (jusqu'à 8s de DNS/RBL bloquants),
 *   avec un risque d'alerte critique dupliquée sur le même incident.
 * - HealthCheckManager::runAutoChecksIfDue() : chevauchement possible,
 *   gaspillage CPU (buildAllChecks() peut être coûteux) sans conséquence
 *   fonctionnelle mais incohérent avec le reste de runBackgroundJobs().
 *
 * Test structurel : vérifie la présence des 3 GET_LOCK()/RELEASE_LOCK()
 * correspondants dans le code source de chaque fichier.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $wd = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
    neria_assert($wd !== false, 'Impossible de lire src/WatchdogManager.php');
    neria_assert(
        strpos($wd, "GET_LOCK('neria_watchdog_digest_") !== false && strpos($wd, "RELEASE_LOCK('neria_watchdog_digest_") !== false,
        "WatchdogManager::sendDailyDigestIfDue() n'a plus de verrou GET_LOCK() — régression du bug corrigé le 09/08/2026 (round 154) : le digest quotidien pourrait de nouveau partir en double au marchand"
    );

    $drm = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($drm !== false, 'Impossible de lire src/DomainReputationManager.php');
    // Round 299 : littéral élargi — le nom du verrou est passé par
    // $lockName (basé sur le domaine, cf. DomainReputationManager::
    // lockName()) depuis pSQL($lockName), plus concaténé en dur.
    neria_assert(
        strpos($drm, "GET_LOCK('\" . pSQL(\$lockName) . \"'") !== false && strpos($drm, "RELEASE_LOCK('\" . pSQL(\$lockName) . \"'") !== false,
        "DomainReputationManager::runFullCheck() n'a plus de verrou GET_LOCK() — régression du bug corrigé le 09/08/2026 (round 154) : double charge DNS/RBL et risque d'alerte dupliquée redeviendraient possibles"
    );

    $hcm = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
    neria_assert($hcm !== false, 'Impossible de lire src/HealthCheckManager.php');
    $posFn = strpos($hcm, 'public function runAutoChecksIfDue(bool $allowHeavyScans = false): void');
    neria_assert($posFn !== false, 'runAutoChecksIfDue() introuvable — jeu de test invalide');
    $body = substr($hcm, $posFn, 2400);
    neria_assert(
        strpos($body, "GET_LOCK('neria_health_autochecks'") !== false && strpos($body, "RELEASE_LOCK('neria_health_autochecks'") !== false,
        "HealthCheckManager::runAutoChecksIfDue() n'a plus de verrou GET_LOCK() — régression du bug corrigé le 09/08/2026 (round 154)"
    );

    return [
        'pass'    => true,
        'message' => "Les 3 blocs identifiés sans protection anti-chevauchement (digest Watchdog, réputation de domaine, auto-diagnostics) ont bien reçu un GET_LOCK()",
    ];
}
