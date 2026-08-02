<?php
/**
 * Régression : post_purchase_care, post_purchase_review, order_shipped_delay,
 * ghost_cart, relationship_anniversary ne doivent laisser AUCUNE variable
 * non résolue avec le jeu de variables réel fourni par BehavioralCronManager
 * (sendPostPurchase(), sendShippedDelayAlerts(), sendGhostCarts(),
 * sendRelationshipAnniversaries() — src/BehavioralCronManager.php, lu le
 * 02/08/2026). Même principe que test_23/24/25.
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
        '{firstname}'   => $row['firstname'],
        '{lastname}'    => $row['lastname'],
        '{shop_name}'   => (string) Configuration::get('PS_SHOP_NAME'),
        '{history_url}' => 'https://example.test/historique',
    ];

    $templates = [
        // sendPostPurchase('post_purchase_care', ...) — pas de bloc upsell
        'post_purchase_care' => array_merge($common, [
            '{review_url}' => 'https://example.test/',
        ]),
        // sendPostPurchase('post_purchase_review', ...) — avec bloc upsell (vide par défaut)
        'post_purchase_review' => array_merge($common, [
            '{review_url}'        => 'https://example.test/',
            '{upsell_block}'      => '',
            '{upsell_block_txt}'  => '',
        ]),
        // sendShippedDelayAlerts()
        'order_shipped_delay' => array_merge($common, [
            '{order_name}'        => 'NR-TEST999',
            '{new_shipping_date}' => '10 août 2026',
        ]),
        // sendGhostCarts()
        'ghost_cart' => array_merge($common, [
            '{product_name}'  => 'Foulard Provence',
            '{product_url}'   => 'https://example.test/foulard',
            '{product_image}' => 'https://example.test/foulard.jpg',
            '{product_price}' => '65,00 €',
            '{times_added}'   => '3',
        ]),
        // sendRelationshipAnniversaries()
        'relationship_anniversary' => array_merge($common, [
            '{years_label}' => '3 ans',
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

        return ['pass' => true, 'message' => 'post_purchase_care/post_purchase_review/order_shipped_delay/ghost_cart/relationship_anniversary ne laissent aucune variable résiduelle avec un jeu de variables réaliste'];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND date_add >= '{$startedAt}'
               AND template IN ('post_purchase_care','post_purchase_review','order_shipped_delay','ghost_cart','relationship_anniversary')"
        );
    }
}
