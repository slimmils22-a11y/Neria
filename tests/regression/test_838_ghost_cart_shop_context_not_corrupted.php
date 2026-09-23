<?php
/**
 * Régression : `BehavioralCronManager::sendGhostCarts()` capturait le
 * contexte boutique statique d'origine via
 * `Shop::getContextShopID(true)` (argument `$null_value_without_multishop`)
 * avant de le restaurer dans son `finally`. Avec cet argument à `true`,
 * PrestaShop renvoie NULL dès que `Shop::isFeatureActive()` est faux —
 * c'est-à-dire sur TOUTE installation mono-boutique (l'immense majorité des
 * marchands Neria, fonctionnalité multiboutique désactivée par défaut), pas
 * seulement "une seule boutique existe". Le `finally` restaurait alors
 * `Shop::setContext(CONTEXT_SHOP, null)`, corrompant durablement
 * `Shop::$context_id_shop` (pas seulement `Context->shop`) à 0 pour tout le
 * reste du process PHP — la boucle multi-boutique suivante de `run()`
 * (recalcul segments/churn, purge RGPD) plantait alors avec une
 * `ShopException` ("Shop id 0 is invalid") non rattrapée dès qu'un seul
 * panier fantôme était détecté, abandonnant sans trace le reste du cron
 * comportemental de ce jour-là (segments/churn jamais recalculés, purge RGPD
 * jamais faite, complétions collection/look jamais envoyées, heartbeat
 * Watchdog final jamais journalisé).
 *
 * Bug trouvé le 23/09/2026 (round 365, campagne de tests fonctionnels P4,
 * reproduit en conditions réelles sur ps-test avec de vraies commandes/
 * paniers antidatés).
 *
 * Corrigé en remplaçant `Shop::getContextShopID(true)` par
 * `Shop::getContextShopID()` (sans argument) dans les 4 méthodes du module
 * qui suivent ce même schéma capture/restauration du contexte statique :
 * BehavioralCronManager::sendGhostCarts(), CollectionManager::
 * getProductImageUrl(), LookCompletionManager::buildProductBlocks(),
 * WaitlistManager::notifyProduct().
 *
 * Test comportemental réel : seed 3 paniers distincts contenant le même
 * produit pour un client réel (jamais commandé) — condition de
 * déclenchement du panier fantôme — puis invoque directement
 * sendGhostCarts() (réflexion, méthode privée) sur cet environnement de dev
 * mono-boutique (Shop::isFeatureActive()===false, condition RÉELLE du bug,
 * pas une simulation). Vérifie qu'après l'appel, le contexte boutique
 * statique reste valide (Shop::getContextShopID() renvoie toujours le vrai
 * id, jamais NULL/0) et qu'une construction Shop() ultérieure ne lève plus
 * d'exception — exactement le symptôme qui cassait la boucle suivante de
 * run() en production.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $p      = neria_test_prefix();
    $module = neria_test_module();

    neria_assert(
        Shop::isFeatureActive() === false,
        'Précondition invalide : cet environnement de dev doit avoir le multiboutique désactivé pour reproduire fidèlement le bug (Shop::isFeatureActive()===false)'
    );

    $idCustomer = neria_test_any_customer_id();
    neria_assert($idCustomer > 0, 'Aucun client actif disponible pour le test');

    $idProduct = (int) $db->getValue(
        "SELECT id_product FROM {$p}product WHERE active=1
         AND id_product NOT IN (
             SELECT product_id FROM {$p}order_detail od
             JOIN {$p}orders o ON o.id_order = od.id_order
             WHERE o.id_customer = {$idCustomer} AND o.valid = 1
         )
         ORDER BY id_product"
    );
    neria_assert($idProduct > 0, 'Aucun produit jamais commandé par ce client disponible pour le test');

    $idShop = (int) $db->getValue("SELECT id_shop FROM {$p}customer WHERE id_customer={$idCustomer}") ?: 1;

    $cartIds = [];
    $address = (int) $db->getValue("SELECT id_address FROM {$p}address WHERE id_customer={$idCustomer} AND deleted=0");
    $carrier = (int) $db->getValue("SELECT id_carrier FROM {$p}carrier WHERE deleted=0 AND active=1 ORDER BY id_carrier");

    $ctx = Context::getContext();
    $originalEmployee = $ctx->employee;
    $originalCurrency = $ctx->currency;
    if (!$ctx->employee || !$ctx->employee->id) {
        $ctx->employee = new Employee((int) $db->getValue("SELECT id_employee FROM {$p}employee ORDER BY id_employee"));
    }
    if (!$ctx->currency || !$ctx->currency->id) {
        $ctx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
    }

    try {
        // 3 paniers distincts, même produit, jamais convertis en commande —
        // condition exacte de déclenchement de sendGhostCarts() (HAVING
        // COUNT(DISTINCT id_cart) >= 3).
        for ($i = 0; $i < 3; $i++) {
            $cart = new Cart();
            $cart->id_customer = $idCustomer;
            $cart->id_address_delivery = $address ?: 0;
            $cart->id_address_invoice  = $address ?: 0;
            $cart->id_currency = 1;
            $cart->id_lang     = (int) $db->getValue("SELECT id_lang FROM {$p}customer WHERE id_customer={$idCustomer}") ?: 1;
            $cart->id_carrier  = $carrier ?: 1;
            $cart->id_shop     = $idShop;
            $cart->add();
            $cart->updateQty(1, $idProduct);
            $db->execute("UPDATE {$p}cart SET date_upd='" . date('Y-m-d H:i:s', strtotime('-' . (10 + $i) . ' days')) . "' WHERE id_cart=" . (int) $cart->id);
            $cartIds[] = (int) $cart->id;
        }

        Context::getContext()->shop = new Shop($idShop);

        // Précondition confirmée sur CET environnement, au moment du test :
        // avec l'argument à true (l'ancien bug), le contexte capturé est
        // bien NULL ici — sans ça le bug ne serait pas reproductible et le
        // test ne prouverait rien.
        neria_assert(
            Shop::getContextShopID(true) === null,
            "Précondition invalide : Shop::getContextShopID(true) devrait renvoyer NULL sur cet environnement mono-boutique (sinon le bug corrigé n'est pas reproductible ici)"
        );
        neria_assert(
            Shop::getContextShopID() === $idShop,
            "Précondition invalide : Shop::getContextShopID() (sans argument) devrait renvoyer le vrai id boutique ({$idShop})"
        );

        $cron   = new BehavioralCronManager($module);
        $method = new ReflectionMethod(BehavioralCronManager::class, 'sendGhostCarts');
        $method->setAccessible(true);

        $exceptionCaught = null;
        try {
            $method->invoke($cron);
        } catch (\Throwable $e) {
            $exceptionCaught = $e;
        }

        neria_assert(
            $exceptionCaught === null,
            'sendGhostCarts() a levé une exception non rattrapée : ' . ($exceptionCaught ? $exceptionCaught->getMessage() : '')
        );

        // L'assertion centrale : le contexte boutique statique doit rester
        // VALIDE après l'appel — c'est exactement ce qui était corrompu à
        // NULL/0 par le bug, cassant toute construction Shop() ultérieure
        // (la boucle segmentation/churn/RGPD suivante dans run()).
        neria_assert(
            Shop::getContextShopID() === $idShop,
            'Shop::getContextShopID() ne renvoie plus le vrai id boutique après sendGhostCarts() — régression du bug corrigé le 23/09/2026 (round 365) : le contexte boutique statique redeviendrait corrompu (NULL/0), cassant la boucle segmentation/churn/RGPD suivante de run()'
        );

        $reconstructFailed = null;
        try {
            $reconstructed = new Shop($idShop);
        } catch (\Throwable $e) {
            $reconstructFailed = $e;
        }
        neria_assert(
            $reconstructFailed === null,
            'new Shop($idShop) échoue après sendGhostCarts() — régression du bug corrigé le 23/09/2026 (round 365) : "' . ($reconstructFailed ? $reconstructFailed->getMessage() : '') . '"'
        );

        return [
            'pass'    => true,
            'message' => "sendGhostCarts() (3 paniers réels, produit jamais commandé, déclenchement réel du panier fantôme) ne corrompt plus le contexte boutique statique — Shop::getContextShopID() reste valide ({$idShop}) et new Shop({$idShop}) fonctionne toujours après l'appel — bug corrigé le 23/09/2026 (round 365)",
        ];
    } finally {
        Context::getContext()->employee = $originalEmployee;
        Context::getContext()->currency = $originalCurrency;
        foreach ($cartIds as $idCart) {
            $db->execute("DELETE FROM {$p}cart_product WHERE id_cart={$idCart}");
            $db->execute("DELETE FROM {$p}cart WHERE id_cart={$idCart}");
        }
        $db->execute("DELETE FROM {$p}neria_behavioral_sent WHERE id_customer={$idCustomer} AND template='ghost_cart' AND ref_id={$idProduct}");
    }
}
