<?php
/**
 * Régression : wishlist_reminder, abandoned_cart_1/2/3, checkout_abandonment
 * ne doivent laisser AUCUNE variable non résolue avec le jeu de variables
 * réel fourni par BehavioralCronManager (sendWishlistReminders(),
 * sendAbandonedCarts(), sendCheckoutAbandonment() — src/BehavioralCronManager.php,
 * lu le 02/08/2026). Même principe que test_23/test_24.
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

    $cartExtra = [
        '{cart_url}'     => 'https://example.test/index.php?controller=order',
        '{products}'     => '<p>Produit test × 1</p>',
        '{products_txt}' => 'Produit test x 1',
    ];

    $templates = [
        // sendWishlistReminders()
        'wishlist_reminder' => array_merge($common, [
            '{product_name}' => 'Écharpe Neria',
            '{shop_url}'     => 'https://example.test/',
        ]),
        // sendAbandonedCarts() — même jeu de vars pour les 3 paliers
        'abandoned_cart_1' => array_merge($common, $cartExtra),
        'abandoned_cart_2' => array_merge($common, $cartExtra),
        'abandoned_cart_3' => array_merge($common, $cartExtra),
        // sendCheckoutAbandonment() — pas de {products_txt}
        'checkout_abandonment' => array_merge($common, [
            '{cart_url}' => 'https://example.test/index.php?controller=order',
            '{products}' => '<p>Produit test × 1</p>',
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

        return ['pass' => true, 'message' => 'wishlist_reminder/abandoned_cart_1-3/checkout_abandonment ne laissent aucune variable résiduelle avec un jeu de variables réaliste'];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND date_add >= '{$startedAt}'
               AND template IN ('wishlist_reminder','abandoned_cart_1','abandoned_cart_2','abandoned_cart_3','checkout_abandonment')"
        );
    }
}
