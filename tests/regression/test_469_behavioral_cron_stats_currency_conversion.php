<?php
/**
 * Régression round 228 (28/08/2026) : BehavioralCronManager::
 * getCheckoutAbandonmentStats() et ::getRelationshipAnniversaryStats()
 * sommaient o.total_paid_tax_incl SANS jamais diviser par
 * o.conversion_rate — même famille de bug que round 227
 * (MonthlyReportManager::getRevenueByTemplate()), non propagée ici,
 * trouvée par un balayage exhaustif de tout src/*.php.
 *
 * Sur une boutique multi-devises, les KPI "revenue_recovered" (widget
 * checkout abandonment) et "revenue_attributed" (widget anniversaire de
 * relation) mélangeaient des montants de devises différentes comme s'ils
 * étaient tous dans la devise par défaut — surestimant/sous-estimant le
 * ROI affiché au marchand sans aucune alerte.
 *
 * Corrigé le 28/08/2026 (round 228) : les deux SUM() divisent désormais
 * par IF(conversion_rate = 0, 1, conversion_rate), même garde-fou que
 * ClvManager/MonthlyReportManager.
 *
 * Test comportemental réel : insère une commande avec conversion_rate=0.5
 * pour chacun des deux scénarios (panier abandonné récupéré, anniversaire
 * de relation attribué) et vérifie que le revenu retourné est bien
 * CONVERTI (doublé), pas le montant brut.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idCustomer = neria_test_any_customer_id();
    $idShop = (int) Context::getContext()->shop->id;

    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';
    $mgr = new BehavioralCronManager($module);

    // ── Scénario 1 : checkout_abandonment récupéré ──────────────────
    $db->execute(
        "INSERT INTO {$prefix}cart (id_shop, id_shop_group, id_customer, id_currency, id_lang, date_add, date_upd)
         VALUES ({$idShop}, 1, {$idCustomer}, 1, 1, NOW(), NOW())"
    );
    $idCart = (int) $db->Insert_ID();

    $db->execute(
        "INSERT INTO {$prefix}orders (id_shop, id_shop_group, id_customer, id_cart, id_currency, id_lang, id_address_delivery, id_address_invoice, id_carrier, current_state, payment, conversion_rate, total_paid, total_paid_tax_incl, total_products, total_products_wt, valid, date_add, date_upd, invoice_number, invoice_date, delivery_number, delivery_date, secure_key)
         VALUES ({$idShop}, 1, {$idCustomer}, {$idCart}, 1, 1, 0, 0, 1, 1, 'regtest469', 0.5, 50, 50, 50, 50, 1, NOW(), NOW(), 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 'regtest469a')"
    );
    $idOrder1 = (int) $db->Insert_ID();

    $db->execute(
        "INSERT INTO {$prefix}neria_behavioral_sent (id_shop, id_customer, template, ref_id, sent_at)
         VALUES ({$idShop}, {$idCustomer}, 'checkout_abandonment', {$idCart}, DATE_SUB(NOW(), INTERVAL 1 HOUR))"
    );

    // ── Scénario 2 : relationship_anniversary attribué ──────────────
    $db->execute(
        "INSERT INTO {$prefix}orders (id_shop, id_shop_group, id_customer, id_currency, id_lang, id_address_delivery, id_address_invoice, id_carrier, current_state, payment, conversion_rate, total_paid, total_paid_tax_incl, total_products, total_products_wt, valid, date_add, date_upd, invoice_number, invoice_date, delivery_number, delivery_date, secure_key)
         VALUES ({$idShop}, 1, {$idCustomer}, 1, 1, 0, 0, 1, 1, 'regtest469', 0.5, 50, 50, 50, 50, 1, NOW(), NOW(), 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 'regtest469b')"
    );
    $idOrder2 = (int) $db->Insert_ID();

    $db->execute(
        "INSERT INTO {$prefix}neria_behavioral_sent (id_shop, id_customer, template, ref_id, sent_at)
         VALUES ({$idShop}, {$idCustomer}, 'relationship_anniversary', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR))"
    );

    try {
        $stats1 = $mgr->getCheckoutAbandonmentStats();
        neria_assert(
            abs((float) $stats1['revenue_recovered'] - 100.0) < 0.01,
            "getCheckoutAbandonmentStats() retourne revenue_recovered={$stats1['revenue_recovered']} au lieu de 100.0 (50 / conversion_rate 0.5) — "
            . "régression du bug corrigé le 28/08/2026 (round 228)"
        );

        // La commande du scénario 1 tombe aussi dans la fenêtre 48h
        // d'attribution du scénario 2 (jointure par id_customer, pas
        // id_order) — supprimée avant de mesurer le scénario 2 pour
        // isoler chaque mesure.
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder1}");

        $stats2 = $mgr->getRelationshipAnniversaryStats();
        neria_assert(
            abs((float) $stats2['revenue_attributed'] - 100.0) < 0.01,
            "getRelationshipAnniversaryStats() retourne revenue_attributed={$stats2['revenue_attributed']} au lieu de 100.0 (50 / conversion_rate 0.5) — "
            . "régression du bug corrigé le 28/08/2026 (round 228)"
        );

        return [
            'pass'    => true,
            'message' => "getCheckoutAbandonmentStats()/getRelationshipAnniversaryStats() convertissent bien via conversion_rate avant de sommer (50 / 0.5 = 100.0)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template IN ('checkout_abandonment', 'relationship_anniversary') AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order IN ({$idOrder1}, {$idOrder2})");
        $db->execute("DELETE FROM {$prefix}cart WHERE id_cart = {$idCart}");
    }
}
