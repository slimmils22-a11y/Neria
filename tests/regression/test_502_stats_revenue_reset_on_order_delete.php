<?php
/**
 * Régression : aucun hook Neria n'écoutait la suppression physique d'une
 * commande PrestaShop (BO > Commandes > Supprimer, différent d'une simple
 * annulation/remboursement qui garde la ligne en base). Le revenu attribué
 * à cette commande dans `neria_stat` (event_type='conversion', figé au
 * moment de la conversion par `StatsManager::recordConversion()`) restait
 * donc indéfiniment inchangé — contrairement au remboursement réel, déjà
 * correctement pris en charge par `OrderTriggersManager::handleRefund()`
 * (hook `actionOrderSlipAdd`, round 185).
 *
 * Bug identifié le 31/08/2026 (round 261, audit "suppression de commande
 * invalidant données CLV/attribution/fidélité") : les KPIs de revenu/ROI
 * par campagne (dashboard, tendances, ABTest — tous des `SUM(revenue)` sur
 * `neria_stat` seule, sans JOIN vers `ps_orders`) restaient surestimés
 * indéfiniment du montant de toute commande supprimée.
 *
 * Corrigé le 31/08/2026 (round 261) : nouveau hook
 * `actionObjectOrderDeleteAfter` (enregistré via upgrade-1.0.44.php sur les
 * installs existantes), qui appelle
 * `StatsManager::adjustConversionRevenueForOrder($idOrder, 0.0)` — même
 * mécanisme déjà utilisé pour le remboursement, réutilisé tel quel.
 *
 * Test comportemental réel : insère une vraie ligne 'conversion' avec un
 * revenu non nul pour une commande de test, déclenche le VRAI hook via
 * l'instance du module (avec un objet Order fabriqué portant cet id), et
 * vérifie que le revenu est bien retombé à 0 en base.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $module = neria_test_module();

    $idOrder = 888000 + random_int(1, 8999); // id_order fictif, garanti absent de ps_orders
    $token   = 'regtest502-' . uniqid();

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, id_customer, template, lang, event_type, tracking_token, id_order, revenue, date_add)
         VALUES ({$idShop}, 0, 'order_conf', 'fr', 'conversion', '" . pSQL($token) . "', {$idOrder}, 149.90, NOW())"
    );

    try {
        $revenueBefore = $db->getValue(
            "SELECT revenue FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "' AND event_type = 'conversion'"
        );
        neria_assert($revenueBefore !== false, "jeu de test invalide : la ligne de conversion de test n'a pas été insérée");
        neria_assert((float) $revenueBefore === 149.90, "jeu de test invalide : revenu initial incorrect (" . var_export($revenueBefore, true) . ")");

        neria_assert(
            method_exists($module, 'hookActionObjectOrderDeleteAfter'),
            "neria.php n'expose plus hookActionObjectOrderDeleteAfter() — régression du bug corrigé le 31/08/2026 (round 261) : aucun mécanisme n'écouterait plus la suppression physique d'une commande"
        );

        $order = new Order();
        $order->id = $idOrder;
        $module->hookActionObjectOrderDeleteAfter(['object' => $order]);

        $revenueAfter = $db->getValue(
            "SELECT revenue FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "' AND event_type = 'conversion'"
        );
        neria_assert(
            $revenueAfter !== false && (float) $revenueAfter === 0.0,
            "Le hook actionObjectOrderDeleteAfter n'a pas remis à 0 le revenu 'conversion' de la commande supprimée (revenu obtenu = " . var_export($revenueAfter, true) . ") — régression du bug corrigé le 31/08/2026 (round 261) : les KPIs de revenu/ROI par campagne resteraient surestimés indéfiniment après une suppression de commande"
        );

        // Vérification structurelle complémentaire : le hook est bien
        // déclaré dans la liste HOOKS (donc réellement enregistré à
        // l'installation/upgrade), pas seulement défini comme méthode.
        $neriaSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
        neria_assert(
            $neriaSrc !== false && strpos($neriaSrc, "'actionObjectOrderDeleteAfter',") !== false,
            "neria.php::HOOKS ne déclare plus 'actionObjectOrderDeleteAfter' — régression du bug corrigé le 31/08/2026 (round 261) : le hook ne serait plus jamais enregistré, même via une réinstallation"
        );

        return [
            'pass'    => true,
            'message' => "neria.php écoute désormais actionObjectOrderDeleteAfter et remet à 0 le revenu 'conversion' attribué à une commande supprimée, via StatsManager::adjustConversionRevenueForOrder() — bug corrigé le 31/08/2026 (round 261)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");
    }
}
