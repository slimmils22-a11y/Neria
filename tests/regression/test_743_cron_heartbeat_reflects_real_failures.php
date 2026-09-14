<?php
/**
 * Régression : les jobs cron déclenchés depuis neria.php::runBackgroundJobs()
 * (webhook, calendar, domain_reputation, seasonal_campaigns, watchdog_digest)
 * doivent poster un cronHeartbeat() qui reflète les échecs RÉELS par
 * boutique, pas un statut 'ok' inconditionnel — même correctif que
 * BehavioralCronManager::run() (round 352), étendu hors round le 14/09/2026
 * au reste du module (scheduling explicite de l'utilisateur, round 352,
 * "traite le heartbeat au prochain round").
 *
 * Défaut avant correctif : webhook/calendar/seasonal_campaigns bouclent sur
 * chaque boutique avec un try/catch interne qui avale silencieusement
 * l'exception (best-effort), puis appellent cronHeartbeat() SANS statut
 * explicite (défaut 'ok') dès qu'AU MOINS UNE boutique avait réussi (ou même
 * inconditionnellement pour webhook) — un échec sur N-1 boutiques restait
 * invisible dans le tableau de bord Watchdog. domain_reputation et
 * watchdog_digest n'appelaient même aucun cronHeartbeat() du tout.
 *
 * Test structurel : vérifie que chaque bloc compte désormais ses échecs par
 * boutique dans une variable dédiée et transmet ce compte à cronHeartbeat().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $checks = [
        'webhook'            => ['$webhookFailCount++;', "cronHeartbeat('webhook', \$webhookFailCount > 0 ? 'error' : 'ok', \$webhookFailCount);"],
        'calendar'           => ['$calendarFailCount++;', "cronHeartbeat('calendar', \$calendarFailCount > 0 ? 'error' : 'ok', \$calendarFailCount);"],
        'domain_reputation'  => ['$domainReputationFailCount++;', "cronHeartbeat('domain_reputation', \$domainReputationFailCount > 0 ? 'error' : 'ok', \$domainReputationFailCount);"],
        'seasonal_campaigns' => ['$seasonalFailCount++;', "cronHeartbeat('seasonal_campaigns', \$seasonalFailCount > 0 ? 'error' : 'ok', \$seasonalFailCount);"],
        'watchdog_digest'    => ['$digestFailCount++;', "cronHeartbeat('watchdog_digest', \$digestFailCount > 0 ? 'error' : 'ok', \$digestFailCount);"],
    ];

    foreach ($checks as $job => [$counterLiteral, $heartbeatLiteral]) {
        neria_assert(
            strpos($src, $counterLiteral) !== false,
            "Job '{$job}' : le compteur d'échecs par boutique ({$counterLiteral}) est absent — régression du correctif hors round du 14/09/2026"
        );
        neria_assert(
            strpos($src, $heartbeatLiteral) !== false,
            "Job '{$job}' : cronHeartbeat() n'est plus appelé avec le statut/compte réel ({$heartbeatLiteral}) — régression du correctif hors round du 14/09/2026, le heartbeat redeviendrait 'ok' inconditionnel"
        );
    }

    return [
        'pass'    => true,
        'message' => 'Les 5 jobs cron (webhook/calendar/domain_reputation/seasonal_campaigns/watchdog_digest) postent désormais un cronHeartbeat() reflétant les échecs réels par boutique — correctif hors round du 14/09/2026 validé',
    ];
}
