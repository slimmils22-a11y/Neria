<?php
/**
 * Régression : SearchConsoleManager::refreshAccessToken() — quand Google
 * renvoie 'invalid_grant' (refresh token révoqué), seul CONFIG_REFRESH_TOKEN
 * était purgé. CONFIG_ACCESS_TOKEN/CONFIG_TOKEN_EXPIRY restaient en base,
 * potentiellement valides encore ~55 min — getAccessToken() ne teste QUE
 * l'expiration locale avant de servir ce token en cache, donc un access
 * token RÉVOQUÉ continuait d'être servi jusqu'à son expiration naturelle,
 * provoquant un appel API Google gaspillé (401) à chaque cycle, avec un
 * message d'erreur final moins clair que celui déjà posé par ce même bloc
 * (le second passage par refreshAccessToken() atteint la branche
 * $rawRefresh === '' qui n'écrit pas CONFIG_LAST_ERROR/_AT).
 *
 * Bug identifié le 14/09/2026 (round 357, audit dédié SearchConsoleManager).
 *
 * Corrigé le 14/09/2026 : CONFIG_ACCESS_TOKEN et CONFIG_TOKEN_EXPIRY purgés
 * aussi, à côté de CONFIG_REFRESH_TOKEN — getAccessToken() retombe
 * immédiatement sur refreshAccessToken() au prochain appel.
 *
 * Test structurel (refreshAccessToken() fait un vrai appel réseau vers
 * Google OAuth — aucune clé de test disponible pour reproduire un vrai
 * 'invalid_grant' de bout en bout dans cet environnement) : vérifie que
 * les 3 deleteByName() sont bien présents dans le même bloc conditionnel
 * 'invalid_grant'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SearchConsoleManager.php');

    $pos = strpos($src, "if (\$errCode === 'invalid_grant') {");
    neria_assert($pos !== false, "Bloc 'invalid_grant' introuvable — jeu de test invalide");

    $body = substr($src, $pos, 400);

    neria_assert(
        strpos($body, 'deleteByName(self::CONFIG_REFRESH_TOKEN)') !== false,
        "Le bloc 'invalid_grant' ne purge plus CONFIG_REFRESH_TOKEN — jeu de test invalide ou régression"
    );
    neria_assert(
        strpos($body, 'deleteByName(self::CONFIG_ACCESS_TOKEN)') !== false,
        "Le bloc 'invalid_grant' ne purge plus CONFIG_ACCESS_TOKEN — régression du bug corrigé le 14/09/2026 (round 357) : un access token révoqué resterait servi en cache jusqu'à son expiration naturelle (~55 min)"
    );
    neria_assert(
        strpos($body, 'deleteByName(self::CONFIG_TOKEN_EXPIRY)') !== false,
        "Le bloc 'invalid_grant' ne purge plus CONFIG_TOKEN_EXPIRY — régression du bug corrigé le 14/09/2026 (round 357)"
    );

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager::refreshAccessToken() purge désormais CONFIG_ACCESS_TOKEN/CONFIG_TOKEN_EXPIRY en plus de CONFIG_REFRESH_TOKEN sur 'invalid_grant' — bug corrigé le 14/09/2026 (round 357)",
    ];
}
