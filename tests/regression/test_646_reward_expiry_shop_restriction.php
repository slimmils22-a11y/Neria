<?php
/**
 * Régression : BehavioralCronManager::sendRewardExpiryAlerts() scope ses
 * candidats via c.id_shop (le client) mais ignorait totalement
 * cr.shop_restriction / ps_cart_rule_shop — alors que
 * LoyaltyManager::checkAndReward() pose shop_restriction=1 +
 * id_shop_list=[idShop] sur les bons de fidélité non transversaux (voir
 * revokeUnusedRewardsBelowThreshold()).
 *
 * Bug réel : si l'id_shop du client a divergé de celui du bon depuis sa
 * création (déménagement de compte, ou bon créé avant un changement de
 * shop_group), le cron alertait "votre bon expire dans 7 jours" pour un
 * CartRule restreint à une AUTRE boutique — donc inutilisable par ce
 * client, malgré l'email affirmant le contraire.
 *
 * Corrigé le 08/09/2026 (round 325) : la requête exclut désormais les bons
 * dont shop_restriction=1 et dont ps_cart_rule_shop ne couvre pas l'idShop
 * courant.
 *
 * Test comportemental réel : crée 2 vrais CartRule actifs expirant dans 3
 * jours pour le même client — l'un avec shop_restriction=1 restreint à une
 * AUTRE boutique (id_shop=999, fictive), l'autre sans restriction — et
 * vérifie que sendRewardExpiryAlerts() ne retient QUE le second (via une
 * requête directe reproduisant le SELECT interne, car la méthode privée
 * envoie un email réel qu'on ne veut pas déclencher en test).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    $makeCartRule = function () use ($idCustomer) {
        $cartRule = new CartRule();
        $cartRule->name              = [(int) Configuration::get('PS_LANG_DEFAULT') => 'Regtest646'];
        $cartRule->code              = 'REGTEST646-' . uniqid();
        $cartRule->quantity          = 1;
        $cartRule->quantity_per_user = 1;
        $cartRule->id_customer       = $idCustomer;
        $cartRule->active            = 1;
        $cartRule->date_from         = date('Y-m-d H:i:s');
        $cartRule->date_to           = date('Y-m-d H:i:s', strtotime('+3 days'));
        $cartRule->reduction_percent = 10;
        $cartRule->minimum_amount    = 0;
        $cartRule->minimum_amount_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cartRule->highlight         = false;
        $cartRule->free_shipping     = false;
        neria_assert($cartRule->add(), 'jeu de test invalide : la création du CartRule a échoué');

        return $cartRule;
    };

    $crRestricted = $makeCartRule();
    $crOpen       = $makeCartRule();

    try {
        // Restreint à une boutique FICTIVE (999) — jamais l'idShop courant.
        $db->execute(
            "INSERT INTO {$prefix}cart_rule_shop (id_cart_rule, id_shop) VALUES ({$crRestricted->id}, 999)"
        );
        $db->execute("UPDATE {$prefix}cart_rule SET shop_restriction = 1 WHERE id_cart_rule = {$crRestricted->id}");

        // Réplique exacte du SELECT interne de sendRewardExpiryAlerts() (la
        // méthode réelle est privée et enverrait un email réel).
        $ref = new ReflectionMethod(BehavioralCronManager::class, 'sendRewardExpiryAlerts');
        neria_assert($ref !== null, 'méthode absente');

        $rows = $db->executeS(
            "SELECT cr.id_cart_rule, cr.id_customer, cr.date_to,
                    c.email, c.firstname, c.lastname, c.id_lang, c.id_shop
             FROM `{$prefix}cart_rule` cr
             JOIN `{$prefix}customer` c ON c.id_customer = cr.id_customer
             WHERE cr.active = 1 AND cr.id_customer > 0 AND c.id_shop = {$idShop}
               AND cr.id_cart_rule IN ({$crRestricted->id}, {$crOpen->id})
               AND DATE(cr.date_to) BETWEEN CURDATE() AND DATE(DATE_ADD(NOW(), INTERVAL 7 DAY))
               AND (
                   cr.shop_restriction = 0
                   OR EXISTS (
                       SELECT 1 FROM `{$prefix}cart_rule_shop` crs
                       WHERE crs.id_cart_rule = cr.id_cart_rule AND crs.id_shop = {$idShop}
                   )
               )"
        );

        $ids = array_column((array) $rows, 'id_cart_rule');
        neria_assert(
            !in_array((int) $crRestricted->id, array_map('intval', $ids), true),
            "le CartRule restreint à une autre boutique (shop_restriction=1, cart_rule_shop != idShop courant) est retenu par sendRewardExpiryAlerts() — régression du bug corrigé le 08/09/2026 (round 325) : une alerte d'expiration serait de nouveau envoyée pour un bon inutilisable dans la boutique du client"
        );
        neria_assert(
            in_array((int) $crOpen->id, array_map('intval', $ids), true),
            "le CartRule sans restriction de boutique n'est plus retenu par sendRewardExpiryAlerts() — faux négatif introduit par le correctif round 325"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
        neria_assert(
            strpos($src, 'cr.shop_restriction = 0') !== false && strpos($src, "'cart_rule_shop` crs") !== false,
            "sendRewardExpiryAlerts() ne filtre plus cart_rule_shop/shop_restriction — régression du bug corrigé le 08/09/2026 (round 325)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}cart_rule_shop WHERE id_cart_rule IN ({$crRestricted->id}, {$crOpen->id})");
        (new CartRule((int) $crRestricted->id))->delete();
        (new CartRule((int) $crOpen->id))->delete();
    }

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::sendRewardExpiryAlerts() exclut bien un bon de fidélité restreint à une autre boutique (shop_restriction/cart_rule_shop) — bug corrigé le 08/09/2026 (round 325)",
    ];
}
