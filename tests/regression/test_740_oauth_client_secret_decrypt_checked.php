<?php
/**
 * Régression : SearchConsoleManager::refreshAccessToken()/
 * PostmasterManager::refreshAccessToken() doivent vérifier le résultat de
 * CryptoManager::decrypt() sur client_secret avant de l'utiliser dans la
 * requête OAuth, exactement comme déjà fait pour le refresh token.
 *
 * Bug identifié le 14/09/2026 (round 353, audit multi-agents, angle
 * crypto) : contrairement à $refresh (vérifié, avec distinction "jamais
 * connecté" vs "token illisible"), client_secret était déchiffré INLINE
 * dans le tableau de paramètres POST, sans jamais vérifier son résultat.
 * Si NERIA_ENCRYPTION_KEY est corrompue/rotée ou si client_secret est
 * tronqué en base indépendamment de la clé (migration, édition manuelle),
 * decrypt() retourne '' silencieusement et la requête OAuth part quand
 * même avec un client_secret vide — Google répond un 'invalid_client'
 * générique, sans que la vraie cause (secret illisible) ne soit jamais
 * distinguée ni journalisée, contrairement à tous les autres secrets du
 * module qui vérifient systématiquement ce retour avant usage sensible.
 *
 * Test structurel (simuler une vraie corruption de NERIA_ENCRYPTION_KEY
 * sans casser les autres secrets déjà chiffrés avec la même clé, dans un
 * environnement de test partagé, est impraticable et risqué) : vérifie
 * que les 2 méthodes vérifient bien le résultat de decrypt() sur
 * client_secret avant de l'utiliser dans l'appel OAuth.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach ([
        'SearchConsoleManager' => ['file' => 'src/SearchConsoleManager.php', 'watchdogKey' => 'watchdog.search_console_client_secret_unreadable'],
        'PostmasterManager'    => ['file' => 'src/PostmasterManager.php',    'watchdogKey' => 'watchdog.postmaster_client_secret_unreadable'],
    ] as $className => $cfg) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/' . $cfg['file']);
        neria_assert($src !== false, "Impossible de lire {$cfg['file']}");

        $posFn = strpos($src, 'private function refreshAccessToken(): ?string');
        neria_assert($posFn !== false, "{$className}::refreshAccessToken() introuvable");

        $posClientSecretDecrypt = strpos($src, '$clientSecret    = \CryptoManager::decrypt($rawClientSecret);', $posFn);
        neria_assert(
            $posClientSecretDecrypt !== false,
            "{$className}::refreshAccessToken() ne décrypte plus client_secret dans une variable vérifiable — régression du bug corrigé le 14/09/2026 (round 353)"
        );

        $body = substr($src, $posClientSecretDecrypt, 900);
        neria_assert(
            strpos($body, "if (\$clientSecret === '' && \$rawClientSecret !== '') {") !== false,
            "{$className}::refreshAccessToken() ne vérifie plus le résultat de decrypt() sur client_secret avant de l'utiliser — régression du bug corrigé le 14/09/2026 (round 353) : un secret illisible partirait de nouveau vide dans la requête OAuth, sans diagnostic distinct"
        );
        neria_assert(
            strpos($body, "\\WatchdogManager::i18nMsg('{$cfg['watchdogKey']}')") !== false,
            "{$className}::refreshAccessToken() ne journalise plus d'alerte Watchdog dédiée pour client_secret illisible — régression du bug corrigé le 14/09/2026 (round 353)"
        );
        neria_assert(
            strpos($body, "'client_secret' => \$clientSecret,") !== false,
            "{$className}::refreshAccessToken() n'utilise plus la variable vérifiée \$clientSecret dans la requête OAuth — régression du bug corrigé le 14/09/2026 (round 353)"
        );
    }

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['watchdog.search_console_client_secret_unreadable', 'watchdog.postmaster_client_secret_unreadable'] as $key) {
        neria_assert(
            isset($translations[$key]) && count($translations[$key]) === 19,
            "Clé de traduction {$key} manquante ou incomplète (19 langues attendues)"
        );
    }

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager/PostmasterManager::refreshAccessToken() vérifient bien le résultat de decrypt() sur client_secret avant usage, avec une alerte Watchdog dédiée — bug corrigé le 14/09/2026 (round 353)",
    ];
}
