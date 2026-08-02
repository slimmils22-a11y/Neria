<?php
/**
 * Régression : order_conf et complete_your_look ne doivent laisser AUCUNE
 * variable non résolue quand ils sont envoyés avec un jeu de variables
 * réaliste (celui que fournirait réellement leur appelant en production).
 *
 * Motivation : {recycled_packaging_label} (order_conf.html) et {tracking_url}
 * (traduction 'tracking_info' d'order_conf) n'ont jamais été injectés par
 * aucun appelant réel — seule la donnée factice de l'aperçu BO les
 * fournissait — donc chaque email de confirmation de commande envoyé en
 * production affichait une ligne cassée et un lien de suivi mort, sans
 * qu'aucun test ne le détecte (trouvés par audit de code puis par ce test
 * lui-même le 02/08/2026, corrigés la même session : {tracking_url} →
 * {history_url}, déjà auto-injecté). Ce test envoie réellement ces deux
 * templates via le vrai pipeline (Mail::Send → hookActionEmailSendBefore →
 * EmailRenderer) avec un jeu de variables réaliste, et vérifie qu'aucune
 * ligne watchdog.residual_vars_stripped n'apparaît dans le journal — le
 * même signal que verrait un vrai envoi client, sans attendre qu'un vrai
 * client déclenche l'envoi.
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
        // order_conf : jeu de variables réaliste, tel que PrestaShop core le
        // fournit réellement à la confirmation de commande native.
        'order_conf' => [
            '{shop_name}'            => (string) Configuration::get('PS_SHOP_NAME'),
            '{firstname}'            => $row['firstname'],
            '{lastname}'             => $row['lastname'],
            '{id_order}'             => '999888555',
            '{order_name}'           => 'NR-TEST999',
            '{date}'                 => date('Y-m-d H:i:s'),
            '{payment}'              => 'Carte bancaire',
            '{products}'             => '<p>Produit test × 1</p>',
            '{products_txt}'         => 'Produit test x 1',
            '{discounts}'            => '',
            '{discounts_txt}'        => '',
            '{total_products}'       => '100,00 €',
            '{total_discounts}'      => '0,00 €',
            '{total_shipping}'       => '0,00 €',
            '{total_tax_paid}'       => '0,00 €',
            '{total_paid}'           => '100,00 €',
            '{carrier}'              => 'Colissimo',
            '{delivery_block_html}'  => '<p>1 rue de Test, 75000 Paris</p>',
            '{invoice_block_html}'   => '<p>1 rue de Test, 75000 Paris</p>',
            '{delivery_block_txt}'   => '1 rue de Test, 75000 Paris',
            '{invoice_block_txt}'    => '1 rue de Test, 75000 Paris',
        ],
        // complete_your_look : jeu de variables réaliste tel que
        // LookCompletionManager::buildVars() le fournit réellement.
        'complete_your_look' => [
            '{shop_name}'      => (string) Configuration::get('PS_SHOP_NAME'),
            '{firstname}'      => $row['firstname'],
            '{category_name}'  => 'Chaussures',
            '{product1_name}'  => 'Produit A',
            '{product1_url}'   => 'https://example.test/a',
            '{product1_image}' => 'https://example.test/a.jpg',
            '{product1_price}' => '89,00 €',
            '{product2_name}'  => 'Produit B',
            '{product2_url}'   => 'https://example.test/b',
            '{product2_image}' => 'https://example.test/b.jpg',
            '{product2_price}' => '49,00 €',
            '{product3_name}'  => 'Produit C',
            '{product3_url}'   => 'https://example.test/c',
            '{product3_image}' => 'https://example.test/c.jpg',
            '{product3_price}' => '29,00 €',
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
            . " — régression du bug {recycled_packaging_label} corrigé le 02/08/2026 (commit ae22bd3)"
        );

        return ['pass' => true, 'message' => 'order_conf et complete_your_look ne laissent aucune variable résiduelle avec un jeu de variables réaliste'];
    } finally {
        // Nettoie les logs générés par ce test (info d'envoi + résidus éventuels)
        $db->execute(
            "DELETE FROM {$prefix}neria_log
             WHERE class = 'EmailRenderer' AND date_add >= '{$startedAt}'
               AND template IN ('order_conf', 'complete_your_look')"
        );
    }
}
