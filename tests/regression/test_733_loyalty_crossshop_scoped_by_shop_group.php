<?php
/**
 * Régression : en mode cumul transversal (NERIA_LOYALTY_CROSS_SHOP_ENABLED),
 * LoyaltyManager doit agréger les points/bons de fidélité PAR GROUPE de
 * boutiques, pas sur TOUTE l'installation.
 *
 * Bug identifié le 13/09/2026 (round 350, décision produit confirmée le
 * même jour — le module est destiné à être vendu à de multiples
 * commerçants via PrestaShop Addons) : getCustomerPoints()/checkAndReward()/
 * getCustomerStats()/getGlobalStats() résolvaient $idShop = null en mode
 * cumul transversal, agrégeant sur TOUTES les boutiques de l'installation
 * sans filtre id_shop_group. Sur une installation multi-enseignes (groupes
 * de boutiques distincts, cas standard PrestaShop multishop), un client
 * partagé entre deux groupes (customer sharing) voyait ses points de deux
 * marques indépendantes fusionnés dans un même total/palier.
 *
 * Corrigé le 13/09/2026 (round 351) : le cumul transversal reste inchangé
 * DANS un groupe (comportement historique voulu), mais ne déborde plus sur
 * les autres groupes de la même installation — via shopIdsInSameGroup()/
 * groupAnchorShopId().
 *
 * Test comportemental réel : crée 2 groupes de boutiques fictifs (groupe A
 * avec 2 boutiques, groupe B avec 1 boutique), attribue des points au même
 * client fictif dans les 3 boutiques, et vérifie que getCustomerPoints()
 * scopé sur le groupe A ne remonte QUE les points des boutiques du groupe
 * A (somme des 2), jamais ceux du groupe B.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $groupA = 900351;
    $groupB = 900352;
    $shopA1 = 900361;
    $shopA2 = 900362;
    $shopB1 = 900363;

    try {
        $db->execute(
            "INSERT INTO `{$prefix}shop_group`
                (id_shop_group, name, color, share_customer, share_order, share_stock, active, deleted)
             VALUES
                ({$groupA}, 'Round351 Groupe A', '#000000', 0, 0, 0, 1, 0),
                ({$groupB}, 'Round351 Groupe B', '#000000', 0, 0, 0, 1, 0)"
        );
        $db->execute(
            "INSERT INTO `{$prefix}shop`
                (id_shop, id_shop_group, name, color, id_category, theme_name, active, deleted)
             VALUES
                ({$shopA1}, {$groupA}, 'Round351 A1', '#000000', 1, 'classic', 1, 0),
                ({$shopA2}, {$groupA}, 'Round351 A2', '#000000', 1, 'classic', 1, 0),
                ({$shopB1}, {$groupB}, 'Round351 B1', '#000000', 1, 'classic', 1, 0)"
        );

        // Shop::getShops() se base sur un cache statique construit une
        // seule fois par process (Shop::cacheShops()) — sans le forcer à se
        // rafraîchir, les boutiques fictives insérées ci-dessus resteraient
        // invisibles à shopIdsInSameGroup() dans ce même process CLI.
        Shop::cacheShops(true);

        require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';
        $mgr = new LoyaltyManager(neria_test_module());

        $shopIdsGroupA = null;
        $anchorA = null;
        $resolveGroups = new ReflectionMethod(LoyaltyManager::class, 'shopIdsInSameGroup');
        $resolveGroups->setAccessible(true);
        $resolveAnchor = new ReflectionMethod(LoyaltyManager::class, 'groupAnchorShopId');
        $resolveAnchor->setAccessible(true);

        $shopIdsGroupA = $resolveGroups->invoke($mgr, $shopA1);
        sort($shopIdsGroupA);
        neria_assert(
            $shopIdsGroupA === [$shopA1, $shopA2],
            "shopIdsInSameGroup({$shopA1}) ne retourne plus exactement [{$shopA1}, {$shopA2}] — obtenu " . json_encode($shopIdsGroupA) . " — régression du bug corrigé le 13/09/2026 (round 351)"
        );

        $anchorA = $resolveAnchor->invoke($mgr, $shopIdsGroupA);
        neria_assert(
            $anchorA === $shopA1,
            "groupAnchorShopId() ne retourne plus le plus petit id_shop du groupe A ({$shopA1}) — obtenu {$anchorA}"
        );

        // Points fictifs dans les 3 boutiques pour le même client.
        $db->execute("INSERT INTO `{$prefix}neria_loyalty_points` (id_customer, id_stat, event_type, points, id_shop, date_add) VALUES ({$idCustomer}, 900351001, 'conversion', 10, {$shopA1}, NOW())");
        $db->execute("INSERT INTO `{$prefix}neria_loyalty_points` (id_customer, id_stat, event_type, points, id_shop, date_add) VALUES ({$idCustomer}, 900351002, 'conversion', 10, {$shopA2}, NOW())");
        $db->execute("INSERT INTO `{$prefix}neria_loyalty_points` (id_customer, id_stat, event_type, points, id_shop, date_add) VALUES ({$idCustomer}, 900351003, 'conversion', 10, {$shopB1}, NOW())");

        $totalGroupA = $mgr->getCustomerPoints($idCustomer, null, $shopIdsGroupA);
        neria_assert(
            $totalGroupA === 20,
            "getCustomerPoints() scopé sur le groupe A ne remonte plus 20 (10+10 des 2 boutiques du groupe A, PAS les 10 du groupe B) — obtenu {$totalGroupA} — régression du bug corrigé le 13/09/2026 (round 351) : les points de deux enseignes indépendantes seraient de nouveau fusionnés"
        );

        $totalWithoutGroupScope = $mgr->getCustomerPoints($idCustomer, $shopA1);
        neria_assert(
            $totalWithoutGroupScope === 10,
            "getCustomerPoints() sans scope de groupe (une seule boutique) doit rester inchangé (10) — obtenu {$totalWithoutGroupScope}, jeu de test invalide sinon"
        );

        // Vérification structurelle complémentaire (checkAndReward()/
        // generateVoucher() créent un vrai CartRule PrestaShop en cas de
        // palier franchi — non déclenché ici pour ne pas polluer la base de
        // bons réels) : la restriction du CartRule à une seule boutique ne
        // doit plus être déduite de $reservationShopId > 0 (toujours vrai
        // désormais avec l'ancre de groupe), et l'ancre doit bien remplacer
        // l'ancienne sentinelle 0 dans checkAndReward().
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
        neria_assert($src !== false, 'Impossible de lire src/LoyaltyManager.php');
        neria_assert(
            strpos($src, '$reservationShopId = $crossShop ? $this->groupAnchorShopId($shopIdsGroup) : $idShop;') !== false,
            "checkAndReward() n'utilise plus l'ancre de groupe pour \$reservationShopId — régression du bug corrigé le 13/09/2026 (round 351) : la sentinelle globale 0 (portée installation entière) pourrait être de retour"
        );
        neria_assert(
            strpos($src, 'if ($restrictToSingleShop) {') !== false,
            "generateVoucher() ne décide plus la restriction CartRule via \$restrictToSingleShop — régression du bug corrigé le 13/09/2026 (round 351) : un bon en cumul transversal deviendrait à tort restreint à la seule boutique ancre, ou au contraire utilisable sur toute l'installation"
        );

        return [
            'pass'    => true,
            'message' => "LoyaltyManager scope bien le cumul transversal au GROUPE de boutiques (20 pts, 2 boutiques du groupe A) sans jamais inclure une boutique d'un AUTRE groupe (10 pts du groupe B exclus), et la logique de réservation/restriction CartRule utilise bien l'ancre de groupe — bug corrigé le 13/09/2026 (round 351)",
        ];
    } finally {
        $db->execute("DELETE FROM `{$prefix}neria_loyalty_points` WHERE id_stat IN (900351001, 900351002, 900351003)");
        $db->execute("DELETE FROM `{$prefix}shop` WHERE id_shop IN ({$shopA1}, {$shopA2}, {$shopB1})");
        $db->execute("DELETE FROM `{$prefix}shop_group` WHERE id_shop_group IN ({$groupA}, {$groupB})");
        Shop::cacheShops(true);
    }
}
