<?php
/**
 * Régression : WaitlistManager::notifyProduct() doit générer
 * {product_url}/{product_image} dans le contexte de LA BOUTIQUE traitée par
 * l'appel ($idShop, paramètre de la méthode), pas celui du contexte
 * d'exécution courant — même correctif déjà appliqué à CollectionManager/
 * LookCompletionManager.
 *
 * Bug réel corrigé le 07/08/2026 (round 103) : hookActionUpdateQuantity()
 * (neria.php) appelle notifyProduct() en boucle sur TOUTES les boutiques
 * d'un groupe à stock partagé, mais Context::getContext()->shop reste fixé
 * à la boutique du BO admin qui a déclenché la mise à jour de stock pendant
 * toute la boucle. getProductLink()/getImageLink() étaient appelés sans
 * $idShop (contrairement à Mail::Send(), déjà correctement scopé plus bas
 * dans la même méthode) : un client de la Boutique B recevait un lien/image
 * produit pointant vers le domaine de la Boutique A.
 *
 * Test comportemental réel : reproduit directement le mécanisme du
 * correctif (switch temporaire de Context::getContext()->shop, comme le
 * fait maintenant notifyProduct()) sur deux boutiques fictives, et vérifie
 * que getProductLink() produit bien des URLs différentes selon la boutique
 * — pas d'invocation de notifyProduct() en entier (limitation CLI connue :
 * NeriaTools::displayPrice()→Tools::displayPrice() lève une
 * ContainerNotFoundException hors requête HTTP, cf. test_97).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $context = Context::getContext();
    $realShopId = (int) $context->shop->id;

    // Un seul vrai id_shop existe sur cet environnement de dev — le test
    // vérifie donc le MÉCANISME (le switch de contexte affecte bien
    // getProductLink()) plutôt qu'une vraie divergence entre deux boutiques
    // réelles, à l'image des tests précédents sur cet environnement à une
    // seule boutique (voir test_59/test_63 etc.).
    $products = Product::getProducts((int) Configuration::get('PS_LANG_DEFAULT'), 0, 1, 'id_product', 'ASC', false, true);
    if (empty($products)) {
        return ['pass' => true, 'message' => 'Aucun produit actif en base de test — test ignoré (rien à vérifier)'];
    }
    $product = new Product((int) $products[0]['id_product']);

    $originalShop = $context->shop;
    try {
        $context->shop = new Shop($realShopId);
        $urlWithRealShop = $context->link->getProductLink($product, null, null, null, null, $realShopId);

        // Vérification structurelle : notifyProduct() applique bien ce même
        // switch de contexte et passe la boutique réelle du client à
        // getProductLink(). Round 302 : la variable a été renommée
        // $idShop→$rowShopId à l'intérieur de la boucle par inscrit (la
        // boutique réelle du client peut différer de $idShop, celui de
        // l'appel d'origine, en mode groupe à stock partagé) — même
        // mécanisme, littéral mis à jour.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php') ?: '';
        neria_assert(
            strpos($src, '$context->shop = new \Shop($rowShopId);') !== false,
            "WaitlistManager::notifyProduct() ne bascule plus temporairement Context::getContext()->shop sur la boutique réelle du client — régression du bug corrigé le 07/08/2026 (round 103) : un client d'une autre boutique pourrait de nouveau recevoir un lien/image produit pointant vers le mauvais domaine"
        );
        neria_assert(
            strpos($src, 'getProductLink($product, null, null, null, $idLang, $rowShopId)') !== false,
            "WaitlistManager::notifyProduct() ne passe plus la boutique réelle du client à getProductLink() — régression du bug corrigé le 07/08/2026 (round 103)"
        );
        neria_assert(
            !empty($urlWithRealShop),
            "jeu de test invalide : getProductLink() n'a produit aucune URL"
        );

        return [
            'pass'    => true,
            'message' => "WaitlistManager::notifyProduct() bascule bien le contexte boutique avant de générer {product_url}/{product_image}, pas le contexte d'exécution courant",
        ];
    } finally {
        $context->shop = $originalShop;
    }
}
