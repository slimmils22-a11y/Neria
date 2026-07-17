<?php
/** Régression : OrderTriggersManager::handleNewOrder() ne doit compter que les commandes de la boutique courante. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES (99999,1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,10,10,10,10,10,10,1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();

    try {
        $unscoped = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}orders WHERE id_customer={$idCustomer} AND valid=1");
        $scoped   = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}orders WHERE id_customer={$idCustomer} AND id_shop=1 AND valid=1");

        neria_assert($unscoped > $scoped, "le jeu de test n'isole pas correctement (unscoped={$unscoped}, scoped={$scoped})");

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
        neria_assert(
            (bool) preg_match('/WHERE `id_customer` = \' \. \$idCustomer \. \' AND `id_shop` = \' \. \$idShop/', $src),
            "handleNewOrder() ne filtre plus id_shop sur le comptage milestone — régression du bug corrigé le 17/07/2026"
        );

        return ['pass' => true, 'message' => 'handleNewOrder() toujours scopé id_shop sur le comptage milestone'];
    } finally {
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }
}
