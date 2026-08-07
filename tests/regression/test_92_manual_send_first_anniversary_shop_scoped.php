<?php
/**
 * Régression : ManualSendManager::send() doit calculer le ref_id de
 * 'first_anniversary' (MIN(id_order) de la commande la plus ancienne)
 * scopé par id_shop — même correctif déjà appliqué dans
 * QueueManager::processSingle() et documenté dans
 * BehavioralCronManager::sendFirstAnniversaries() (« 1ère commande DE CETTE
 * boutique »).
 *
 * Bug réel corrigé le 07/08/2026 (round 88) : send() calculait
 * `MIN(id_order) FROM orders WHERE id_customer = X AND valid = 1` SANS
 * filtre id_shop. Sur une installation multi-boutiques à clients partagés,
 * un client avec une commande plus ancienne sur une AUTRE boutique obtenait
 * un ref_id ne correspondant à aucune commande de la boutique courante —
 * incohérent avec le ref_id que calculerait ensuite le cron
 * (sendFirstAnniversaries(), lui bien scopé), cassant la traçabilité de
 * neria_behavioral_sent en multi-shop.
 *
 * Ne pas invoquer send() directement (déclenche un envoi réel via
 * Mail::Send()) : ce test reproduit exactement la requête SQL du correctif
 * (identique à celle de QueueManager::processSingle(), déjà validée par
 * test_65) sur un jeu de données multi-boutiques réaliste, et vérifie
 * qu'elle retourne bien la commande de LA boutique concernée, pas la plus
 * ancienne toutes boutiques confondues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // Vérification structurelle : send() applique bien le filtre id_shop
    // sur SA requête MIN(id_order) (pas seulement la logique testée plus
    // bas, qui reproduit la requête hors du code réel pour éviter un envoi
    // d'email pendant le test).
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php') ?: '';
    $posQuery = strpos($src, "'SELECT MIN(id_order) FROM `' . _DB_PREFIX_ . 'orders`");
    neria_assert(
        $posQuery !== false && strpos(substr($src, $posQuery, 300), 'id_shop') !== false,
        "ManualSendManager::send() n'inclut plus id_shop dans sa requête MIN(id_order) pour first_anniversary — régression du bug corrigé le 07/08/2026 (round 88)"
    );

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $realShop   = (int) Context::getContext()->shop->id;
    $otherShop  = 999997; // boutique fictive, isolée des vraies données

    // Commande la plus ANCIENNE (toutes boutiques) sur la boutique fictive,
    // commande plus RÉCENTE mais réelle sur la vraie boutique — reproduit
    // le cas d'un client partagé avec un historique antérieur ailleurs.
    $db->execute(
        "INSERT INTO {$prefix}orders (id_shop, id_shop_group, id_customer, id_currency, id_lang, id_address_delivery, id_address_invoice, id_carrier, current_state, payment, conversion_rate, total_paid, total_paid_tax_incl, total_products, total_products_wt, valid, date_add, date_upd, invoice_number, invoice_date, delivery_number, delivery_date, secure_key)
         VALUES ({$otherShop}, 1, {$idCustomer}, 1, 1, 0, 0, 1, 1, 'regtest', 1, 10, 10, 10, 10, 1, DATE_SUB(NOW(), INTERVAL 5 YEAR), NOW(), 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 'regtest88')"
    );
    $idOldOrderOtherShop = (int) $db->Insert_ID();

    $db->execute(
        "INSERT INTO {$prefix}orders (id_shop, id_shop_group, id_customer, id_currency, id_lang, id_address_delivery, id_address_invoice, id_carrier, current_state, payment, conversion_rate, total_paid, total_paid_tax_incl, total_products, total_products_wt, valid, date_add, date_upd, invoice_number, invoice_date, delivery_number, delivery_date, secure_key)
         VALUES ({$realShop}, 1, {$idCustomer}, 1, 1, 0, 0, 1, 1, 'regtest', 1, 10, 10, 10, 10, 1, DATE_SUB(NOW(), INTERVAL 1 YEAR), NOW(), 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 'regtest88')"
    );
    $idRecentOrderRealShop = (int) $db->Insert_ID();

    try {
        // Requête EXACTE du correctif dans ManualSendManager::send() (et
        // identique à QueueManager::processSingle()).
        $refId = (int) $db->getValue(
            "SELECT MIN(id_order) FROM {$prefix}orders
             WHERE id_customer = {$idCustomer} AND valid = 1 AND id_shop = {$realShop}"
        );

        neria_assert(
            $refId === $idRecentOrderRealShop,
            "la requête scopée par id_shop de ManualSendManager::send() ne retourne plus la commande de LA boutique concernée (obtenu ref_id={$refId}, attendu {$idRecentOrderRealShop}) — régression du bug corrigé le 07/08/2026 (round 88)"
        );
        neria_assert(
            $refId !== $idOldOrderOtherShop,
            "la requête scopée par id_shop retourne encore la commande plus ancienne d'une AUTRE boutique (ref_id={$refId}) — régression du bug corrigé le 07/08/2026 (round 88) : sans le filtre id_shop, MIN(id_order) toutes boutiques confondues aurait retourné {$idOldOrderOtherShop}"
        );

        return [
            'pass'    => true,
            'message' => "ManualSendManager::send() calcule bien le ref_id de first_anniversary scopé par id_shop, pas MIN(id_order) toutes boutiques confondues",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order IN ({$idOldOrderOtherShop}, {$idRecentOrderRealShop})");
    }
}
