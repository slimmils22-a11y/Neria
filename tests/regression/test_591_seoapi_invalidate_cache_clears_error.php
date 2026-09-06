<?php
/**
 * Régression : SeoApiManager::invalidateCache() (appelée quand le
 * marchand change de fournisseur/clé API en BO — voir son docblock) ne
 * purgeait que CONFIG_CACHE/CONFIG_CACHE_TIME, jamais CONFIG_LAST_ERROR/
 * CONFIG_LAST_ERROR_AT. Une erreur laissée par l'ancien fournisseur/
 * l'ancienne clé continuait donc à s'afficher dans l'onglet santé BO
 * (HealthCheckManager) jusqu'au prochain runCheck() réussi, malgré le
 * marchand venant de corriger le problème signalé — contredisant
 * l'intention du docblock ("fournisseur/clé modifié en BO").
 *
 * Corrigé le 06/09/2026 (round 309) : invalidateCache() purge désormais
 * aussi CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT.
 *
 * Test comportemental réel : positionne une erreur réelle (comme le
 * ferait un appel API en échec), appelle invalidateCache(), puis vérifie
 * que getLastError()/getLastErrorAt() sont bien remis à vide/null.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php';

    $mgr = new SeoApiManager(neria_test_module());

    $refCache = new ReflectionMethod($mgr, 'cacheKey');
    $refCache->setAccessible(true);
    $errKey   = $refCache->invoke($mgr, SeoApiManager::CONFIG_LAST_ERROR);
    $errAtKey = $refCache->invoke($mgr, SeoApiManager::CONFIG_LAST_ERROR_AT);

    try {
        // Simule une erreur API réelle laissée par l'ancien fournisseur/clé.
        Configuration::updateValue($errKey, 'Regtest591 erreur simulée');
        Configuration::updateValue($errAtKey, time());

        neria_assert(
            $mgr->getLastError() !== '' && $mgr->getLastErrorAt() !== null,
            "jeu de test invalide : l'erreur simulée n'a pas été positionnée correctement"
        );

        $mgr->invalidateCache();

        neria_assert(
            $mgr->getLastError() === '',
            "SeoApiManager::invalidateCache() ne purge plus CONFIG_LAST_ERROR — régression du bug corrigé le 06/09/2026 (round 309) : une erreur de l'ancien fournisseur/ancienne clé continuerait à s'afficher dans l'onglet santé BO malgré le changement de configuration"
        );
        neria_assert(
            $mgr->getLastErrorAt() === null,
            "SeoApiManager::invalidateCache() ne purge plus CONFIG_LAST_ERROR_AT — régression du bug corrigé le 06/09/2026 (round 309)"
        );

        return [
            'pass'    => true,
            'message' => "SeoApiManager::invalidateCache() purge bien LAST_ERROR/LAST_ERROR_AT en plus du cache de résultat, cohérent avec son intention documentée (changement de fournisseur/clé = repartir propre) — bug corrigé le 06/09/2026 (round 309)",
        ];
    } finally {
        Configuration::deleteByName($errKey);
        Configuration::deleteByName($errAtKey);
    }
}
