<?php
/**
 * Régression : WaitlistManager::notifyProduct() doit résoudre
 * {product_price} via PS_CURRENCY_DEFAULT scopé par $idShop, pas via
 * Context::getContext()->currency (contexte d'exécution courant) — même
 * piège multi-boutique déjà corrigé au round 103 pour
 * {product_url}/{product_image} et au round 106 pour {shop_name}, dans ce
 * MÊME bloc de variables.
 *
 * Bug réel corrigé le 08/08/2026 (round 109) : {product_price} utilisait
 * Context::getContext()->currency->id, jamais basculé — contrairement à
 * {product_url}/{product_image} (switch temporaire de
 * Context::getContext()->shop, restauré par un finally AVANT que ce bloc de
 * variables ne soit construit) et {shop_name} (déjà scopé par $idShop).
 * hookActionUpdateQuantity() (neria.php) appelle notifyProduct() en boucle
 * sur toutes les boutiques d'un groupe à stock partagé : sur une
 * installation multi-boutiques à devises distinctes, un client de la
 * Boutique B (USD) recevait un email avec un lien produit correctement
 * scopé mais un prix affiché dans la devise de la Boutique A (EUR, celle du
 * contexte BO admin qui a déclenché la mise à jour de stock).
 *
 * Chaîne recherchée mise à jour (round 184) : $product->price a été
 * remplacé par $this->safeProductPrice($idProduct) (prix réel avec
 * taxe/promo, cf. test_380), le scope devise par $idShop est inchangé.
 * Round 292 : safeProductPrice() élargie d'un paramètre $idCustomer.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WaitlistManager.php');

    neria_assert(
        strpos($src, "\\NeriaTools::displayPrice(\$this->safeProductPrice(\$idProduct, \$idShop, \$idCustomer), new \\Currency((int) \\Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$idShop)), \$idLang)") !== false,
        "WaitlistManager::notifyProduct() ne résout plus {product_price} via PS_CURRENCY_DEFAULT scopé par \$idShop — régression du bug corrigé le 08/08/2026 (round 109) : un client d'une autre boutique pourrait de nouveau voir un prix dans la devise du contexte d'exécution courant plutôt que celle de sa boutique"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager::notifyProduct() résout bien {product_price} via la devise de la boutique du client (\$idShop), pas celle du contexte d'exécution courant",
    ];
}
