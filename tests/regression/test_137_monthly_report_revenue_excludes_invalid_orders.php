<?php
/**
 * Régression : MonthlyReportManager::getRevenueByTemplate() doit filtrer
 * o.valid = 1 sur les DEUX requêtes (revenu direct et revenu attribué) —
 * comme partout ailleurs dans le module (ClvManager, UpsellManager) où une
 * somme de montants de commande est calculée.
 *
 * Bug réel corrigé le 08/08/2026 : les deux requêtes SQL sommaient
 * o.total_paid_tax_incl sans filtrer sur o.valid, incluant les commandes
 * annulées/remboursées (valid=0) dans le "CA attribué" affiché au marchand
 * dans le rapport mensuel — surestimation du chiffre d'affaires réel.
 *
 * Test fonctionnel réel : insère un événement 'sent' lié à une commande
 * INVALIDE (valid=0) et vérifie que son montant n'apparaît PAS dans le
 * revenu calculé pour ce template.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $template   = 'regtest_round_mrr_' . time();

    // Commande INVALIDE (valid=0, ex. annulée/remboursée) — 999,99 € qui ne
    // doit PAS remonter dans le CA du rapport.
    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES (1,1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,999.99,999.99,999.99,0,999.99,999.99,0, NOW(), NOW())");
    $idOrderInvalid = (int) $db->Insert_ID();

    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
         VALUES
            (1, '" . pSQL($template) . "', 'fr', {$idCustomer}, {$idOrderInvalid}, '" . bin2hex(random_bytes(16)) . "', 'sent', NOW())"
    );

    try {
        $mgr = new MonthlyReportManager(neria_test_module());
        $ref = new ReflectionMethod(MonthlyReportManager::class, 'getRevenueByTemplate');
        $ref->setAccessible(true);

        $dateFrom = date('Y-m-d', strtotime('-1 day'));
        $dateTo   = date('Y-m-d', strtotime('+1 day'));
        $revenue  = $ref->invoke($mgr, $dateFrom, $dateTo);

        neria_assert(
            !isset($revenue[$template]) || (float) $revenue[$template] === 0.0,
            "getRevenueByTemplate() inclut " . ($revenue[$template] ?? 0) . "€ pour une commande invalide (valid=0) — régression du bug corrigé le 08/08/2026 : le CA du rapport mensuel surestimerait de nouveau les revenus en incluant les commandes annulées/remboursées"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '" . pSQL($template) . "'");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrderInvalid}");
    }

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::getRevenueByTemplate() exclut bien les commandes invalides (valid=0) du calcul du CA",
    ];
}
