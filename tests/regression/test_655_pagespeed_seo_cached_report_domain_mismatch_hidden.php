<?php
/**
 * Régression : neria.php (page Stats BO) affichait le cache brut de
 * PageSpeedManager/SeoApiManager via getCachedReport() — SANS jamais
 * passer par getReport(), qui contient pourtant toute la logique de
 * correspondance de domaine construite au fil des rounds précédents
 * (vérifier que $data['url']/$data['domain'] du cache correspond bien au
 * domaine ACTUEL avant de l'afficher). Toute cette protection était donc
 * du code mort côté affichage réel.
 *
 * Bug réel : un marchand multi-boutiques changeant le domaine de sa
 * boutique (renommage hors formulaire Neria, sans invalidateCache())
 * continuait à voir indéfiniment le rapport PageSpeed/SEO de l'ANCIEN
 * domaine, avec un cache_age laissant croire à des données pertinentes.
 *
 * Corrigé le 09/09/2026 (round 327) : nouvelle méthode
 * getCachedReportIfCurrentDomain() (appliquant le même contrôle que
 * getReport(), SANS jamais déclencher runCheck() — pas d'appel réseau
 * synchrone au chargement d'une page BO normale), utilisée par neria.php
 * à la place de getCachedReport() brut.
 *
 * Test comportemental réel : écrit un cache réel en base référençant un
 * domaine/URL FICTIF (différent du domaine réel de la boutique de test),
 * vérifie que getCachedReportIfCurrentDomain() retourne bien null (alors
 * que getCachedReport() brut retournerait toujours les données pour
 * prouver le mécanisme du bug), pour les 2 classes concernées.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php';

    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    // ── PageSpeedManager ──────────────────────────────────────────
    $psCacheKey     = 'NERIA_PAGESPEED_CACHE_' . $idShop;
    $psCacheTimeKey = 'NERIA_PAGESPEED_CACHE_TIME_' . $idShop;
    $psBackupCache     = Configuration::get($psCacheKey);
    $psBackupCacheTime = Configuration::get($psCacheTimeKey);

    try {
        $fakeData = ['url' => 'https://domaine-fictif-regtest655.invalid/', 'mobile' => ['performance' => 80]];
        Configuration::updateValue($psCacheKey, json_encode($fakeData));
        Configuration::updateValue($psCacheTimeKey, time());

        $psMgr = new PageSpeedManager($module);

        neria_assert(
            $psMgr->getCachedReport() !== null,
            "jeu de test invalide : getCachedReport() ne relit pas le cache fictif écrit"
        );
        neria_assert(
            $psMgr->getCachedReportIfCurrentDomain() === null,
            "PageSpeedManager::getCachedReportIfCurrentDomain() renvoie encore le rapport d'un domaine différent du domaine actuel — régression du bug corrigé le 09/09/2026 (round 327) : la page Stats BO afficherait de nouveau indéfiniment un rapport obsolète appartenant à un autre domaine"
        );
    } finally {
        if ($psBackupCache !== false && $psBackupCache !== null) {
            Configuration::updateValue($psCacheKey, $psBackupCache);
        } else {
            Configuration::deleteByName($psCacheKey);
        }
        if ($psBackupCacheTime !== false && $psBackupCacheTime !== null) {
            Configuration::updateValue($psCacheTimeKey, $psBackupCacheTime);
        } else {
            Configuration::deleteByName($psCacheTimeKey);
        }
    }

    // ── SeoApiManager ──────────────────────────────────────────────
    $seoCacheKey     = 'NERIA_SEO_API_CACHE_' . $idShop;
    $seoCacheTimeKey = 'NERIA_SEO_API_CACHE_TIME_' . $idShop;
    $seoBackupCache     = Configuration::get($seoCacheKey);
    $seoBackupCacheTime = Configuration::get($seoCacheTimeKey);

    try {
        $fakeSeoData = ['domain' => 'domaine-fictif-regtest655.invalid', 'authority_score' => 5];
        Configuration::updateValue($seoCacheKey, json_encode($fakeSeoData));
        Configuration::updateValue($seoCacheTimeKey, time());

        $seoMgr = new SeoApiManager($module);

        neria_assert(
            $seoMgr->getCachedReport() !== null,
            "jeu de test invalide : getCachedReport() (SEO) ne relit pas le cache fictif écrit"
        );
        neria_assert(
            $seoMgr->getCachedReportIfCurrentDomain() === null,
            "SeoApiManager::getCachedReportIfCurrentDomain() renvoie encore le rapport d'un domaine différent du domaine actuel — régression du bug corrigé le 09/09/2026 (round 327)"
        );
    } finally {
        if ($seoBackupCache !== false && $seoBackupCache !== null) {
            Configuration::updateValue($seoCacheKey, $seoBackupCache);
        } else {
            Configuration::deleteByName($seoCacheKey);
        }
        if ($seoBackupCacheTime !== false && $seoBackupCacheTime !== null) {
            Configuration::updateValue($seoCacheTimeKey, $seoBackupCacheTime);
        } else {
            Configuration::deleteByName($seoCacheTimeKey);
        }
    }

    $neriaSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(
        strpos($neriaSrc, '$mgr->getCachedReportIfCurrentDomain();') !== false,
        "neria.php n'utilise plus getCachedReportIfCurrentDomain() pour pagespeed_report/seo_report — régression du bug corrigé le 09/09/2026 (round 327)"
    );

    return [
        'pass'    => true,
        'message' => "PageSpeedManager/SeoApiManager::getCachedReportIfCurrentDomain() masquent bien un rapport en cache appartenant à un autre domaine, et neria.php les utilise bien pour l'affichage réel — bug corrigé le 09/09/2026 (round 327)",
    ];
}
