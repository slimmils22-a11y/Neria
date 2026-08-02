<?php
/**
 * Régression : milestone_order, order_partial_shipped, order_on_hold,
 * refund_processed, return_received ne doivent laisser AUCUNE variable non
 * résolue avec le jeu de variables réel construit par OrderTriggersManager
 * (checkMilestone(), handleStatusChange(), handleRefund(), handleReturn(),
 * buildShippedItemsVars() — src/OrderTriggersManager.php, lu le 02/08/2026).
 * Même principe que test_23.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $row = $db->getRow("SELECT email, firstname, lastname, id_lang FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    $idLang = (int) ($row['id_lang'] ?: Configuration::get('PS_LANG_DEFAULT'));
    $idShop = (int) Context::getContext()->shop->id;

    $startedAt = date('Y-m-d H:i:s');

    $common = [
        '{firstname}' => $row['firstname'],
        '{lastname}'  => $row['lastname'],
        '{shop_name}' => (string) Configuration::get('PS_SHOP_NAME'),
    ];

    $templates = [
        // checkMilestone()
        'milestone_order' => array_merge($common, [
            '{milestone_count}'             => '10e',
            '{order_count}'                 => '10',
            '{voucher_code}'                => 'NERIA-MILE-TEST10',
            '{milestone_voucher_block}'     => '<p>Bon de réduction : NERIA-MILE-TEST10</p>',
            '{milestone_voucher_block_txt}' => 'Bon de réduction : NERIA-MILE-TEST10',
        ]),
        // handleStatusChange() — order_partial_shipped
        'order_partial_shipped' => array_merge($common, [
            '{order_name}'        => 'NR-TEST999',
            '{shipped_items}'     => '<p>× 1 Produit test</p><p>Colis — Colissimo TRACK123</p>',
            '{shipped_items_txt}' => "× 1 Produit test\nColis — Colissimo TRACK123",
        ]),
        // handleStatusChange() — order_on_hold
        'order_on_hold' => array_merge($common, [
            '{order_name}'  => 'NR-TEST999',
            '{hold_reason}' => 'En attente de vérification',
        ]),
        // handleRefund()
        'refund_processed' => array_merge($common, [
            '{order_name}'    => 'NR-TEST999',
            '{refund_amount}' => '50,00 €',
        ]),
        // handleReturn()
        'return_received' => array_merge($common, [
            '{order_name}'        => 'NR-TEST999',
            '{meta_products}'     => '× 1 Produit test',
            '{meta_products_txt}' => '× 1 Produit test',
        ]),
    ];

    $failures = [];

    foreach ($templates as $template => $vars) {
        Mail::Send(
            $idLang,
            $template,
            'Test régression — ' . $template,
            $vars,
            (string) $row['email'],
            trim($row['firstname'] . ' ' . $row['lastname']),
            null, null, null, null,
            _PS_MODULE_DIR_ . 'neria/mails/',
            false,
            $idShop
        );

        $residual = $db->getRow(
            "SELECT message FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND template = '" . pSQL($template) . "'
               AND message LIKE '%residual_vars_stripped%'
               AND date_add >= '{$startedAt}'
             ORDER BY id_log DESC"
        );

        if ($residual) {
            $failures[] = "{$template}: " . $residual['message'];
        }
    }

    try {
        neria_assert(
            empty($failures),
            "Variable(s) non résolue(s) détectée(s) lors d'un envoi réaliste : " . implode(' | ', $failures)
        );

        return ['pass' => true, 'message' => 'milestone_order/order_partial_shipped/order_on_hold/refund_processed/return_received ne laissent aucune variable résiduelle avec un jeu de variables réaliste'];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND date_add >= '{$startedAt}'
               AND template IN ('milestone_order','order_partial_shipped','order_on_hold','refund_processed','return_received')"
        );
    }
}
