<?php
/**
 * Régression : PostmasterManager/SearchConsoleManager ne doivent pas
 * écraser un refresh_token OAuth déjà valide par une valeur vide quand
 * Google ne renvoie pas ce champ (cas réel : jeton déjà valide côté Google
 * pour ce compte/ces scopes, même avec prompt=consent).
 *
 * Bug réel corrigé le 05/08/2026 (round 51) : `$response['refresh_token']
 * ?? ''` puis CryptoManager::encrypt('') = '' écrasait silencieusement un
 * refresh_token fonctionnel — isConnected() devenait faux et l'admin devait
 * se reconnecter sans qu'aucune erreur ne soit remontée (access_token
 * présent = échange considéré comme un succès).
 *
 * La logique a été extraite dans applyTokenResponse() (privée) pour être
 * testable sans mocker l'appel réseau à Google — ce test l'invoque via
 * réflexion avec un $response fabriqué SANS refresh_token, et vérifie que
 * la valeur déjà en config n'est pas altérée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $module = neria_test_module();
    $classes = ['PostmasterManager', 'SearchConsoleManager'];

    foreach ($classes as $className) {
        $instance = new $className($module);
        $refreshKey = $className::CONFIG_REFRESH_TOKEN;
        $accessKey  = $className::CONFIG_ACCESS_TOKEN;

        $previousRefresh = Configuration::get($refreshKey);
        $previousAccess  = Configuration::get($accessKey);

        try {
            $knownGoodEncrypted = CryptoManager::encrypt('regtest-refresh-token-value');
            Configuration::updateValue($refreshKey, $knownGoodEncrypted);

            $ref = new ReflectionMethod($instance, 'applyTokenResponse');
            $ref->setAccessible(true);

            // Réponse Google réaliste SANS refresh_token (cas documenté).
            $ref->invoke($instance, [
                'access_token' => 'regtest-new-access-token',
                'expires_in'   => 3600,
            ]);

            $afterRefresh = Configuration::get($refreshKey);
            neria_assert(
                $afterRefresh === $knownGoodEncrypted,
                "{$className}::applyTokenResponse() a écrasé un refresh_token valide alors que la réponse n'en contenait pas — régression du bug corrigé le 05/08/2026"
            );

            $afterAccess = Configuration::get($accessKey);
            neria_assert(
                CryptoManager::decrypt($afterAccess) === 'regtest-new-access-token',
                "{$className}::applyTokenResponse() ne met plus à jour access_token"
            );
        } finally {
            Configuration::updateValue($refreshKey, (string) $previousRefresh);
            Configuration::updateValue($accessKey, (string) $previousAccess);
        }
    }

    return [
        'pass'    => true,
        'message' => 'PostmasterManager et SearchConsoleManager préservent le refresh_token existant quand Google n\'en renvoie pas',
    ];
}
