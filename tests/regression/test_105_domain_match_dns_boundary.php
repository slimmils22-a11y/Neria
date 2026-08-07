<?php
/**
 * Régression : PostmasterManager::domainsMatch() et SearchConsoleManager::
 * matchesShopHost() doivent comparer les domaines en respectant les
 * frontières de labels DNS, pas via stripos() en sous-chaîne pure.
 *
 * Bug réel corrigé le 07/08/2026 (round 101) : "shop.com" est une
 * sous-chaîne de "myshop.com" — stripos("myshop.com", "shop.com") retourne
 * 2 (position du match), jamais false. Le filtre acceptait donc à tort un
 * domaine Google Postmaster Tools / Search Console totalement non
 * apparenté dès qu'il partageait une fin de chaîne par coïncidence : un
 * marchand avec plusieurs domaines sous le même compte Google voyait la
 * réputation d'envoi (SPF/DKIM/DMARC, spam) ou les statistiques SEO d'un
 * AUTRE site affichées comme celles de sa propre boutique.
 *
 * Ce même défaut avait été copié tel quel dans SearchConsoleManager au
 * round 85 (PostmasterManager servait alors de référence "correcte" pour
 * la bidirectionnalité — mais sans jamais questionner la granularité de
 * stripos() lui-même).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $module = neria_test_module();

    // PostmasterManager::domainsMatch() (privée statique)
    $pm = new ReflectionMethod(PostmasterManager::class, 'domainsMatch');
    $pm->setAccessible(true);

    neria_assert(
        $pm->invoke(null, 'myshop.com', 'shop.com') === false,
        "PostmasterManager::domainsMatch() fait encore correspondre 'myshop.com' et 'shop.com' (sous-chaîne coïncidente) — régression du bug corrigé le 07/08/2026 (round 101) : la réputation d'un domaine totalement différent pourrait de nouveau être affichée comme celle de la boutique"
    );
    neria_assert(
        $pm->invoke(null, 'www.example.com', 'example.com') === true,
        "PostmasterManager::domainsMatch() ne reconnaît plus 'www.example.com' comme correspondant à 'example.com' (sous-domaine légitime)"
    );
    neria_assert(
        $pm->invoke(null, 'example.com', 'example.com') === true,
        "PostmasterManager::domainsMatch() ne reconnaît plus une correspondance exacte"
    );

    // SearchConsoleManager::matchesShopHost() (privée)
    $scr = new SearchConsoleManager($module);
    $sc = new ReflectionMethod(SearchConsoleManager::class, 'matchesShopHost');
    $sc->setAccessible(true);

    neria_assert(
        $sc->invoke($scr, 'sc-domain:shop.com', 'myshop.com') === false,
        "SearchConsoleManager::matchesShopHost() fait encore correspondre 'myshop.com' et 'shop.com' (sous-chaîne coïncidente) — régression du bug corrigé le 07/08/2026 (round 101)"
    );
    neria_assert(
        $sc->invoke($scr, 'sc-domain:example.com', 'www.example.com') === true,
        "SearchConsoleManager::matchesShopHost() ne reconnaît plus une Domain property GSC légitime (round 85) après le durcissement de la comparaison (round 101)"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager::domainsMatch() et SearchConsoleManager::matchesShopHost() comparent bien les domaines par frontière DNS, plus par sous-chaîne pure",
    ];
}
