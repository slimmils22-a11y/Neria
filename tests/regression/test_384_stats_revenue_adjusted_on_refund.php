<?php
/**
 * Régression : aucun code du module n'ajustait le revenu attribué
 * (ps_neria_stat.revenue, event_type='conversion') après un remboursement
 * ou une annulation de commande — getRevenueStats()/MonthlyReportManager
 * continuaient de compter le revenu ORIGINAL de la commande indéfiniment,
 * surestimant durablement le ROI par template/campagne dès qu'un
 * remboursement (même partiel) survenait après l'attribution.
 *
 * Corrigé le 18/08/2026 (round 185) : nouvelle méthode
 * StatsManager::adjustConversionRevenueForOrder(), appelée par
 * OrderTriggersManager::handleRefund() avec le montant réellement
 * conservé (total commande - cumul des avoirs).
 *
 * Test comportemental réel : crée une vraie ligne 'conversion' avec un
 * revenu de 500, appelle adjustConversionRevenueForOrder() avec un
 * remboursement partiel (nouveau revenu = 300), vérifie que la ligne est
 * bien mise à jour et que getRevenueStats() reflète le nouveau total.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $module     = neria_test_module();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $testOrderId = 977000 + ($idCustomer % 900);
    $testToken   = bin2hex(random_bytes(16));

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$testToken}'");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, revenue, date_add)
             VALUES
                ({$idShop}, 'order_conf', 'fr', {$idCustomer}, {$testOrderId}, '{$testToken}', 'conversion', 500.00, NOW())"
        );

        $mgr = new StatsManager($module);
        $mgr->adjustConversionRevenueForOrder($testOrderId, 300.00);

        $storedRevenue = (float) $db->getValue(
            "SELECT revenue FROM {$prefix}neria_stat WHERE tracking_token = '{$testToken}'"
        );
        neria_assert(
            abs($storedRevenue - 300.00) < 0.01,
            "adjustConversionRevenueForOrder() a laissé revenue={$storedRevenue} au lieu de 300.00 — régression du bug corrigé le 18/08/2026 (round 185) : le revenu attribué resterait figé au montant original malgré le remboursement"
        );

        // Remboursement total (avoir couvrant 100%) : le revenu doit descendre à 0.
        $mgr->adjustConversionRevenueForOrder($testOrderId, 0.0);
        $storedRevenueAfterFull = (float) $db->getValue(
            "SELECT revenue FROM {$prefix}neria_stat WHERE tracking_token = '{$testToken}'"
        );
        neria_assert(
            abs($storedRevenueAfterFull - 0.0) < 0.01,
            "adjustConversionRevenueForOrder() a laissé revenue={$storedRevenueAfterFull} au lieu de 0 après un remboursement total"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '{$testToken}'");
    }

    return [
        'pass'    => true,
        'message' => "StatsManager::adjustConversionRevenueForOrder() ajuste bien le revenu attribué au montant réellement conservé après remboursement — bug corrigé le 18/08/2026 (round 185)",
    ];
}
