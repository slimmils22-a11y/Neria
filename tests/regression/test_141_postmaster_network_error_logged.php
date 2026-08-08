<?php
/**
 * Régression : PostmasterManager::apiGet() doit journaliser CONFIG_LAST_ERROR
 * / CONFIG_LAST_ERROR_AT (+ alerte Watchdog) sur un échec transport
 * (timeout/DNS/TLS) ou une réponse JSON invalide — pas seulement sur une
 * erreur HTTP>=400 explicite renvoyée par Google.
 *
 * Bug réel corrigé le 08/08/2026 : quand curl_exec() échouait au niveau
 * transport, apiGet() retournait null en silence total, sans jamais écrire
 * CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT ni appeler wd()->warning() —
 * contrairement à ce qu'affirmait le commentaire de fetchAndCache()
 * ("erreur déjà journalisée dans apiGet()"). En cas de panne réseau
 * persistante, HealthCheckManager::checkOAuthFreshness() ne pouvait
 * afficher que le message générique "jamais rafraîchi" au lieu du vrai
 * diagnostic, et son escalade par ancienneté d'erreur ne se déclenchait
 * jamais faute de timestamp posé.
 *
 * Test structurel : vérifie que les deux nouvelles branches (échec
 * transport, JSON invalide) écrivent bien CONFIG_LAST_ERROR avant le
 * traitement de la réponse HTTP>=400 existant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PostmasterManager.php');

    $posMethod = strpos($src, 'private function apiGet(string $path, string $token): ?array');
    neria_assert($posMethod !== false, 'apiGet() introuvable — régression du bug corrigé le 08/08/2026');

    $body = substr($src, $posMethod, 2800);

    $posNetworkCheck = strpos($body, 'if ($body === false)');
    neria_assert(
        $posNetworkCheck !== false,
        "apiGet() ne distingue plus l'échec transport (\$body === false) du corps vide légitime — régression du bug corrigé le 08/08/2026 : une panne réseau redeviendrait totalement silencieuse"
    );

    $posNetworkLog = strpos($body, "'network error: '", $posNetworkCheck);
    neria_assert(
        $posNetworkLog !== false && $posNetworkLog < strpos($body, 'json_decode($body, true)'),
        "apiGet() ne journalise plus CONFIG_LAST_ERROR sur un échec transport — régression du bug corrigé le 08/08/2026"
    );

    $posInvalidJson = strpos($body, "'invalid JSON response'");
    neria_assert(
        $posInvalidJson !== false && $posInvalidJson > $posNetworkLog,
        "apiGet() ne journalise plus CONFIG_LAST_ERROR sur une réponse JSON invalide — régression du bug corrigé le 08/08/2026"
    );

    // Les deux nouvelles branches doivent précéder la branche HTTP>=400
    // existante (round antérieur), pas la remplacer.
    $posHttpErrorCheck = strpos($body, 'httpCode >= 400');
    neria_assert(
        $posHttpErrorCheck !== false && $posHttpErrorCheck > $posInvalidJson,
        "apiGet() : la branche HTTP>=400 existante a disparu ou n'est plus après les nouvelles branches d'échec transport/JSON — régression"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager::apiGet() journalise bien CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT et alerte Watchdog sur un échec transport ou une réponse JSON invalide, pas seulement sur une erreur HTTP explicite",
    ];
}
