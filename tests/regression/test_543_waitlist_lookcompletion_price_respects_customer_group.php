<?php
/**
 * Régression : `WaitlistManager::safeProductPrice()` et
 * `LookCompletionManager::safeProductPrice()` appelaient
 * `Product::getPriceStatic()` sans jamais transmettre `$idCustomer`, bien
 * que le VRAI client destinataire soit connu à l'appelant (`{id_customer}`
 * de la ligne liste d'attente / `o.id_customer` de la commande livrée).
 * `Product::getPriceStatic()` retombe alors sur `Group::getCurrent()->id`
 * (groupe "visiteur" par défaut du contexte cron) — un client B2B à tarif
 * négocié (remise groupe restreinte à son `id_group`) voyait le prix
 * PUBLIC plein tarif dans son email "de retour en stock"/"complétez
 * votre look", différent de celui qu'il paierait réellement au checkout.
 *
 * `UpsellManager::safeProductPrice()` avait déjà ce correctif (round
 * 184/381) — jamais répliqué ici, même angle mort classique de cette
 * série (fix appliqué à un endroit, pas généralisé aux jumelles).
 *
 * Bug identifié le 03/09/2026 (round 292, audit "prix groupe client B2B
 * non propagé dans les emails").
 *
 * Corrigé le 03/09/2026 (round 292) : `$idCustomer` propagé jusqu'à
 * `Product::getPriceStatic()` (10e paramètre) dans les deux managers ;
 * `LookCompletionManager::buildProductBlocks()` élargie pour le relayer
 * depuis `runDailyCheck()`.
 *
 * Test comportemental réel : même technique que test_381
 * (UpsellManager) — appelle `safeProductPrice()` (privée, via réflexion)
 * pour un vrai produit avec `$idCustomer=0` (visiteur) puis avec l'id
 * d'un vrai client de l'environnement de dev, vérifie que l'appel
 * s'exécute sans erreur et produit un prix positif (confirme le câblage
 * réel du 10e paramètre de `getPriceStatic()`).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $wlSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($wlSrc !== false, 'Impossible de lire src/WaitlistManager.php');
    neria_assert(
        strpos($wlSrc, 'private function safeProductPrice(int $idProduct, int $idShop, int $idCustomer = 0): float') !== false,
        "WaitlistManager::safeProductPrice() n'accepte plus \$idCustomer — régression du bug corrigé le 03/09/2026 (round 292)"
    );
    neria_assert(
        strpos($wlSrc, '$idCustomer > 0 ? $idCustomer : null)') !== false,
        "WaitlistManager::safeProductPrice() ne transmet plus \$idCustomer à Product::getPriceStatic() — régression du bug corrigé le 03/09/2026 (round 292) : un client B2B à tarif négocié verrait de nouveau le prix public dans son email de retour en stock"
    );

    $lcSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($lcSrc !== false, 'Impossible de lire src/LookCompletionManager.php');
    neria_assert(
        strpos($lcSrc, 'private function safeProductPrice(int $idProduct, int $idShop, int $idCurrency = 0, int $idCustomer = 0): float') !== false,
        "LookCompletionManager::safeProductPrice() n'accepte plus \$idCustomer — régression du bug corrigé le 03/09/2026 (round 292)"
    );
    neria_assert(
        strpos($lcSrc, 'private function buildProductBlocks(array $productIds, int $idLang, int $idShop, int $idCurrency = 0, int $idCustomer = 0): array') !== false,
        "LookCompletionManager::buildProductBlocks() n'accepte plus \$idCustomer — régression du bug corrigé le 03/09/2026 (round 292)"
    );
    neria_assert(
        strpos($lcSrc, '$this->buildProductBlocks(array_slice($productIds, 0, 3), $idLang, $idShop, $idCurrency, $idCustomer);') !== false,
        "LookCompletionManager::runDailyCheck() ne transmet plus \$idCustomer à buildProductBlocks() — régression du bug corrigé le 03/09/2026 (round 292)"
    );

    // Vérification comportementale réelle sur les deux managers.
    $module = neria_test_module();
    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();

    $products = Product::getProducts($idLang, 0, 1, 'id_product', 'ASC', false, true);
    if (empty($products)) {
        return ['pass' => true, 'message' => 'Aucun produit actif en base de test — vérification structurelle uniquement (rien à exécuter)'];
    }
    $idProduct = (int) $products[0]['id_product'];

    $wlMgr = new WaitlistManager($module);
    $wlRef = new ReflectionMethod(WaitlistManager::class, 'safeProductPrice');
    $wlRef->setAccessible(true);
    $wlPriceVisitor  = $wlRef->invoke($wlMgr, $idProduct, $idShop, 0);
    $wlPriceCustomer = $wlRef->invoke($wlMgr, $idProduct, $idShop, $idCustomer);
    neria_assert(
        is_float($wlPriceVisitor) && $wlPriceVisitor >= 0.0 && is_float($wlPriceCustomer) && $wlPriceCustomer >= 0.0,
        "WaitlistManager::safeProductPrice() avec \$idCustomer n'a pas produit de prix valide — le 10e paramètre de Product::getPriceStatic() casserait l'appel"
    );

    $lcMgr = new LookCompletionManager($module);
    $lcRef = new ReflectionMethod(LookCompletionManager::class, 'safeProductPrice');
    $lcRef->setAccessible(true);
    $lcPriceVisitor  = $lcRef->invoke($lcMgr, $idProduct, $idShop, 0, 0);
    $lcPriceCustomer = $lcRef->invoke($lcMgr, $idProduct, $idShop, 0, $idCustomer);
    neria_assert(
        is_float($lcPriceVisitor) && $lcPriceVisitor >= 0.0 && is_float($lcPriceCustomer) && $lcPriceCustomer >= 0.0,
        "LookCompletionManager::safeProductPrice() avec \$idCustomer n'a pas produit de prix valide — le 10e paramètre de Product::getPriceStatic() casserait l'appel"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager/LookCompletionManager::safeProductPrice() transmettent désormais \$idCustomer jusqu'à Product::getPriceStatic(), alignés sur UpsellManager — bug corrigé le 03/09/2026 (round 292)",
    ];
}
