<?php
/**
 * Régression (constat réel du 22/09/2026, campagne de tests fonctionnels) : les champs de contenu éditables
 * d'un envoi manuel (ex. {product_name} de product_recall) n'avaient aucune validation — ni le formulaire
 * (pas d'attribut HTML `required`), ni ManualSendManager::send()/scheduleManual(). Un envoi validé avec le
 * champ laissé vide livrait au client un mail avec une phrase visiblement tronquée : "Produit concerné :"
 * suivi de rien.
 *
 * Corrigé : ManualSendManager::findMissingEditableVars() bloque désormais l'envoi si un champ découvert par
 * getEditableVars() (hors {custom_message}, volontairement facultatif) est resté vide, dans send() et
 * scheduleManual(). L'attribut HTML `required` a aussi été ajouté sur le champ correspondant dans send.tpl.
 *
 * Test comportemental réel : un vrai envoi manuel de product_recall sans {product_name} doit être refusé
 * avec le message exact ; le même envoi avec {product_name} renseigné doit réussir.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $email      = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    neria_assert($email !== '', 'Aucun client de test disponible — jeu de test invalide');

    // Purge un éventuel envoi 'sent' récent (Mode Silence), comme test_833.
    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer={$idCustomer} AND template='product_recall' AND event_type='sent' AND date_add > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");

    // product_recall a besoin d'une commande liée (pour {order_name}) — commande jetable, même fixture que
    // test_252_manual_send_find_order_shop_scoped.php.
    $idShop = (int) Context::getContext()->shop->id;
    $ref    = 'NRT' . substr(md5(uniqid('', true)), 0, 6);
    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, reference, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','{$ref}','regtest',1,50,50,50,50,50,50,1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();

    try {
        $mgr = new ManualSendManager(neria_test_module());

        // 1. {product_name} vide (ou absent) : envoi refusé, message exact.
        $result = $mgr->send('product_recall', $email, $ref, '', ['product_materials' => 'coton']);
        neria_assert(
            ($result['ok'] ?? true) === false,
            "L'envoi de product_recall sans {product_name} n'a pas été refusé — régression du correctif du 22/09/2026"
        );
        neria_assert(
            strpos((string) ($result['message'] ?? ''), 'product_name') !== false,
            "Le message de refus ne mentionne pas 'product_name' (obtenu : '" . (string) ($result['message'] ?? '') . "') — assertion du message exact, pas seulement ok===false"
        );

        // 2. {product_name} renseigné (avec espaces superflus, doit être trim()) : envoi accepté.
        $result2 = $mgr->send('product_recall', $email, $ref, '', ['product_name' => '  Écharpe en cachemire  ']);
        neria_assert(
            ($result2['ok'] ?? false) === true,
            "L'envoi de product_recall avec {product_name} renseigné a été refusé à tort : " . (string) ($result2['message'] ?? '')
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }

    // 3. Structurel : le garde-fou est bien câblé dans send() ET scheduleManual(), et le formulaire porte
    // désormais l'attribut required.
    $src    = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
    $tplSrc = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/send.tpl');
    neria_assert(
        substr_count($src, '$this->findMissingEditableVars(') === 2,
        "findMissingEditableVars() n'est plus appelé dans les 2 points d'envoi (send()/scheduleManual())"
    );
    neria_assert(
        strpos($tplSrc, 'name="neria_var[{$f.key}]" required') !== false,
        "L'attribut HTML required a disparu du champ de contenu éditable dans send.tpl"
    );

    return [
        'pass'    => true,
        'message' => "Un champ de contenu éditable (ex. {product_name}) resté vide bloque bien l'envoi manuel, avec un message clair ; renseigné, l'envoi part normalement",
    ];
}
