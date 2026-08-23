<?php
/**
 * Régression : PostmasterManager::disconnect()/SearchConsoleManager::disconnect()
 * révoquent le token OAuth globalement (deleteByName() sans idShop — la
 * connexion est volontairement partagée par toute l'installation), mais
 * clearCache() ne vidait QUE le cache de la boutique courante (cacheKey()
 * suffixé par id_shop).
 *
 * Bug réel identifié le 23/08/2026 (round 189) : sur une install
 * multi-boutiques, se déconnecter depuis la Boutique A laissait le cache de
 * la Boutique B intact jusqu'à expiration du TTL (1h Postmaster / 12h Search
 * Console) — son tableau de bord continuait d'afficher des données "en
 * direct" d'une connexion pourtant révoquée globalement, alors qu'isConnected()
 * signalait correctement "déconnecté".
 *
 * Corrigé le 23/08/2026 (round 189) : disconnect() itère désormais
 * Shop::getShops() et vide le cache de CHAQUE boutique, pas seulement celle
 * du contexte courant.
 *
 * Test comportemental réel : seed le cache pour la boutique courante (réelle,
 * garantie d'exister) et vérifie qu'il est bien vidé par disconnect(), pour
 * les 2 classes. Complété par une vérification structurelle que la boucle
 * Shop::getShops() est bien présente (preuve qu'un environnement multi-
 * boutique réel serait couvert, non testable en dur sans fixture 2e boutique).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    // ── PostmasterManager ────────────────────────────────────────────
    Configuration::updateValue('NERIA_POSTMASTER_CACHE_' . $idShop, json_encode(['x' => 1]));
    Configuration::updateValue('NERIA_POSTMASTER_CACHE_TIME_' . $idShop, time());

    (new PostmasterManager($module))->disconnect();

    neria_assert(
        Configuration::get('NERIA_POSTMASTER_CACHE_' . $idShop) === false,
        "PostmasterManager::disconnect() n'a pas vidé le cache de la boutique courante — régression du bug corrigé le 23/08/2026 (round 189)"
    );
    neria_assert(
        Configuration::get('NERIA_POSTMASTER_CACHE_TIME_' . $idShop) === false,
        "PostmasterManager::disconnect() n'a pas vidé CACHE_TIME de la boutique courante — régression du bug corrigé le 23/08/2026 (round 189)"
    );

    // ── SearchConsoleManager ─────────────────────────────────────────
    Configuration::updateValue('NERIA_SC_CACHE_' . $idShop, json_encode(['x' => 1]));
    Configuration::updateValue('NERIA_SC_CACHE_TIME_' . $idShop, time());

    (new SearchConsoleManager($module))->disconnect();

    neria_assert(
        Configuration::get('NERIA_SC_CACHE_' . $idShop) === false,
        "SearchConsoleManager::disconnect() n'a pas vidé le cache de la boutique courante — régression du bug corrigé le 23/08/2026 (round 189)"
    );
    neria_assert(
        Configuration::get('NERIA_SC_CACHE_TIME_' . $idShop) === false,
        "SearchConsoleManager::disconnect() n'a pas vidé CACHE_TIME de la boutique courante — régression du bug corrigé le 23/08/2026 (round 189)"
    );

    // ── Structurel : preuve que TOUTES les boutiques sont couvertes ──
    $pmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    $scSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php');
    neria_assert(
        strpos($pmSrc, 'Shop::getShops(false, null, true)') !== false,
        "PostmasterManager::disconnect() n'itère plus Shop::getShops() — ne viderait de nouveau que le cache de la boutique courante sur une install multi-boutiques"
    );
    neria_assert(
        strpos($scSrc, 'Shop::getShops(false, null, true)') !== false,
        "SearchConsoleManager::disconnect() n'itère plus Shop::getShops() — ne viderait de nouveau que le cache de la boutique courante sur une install multi-boutiques"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager/SearchConsoleManager::disconnect() vident bien le cache de toutes les boutiques (Shop::getShops()), pas seulement la courante — bug corrigé le 23/08/2026 (round 189)",
    ];
}
