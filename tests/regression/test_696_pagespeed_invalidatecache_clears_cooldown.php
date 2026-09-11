<?php
/**
 * Régression : `PageSpeedManager::invalidateCache()`/`clearError()`
 * (appelées par le handler BO `save_pagespeed_key` de `neria.php` à chaque
 * enregistrement de la configuration) n'effaçaient pas
 * `CONFIG_LAST_ATTEMPT`/`CONFIG_LAST_ATTEMPT_RATE_LIMITED`. Un marchand
 * corrigeant une clé API/URL invalide juste après un échec (mobile ET
 * desktop) restait bloqué par le cooldown résiduel (15 min à 1h si le
 * dernier échec était un 429) : `getReport()` retombait sur
 * `isInFailureCooldown()===true`, puis sur `getCachedReport()` — qui vient
 * pourtant d'être vidé par `invalidateCache()` — donc affichait "aucune
 * donnée" malgré une configuration désormais valide, jusqu'à expiration du
 * cooldown ou clic explicite sur "Rafraîchir" (seul chemin appelant
 * `runCheck()` directement, contournant le problème sans le résoudre).
 *
 * Bug identifié le 11/09/2026 (round 338, audit PageSpeedManager/
 * NeriaTools).
 *
 * Corrigé le 11/09/2026 (round 338) : `invalidateCache()` efface désormais
 * aussi `CONFIG_LAST_ATTEMPT`/`CONFIG_LAST_ATTEMPT_RATE_LIMITED`.
 *
 * Test comportemental réel : simule un échec récent (écrit
 * `CONFIG_LAST_ATTEMPT=time()` directement, comme le ferait `runCheck()`
 * après un double échec), vérifie que `isInFailureCooldown()` (privée,
 * via réflexion) retourne bien `true` AVANT le correctif, puis appelle
 * `invalidateCache()` et vérifie qu'elle retourne bien `false` après.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';

    $module = neria_test_module();
    $mgr    = new PageSpeedManager($module);

    $refCacheKey = new ReflectionMethod(PageSpeedManager::class, 'cacheKey');
    $refCacheKey->setAccessible(true);
    $keyLastAttempt = $refCacheKey->invoke($mgr, PageSpeedManager::CONFIG_LAST_ATTEMPT);
    $keyRateLimited = $refCacheKey->invoke($mgr, PageSpeedManager::CONFIG_LAST_ATTEMPT_RATE_LIMITED);

    $origLastAttempt = Configuration::get($keyLastAttempt);
    $origRateLimited = Configuration::get($keyRateLimited);

    $refCooldown = new ReflectionMethod(PageSpeedManager::class, 'isInFailureCooldown');
    $refCooldown->setAccessible(true);

    try {
        // Simule un échec RÉCENT (comme runCheck() après double échec mobile+desktop).
        Configuration::updateValue($keyLastAttempt, time());
        Configuration::updateValue($keyRateLimited, 0);

        neria_assert(
            $refCooldown->invoke($mgr) === true,
            "isInFailureCooldown() ne retourne pas true immédiatement après un échec simulé — jeu de test invalide"
        );

        $mgr->invalidateCache();

        neria_assert(
            (string) Configuration::get($keyLastAttempt) === '' || (int) Configuration::get($keyLastAttempt) === 0,
            "invalidateCache() n'efface plus CONFIG_LAST_ATTEMPT — régression du bug corrigé le 11/09/2026 (round 338)"
        );
        neria_assert(
            $refCooldown->invoke($mgr) === false,
            "isInFailureCooldown() retourne encore true après invalidateCache() — régression du bug corrigé le 11/09/2026 (round 338) : un marchand corrigeant sa configuration resterait bloqué par le cooldown résiduel malgré une reconfiguration valide"
        );

        return [
            'pass'    => true,
            'message' => "PageSpeedManager::invalidateCache() efface désormais CONFIG_LAST_ATTEMPT/CONFIG_LAST_ATTEMPT_RATE_LIMITED — une reconfiguration valide n'est plus bloquée par le cooldown résiduel d'un échec antérieur — bug corrigé le 11/09/2026 (round 338)",
        ];
    } finally {
        if ($origLastAttempt !== false && $origLastAttempt !== '') {
            Configuration::updateValue($keyLastAttempt, $origLastAttempt);
        } else {
            Configuration::deleteByName($keyLastAttempt);
        }
        if ($origRateLimited !== false && $origRateLimited !== '') {
            Configuration::updateValue($keyRateLimited, $origRateLimited);
        } else {
            Configuration::deleteByName($keyRateLimited);
        }
    }
}
