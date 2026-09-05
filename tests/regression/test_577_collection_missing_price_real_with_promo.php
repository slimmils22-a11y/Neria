<?php
/**
 * Régression : CollectionManager::processCollection() résolvait
 * {missing_price} via $product->price brut — le champ ObjectModel
 * catalogue (HT, sans specific_price/promo, sans groupe tarifaire), pas
 * via Product::getPriceStatic() comme le font déjà UpsellManager::enrich()
 * et LookCompletionManager::buildProductBlocks() (round 184) pour ce même
 * problème. Un produit manquant de la collection actuellement en
 * promotion, ou un client à tarif groupe négocié (B2B), voyait un prix HT
 * plein tarif dans l'email "il ne vous manque que X", différent de celui
 * réellement affiché sur la fiche produit au clic.
 *
 * Corrigé le 05/09/2026 (round 305) : nouvelle méthode privée
 * safeProductPrice() (même logique que les 2 classes jumelles, avec le
 * correctif de bascule $context->currency intégré dès la première version
 * — voir test_576), utilisée à la place de $product->price.
 *
 * Test comportemental réel : compare le prix retourné par
 * CollectionManager::safeProductPrice() à celui de $product->price brut
 * pour un produit avec une réduction active (specific_price), et vérifie
 * que les deux diffèrent (preuve que le calcul passe bien par
 * Product::getPriceStatic(), pas le champ catalogue). Vérification
 * structurelle complémentaire : processCollection() appelle bien
 * safeProductPrice(), plus $product->price directement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $db = Db::getInstance();
    $idShop = (int) Context::getContext()->shop->id;
    $idProduct = (int) $db->getValue("SELECT id_product FROM " . _DB_PREFIX_ . "product WHERE active = 1");
    neria_assert($idProduct > 0, "Aucun produit actif trouvé — jeu de test invalide");

    // Réduction spécifique de test (-50%, toutes boutiques/devises/groupes)
    // pour rendre la divergence entre prix brut et prix réel indiscutable.
    $db->execute(
        "INSERT INTO " . _DB_PREFIX_ . "specific_price
            (id_shop, id_shop_group, id_currency, id_country, id_group, id_customer, id_product, id_product_attribute, id_cart, id_specific_price_rule, price, from_quantity, reduction, reduction_tax, reduction_type, `from`, `to`)
         VALUES (0, 0, 0, 0, 0, 0, {$idProduct}, 0, 0, 0, -1, 1, 0.5, 1, 'percentage', '1970-01-01 00:00:00', '0000-00-00 00:00:00')"
    );
    $idSpecificPrice = (int) $db->Insert_ID();

    try {
        \Product::flushPriceCache();

        $mgr = new CollectionManager(neria_test_module());
        $ref = new ReflectionMethod(CollectionManager::class, 'safeProductPrice');
        $ref->setAccessible(true);

        $realPrice = $ref->invoke($mgr, $idProduct, $idShop, 0);
        $rawPrice  = (float) (new Product($idProduct))->price;

        neria_assert(
            $realPrice > 0 && abs($realPrice - $rawPrice) > 0.001,
            "CollectionManager::safeProductPrice() renvoie {$realPrice}, identique (ou proche) au prix catalogue brut {$rawPrice} malgré une réduction de -50% active — régression du bug corrigé le 05/09/2026 (round 305) : {missing_price} redeviendrait résolu via \$product->price brut, sans promo/taxe/groupe tarifaire"
        );

        // Vérification structurelle : processCollection() utilise bien
        // safeProductPrice(), plus $product->price directement pour
        // {missing_price}.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php');
        neria_assert($src !== false, 'Impossible de lire src/CollectionManager.php');
        neria_assert(
            strpos($src, '$productPrice = $this->safeProductPrice($missingId, $idShop, $idCustomer);') !== false,
            "CollectionManager::processCollection() n'utilise plus safeProductPrice() pour {missing_price} — régression du bug corrigé le 05/09/2026 (round 305)"
        );
        neria_assert(
            strpos($src, '$productPrice = (float) $product->price;') === false,
            "CollectionManager::processCollection() résout encore {missing_price} via \$product->price brut quelque part — régression du bug corrigé le 05/09/2026 (round 305)"
        );

        return [
            'pass'    => true,
            'message' => "CollectionManager résout bien {missing_price} via Product::getPriceStatic() (taxe/promo/groupe tarifaire appliqués), plus \$product->price brut — bug corrigé le 05/09/2026 (round 305)",
        ];
    } finally {
        $db->execute("DELETE FROM " . _DB_PREFIX_ . "specific_price WHERE id_specific_price = {$idSpecificPrice}");
        \Product::flushPriceCache();
    }
}
