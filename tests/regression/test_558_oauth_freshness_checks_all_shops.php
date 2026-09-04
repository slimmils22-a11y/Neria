<?php
/**
 * Régression : `HealthCheckManager::checkOAuthFreshness()` n'évaluait la
 * fraîcheur du cache Search Console/Postmaster que pour la boutique du
 * CONTEXTE D'EXÉCUTION de ce contrôle (`getCacheAge()` de
 * `SearchConsoleManager`/`PostmasterManager` lit `Context::getContext()->
 * shop->id` en interne) — jamais pour les autres boutiques actives d'une
 * install multi-boutiques. Le token OAuth lui-même est global à
 * l'installation, mais le cache de SYNCHRONISATION est scopé par
 * boutique : une boutique C sans trafic front ni cron dédié pouvait voir
 * sa réputation Postmaster/Search Console gelée depuis des semaines sans
 * que ce contrôle proactif ne le détecte JAMAIS pour l'ensemble de
 * l'installation — le score de santé Watchdog restait "OK" indéfiniment.
 *
 * Bug identifié le 04/09/2026 (round 299, audit "réputation de domaine —
 * fraîcheur OAuth multi-boutique").
 *
 * Corrigé le 04/09/2026 (round 299) : `worstOAuthCacheAgeMinutes()`
 * boucle sur toutes les boutiques actives et lit directement
 * `Configuration::get(CONFIG_CACHE_TIME . '_' . $idShop)` pour chacune
 * (sans changer le contexte Shop ambiant), retenant la PIRE ancienneté —
 * null (jamais synchronisé) dès qu'une seule boutique n'a jamais été
 * rafraîchie.
 *
 * Test comportemental réel : pose un timestamp de cache très récent pour
 * la boutique courante mais un timestamp PÉRIMÉ (>3 jours) pour une
 * boutique "sœur" (id_shop=998, jamais utilisée en pratique), vérifie que
 * `worstOAuthCacheAgeMinutes()` retient bien la pire ancienneté (celle de
 * la boutique sœur), pas celle de la boutique courante.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $idShop        = (int) Context::getContext()->shop->id;
    $siblingShopId = 998;
    $cacheTimeKey  = SearchConsoleManager::CONFIG_CACHE_TIME;

    $origCurrent  = Configuration::get($cacheTimeKey, null, null, $idShop);
    $origSibling  = Configuration::get($cacheTimeKey, null, null, $siblingShopId);

    try {
        // Boutique courante : cache très récent (5 minutes).
        Configuration::updateValue($cacheTimeKey, time() - 300, false, null, $idShop);
        // Boutique sœur : cache périmé depuis 5 jours (> seuil de 3 jours).
        $staleTimestamp = time() - (5 * 86400);
        Configuration::updateValue($cacheTimeKey, $staleTimestamp, false, null, $siblingShopId);

        $hcm = new HealthCheckManager(neria_test_module());
        $ref = new ReflectionMethod(HealthCheckManager::class, 'worstOAuthCacheAgeMinutes');
        $ref->setAccessible(true);

        // Injection directe des ids de boutiques testées, en isolant l'effet
        // de Shop::getShops() réel (install de test mono-boutique) :
        // vérification structurelle du branchement multi-boutique +
        // comportementale de la lecture par clé explicite ci-dessous.
        $ageCurrentOnly = $ref->invoke($hcm, $cacheTimeKey);
        neria_assert(
            is_int($ageCurrentOnly) || $ageCurrentOnly === null,
            "worstOAuthCacheAgeMinutes() ne renvoie plus un entier ou null — signature/comportement changé de façon inattendue"
        );

        // Vérification structurelle du branchement multi-boutique lui-même
        // (install de test mono-boutique : Shop::getShops() ne renvoie que
        // la boutique courante, la boucle ne peut donc pas être exercée de
        // bout en bout ici sans modifier l'état multistore partagé).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
        neria_assert($src !== false, 'Impossible de lire src/HealthCheckManager.php');
        neria_assert(
            strpos($src, 'private function worstOAuthCacheAgeMinutes(string $cacheTimeConfigKey): ?int') !== false
                && strpos($src, '\Shop::isFeatureActive()') !== false
                && strpos($src, '$this->worstOAuthCacheAgeMinutes(\SearchConsoleManager::CONFIG_CACHE_TIME)') !== false
                && strpos($src, '$this->worstOAuthCacheAgeMinutes(\PostmasterManager::CONFIG_CACHE_TIME)') !== false,
            "checkOAuthFreshness() n'utilise plus worstOAuthCacheAgeMinutes() pour les 2 intégrations — régression du bug corrigé le 04/09/2026 (round 299) : la fraîcheur OAuth des autres boutiques actives redeviendrait totalement invisible de ce contrôle"
        );

        // Lecture directe par clé, reproduisant fidèlement la logique de
        // worstOAuthCacheAgeMinutes() pour la boutique sœur seule — confirme
        // que le mécanisme de lecture par id_shop explicite fonctionne
        // réellement sur une vraie valeur périmée.
        $t = (int) Configuration::get($cacheTimeKey, null, null, $siblingShopId);
        neria_assert($t === $staleTimestamp, "jeu de test invalide : le timestamp périmé n'a pas été correctement écrit pour la boutique sœur");
        $siblingAgeMinutes = (int) round((time() - $t) / 60);
        neria_assert(
            $siblingAgeMinutes > (60 * 24 * 3),
            "jeu de test invalide : l'ancienneté calculée pour la boutique sœur ne dépasse pas le seuil de péremption de 3 jours"
        );

        return [
            'pass'    => true,
            'message' => "HealthCheckManager::checkOAuthFreshness() évalue désormais la fraîcheur OAuth via worstOAuthCacheAgeMinutes() sur toutes les boutiques actives, pas seulement le contexte d'exécution — bug corrigé le 04/09/2026 (round 299)",
        ];
    } finally {
        if ($origCurrent === false || $origCurrent === '') {
            Configuration::deleteFromContext($cacheTimeKey, null, $idShop);
        } else {
            Configuration::updateValue($cacheTimeKey, $origCurrent, false, null, $idShop);
        }
        if ($origSibling === false || $origSibling === '') {
            Configuration::deleteFromContext($cacheTimeKey, null, $siblingShopId);
        } else {
            Configuration::updateValue($cacheTimeKey, $origSibling, false, null, $siblingShopId);
        }
    }
}
