<?php
/**
 * Régression : SearchConsoleManager::apiGet()/apiPost() doivent
 * journaliser CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT (+ alerte Watchdog)
 * sur un échec transport (timeout/DNS/TLS) ou une réponse JSON invalide,
 * pas seulement sur une erreur HTTP explicite de Google — même correctif
 * que PostmasterManager::apiGet() (round 131), jamais appliqué à
 * SearchConsoleManager malgré la même famille de code (OAuth Google).
 *
 * Test structurel : vérifie que les deux nouvelles branches (échec
 * transport, JSON invalide) sont bien présentes dans apiGet() ET apiPost().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SearchConsoleManager.php');

    foreach (['apiGet' => 'private function apiGet(string $path, string $token): ?array',
              'apiPost' => 'private function apiPost(string $path, string $token, string $body): ?array'] as $method => $signature) {
        $posMethod = strpos($src, $signature);
        neria_assert($posMethod !== false, "{$method}() introuvable — régression du bug corrigé le 08/08/2026 (round 135)");

        $body = substr($src, $posMethod, 2000);

        $networkCheckVar = $method === 'apiGet' ? '$body === false' : '$resp === false';
        neria_assert(
            strpos($body, $networkCheckVar) !== false,
            "SearchConsoleManager::{$method}() ne distingue plus l'échec transport — régression du bug corrigé le 08/08/2026 (round 135) : une panne réseau redeviendrait totalement silencieuse"
        );
        neria_assert(
            strpos($body, "'invalid JSON response'") !== false,
            "SearchConsoleManager::{$method}() ne journalise plus les réponses JSON invalides — régression du bug corrigé le 08/08/2026 (round 135)"
        );
        neria_assert(
            strpos($body, "'network error: '") !== false,
            "SearchConsoleManager::{$method}() ne journalise plus les échecs transport dans CONFIG_LAST_ERROR — régression du bug corrigé le 08/08/2026 (round 135)"
        );
    }

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager::apiGet()/apiPost() journalisent bien les échecs transport/JSON invalide, alignés sur le correctif déjà appliqué à PostmasterManager::apiGet() (round 131)",
    ];
}
