<?php
/**
 * Régression : SearchConsoleManager::getStats()/getCachedStats() doivent
 * revalider le domaine du cache (via son champ 'site_url') contre le host
 * ACTUEL de la boutique avant de le servir, comme SeoApiManager::
 * getReport()/PageSpeedManager::getReport() le font déjà.
 *
 * Bug réel corrigé le 09/08/2026 (round 150) : ni getStats() ni
 * getCachedStats() ne comparaient jamais 'site_url' (déjà présent dans le
 * JSON caché) au host actuel de la boutique — un changement de domaine
 * pendant la fenêtre de cache (jusqu'à 12h, le plus long TTL des managers
 * SEO/API du module) continuait d'afficher les clics/impressions/CTR
 * d'une propriété Search Console qui ne correspond plus au domaine
 * réellement servi.
 *
 * Test comportemental réel : pose un cache dont 'site_url' pointe vers un
 * domaine DIFFÉRENT du domaine réel de la boutique de test, vérifie que
 * getCachedStats() ne le sert pas (renvoie null). Puis vérifie qu'un
 * cache avec le BON site_url est bien servi normalement (non-régression).
 * Utilise Configuration::updateValue() (voir test_233 pour la raison :
 * ces clés ne sont pas affectées par la limitation mono-boutique de
 * Configuration::, et updateValue() met à jour le cache interne PS en
 * plus de la base — contrairement à un INSERT SQL brut).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $module = neria_test_module();
    $idShop = (int) \Context::getContext()->shop->id;

    $mgr = new SearchConsoleManager($module);
    $realHost = (string) parse_url(\Tools::getShopDomainSsl(true), PHP_URL_HOST);

    $keyCache = 'NERIA_SC_CACHE_' . $idShop;
    $keyTime  = 'NERIA_SC_CACHE_TIME_' . $idShop;

    try {
        \Configuration::updateValue($keyCache, json_encode([
            'site_url'    => 'https://ancien-domaine-regtest150.example/',
            'clicks'      => 999,
            'impressions' => 9999,
        ], JSON_UNESCAPED_UNICODE));
        \Configuration::updateValue($keyTime, (string) time());

        $cachedStale = $mgr->getCachedStats();
        neria_assert(
            $cachedStale === null,
            "getCachedStats() sert encore un cache dont site_url pointe vers un domaine different — regression du bug corrige le 09/08/2026 (round 150) : les statistiques d'un ancien domaine seraient de nouveau affichees comme actuelles"
        );

        // Corrige site_url pour matcher le domaine reel -> doit redevenir servable
        \Configuration::updateValue($keyCache, json_encode([
            'site_url'    => 'https://' . $realHost . '/',
            'clicks'      => 999,
            'impressions' => 9999,
        ], JSON_UNESCAPED_UNICODE));
        $cachedFresh = $mgr->getCachedStats();
        neria_assert(
            is_array($cachedFresh) && ($cachedFresh['clicks'] ?? null) === 999,
            "getCachedStats() ne sert plus un cache dont site_url correspond bien au domaine actuel — non-regression cassee"
        );
    } finally {
        \Configuration::deleteByName($keyCache);
        \Configuration::deleteByName($keyTime);
    }

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager::getCachedStats() revalide bien le domaine (site_url) du cache avant de le servir",
    ];
}
