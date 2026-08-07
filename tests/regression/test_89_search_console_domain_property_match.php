<?php
/**
 * Régression : SearchConsoleManager::matchesShopHost() (extraite de
 * fetchAndCache()) doit comparer bidirectionnellement le siteUrl Google
 * Search Console au host de la boutique — même logique que
 * PostmasterManager::fetchAndCache(), qui fait déjà cette comparaison dans
 * les deux sens.
 *
 * Bug réel corrigé le 07/08/2026 (round 85) : la comparaison n'était faite
 * que dans un sens (stripos($su, $host)). Une "Domain property" Search
 * Console — le type recommandé par Google, siteUrl = 'sc-domain:example.com'
 * — est plus COURTE que le host complet de la boutique ('www.example.com'),
 * donc stripos() échouait systématiquement : aucune propriété ne matchait
 * jamais, et le BO affichait à tort "aucun site Search Console
 * correspondant" avec des statistiques vides, alors que le compte était
 * correctement configuré.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $mgr = new SearchConsoleManager(neria_test_module());
    $match = new ReflectionMethod(SearchConsoleManager::class, 'matchesShopHost');
    $match->setAccessible(true);

    // Cas réel : boutique sur www.example.com, propriété GSC enregistrée en
    // "Domain property" (sc-domain:example.com, sans www).
    neria_assert(
        $match->invoke($mgr, 'sc-domain:example.com', 'www.example.com') === true,
        "matchesShopHost() ne reconnaît plus une Domain property GSC ('sc-domain:example.com') comme correspondant à la boutique 'www.example.com' — régression du bug corrigé le 07/08/2026 (round 85) : le BO afficherait à tort 'aucun site Search Console correspondant'"
    );

    // Sens inverse : propriété GSC avec www, boutique sans www.
    neria_assert(
        $match->invoke($mgr, 'https://www.example.com/', 'example.com') === true,
        "matchesShopHost() ne reconnaît plus une propriété GSC 'https://www.example.com/' comme correspondant à la boutique 'example.com'"
    );

    // Domaine réellement différent : ne doit jamais matcher.
    neria_assert(
        $match->invoke($mgr, 'sc-domain:autresite.com', 'www.example.com') === false,
        "matchesShopHost() fait correspondre à tort un domaine complètement différent ('autresite.com' vs 'www.example.com')"
    );

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager::matchesShopHost() compare bien bidirectionnellement, y compris pour les Domain properties GSC",
    ];
}
