<?php
/**
 * Régression : `MonthlyReportManager::getRevenueByTemplate()` (revenu
 * direct ET revenu attribué) sommait `o.total_paid_tax_incl` sans jamais
 * déduire les avoirs partiels (`order_slip`) — contrairement à
 * `ClvManager`/`StatsManager::getRevenueStats()`, qui déduisent déjà ces
 * mêmes avoirs (rounds 185/227) pour les mêmes commandes.
 *
 * `total_paid_tax_incl` n'est JAMAIS modifié par la création d'un avoir
 * partiel — la commande reste `valid = 1` (seul un remboursement TOTAL
 * fait basculer la commande à un état non-loggable, déjà exclu par le
 * filtre `o.valid = 1` existant, round 130). Le rapport mensuel
 * automatique (envoyé par email au marchand) surestimait donc durablement
 * le CA attribué à un template dès qu'il y avait des retours partiels —
 * cas très fréquent en e-commerce — divergeant silencieusement du
 * dashboard temps réel (`StatsManager::getRevenueStats()`), qui lui est
 * correct.
 *
 * Bug identifié le 04/09/2026 (round 297, audit "remboursements dans
 * l'attribution de revenu").
 *
 * Corrigé le 04/09/2026 (round 297) : les deux requêtes déduisent
 * désormais la somme des avoirs (`order_slip.total_products_tax_incl +
 * total_shipping_tax_incl`, convertie par son propre `conversion_rate`,
 * plafonnée à 0 via `GREATEST(0, ...)`) — même schéma exact que
 * `ClvManager::computeClv()`.
 *
 * Test fonctionnel réel : insère une commande valide de 500€ liée à un
 * envoi 'sent' d'un template, puis un avoir partiel de 300€ sur cette
 * même commande — vérifie que le CA calculé pour ce template est bien
 * 200€ (net), pas 500€ (brut).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $template   = 'regtest_round297_mrr_' . time();

    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES (1,1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,500.00,500.00,500.00,500.00,500.00,500.00,1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();

    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
         VALUES
            (1, '" . pSQL($template) . "', 'fr', {$idCustomer}, {$idOrder}, '" . bin2hex(random_bytes(16)) . "', 'sent', NOW())"
    );

    // Avoir partiel de 300€ sur cette commande (le client garde 200€
    // d'achat réel) — conversion_rate=1 pour rester simple (même devise).
    $db->execute(
        "INSERT INTO {$prefix}order_slip
            (id_customer, id_order, conversion_rate, total_products_tax_incl, total_products_tax_excl, total_shipping_tax_incl, total_shipping_tax_excl, amount, shipping_cost, shipping_cost_amount, partial, date_add, date_upd)
         VALUES
            ({$idCustomer}, {$idOrder}, 1, 300.00, 300.00, 0.00, 0.00, 300.00, 0, 0.00, 1, NOW(), NOW())"
    );
    $idOrderSlip = (int) $db->Insert_ID();

    try {
        $mgr = new MonthlyReportManager(neria_test_module());
        $ref = new ReflectionMethod(MonthlyReportManager::class, 'getRevenueByTemplate');
        $ref->setAccessible(true);

        $dateFrom = date('Y-m-d', strtotime('-1 day'));
        $dateTo   = date('Y-m-d', strtotime('+1 day'));
        $revenue  = $ref->invoke($mgr, $dateFrom, $dateTo);

        $got = (float) ($revenue[$template] ?? -1.0);
        neria_assert(
            abs($got - 200.0) < 0.01,
            "getRevenueByTemplate() renvoie {$got}€ pour une commande de 500€ avec un avoir partiel de 300€ (attendu 200€ net) — régression du bug corrigé le 04/09/2026 (round 297) : le CA du rapport mensuel surestimerait de nouveau les revenus en ignorant les avoirs partiels (order_slip)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}order_slip WHERE id_order_slip = {$idOrderSlip}");
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '" . pSQL($template) . "'");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::getRevenueByTemplate() déduit désormais les avoirs partiels (order_slip) du CA attribué, cohérent avec ClvManager/StatsManager::getRevenueStats() — bug corrigé le 04/09/2026 (round 297)",
    ];
}
