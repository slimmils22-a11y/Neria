<?php
/**
 * Régression : `SegmentManager::recomputeAll()` comptait `total_conv`
 * (`SUM(event_type = 'conversion')`) sans jamais tenir compte du statut
 * de remboursement de la commande liée. `StatsManager::
 * adjustConversionRevenueForOrder()` (round 185) ramène déjà `revenue` à
 * 0 sur cette ligne 'conversion' en cas de remboursement quasi-total,
 * mais ne supprime NI ne requalifie jamais la ligne elle-même — un client
 * dont toutes les commandes ont été remboursées à ≥90% restait donc
 * compté dans `total_conv` et pouvait être classé 'ambassador'/'loyal'
 * (ciblage VIP, campagnes de récompense) malgré un CA net réel nul.
 *
 * Bug identifié et corrigé le 04/09/2026 (round 300, audit "RFM et
 * paliers de fidélité vs remboursements").
 *
 * Corrigé le 04/09/2026 (round 300) : les conversions dont la commande
 * liée a été remboursée à ≥90% (même seuil que le clawback fidélité,
 * `OrderTriggersManager::handleRefund()`, round 178) sont désormais
 * exclues du comptage `total_conv`.
 *
 * Test comportemental réel : crée une commande réelle (copiée depuis une
 * commande existante de la base de test) avec un avoir (`order_slip`)
 * couvrant 100% de son montant, insère un événement 'conversion' lié, et
 * vérifie que `recomputeAll()` ne compte plus cette conversion pour le
 * client concerné.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();

    // Sauvegarde la ligne de segment préexistante de ce client (réelle,
    // produite par de précédents recalculs) pour la restaurer telle
    // quelle en fin de test plutôt que de la supprimer définitivement.
    $origSegRow = $db->getRow(
        "SELECT * FROM {$prefix}neria_customer_segment
         WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}"
    );

    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,300.00,300.00,300.00,300.00,300.00,300.00,1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();

    $token = 'regtest563-' . uniqid();
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, revenue, date_add)
         VALUES ({$idShop}, 'order_conf', 'fr', {$idCustomer}, {$idOrder}, '{$token}', 'conversion', 0.00, NOW())"
    );

    // Avoir couvrant 100% du montant de la commande.
    $db->execute(
        "INSERT INTO {$prefix}order_slip
            (id_customer, id_order, conversion_rate, total_products_tax_incl, total_products_tax_excl, total_shipping_tax_incl, total_shipping_tax_excl, amount, shipping_cost, shipping_cost_amount, partial, date_add, date_upd)
         VALUES ({$idCustomer}, {$idOrder}, 1, 300.00, 300.00, 0.00, 0.00, 300.00, 0, 0.00, 0, NOW(), NOW())"
    );
    $idOrderSlip = (int) $db->Insert_ID();

    try {
        $mgr = new SegmentManager(neria_test_module());
        $mgr->recomputeAll();

        $segRow = $db->getRow(
            "SELECT total_conversions FROM {$prefix}neria_customer_segment
             WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}"
        );
        neria_assert(
            $segRow !== false,
            "recomputeAll() n'a produit aucune ligne de segment pour ce client — jeu de test invalide"
        );
        neria_assert(
            (int) $segRow['total_conversions'] === 0,
            "SegmentManager::recomputeAll() compte encore total_conversions=" . $segRow['total_conversions'] . " pour une conversion dont la commande a été intégralement remboursée — régression du bug corrigé le 04/09/2026 (round 300) : un client sans aucun CA net réel pourrait de nouveau être classé 'ambassador'/'loyal' et cibler des campagnes VIP"
        );

        return [
            'pass'    => true,
            'message' => "SegmentManager::recomputeAll() exclut désormais les conversions dont la commande a été remboursée à ≥90% du comptage total_conv — bug corrigé le 04/09/2026 (round 300)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}order_slip WHERE id_order_slip = {$idOrderSlip}");
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$token}'");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");

        // Restaure la ligne de segment préexistante de ce client (données
        // réelles), ou la supprime si elle n'existait pas avant le test.
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}");
        if ($origSegRow !== false) {
            $db->execute(
                "INSERT INTO {$prefix}neria_customer_segment
                    (id_shop, id_customer, segment, total_sent, total_opens, total_clicks, total_conversions, last_open, last_conversion, computed_at)
                 VALUES (
                    {$idShop}, {$idCustomer}, '" . pSQL($origSegRow['segment']) . "',
                    " . (int) $origSegRow['total_sent'] . ", " . (int) $origSegRow['total_opens'] . ",
                    " . (int) $origSegRow['total_clicks'] . ", " . (int) $origSegRow['total_conversions'] . ",
                    " . ($origSegRow['last_open'] !== null ? "'" . pSQL($origSegRow['last_open']) . "'" : 'NULL') . ",
                    " . ($origSegRow['last_conversion'] !== null ? "'" . pSQL($origSegRow['last_conversion']) . "'" : 'NULL') . ",
                    '" . pSQL($origSegRow['computed_at']) . "'
                 )"
            );
        }
    }
}
