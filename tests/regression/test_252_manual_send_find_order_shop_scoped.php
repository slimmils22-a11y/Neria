<?php
/**
 * Régression : ManualSendManager::findOrder() filtrait sur
 * Context::getContext()->shop->id (contexte BO de l'opérateur qui
 * déclenche l'envoi) au lieu de la boutique du CLIENT réel destinataire,
 * contrairement à tout le reste de send()/scheduleManual() déjà scopé
 * (round 74/106/136 : {shop_url}, {history_url}, {shop_name},
 * BlacklistManager). Order::generateReference() génère une référence
 * globalement unique sur toute l'installation (pas par boutique), donc ce
 * filtre id_shop n'existe pas pour lever une ambiguïté entre boutiques :
 * il excluait à tort une commande valide dès que l'opérateur n'était pas
 * dans le même contexte boutique que le client destinataire, bloquant à
 * tort le garde-fou "contexte commande" pour alteration_update/
 * gift_guarantee (msg.send_blocked_missing_order).
 *
 * Corrigé le 09/08/2026 (round 156) en passant explicitement l'idShop du
 * client réel à findOrder(), au lieu de lire Context::getContext().
 *
 * Test comportemental réel : crée une vraie commande valide, puis appelle
 * findOrder() (via Reflection, méthode privée) avec l'idShop RÉEL de la
 * commande (doit la trouver) et avec un idShop délibérément DIFFÉRENT
 * (doit ne PAS la trouver) — prouve que c'est bien le paramètre reçu qui
 * pilote le filtre, pas un état global implicite.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $ref        = 'NRT' . substr(md5(uniqid('', true)), 0, 6);

    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, reference, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','{$ref}','regtest',1,50,50,50,50,50,50,1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();

    try {
        $mgr = new ManualSendManager(neria_test_module());
        $method = new ReflectionMethod('ManualSendManager', 'findOrder');
        $method->setAccessible(true);

        $foundWithRealShop = $method->invoke($mgr, $ref, $idShop);
        neria_assert(
            is_array($foundWithRealShop) && (int) $foundWithRealShop['id_order'] === $idOrder,
            "findOrder() ne retrouve plus une commande réelle avec l'idShop correct — régression du bug corrigé le 09/08/2026 (round 156)"
        );

        $wrongShopId = $idShop + 999;
        $foundWithWrongShop = $method->invoke($mgr, $ref, $wrongShopId);
        neria_assert(
            $foundWithWrongShop === null,
            "findOrder() retrouve encore la commande avec un idShop délibérément faux — le paramètre \$idShop n'est plus réellement utilisé comme filtre, régression potentielle du bug corrigé le 09/08/2026 (round 156)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }

    return [
        'pass'    => true,
        'message' => "ManualSendManager::findOrder() filtre bien sur l'idShop explicitement reçu (client réel), pas sur Context::getContext() (opérateur BO) — bug corrigé le 09/08/2026 (round 156)",
    ];
}
