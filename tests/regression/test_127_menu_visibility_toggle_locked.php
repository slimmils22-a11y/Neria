<?php
/**
 * Régression : ConfigManager::toggleMenuItemVisibility() doit protéger son
 * cycle lecture-modification-écriture de NERIA_MENU_HIDDEN_ITEMS par un
 * verrou MySQL (GET_LOCK/RELEASE_LOCK).
 *
 * Bug réel corrigé le 08/08/2026 (round 123) : même famille que le round
 * 122 (states OAuth pending), ici sur la liste JSON de visibilité du menu
 * BO. Sans verrou, deux clics de masquage sur deux features différentes à
 * quelques centaines de ms d'écart (deux onglets BO, double clic) peuvent
 * tous deux lire la même liste avant que l'un des deux n'écrive : le
 * second Configuration::updateGlobalValue() écrase intégralement le
 * masquage posé par le premier, qui réapparaît silencieusement dans le
 * menu BO sans que l'admin l'ait réactivé lui-même.
 *
 * Test : vérifie structurellement la présence du verrou (une race
 * condition véritable n'est pas reproductible de façon fiable dans un test
 * PHP mono-thread), et fonctionnellement que deux appels séquentiels à
 * toggleMenuItemVisibility() pour DEUX clés différentes masquent bien LES
 * DEUX (le verrou ne doit pas casser le comportement normal non
 * concurrent).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ConfigManager.php');
    neria_assert(
        strpos($src, "GET_LOCK('neria_menu_hidden_items', 3)") !== false
        && strpos($src, "RELEASE_LOCK('neria_menu_hidden_items')") !== false,
        "ConfigManager::toggleMenuItemVisibility() ne verrouille plus le cycle lecture-modification-écriture — régression du bug corrigé le 08/08/2026 (round 123)"
    );

    $mgr = new ConfigManager(neria_test_module());
    \Configuration::updateGlobalValue('NERIA_MENU_HIDDEN_ITEMS', '[]');

    $keyA = 'regtest_round123_a';
    $keyB = 'regtest_round123_b';

    try {
        $mgr->toggleMenuItemVisibility($keyA);
        $mgr->toggleMenuItemVisibility($keyB);

        $hidden = $mgr->getHiddenMenuItems();
        neria_assert(
            in_array($keyA, $hidden, true) && in_array($keyB, $hidden, true),
            "toggleMenuItemVisibility() appelé deux fois de suite pour des clés différentes ne conserve que " . json_encode($hidden) . " au lieu des deux — le verrou casse le comportement normal non concurrent"
        );
    } finally {
        \Configuration::updateGlobalValue('NERIA_MENU_HIDDEN_ITEMS', '[]');
    }

    return [
        'pass'    => true,
        'message' => "ConfigManager::toggleMenuItemVisibility() verrouille bien le cycle lecture-modification-écriture, sans casser l'accumulation séquentielle normale",
    ];
}
