<?php
/**
 * Régression : HealthCheckManager::runAutoChecksIfDue() ne doit PAS
 * ré-exécuter checkKnownRegressionsGuard() (~150 lectures de fichiers,
 * centaines de regex) quand $allowHeavyScans est false (déclenchement
 * passif front-office) — il doit réutiliser le dernier résultat connu.
 *
 * Bug réel corrigé le 09/08/2026 (round 141) : checkKnownRegressionsGuard()
 * tournait sur CHAQUE page front-office (via hookDisplayHeader, throttlé
 * seulement 24h), bloquant la requête HTTP du premier visiteur après
 * expiration du throttle pendant toute la durée du scan.
 *
 * Test comportemental réel : force le throttle à expiré, pré-positionne un
 * résultat connu pour known_regressions_guard, appelle
 * runAutoChecksIfDue(false) (mode front) et vérifie que ce résultat précis
 * est conservé tel quel (donc que la méthode lourde n'a pas tourné —
 * sinon le résultat serait remplacé par un vrai résultat de scan, qui
 * diffère du marqueur artificiel injecté). Vérifie aussi que
 * runAutoChecksIfDue(true) (mode cron réel), lui, recalcule bien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';

    $module = neria_test_module();
    $marker = 'ROUND141_MARKER_' . uniqid();

    $savedLastRun = Configuration::get('NERIA_HEALTH_LAST_RUN');
    $savedResults = Configuration::get('NERIA_HEALTH_RESULTS');

    try {
        // Résultat "précédent" artificiel avec un marqueur reconnaissable.
        $fakeResults = [
            'known_regressions_guard' => ['status' => 'ok', 'detail' => $marker],
        ];
        Configuration::updateValue('NERIA_HEALTH_RESULTS', json_encode($fakeResults));
        Configuration::updateValue('NERIA_HEALTH_LAST_RUN', date('Y-m-d H:i:s', time() - 90000)); // > 24h

        $health = new HealthCheckManager($module);
        $health->runAutoChecksIfDue(false);

        $after = $health->getLastResults();
        neria_assert(
            isset($after['known_regressions_guard']['detail']) && $after['known_regressions_guard']['detail'] === $marker,
            "known_regressions_guard a été recalculé en mode front (allowHeavyScans=false) au lieu de réutiliser le dernier résultat connu — régression du bug corrigé le 09/08/2026 (round 141) : le scan lourd bloquerait de nouveau le premier visiteur après expiration du throttle"
        );

        // Mode cron réel (allowHeavyScans=true) : le marqueur doit disparaître,
        // remplacé par un vrai résultat de scan.
        Configuration::updateValue('NERIA_HEALTH_LAST_RUN', date('Y-m-d H:i:s', time() - 90000));
        $health2 = new HealthCheckManager($module);
        $health2->runAutoChecksIfDue(true);
        $after2 = $health2->getLastResults();
        neria_assert(
            isset($after2['known_regressions_guard']['detail']) && $after2['known_regressions_guard']['detail'] !== $marker,
            "known_regressions_guard n'a PAS été recalculé en mode cron réel (allowHeavyScans=true) — le contrôle ne s'exécuterait plus jamais nulle part"
        );
    } finally {
        Configuration::updateValue('NERIA_HEALTH_LAST_RUN', (string) $savedLastRun);
        Configuration::updateValue('NERIA_HEALTH_RESULTS', (string) $savedResults);
    }

    return [
        'pass'    => true,
        'message' => "runAutoChecksIfDue(false) réutilise bien le dernier résultat de known_regressions_guard sans le recalculer ; runAutoChecksIfDue(true) le recalcule bien",
    ];
}
