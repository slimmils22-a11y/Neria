<?php
/**
 * Régression : PageSpeedManager::getReport() n'avait pas de repli sur le
 * dernier rapport connu, contrairement à sa jumelle SeoApiManager::
 * getReport() (round 171 : "repli gracieux sur dernières données connues").
 *
 * Bug réel identifié le 24/08/2026 (round 198) : quand le cache de 24h
 * expire pile au moment d'une panne/rate-limit de l'API Google
 * (isInFailureCooldown() actif), le widget BO passait brutalement de
 * "scores valables" à VIDE pendant toute la durée du cooldown (15min-1h),
 * au lieu d'afficher les derniers scores connus avec leur âge.
 *
 * Corrigé le 24/08/2026 (round 198) : repli sur getCachedReport() (même
 * périmé) quand isInFailureCooldown() est actif ou que runCheck() échoue,
 * cohérent avec SeoApiManager.
 *
 * Test comportemental réel : seed un rapport en cache PÉRIMÉ (cache_time
 * > 24h) et force le cooldown d'échec — getReport() doit retourner ce
 * rapport périmé plutôt que null.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';

    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $mgr = new PageSpeedManager($module);

    $cacheKeySuffix = '_' . $idShop;
    $targetUrl = $mgr->getTargetUrl();
    $staleReport = ['url' => $targetUrl, 'mobile' => ['score' => 42], 'desktop' => ['score' => 55]];

    $backupCache = Configuration::get('NERIA_PAGESPEED_CACHE' . $cacheKeySuffix);
    $backupCacheTime = Configuration::get('NERIA_PAGESPEED_CACHE_TIME' . $cacheKeySuffix);
    $backupLastAttempt = Configuration::get('NERIA_PAGESPEED_LAST_ATTEMPT' . $cacheKeySuffix);
    $backupRateLimited = Configuration::get('NERIA_PAGESPEED_LAST_ATTEMPT_RL' . $cacheKeySuffix);

    try {
        // Cache PÉRIMÉ (> 24h) — force getReport() à sortir du chemin "cache frais".
        Configuration::updateValue('NERIA_PAGESPEED_CACHE' . $cacheKeySuffix, json_encode($staleReport));
        Configuration::updateValue('NERIA_PAGESPEED_CACHE_TIME' . $cacheKeySuffix, time() - (25 * 3600));
        // Force le cooldown d'échec (tentative très récente).
        Configuration::updateValue('NERIA_PAGESPEED_LAST_ATTEMPT' . $cacheKeySuffix, time());
        Configuration::updateValue('NERIA_PAGESPEED_LAST_ATTEMPT_RL' . $cacheKeySuffix, 0);

        $result = $mgr->getReport();

        neria_assert(
            $result !== null,
            "PageSpeedManager::getReport() retourne null pendant le cooldown d'échec au lieu du dernier rapport connu — régression du bug corrigé le 24/08/2026 (round 198) : le widget BO passerait de nouveau brutalement de 'scores valables' à VIDE lors d'une panne transitoire de l'API"
        );
        neria_assert(
            isset($result['mobile']['score']) && $result['mobile']['score'] === 42,
            "PageSpeedManager::getReport() ne retourne pas le rapport périmé attendu pendant le cooldown"
        );
    } finally {
        if ($backupCache === false) {
            Configuration::deleteByName('NERIA_PAGESPEED_CACHE' . $cacheKeySuffix);
        } else {
            Configuration::updateValue('NERIA_PAGESPEED_CACHE' . $cacheKeySuffix, $backupCache);
        }
        if ($backupCacheTime === false) {
            Configuration::deleteByName('NERIA_PAGESPEED_CACHE_TIME' . $cacheKeySuffix);
        } else {
            Configuration::updateValue('NERIA_PAGESPEED_CACHE_TIME' . $cacheKeySuffix, $backupCacheTime);
        }
        if ($backupLastAttempt === false) {
            Configuration::deleteByName('NERIA_PAGESPEED_LAST_ATTEMPT' . $cacheKeySuffix);
        } else {
            Configuration::updateValue('NERIA_PAGESPEED_LAST_ATTEMPT' . $cacheKeySuffix, $backupLastAttempt);
        }
        if ($backupRateLimited === false) {
            Configuration::deleteByName('NERIA_PAGESPEED_LAST_ATTEMPT_RL' . $cacheKeySuffix);
        } else {
            Configuration::updateValue('NERIA_PAGESPEED_LAST_ATTEMPT_RL' . $cacheKeySuffix, $backupRateLimited);
        }
    }

    return [
        'pass'    => true,
        'message' => "PageSpeedManager::getReport() retombe bien sur le dernier rapport connu pendant le cooldown d'échec, comme SeoApiManager — bug corrigé le 24/08/2026 (round 198)",
    ];
}
