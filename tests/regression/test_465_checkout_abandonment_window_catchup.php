<?php
/**
 * Régression round 224 (28/08/2026) : BehavioralCronManager::
 * sendCheckoutAbandonment() bornait sa fenêtre de recherche à
 * [NOW-24H, NOW-1H] — contrairement à sendAbandonedCarts(), déjà
 * corrigée pour le même défaut (commentaire ligne ~798) : run() ne
 * tourne qu'une fois par jour, à une heure non maîtrisée (premier
 * visiteur front). Si le cron sautait un jour (maintenance, panne),
 * un panier abandonné avec transporteur+adresses déjà sélectionnés
 * (signal fort de conversion) sortait définitivement de la fenêtre de
 * 24h sans jamais recevoir sa relance — silencieusement, sans log ni
 * alerte Watchdog.
 *
 * Corrigé le 28/08/2026 (round 224) : borne haute élargie à 168h (7
 * jours). La déduplication neria_behavioral_sent rend cet
 * élargissement sûr (pas de risque de double-envoi).
 *
 * Ce test simule exactement le scénario perdu avant le correctif : un
 * panier avec transporteur + adresses sélectionnées, vieux de 48h (donc
 * hors de l'ancienne fenêtre 24h, dans la nouvelle 168h), doit
 * désormais bien recevoir la relance checkout_abandonment.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $carrier = $db->getRow("SELECT id_carrier FROM {$prefix}carrier WHERE deleted = 0");
    $address = $db->getRow("SELECT id_address FROM {$prefix}address WHERE id_customer = {$idCustomer} AND deleted = 0");
    neria_assert($carrier && $address, 'Jeu de test invalide : aucun transporteur ou adresse client disponible pour simuler le panier');
    $idCarrier = (int) $carrier['id_carrier'];
    $idAddress = (int) $address['id_address'];

    $db->execute("INSERT INTO {$prefix}cart (id_shop, id_shop_group, id_customer, id_currency, id_lang,
            id_carrier, id_address_delivery, id_address_invoice, date_add, date_upd)
        VALUES (1, 1, {$idCustomer}, 1, 1, {$idCarrier}, {$idAddress}, {$idAddress},
            NOW(), DATE_SUB(NOW(), INTERVAL 48 HOUR))");
    $idCart = (int) $db->Insert_ID();

    // Un produit dans le panier : la requête filtre sur cart_product non vide.
    $product = $db->getRow("SELECT id_product FROM {$prefix}product WHERE active = 1");
    neria_assert($product !== false && $product !== null, 'Jeu de test invalide : aucun produit actif disponible');
    $idProduct = (int) $product['id_product'];
    $db->execute("INSERT INTO {$prefix}cart_product (id_cart, id_product, id_address_delivery, id_shop, quantity, date_add)
        VALUES ({$idCart}, {$idProduct}, {$idAddress}, 1, 1, NOW())");

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';
        $mgr = new BehavioralCronManager(neria_test_module());
        $ref = new ReflectionMethod($mgr, 'sendCheckoutAbandonment');
        $ref->setAccessible(true);
        $ref->invoke($mgr);

        $sent = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent
             WHERE ref_id = {$idCart} AND template = 'checkout_abandonment'",
            false
        );

        neria_assert(
            $sent === 1,
            "checkout_abandonment n'a pas été envoyé pour un panier de 48h avec transporteur+adresses sélectionnés — "
            . "régression du bug corrigé le 28/08/2026 (round 224) : la relance à fort taux de conversion serait de nouveau perdue silencieusement dès que le cron saute plus de 24h"
        );

        return [
            'pass'    => true,
            'message' => 'Round 224 : sendCheckoutAbandonment() capte bien un panier de 48h (fenêtre élargie à 168h), plus de perte silencieuse si le cron saute un jour',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE ref_id={$idCart}");
        $db->execute("DELETE FROM {$prefix}cart_product WHERE id_cart={$idCart}");
        $db->execute("DELETE FROM {$prefix}cart WHERE id_cart={$idCart}");
    }
}
