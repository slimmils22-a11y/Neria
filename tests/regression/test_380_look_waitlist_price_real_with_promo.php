<?php
/**
 * Régression : LookCompletionManager::buildProductBlocks() et
 * WaitlistManager::notifyProductLocked() affichaient $product->price brut
 * (champ ObjectModel catalogue : HT, sans specific_price/promo, sans
 * groupe tarifaire) au lieu du prix réellement en vigueur — un produit en
 * promo affichait son prix plein tarif dans l'email "Complétez votre
 * look"/"de retour en stock", différent de celui affiché sur la fiche
 * produit au clic.
 *
 * Corrigé le 18/08/2026 (round 184) : Product::getPriceStatic() (via une
 * nouvelle méthode privée safeProductPrice(), même pattern que
 * UpsellManager::safeProductPrice()) remplace $product->price dans les
 * deux fichiers.
 *
 * Test structurel (une fixture specific_price réelle nécessiterait un
 * produit catalogue dédié + une règle de prix, montage lourd pour ce seul
 * contrôle, cf. raisonnement de test_206/test_378) : vérifie la présence
 * de safeProductPrice()/getPriceStatic() aux 2 points d'affichage de prix,
 * et l'absence de $product->price brut à ces mêmes points.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $lcmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($lcmSrc !== false, 'Impossible de lire src/LookCompletionManager.php');
    $wlSrc  = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($wlSrc !== false, 'Impossible de lire src/WaitlistManager.php');

    neria_assert(
        // Round 275 : signatures élargies d'un paramètre int $idCurrency = 0.
        strpos($lcmSrc, 'private function safeProductPrice(int $idProduct, int $idShop, int $idCurrency = 0): float') !== false
            && strpos($lcmSrc, '$realPrice = $this->safeProductPrice($pid, $idShop, $idCurrency);') !== false,
        "LookCompletionManager n'utilise plus safeProductPrice()/Product::getPriceStatic() pour le prix affiché — régression du bug corrigé le 18/08/2026 (round 184) : un produit en promo afficherait de nouveau son prix plein tarif dans l'email"
    );
    neria_assert(
        strpos($lcmSrc, "'price' => \NeriaTools::displayPrice((float) \$product->price,") === false,
        "LookCompletionManager utilise de nouveau \$product->price brut pour le prix affiché — régression du bug corrigé le 18/08/2026 (round 184)"
    );

    neria_assert(
        strpos($wlSrc, 'private function safeProductPrice(int $idProduct, int $idShop): float') !== false
            && strpos($wlSrc, "\$this->safeProductPrice(\$idProduct, \$idShop)") !== false,
        "WaitlistManager n'utilise plus safeProductPrice()/Product::getPriceStatic() pour {product_price} — régression du bug corrigé le 18/08/2026 (round 184)"
    );
    neria_assert(
        strpos($wlSrc, "'{product_price}'      => \NeriaTools::displayPrice((float) \$product->price,") === false,
        "WaitlistManager utilise de nouveau \$product->price brut pour {product_price} — régression du bug corrigé le 18/08/2026 (round 184)"
    );

    return [
        'pass'    => true,
        'message' => "LookCompletionManager/WaitlistManager affichent bien le prix réel (taxe + promo appliqués) via Product::getPriceStatic(), plus \$product->price brut — bug corrigé le 18/08/2026 (round 184)",
    ];
}
