<?php
/**
 * Régression : PostmasterManager/SearchConsoleManager::applyTokenResponse()
 * n'effaçait jamais CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT après un échange
 * de token OAuth réussi.
 *
 * Bug réel : quand le refresh token expire/est révoqué, refreshAccessToken()
 * écrit CONFIG_LAST_ERROR='invalid_grant' et isConnected() devient false.
 * Le marchand reclique "Se connecter" et termine le flux OAuth avec succès
 * — mais CONFIG_LAST_ERROR restait positionné. HealthCheckManager::
 * checkOAuthFreshness() affiche donc "Search Console (erreur: invalid_grant)"
 * / "Postmaster Tools (erreur: ...)" dans le Health Check du BO juste après
 * une reconnexion pourtant réussie, jusqu'au prochain cycle getStats()
 * complet (jusqu'à 12h de TTL de cache).
 *
 * Corrigé le 08/09/2026 (round 320) : applyTokenResponse() efface désormais
 * CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT après avoir appliqué la réponse.
 *
 * Test comportemental réel : positionne une erreur OAuth périmée en config,
 * invoque applyTokenResponse() (via réflexion, comme test_48) avec une
 * réponse de succès, et vérifie que l'erreur est bien effacée.
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
        $errKey   = $className::CONFIG_LAST_ERROR;
        $errAtKey = $className::CONFIG_LAST_ERROR_AT;

        $previousErr   = Configuration::get($errKey);
        $previousErrAt = Configuration::get($errAtKey);

        try {
            Configuration::updateValue($errKey, 'invalid_grant');
            Configuration::updateValue($errAtKey, (string) (time() - 3600));

            $ref = new ReflectionMethod($instance, 'applyTokenResponse');
            $ref->setAccessible(true);
            $ref->invoke($instance, [
                'access_token' => 'regtest628-new-access-token',
                'expires_in'   => 3600,
            ]);

            $errAfter   = Configuration::get($errKey);
            $errAtAfter = Configuration::get($errAtKey);

            neria_assert(
                $errAfter === false || $errAfter === '',
                "{$className}::applyTokenResponse() ne remet plus à zéro CONFIG_LAST_ERROR (valeur='" . var_export($errAfter, true) . "') après un échange de token réussi — régression du bug corrigé le 08/09/2026 (round 320) : le Health Check afficherait de nouveau une erreur OAuth périmée juste après une reconnexion réussie"
            );
            neria_assert(
                $errAtAfter === false || $errAtAfter === '',
                "{$className}::applyTokenResponse() ne remet plus à zéro CONFIG_LAST_ERROR_AT après un échange de token réussi — régression du bug corrigé le 08/09/2026 (round 320)"
            );
        } finally {
            Configuration::updateValue($errKey, (string) $previousErr);
            Configuration::updateValue($errAtKey, (string) $previousErrAt);
        }
    }

    return [
        'pass'    => true,
        'message' => 'PostmasterManager et SearchConsoleManager effacent bien CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT après une reconnexion OAuth réussie — bug corrigé le 08/09/2026 (round 320)',
    ];
}
