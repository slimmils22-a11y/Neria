<?php
/**
 * Régression round 227 (28/08/2026) : MonthlyReportManager::
 * getRevenueByTemplate() sommait o.total_paid_tax_incl SANS jamais diviser
 * par o.conversion_rate — contrairement à ClvManager (déjà corrigé,
 * commentaire lignes 116-118) qui applique ce garde-fou partout où des
 * montants de commandes sont sommés.
 *
 * Sur une boutique multi-devises, ce SUM() mélangeait donc des montants
 * dans des devises différentes comme s'ils étaient tous dans la devise par
 * défaut de la boutique : 100€ (conversion_rate=1.0) + 100$
 * (conversion_rate≈0.92, donc ≈92€ réels) étaient comptés comme 200 au
 * lieu de ≈192 — surestimant le CA par template affiché au marchand dans
 * le rapport mensuel automatique.
 *
 * Corrigé le 28/08/2026 (round 227) : order_total divisé par
 * IF(conversion_rate = 0, 1, conversion_rate) dans les deux requêtes
 * (revenus "directs" et "attribués").
 *
 * Test comportemental réel : insère une commande avec conversion_rate=0.5
 * (simule une devise valant deux fois la devise par défaut) et un
 * événement 'sent' lié, puis vérifie via Reflection que
 * getRevenueByTemplate() retourne le montant CONVERTI (divisé par 0.5,
 * donc doublé), pas le montant brut.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idCustomer = neria_test_any_customer_id();
    $idShop = (int) Context::getContext()->shop->id;
    $template = 'regtest468_template';

    // conversion_rate = 0.5 : un montant de 50 dans la devise de la
    // commande vaut donc 100 dans la devise par défaut de la boutique
    // (total_paid_tax_incl / conversion_rate = 50 / 0.5 = 100).
    $db->execute(
        "INSERT INTO {$prefix}orders (id_shop, id_shop_group, id_customer, id_currency, id_lang, id_address_delivery, id_address_invoice, id_carrier, current_state, payment, conversion_rate, total_paid, total_paid_tax_incl, total_products, total_products_wt, valid, date_add, date_upd, invoice_number, invoice_date, delivery_number, delivery_date, secure_key)
         VALUES ({$idShop}, 1, {$idCustomer}, 1, 1, 0, 0, 1, 1, 'regtest468', 0.5, 50, 50, 50, 50, 1, NOW(), NOW(), 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 'regtest468')"
    );
    $idOrder = (int) $db->Insert_ID();

    $db->execute(
        "INSERT INTO {$prefix}neria_stat (id_shop, id_customer, id_order, template, event_type, date_add)
         VALUES ({$idShop}, {$idCustomer}, {$idOrder}, '" . pSQL($template) . "', 'sent', NOW())"
    );

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';
        $mgr = new MonthlyReportManager($module);
        $ref = new ReflectionMethod($mgr, 'getRevenueByTemplate');
        $ref->setAccessible(true);
        $result = $ref->invoke(
            $mgr,
            date('Y-m-d', strtotime('-1 day')),
            date('Y-m-d', strtotime('+1 day'))
        );

        neria_assert(
            isset($result[$template]),
            "getRevenueByTemplate() ne retourne aucune ligne pour le template de test — jeu de test invalide"
        );

        $revenue = (float) $result[$template];
        neria_assert(
            abs($revenue - 100.0) < 0.01,
            "getRevenueByTemplate() retourne {$revenue} au lieu de 100.0 (50 / conversion_rate 0.5) pour une commande à conversion_rate=0.5 — "
            . "régression du bug corrigé le 28/08/2026 (round 227) : le CA par template mélangerait de nouveau des montants de devises différentes sans conversion"
        );

        return [
            'pass'    => true,
            'message' => "getRevenueByTemplate() convertit bien total_paid_tax_incl via conversion_rate avant de sommer (50 / 0.5 = 100.0), comme ClvManager",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_order = {$idOrder} AND template = '" . pSQL($template) . "'");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }
}
