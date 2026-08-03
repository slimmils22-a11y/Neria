<?php
/** Régression : QueueManager::getStats() ne doit pas compter un client 2x sur 2 créneaux horaires. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $rows = [['09:00:00'], ['09:05:00'], ['15:00:00'], ['15:10:00']];
    $ids = [];
    foreach ($rows as $r) {
        $db->execute("INSERT INTO {$prefix}orders
            (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
            VALUES (1,1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,10,10,10,10,10,10,1, CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY),' {$r[0]}'), NOW())");
        $ids[] = (int) $db->Insert_ID();
    }

    try {
        $buggy = (int) $db->getValue(
            "SELECT COUNT(*) FROM (
               SELECT id_customer FROM {$prefix}orders
               WHERE valid = 1 AND id_customer = {$idCustomer}
               GROUP BY id_customer, HOUR(date_add)
               HAVING COUNT(*) >= 2
             ) sub"
        );
        $fixed = (int) $db->getValue(
            "SELECT COUNT(DISTINCT id_customer) FROM (
               SELECT id_customer FROM {$prefix}orders
               WHERE valid = 1 AND id_customer = {$idCustomer}
               GROUP BY id_customer, HOUR(date_add)
               HAVING COUNT(*) >= 2
             ) sub"
        );
        neria_assert($buggy === 2 && $fixed === 1, "comptage inattendu (buggy={$buggy}, fixed={$fixed}) — le jeu de test ne reproduit plus le scénario");

        // Depuis le 03/08/2026, QueueManager::getStats() ne duplique plus cette
        // requête en interne : il délègue à PurchaseWindowManager::getWindowCoverageCount(),
        // qui porte désormais la protection COUNT(DISTINCT id_customer) (et le
        // regroupement par créneau de 2h, corrigé séparément). On vérifie donc
        // les deux : que QueueManager appelle bien ce manager, et que la
        // protection anti-double-comptage existe toujours dans son implémentation.
        $queueSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
        neria_assert(
            str_contains($queueSrc, 'PurchaseWindowManager()') && str_contains($queueSrc, 'getWindowCoverageCount('),
            "QueueManager::getStats() ne délègue plus à PurchaseWindowManager::getWindowCoverageCount() — vérifier que le calcul de couverture n'a pas été réintroduit en double"
        );

        $pwmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PurchaseWindowManager.php');
        neria_assert(
            str_contains($pwmSrc, 'SELECT COUNT(DISTINCT id_customer) FROM ('),
            "PurchaseWindowManager::getWindowCoverageCount() n'utilise plus COUNT(DISTINCT id_customer) — régression du bug corrigé le 17/07/2026"
        );

        return ['pass' => true, 'message' => 'QueueManager::getStats() toujours protégé contre le double comptage'];
    } finally {
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order IN (" . implode(',', $ids) . ")");
    }
}
