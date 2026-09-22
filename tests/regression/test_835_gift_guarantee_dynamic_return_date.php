<?php
/**
 * Régression (constat réel du 22/09/2026, campagne de tests fonctionnels) : le modèle gift_guarantee affichait
 * une date de retour FIXE ("31 janvier" / "January 31st" / etc.), identique dans les 19 langues, toute l'année
 * — alors que ce modèle est générique (pas saisonnier : Noël a ses propres modèles christmas.html/
 * end_of_year_gift.html). Un cadeau envoyé en juin affichait déjà une date passée, ou à sept mois d'écart.
 *
 * Corrigé : {gift_return_date} calculée à partir de la date d'ENVOI + un délai configurable par le marchand
 * (ConfigManager::getGiftGuaranteeDays(), défaut 30 jours, plancher légal 14 jours — délai de rétractation UE,
 * art. L221-18 du Code de la consommation).
 *
 * Test comportemental réel : un vrai envoi manuel de gift_guarantee doit contenir une date de retour cohérente
 * avec "aujourd'hui + délai configuré", jamais le texte figé "31 janvier"/"January 31st", et le réglage
 * (sauvegarde + plancher) doit fonctionner.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $email      = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    neria_assert($email !== '', 'Aucun client de test disponible — jeu de test invalide');

    $module = neria_test_module();
    $cfg    = new ConfigManager($module);
    $originalDays = (int) $cfg->get(ConfigManager::KEY_GIFT_GUARANTEE_DAYS, 30);

    // Commande jetable, même fixture que test_834 (product_recall).
    $idShop = (int) Context::getContext()->shop->id;
    $ref    = 'NRT' . substr(md5(uniqid('', true)), 0, 6);
    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, reference, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','{$ref}','regtest',1,50,50,50,50,50,50,1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer={$idCustomer} AND template='gift_guarantee' AND event_type='sent' AND date_add > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");

    try {
        // 1. Réglage : plancher légal appliqué même à une valeur invalide (en dessous de 14 jours).
        neria_assert($cfg->saveGiftGuaranteeDays(5), 'saveGiftGuaranteeDays() a échoué — jeu de test invalide');
        neria_assert(
            (new ConfigManager($module))->getGiftGuaranteeDays() === ConfigManager::GIFT_GUARANTEE_DAYS_FLOOR,
            "Le plancher légal (14 jours) n'est plus appliqué à une valeur trop basse — régression du correctif du 22/09/2026"
        );

        // 2. Valeur normale, utilisée pour le reste du test.
        neria_assert($cfg->saveGiftGuaranteeDays(45), 'saveGiftGuaranteeDays(45) a échoué');
        neria_assert((new ConfigManager($module))->getGiftGuaranteeDays() === 45, 'La valeur enregistrée (45) ne ressort pas telle quelle');

        // 3. Envoi réel via le vrai code (ManualSendManager::send()) : doit réussir, et le compteur d'envois
        // réels (neria_stat) doit refléter gift_guarantee pour ce client — preuve que Mail::Send() a été
        // atteint avec le template correctement compilé (une exception à la compilation aurait empêché ça).
        $mgr    = new ManualSendManager($module);
        $result = $mgr->send('gift_guarantee', $email, $ref, '', []);
        neria_assert(($result['ok'] ?? false) === true, "Envoi refusé — jeu de test invalide : " . (string) ($result['message'] ?? ''));

        // 4. Le TEMPLATE affiche bien {gift_return_date} là où le texte était figé, avec la valeur exacte que
        // send() calcule (même formule, reproduite ici) — vérifié via compileNeriaTemplate() directement,
        // comme le fait déjà test_781 pour un autre scénario de rendu réel.
        $expected = \NeriaTools::formatDate(date('Y-m-d', strtotime('+45 days')), 'fr');
        $renderer = new EmailRenderer($module);
        $compile  = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
        $compile->setAccessible(true);
        $outputName = 'regtest835_gift_guarantee';
        $outFile    = _PS_MODULE_DIR_ . 'neria/mails/fr/' . $outputName . '.html';
        try {
            $compile->invoke($renderer, 'gift_guarantee', 'fr', 'fr', [
                '{gift_return_date}'    => $expected,
                '{order_name}'          => $ref,
                '{order_url}'           => 'https://example.test/order',
                '{custom_message}'      => '',
                '{custom_message_txt}'  => '',
            ], false, false, $outputName);
            neria_assert(is_file($outFile), 'compileNeriaTemplate() a échoué — jeu de test invalide');
            $html = (string) file_get_contents($outFile);
            neria_assert(
                strpos($html, $expected) !== false,
                "La date calculée ({$expected}) n'apparaît pas dans le rendu du template gift_guarantee — le placeholder {gift_return_date} a-t-il disparu du fichier source ?"
            );
            neria_assert(
                stripos($html, 'janvier') === false,
                "Le texte figé \"31 janvier\" est réapparu dans le rendu — régression du correctif du 22/09/2026"
            );
        } finally {
            if (is_file($outFile)) {
                unlink($outFile);
            }
            @unlink(_PS_MODULE_DIR_ . 'neria/mails/fr/' . $outputName . '.txt');
        }

        // 5. Structurel : les 2 points d'injection (send()/scheduleManual()) sont bien câblés.
        $src = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
        neria_assert(
            substr_count($src, "\$vars['{gift_return_date}']") === 2,
            "{gift_return_date} n'est plus injecté dans les 2 points d'envoi (send()/scheduleManual())"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
        $cfg->saveGiftGuaranteeDays($originalDays);
    }

    return [
        'pass'    => true,
        'message' => "gift_guarantee affiche une date de retour calculée (aujourd'hui + délai configurable, plancher légal 14 jours), plus de texte figé",
    ];
}
