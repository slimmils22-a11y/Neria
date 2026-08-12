<?php
/**
 * Régression : PostmasterManager::getStats()/getCachedStats() doivent
 * revalider le domaine du cache contre le host ACTUEL de la boutique
 * avant de le servir, comme SeoApiManager::getReport()/PageSpeedManager::
 * getReport() le font déjà pour leur propre cache.
 *
 * Bug réel corrigé le 09/08/2026 (round 150) : CONFIG_CACHE_HOST était
 * écrit par fetchAndCache() mais jamais relu par getStats()/
 * getCachedStats() — un changement de domaine de la boutique pendant la
 * fenêtre de cache (jusqu'à 1h) continuait d'afficher la réputation
 * d'envoi de l'ANCIEN domaine sans que rien ne le signale.
 *
 * Test comportemental réel : pose un cache valide (TTL non expiré) associé
 * à un host DIFFÉRENT du host réel de la boutique de test, vérifie que
 * getCachedStats() ne le sert PAS tel quel (doit renvoyer null). Puis
 * vérifie que le même cache, avec le BON host, est bien servi normalement
 * (non-régression). Utilise Configuration::updateValue() (pas de SQL brut)
 * : cacheKey() suffixe le nom avec l'id_shop et PostmasterManager appelle
 * Configuration::get()/updateValue() SANS idShop explicite — cette clé
 * n'est donc PAS affectée par la limitation mono-boutique de
 * Configuration:: documentée ailleurs dans ce projet, et updateValue()
 * met à jour le cache interne de PrestaShop en plus de la base (contrairement
 * à un INSERT SQL brut, invisible du cache déjà chargé en mémoire).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php';

    $module = neria_test_module();
    $idShop = (int) \Context::getContext()->shop->id;

    $mgr = new PostmasterManager($module);
    $realHost = (string) parse_url(\Tools::getShopDomainSsl(true), PHP_URL_HOST);
    $fakeHost = 'ancien-domaine-regtest150.example';

    $keyCache = 'NERIA_POSTMASTER_CACHE_' . $idShop;
    $keyTime  = 'NERIA_POSTMASTER_CACHE_TIME_' . $idShop;
    $keyHost  = 'NERIA_POSTMASTER_CACHE_HOST_' . $idShop;

    try {
        \Configuration::updateValue($keyCache, json_encode(['domain_reputation' => 'HIGH', 'spam_rate' => 'LOW']));
        \Configuration::updateValue($keyTime, (string) time());
        \Configuration::updateValue($keyHost, $fakeHost);

        $cachedStale = $mgr->getCachedStats();
        neria_assert(
            $cachedStale === null,
            "getCachedStats() sert encore un cache associe a un domaine different ('{$fakeHost}' vs '{$realHost}') — regression du bug corrige le 09/08/2026 (round 150) : la reputation d'un ancien domaine serait de nouveau affichee comme actuelle"
        );

        // Corrige le host en base pour matcher le domaine reel -> doit redevenir servable
        \Configuration::updateValue($keyHost, $realHost);
        $cachedFresh = $mgr->getCachedStats();
        neria_assert(
            is_array($cachedFresh) && ($cachedFresh['domain_reputation'] ?? null) === 'HIGH',
            "getCachedStats() ne sert plus un cache dont le host correspond bien au domaine actuel — non-regression cassee"
        );
    } finally {
        \Configuration::deleteByName($keyCache);
        \Configuration::deleteByName($keyTime);
        \Configuration::deleteByName($keyHost);
    }

    return [
        'pass'    => true,
        'message' => "PostmasterManager::getCachedStats() revalide bien le domaine du cache avant de le servir",
    ];
}
