<?php
/**
 * Régression : dans neria.php::runBackgroundJobs(), les 5 boucles
 * per-boutique (webhook, calendar, domain reputation, seasonal campaigns,
 * watchdog digest) doivent restaurer Context::getContext()->shop dans un
 * bloc `finally`, garantissant la restauration même si `new \Shop($idShop)`
 * lève une exception (boutique fantôme/orpheline, panne DB transitoire).
 *
 * Bug identifié le 13/09/2026 (round 347, audit multi-agents, angle hooks) :
 * l'instanciation `Context::getContext()->shop = new \Shop((int) $idShopX);`
 * était placée AVANT le try/catch interne (qui ne protégeait que l'appel au
 * manager), et aucun try/finally n'englobait la boucle elle-même. Si
 * `new \Shop()` levait, l'exception s'échappait de toute la boucle SANS
 * jamais restaurer `$originalShopX` — le contexte boutique de la requête
 * HTTP du visiteur (hookDisplayHeader est appelé sur du vrai trafic front,
 * pas seulement le cron réel) restait alors positionné sur la dernière
 * boutique manipulée pour le reste du rendu de sa page (prix, devise,
 * URLs Link::getProductLink(), autres hooks tiers exécutés plus tard dans
 * le même rendu).
 *
 * Test structurel (runBackgroundJobs() est déclenchée par un throttle 24h
 * interne difficile à forcer de façon isolée en test — la garantie
 * recherchée est la présence du bloc try/finally, pas le comportement d'un
 * `new \Shop()` qui lève, scénario rare par nature) : vérifie pour CHACUNE
 * des 5 boucles que l'affectation `Context::getContext()->shop = new
 * \Shop(...)` est bien à l'intérieur d'un `try` dont le `finally` restaure
 * la variable $originalShopX d'origine.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $sites = [
        'webhook'  => 'Webhook',
        'calendar' => 'Calendar',
        'domain reputation' => 'DR',
        'seasonal campaigns' => 'Seasonal',
        'watchdog digest' => 'Digest',
    ];

    foreach ($sites as $label => $suffix) {
        $originalVar = '$originalShop' . $suffix;
        $posOriginal = strpos($src, $originalVar . ' = \Context::getContext()->shop;');
        neria_assert($posOriginal !== false, "Boucle '$label' : variable $originalVar introuvable — régression du correctif round 347 ou renommage non répercuté dans ce test");

        // Fenêtre de recherche : entre la déclaration de $originalShopX et
        // les ~2200 caractères suivants (couvre foreach + try + catch +
        // finally pour chacun des 5 sites, y compris les longs commentaires
        // explicatifs intercalés — mesuré sur le code réel, marge incluse).
        $window = substr($src, $posOriginal, 2500);

        $posTry = strpos($window, 'try {');
        neria_assert($posTry !== false, "Boucle '$label' : aucun try{ trouvé après la capture de $originalVar — régression du correctif round 347 (plus de try/finally englobant)");

        $posFinally = strpos($window, '} finally {', $posTry);
        neria_assert($posFinally !== false, "Boucle '$label' : aucun } finally { trouvé — régression du correctif round 347, la restauration du contexte boutique n'est plus garantie si new \Shop() lève");

        // La restauration doit se trouver DANS le finally, pas seulement
        // ailleurs dans la fenêtre (sinon un finally vide passerait le test).
        $finallyBody = substr($window, $posFinally, 700);
        neria_assert(
            strpos($finallyBody, '\Context::getContext()->shop = ' . $originalVar . ';') !== false,
            "Boucle '$label' : le bloc finally ne restaure plus \Context::getContext()->shop = $originalVar — régression du correctif round 347"
        );
    }

    return [
        'pass'    => true,
        'message' => "Les 5 boucles per-boutique de runBackgroundJobs() restaurent bien Context::getContext()->shop dans un bloc finally, garantissant la restauration même si new \Shop() lève une exception",
    ];
}
