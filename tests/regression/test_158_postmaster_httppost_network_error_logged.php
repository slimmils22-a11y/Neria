<?php
/**
 * Régression : PostmasterManager::httpPost() (utilisé par
 * refreshAccessToken() pour rafraîchir le token OAuth) doit journaliser
 * via Watchdog en cas d'échec transport, comme
 * SearchConsoleManager::httpPost() déjà corrigé — sinon une déconnexion
 * OAuth "mystère" en production ne peut jamais être diagnostiquée.
 *
 * Bug réel corrigé le 08/08/2026 (round 135) : httpPost() retournait
 * silencieusement [] sur un échec curl_exec(), sans capturer curl_error()
 * ni journaliser quoi que ce soit — contrairement à apiGet() (round 131,
 * lecture des données) ET à SearchConsoleManager::httpPost() (même métier,
 * déjà journalisé).
 *
 * Test structurel : vérifie la capture de curl_error() et le log Watchdog
 * dans httpPost().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PostmasterManager.php');

    $posMethod = strpos($src, 'private function httpPost(string $url, array $data): array');
    neria_assert($posMethod !== false, 'httpPost() introuvable — régression du bug corrigé le 08/08/2026 (round 135)');

    $body = substr($src, $posMethod, 1200);

    neria_assert(
        strpos($body, '$curlErr = curl_error($ch);') !== false,
        "PostmasterManager::httpPost() ne capture plus curl_error(\$ch) — régression du bug corrigé le 08/08/2026 (round 135)"
    );
    neria_assert(
        strpos($body, "WatchdogManager::i18nMsg('watchdog.postmaster_api_error'") !== false,
        "PostmasterManager::httpPost() ne journalise plus l'échec via Watchdog — régression du bug corrigé le 08/08/2026 (round 135) : une panne réseau lors du rafraîchissement du token OAuth redeviendrait indiagnosticable"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager::httpPost() capture bien curl_error() et journalise via Watchdog, aligné sur SearchConsoleManager::httpPost() déjà corrigé",
    ];
}
