<?php
/**
 * Régression : PostmasterManager::fetchAndCache() doit écrire le cache via
 * cacheKey() (scopé par boutique) — comme TOUTES les lectures
 * (getStats()/getCachedStats()/getCacheAge()) et clearCache() dans ce même
 * fichier, et comme SearchConsoleManager::fetchAndCache() (méthode jumelle).
 *
 * Bug réel corrigé le 08/08/2026 (round 128) : fetchAndCache() écrivait via
 * Configuration::updateValue(self::CONFIG_CACHE, ...) — les constantes
 * BRUTES, non suffixées — alors que les 3 méthodes de lecture (getStats(),
 * getCachedStats(), getCacheAge()) lisent toutes via
 * Configuration::get($this->cacheKey(...)), une clé suffixée par id_shop.
 * Résultat : le cache écrit n'était JAMAIS relu par aucune boutique (qui
 * lisent toutes une clé différente, suffixée par leur propre id_shop) — le
 * cache n'était en réalité jamais exploité, et chaque chargement du BO
 * Postmaster Tools, sur n'importe quelle boutique, redéclenchait un appel
 * réel à l'API Gmail Postmaster (sensible aux quotas), quel que soit le TTL
 * de 3600s configuré.
 *
 * Test structurel (fetchAndCache() effectue un vrai appel réseau à l'API
 * Google, non invocable dans ce jeu de tests) : vérifie que les 3 écritures
 * de fetchAndCache() passent bien par cacheKey(), comme toutes les
 * lectures.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PostmasterManager.php');

    $posFetch = strpos($src, 'private function fetchAndCache(');
    neria_assert($posFetch !== false, "Méthode fetchAndCache() introuvable — jeu de test invalide");
    $block = substr($src, $posFetch, 3500);

    neria_assert(
        strpos($block, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE),') !== false,
        "PostmasterManager::fetchAndCache() n'écrit plus CONFIG_CACHE via cacheKey() — régression du bug corrigé le 08/08/2026 (round 128) : le cache écrit ne serait plus jamais relu par aucune boutique"
    );
    neria_assert(
        strpos($block, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_TIME), time());') !== false,
        "PostmasterManager::fetchAndCache() n'écrit plus CONFIG_CACHE_TIME via cacheKey() — régression du bug corrigé le 08/08/2026 (round 128)"
    );
    neria_assert(
        strpos($block, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_HOST), $shopHost);') !== false,
        "PostmasterManager::fetchAndCache() n'écrit plus CONFIG_CACHE_HOST via cacheKey() — régression du bug corrigé le 08/08/2026 (round 128)"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager::fetchAndCache() écrit bien le cache via cacheKey(), cohérent avec les lectures scopées par boutique",
    ];
}
