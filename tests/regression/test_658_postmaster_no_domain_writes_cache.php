<?php
/**
 * Régression : PostmasterManager::fetchAndCache() retournait [] avant
 * d'atteindre le bloc d'écriture du cache quand le compte Google Postmaster
 * Tools ne contient encore AUCUN domaine vérifié (compte fraîchement
 * connecté, domaine sans historique d'envoi suffisant pour être listé par
 * Google) — CONFIG_CACHE_TIME restait à 0 indéfiniment dans ce cas précis.
 *
 * Bug réel : getStats() ne déclenche fetchAndCache() QUE si le cache est
 * absent/expiré (TTL 1h). Sans cette écriture, chaque chargement d'une page
 * BO affichant le widget Postmaster redéclenchait un appel réseau réel à
 * l'API Gmail Postmaster Tools, sans jamais respecter le TTL — l'API étant
 * explicitement documentée comme sensible aux quotas. Même bug déjà corrigé
 * pour SearchConsoleManager (round 171) et pour le cas "aucun domaine
 * CORRESPONDANT à la boutique" de cette même méthode (plus bas dans le
 * fichier), mais jamais porté sur cette branche plus en amont ("aucun
 * domaine DU TOUT dans le compte").
 *
 * Corrigé le 09/09/2026 (round 328) : le cache (CONFIG_CACHE=[],
 * CONFIG_CACHE_TIME, CONFIG_CACHE_HOST) est désormais écrit avant ce retour
 * anticipé, comme pour les autres branches de la méthode.
 *
 * Test structurel (fetchAndCache() effectue un vrai appel réseau à l'API
 * Google, non invocable dans ce jeu de tests — même limitation documentée
 * par test_132) : vérifie que les 3 écritures de cache sont bien présentes
 * dans le bloc "aucun domaine".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PostmasterManager.php');

    $posBlock = strpos($src, "watchdog.postmaster_no_domain'), '', 'PostmasterManager');");
    neria_assert($posBlock !== false, "Bloc 'aucun domaine' introuvable — jeu de test invalide");
    $block = substr($src, $posBlock, 1300);

    neria_assert(
        strpos($block, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE),      json_encode([], JSON_UNESCAPED_UNICODE));') !== false,
        "PostmasterManager::fetchAndCache() n'écrit plus CONFIG_CACHE dans le bloc 'aucun domaine' — régression du bug corrigé le 09/09/2026 (round 328) : chaque page BO redéclencherait un appel réseau réel à l'API Google Postmaster tant qu'aucun domaine n'est encore listé"
    );
    neria_assert(
        strpos($block, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_TIME), time());') !== false,
        "PostmasterManager::fetchAndCache() n'écrit plus CONFIG_CACHE_TIME dans le bloc 'aucun domaine' — régression du bug corrigé le 09/09/2026 (round 328)"
    );
    neria_assert(
        strpos($block, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_HOST), $this->getShopHost());') !== false,
        "PostmasterManager::fetchAndCache() n'écrit plus CONFIG_CACHE_HOST dans le bloc 'aucun domaine' — régression du bug corrigé le 09/09/2026 (round 328)"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager::fetchAndCache() écrit bien le cache (TTL respecté) même quand le compte Google Postmaster Tools ne liste encore aucun domaine — bug corrigé le 09/09/2026 (round 328)",
    ];
}
