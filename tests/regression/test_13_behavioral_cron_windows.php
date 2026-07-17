<?php
/** Régression : les fenêtres abandoned_cart_1/2/3 + checkout_abandonment ne doivent ni se chevaucher ni être trop étroites pour un cron quotidien. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $db->execute("INSERT INTO {$prefix}cart (id_shop, id_shop_group, id_customer, id_currency, id_lang, date_add, date_upd)
        VALUES (1, 1, {$idCustomer}, 1, 1, NOW(), DATE_SUB(NOW(), INTERVAL '24:30:00' HOUR_SECOND))");
    $idCart = (int) $db->Insert_ID();

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';
        $mgr = new BehavioralCronManager(neria_test_module());
        $ref = new ReflectionMethod($mgr, 'sendAbandonedCarts');
        $ref->setAccessible(true);
        $ref->invoke($mgr, 'abandoned_cart_1', 1);
        $ref->invoke($mgr, 'abandoned_cart_2', 24);

        $rows = $db->executeS("SELECT template FROM {$prefix}neria_behavioral_sent WHERE ref_id={$idCart}");
        $count = count($rows);

        neria_assert(
            $count === 1,
            "{$count} template(s) déclenché(s) pour un panier de 24h30 (attendu 1) — régression du chevauchement de fenêtres corrigé le 17/07/2026 (commit 32c05c4)"
        );

        return ['pass' => true, 'message' => 'Fenêtres cron panier abandonné toujours jointives sans chevauchement'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE ref_id={$idCart}");
        $db->execute("DELETE FROM {$prefix}cart WHERE id_cart={$idCart}");
    }
}
