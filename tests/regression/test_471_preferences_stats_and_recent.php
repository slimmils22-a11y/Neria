<?php
/**
 * Régression round 232 (28/08/2026) : views/templates/admin/configure.tpl
 * référence $prefs_stats et $prefs_recent (bloc "Centre de préférences
 * email", ligne 1150 et 1166) depuis sa création, mais AUCUNE méthode PHP
 * ne les calculait jamais — ni dans PreferencesManager, ni ailleurs dans
 * src/*.php, ni dans neria.php. La section restait donc vide en
 * permanence pour tous les marchands (le {if} Smarty échoue
 * silencieusement sur une variable non définie, sans erreur visible).
 *
 * Corrigé le 28/08/2026 (round 232) : PreferencesManager::getStats() et
 * ::getRecentChanges() ajoutées, branchées dans neria.php::configure().
 *
 * Test comportemental réel : insère des lignes neria_preferences réelles
 * (2 catégories, dont un désabonnement) pour un client de test, puis
 * vérifie que getStats() et getRecentChanges() reflètent bien ces
 * données réelles.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idCustomer = neria_test_any_customer_id();
    $idShop = (int) Context::getContext()->shop->id;

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND category IN ('cart', 'loyalty')");

    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
         VALUES ({$idShop}, {$idCustomer}, 'regtest471@example.com', 'cart', 0, NOW())"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
         VALUES ({$idShop}, {$idCustomer}, 'regtest471@example.com', 'loyalty', 1, NOW())"
    );

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';
        $mgr = new PreferencesManager($module);

        $stats = $mgr->getStats();
        neria_assert(
            isset($stats['cart']) && isset($stats['loyalty']),
            "getStats() ne retourne pas les catégories attendues — jeu de test invalide"
        );
        neria_assert(
            $stats['cart']['opted_out'] >= 1,
            "getStats() ne compte pas le désabonnement 'cart' inséré (opted_out={$stats['cart']['opted_out']}) — "
            . "régression du bug corrigé le 28/08/2026 (round 232) : le bloc Centre de préférences email afficherait de nouveau des compteurs faux ou vides"
        );
        neria_assert(
            $stats['loyalty']['total'] >= 1,
            "getStats() ne compte pas la ligne 'loyalty' insérée (total={$stats['loyalty']['total']})"
        );

        $recent = $mgr->getRecentChanges(50);
        $found = false;
        foreach ($recent as $r) {
            if ((int) $r['id_customer'] === $idCustomer) {
                $found = true;
                neria_assert(
                    (int) $r['nb_optout'] >= 1,
                    "getRecentChanges() ne compte pas le désabonnement du client de test dans nb_optout"
                );
                break;
            }
        }
        neria_assert(
            $found,
            "getRecentChanges() ne retrouve pas le client de test qui vient de modifier ses préférences — "
            . "régression du bug corrigé le 28/08/2026 (round 232) : le tableau des modifications récentes resterait de nouveau vide"
        );

        return [
            'pass'    => true,
            'message' => "PreferencesManager::getStats()/getRecentChanges() alimentent bien configure.tpl avec des données réelles (round 232)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND category IN ('cart', 'loyalty')");
    }
}
