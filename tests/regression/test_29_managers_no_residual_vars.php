<?php
/**
 * Régression : loyalty_recap, loyalty_tier_upgrade, certificate_email,
 * collection_completion, waitlist_available ne doivent laisser AUCUNE
 * variable non résolue avec le jeu de variables réel construit par leur
 * manager Neria respectif (LoyaltyManager::sendRewardEmail() / recap
 * mensuel, CertificateManager::sendCertificateEmail(),
 * CollectionManager::checkCompletions(), WaitlistManager::notifyProduct()
 * — sources lues le 02/08/2026). Même principe que test_23.
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

    $templates = [
        // LoyaltyManager::sendRewardEmail()
        'loyalty_tier_upgrade' => [
            '{firstname}'      => $row['firstname'],
            '{lastname}'       => $row['lastname'],
            '{shop_name}'      => (string) Configuration::get('PS_SHOP_NAME'),
            '{new_tier_name}'  => 'Ambassadeur',
            '{voucher_code}'   => 'NERIA-TIER-TEST99',
            '{voucher_amount}' => '10,00 €',
            '{total_points}'   => '500',
            '{history_url}'    => 'https://example.test/historique',
        ],
        // LoyaltyManager recap mensuel (Mail::Send 'loyalty_recap')
        'loyalty_recap' => [
            '{firstname}'         => $row['firstname'],
            '{lastname}'          => $row['lastname'],
            '{shop_name}'         => (string) Configuration::get('PS_SHOP_NAME'),
            '{shop_url}'          => 'https://example.test/',
            '{history_url}'       => 'https://example.test/historique',
            '{points_this_month}' => '80',
            '{points_total}'      => '500',
            '{next_tier_name}'    => 'Ambassadeur',
            '{next_tier_points}'  => '1000',
            '{points_remaining}'  => '500',
            '{progress_pct}'      => '50',
        ],
        // CertificateManager::sendCertificateEmail()
        'certificate_email' => [
            '{firstname}'     => $row['firstname'],
            '{customer_name}' => trim($row['firstname'] . ' ' . $row['lastname']),
            '{product_name}'  => 'Sac Camille — cuir pleine fleur',
            '{serial_number}' => 'NR-CERT-TEST-0001',
            '{id_order}'      => '999888555',
            '{order_name}'    => 'NR-TEST999',
            '{shop_name}'     => (string) Configuration::get('PS_SHOP_NAME'),
            '{shop_url}'      => 'https://example.test/',
        ],
        // CollectionManager::checkCompletions()
        'collection_completion' => [
            '{firstname}'           => $row['firstname'],
            '{collection_name}'     => 'Collection Provence',
            '{missing_product}'     => 'Foulard Provence',
            '{missing_product_url}' => 'https://example.test/foulard',
            '{missing_image_url}'   => 'https://example.test/foulard.jpg',
            '{missing_price}'       => '65,00 €',
            '{bought_count}'        => '2',
            '{total_count}'         => '3',
            '{shop_name}'           => (string) Configuration::get('PS_SHOP_NAME'),
        ],
        // WaitlistManager::notifyProduct()
        'waitlist_available' => [
            '{firstname}'          => $row['firstname'],
            '{days_waited_plural}' => 's',
            '{product_name}'       => 'Ceinture cuir Neria',
            '{product_url}'        => 'https://example.test/ceinture',
            '{product_image}'      => 'https://example.test/ceinture.jpg',
            '{product_price}'      => '89,00 €',
            '{days_waited}'        => '5',
            '{reservation_hours}'  => '48',
            '{shop_name}'          => (string) Configuration::get('PS_SHOP_NAME'),
        ],
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

        return ['pass' => true, 'message' => 'loyalty_recap/loyalty_tier_upgrade/certificate_email/collection_completion/waitlist_available ne laissent aucune variable résiduelle avec un jeu de variables réaliste'];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND date_add >= '{$startedAt}'
               AND template IN ('loyalty_recap','loyalty_tier_upgrade','certificate_email','collection_completion','waitlist_available')"
        );
    }
}
