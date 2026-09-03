<?php
/**
 * Régression : `WebhookManager::processQueue()` journalisait l'échec
 * DÉFINITIF d'un webhook (après épuisement de `MAX_ATTEMPTS` tentatives,
 * recul exponentiel déjà consommé) via `wd()->warning()` — un niveau qui
 * ne déclenche JAMAIS `sendImmediateAlert()` (contrairement à
 * `error()`/`critical()`, cf. `WatchdogManager::warning()` vs `error()`,
 * rounds 268/276). Contrairement à un échec RETENTABLE (encore en cours
 * de recul, ligne juste en dessous, correctement resté en `pending`),
 * un échec définitif signifie que la notification externe est PERDUE
 * POUR DE BON — aucune nouvelle tentative n'aura jamais lieu. Le
 * marchand n'était informé de cette perte définitive qu'au digest
 * quotidien (opt-in, jusqu'à ~24h de délai), jamais immédiatement,
 * alors que `QueueManager` escalade déjà les pannes d'envoi équivalentes.
 *
 * Bug identifié le 02/09/2026 (round 287, audit "backoff et tempête de
 * nouvelles tentatives QueueManager/WebhookManager").
 *
 * Corrigé le 02/09/2026 (round 287) : le log passé en `error()`,
 * déclenchant désormais `sendImmediateAlert()`.
 *
 * Test structurel : reproduire un vrai cycle d'épuisement de 3 tentatives
 * avec recul exponentiel nécessiterait de manipuler `last_attempt_at`
 * sur plusieurs passages, hors périmètre sûr d'un test isolé (voir
 * test_527 pour le même choix sur LicenseManager). Vérifie que le code
 * source utilise bien `error()` (pas `warning()`) pour cet événement
 * précis.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WebhookManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WebhookManager.php');

    $posFailed = strpos($src, "\$definitivelyFailed++;");
    neria_assert($posFailed !== false, "le bloc d'échec définitif est introuvable — jeu de test invalide");

    $body = substr($src, $posFailed, 1200);
    neria_assert(
        strpos($body, '$this->watchdog()->error(') !== false
            && strpos($body, "\\WatchdogManager::i18nMsg('watchdog.webhook_definitively_failed',") !== false,
        "WebhookManager::processQueue() ne journalise plus un échec définitif via watchdog()->error() — régression du bug corrigé le 02/09/2026 (round 287) : sendImmediateAlert() ne serait de nouveau jamais déclenché, le marchand ne serait informé de la perte définitive de la notification qu'au digest quotidien opt-in, jamais immédiatement"
    );
    neria_assert(
        strpos($body, '$this->watchdog()->warning(') === false,
        "WebhookManager::processQueue() utilise de nouveau watchdog()->warning() pour l'échec définitif — régression du bug corrigé le 02/09/2026 (round 287)"
    );

    return [
        'pass'    => true,
        'message' => "WebhookManager::processQueue() déclenche désormais une alerte email immédiate (error()) sur échec définitif d'un webhook, au lieu du seul digest opt-in — bug corrigé le 02/09/2026 (round 287)",
    ];
}
