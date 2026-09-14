<?php
/**
 * Régression : PostmasterManager (fichier jumeau de SearchConsoleManager,
 * même API Google OAuth) — quand Google renvoie 'invalid_grant' (refresh
 * token révoqué), seul CONFIG_REFRESH_TOKEN était purgé. Exactement le même
 * défaut que SearchConsoleManager (round 357) : CONFIG_ACCESS_TOKEN/
 * CONFIG_TOKEN_EXPIRY restaient en base, potentiellement valides encore
 * jusqu'à ~55 min, et tout code lisant ces 2 clés sans repasser par
 * isConnected() (qui ne teste que CONFIG_REFRESH_TOKEN) retombait sur un
 * état résiduel incohérent.
 *
 * Bug identifié le 14/09/2026 (round 359, audit dédié PostmasterManager).
 *
 * Corrigé le 14/09/2026 : CONFIG_ACCESS_TOKEN et CONFIG_TOKEN_EXPIRY purgés
 * aussi, à côté de CONFIG_REFRESH_TOKEN — même correctif que
 * SearchConsoleManager round 357.
 *
 * Test structurel (même contrainte que test_757 : appel réseau réel vers
 * Google OAuth, aucune clé de test disponible pour un vrai 'invalid_grant'
 * de bout en bout) : vérifie que les 3 deleteByName() sont bien présents
 * dans le même bloc conditionnel 'invalid_grant'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PostmasterManager.php');

    $pos = strpos($src, "if (\$errCode === 'invalid_grant') {");
    neria_assert($pos !== false, "Bloc 'invalid_grant' introuvable — jeu de test invalide");

    $body = substr($src, $pos, 400);

    neria_assert(
        strpos($body, 'deleteByName(self::CONFIG_REFRESH_TOKEN)') !== false,
        "Le bloc 'invalid_grant' ne purge plus CONFIG_REFRESH_TOKEN — jeu de test invalide ou régression"
    );
    neria_assert(
        strpos($body, 'deleteByName(self::CONFIG_ACCESS_TOKEN)') !== false,
        "Le bloc 'invalid_grant' ne purge plus CONFIG_ACCESS_TOKEN — régression du bug corrigé le 14/09/2026 (round 359) : un access token révoqué resterait servi en cache jusqu'à son expiration naturelle"
    );
    neria_assert(
        strpos($body, 'deleteByName(self::CONFIG_TOKEN_EXPIRY)') !== false,
        "Le bloc 'invalid_grant' ne purge plus CONFIG_TOKEN_EXPIRY — régression du bug corrigé le 14/09/2026 (round 359)"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager purge désormais CONFIG_ACCESS_TOKEN/CONFIG_TOKEN_EXPIRY en plus de CONFIG_REFRESH_TOKEN sur 'invalid_grant' — bug corrigé le 14/09/2026 (round 359)",
    ];
}
