<?php
/**
 * Régression : quote_expiry_48h, quote_expiry_day, quote_extension_offer,
 * refund_reconciliation_1/2/3, product_lifespan_reminder ne doivent laisser
 * AUCUNE variable non résolue avec le jeu de variables réel fourni par
 * BehavioralCronManager (sendQuoteEmail(), sendRefundReconciliations(),
 * sendLifespanReminders() — src/BehavioralCronManager.php, lu le 02/08/2026).
 * Même principe que test_23/24/25/26.
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

    // sendQuoteEmail() : vars communes à quote_expiry_48h/quote_expiry_day/quote_extension_offer
    $quoteCommon = array_merge($common, [
        '{quote_ref}'   => 'DEVIS-TEST-001',
        '{quote_total}' => '450,00 €',
        '{expiry_date}' => '10 août 2026',
        '{quote_url}'   => 'https://example.test/',
    ]);

    $templates = [
        // withExtension=false → {new_expiry_date} vide
        'quote_expiry_48h' => array_merge($quoteCommon, ['{new_expiry_date}' => '']),
        'quote_expiry_day' => array_merge($quoteCommon, ['{new_expiry_date}' => '']),
        // withExtension=true → {new_expiry_date} rempli
        'quote_extension_offer' => array_merge($quoteCommon, ['{new_expiry_date}' => '17 août 2026']),
        // sendRefundReconciliations() — {order_name} vide dans le code réel
        'refund_reconciliation_1' => array_merge($common, ['{order_name}' => '']),
        'refund_reconciliation_2' => array_merge($common, ['{order_name}' => '']),
        'refund_reconciliation_3' => array_merge($common, ['{order_name}' => '']),
        // sendLifespanReminders()
        'product_lifespan_reminder' => array_merge($common, [
            '{product_name}'   => 'Ceinture cuir Neria',
            '{product_url}'    => 'https://example.test/ceinture',
            '{estimated_days}' => '365',
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

        return ['pass' => true, 'message' => 'quote_expiry_48h/quote_expiry_day/quote_extension_offer/refund_reconciliation_1-3/product_lifespan_reminder ne laissent aucune variable résiduelle avec un jeu de variables réaliste'];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND date_add >= '{$startedAt}'
               AND template IN ('quote_expiry_48h','quote_expiry_day','quote_extension_offer','refund_reconciliation_1','refund_reconciliation_2','refund_reconciliation_3','product_lifespan_reminder')"
        );
    }
}
