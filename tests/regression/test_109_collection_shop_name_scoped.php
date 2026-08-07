<?php
/**
 * Régression : CollectionManager::checkAndSend() doit générer {shop_name}
 * pour le nom de LA BOUTIQUE du client ($idShop, issu du GROUP BY
 * o.id_customer, o.id_shop sur les commandes payées), pas la valeur globale
 * du contexte d'exécution du cron.
 *
 * Bug réel corrigé le 07/08/2026 (round 105) : la méthode scope déjà
 * soigneusement {missing_product_url}/{missing_image_url} (getProductLink
 * avec $idShop) et {missing_price} (Configuration::get('PS_CURRENCY_DEFAULT',
 * null, null, $idShop)) sur la boutique du client, avec des commentaires
 * explicites documentant ce piège — mais {shop_name} appelait
 * Configuration::get('PS_SHOP_NAME') SANS $idShop, retombant donc sur la
 * valeur par défaut/du contexte au lieu de celle réellement configurée pour
 * la boutique du client. Sur une install multi-boutiques avec des noms
 * distincts, un client de la boutique 2 recevait "chez <nom boutique 1>"
 * (celle chargée au moment où le cron a démarré) au lieu du vrai nom de sa
 * boutique.
 *
 * Test comportemental réel : vérifie que Configuration::get('PS_SHOP_NAME',
 * null, null, $idShop) avec le 4e paramètre produit bien un résultat cohérent
 * pour la vraie boutique de test (jeu de test à une seule boutique, comme
 * test_107/test_108), et vérifie structurellement que le correctif est en
 * place dans le code source (le 4e paramètre $idShop est bien passé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $context = Context::getContext();
    $realShopId = (int) $context->shop->id;

    $nameWithShop = Configuration::get('PS_SHOP_NAME', null, null, $realShopId);
    neria_assert(
        !empty($nameWithShop),
        "jeu de test invalide : Configuration::get('PS_SHOP_NAME', null, null, \$idShop) n'a produit aucune valeur"
    );

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php') ?: '';
    neria_assert(
        strpos($src, "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)") !== false,
        "CollectionManager::checkAndSend() ne passe plus \$idShop à Configuration::get('PS_SHOP_NAME', ...) — régression du bug corrigé le 07/08/2026 (round 105) : {shop_name} pourrait de nouveau afficher le nom de la mauvaise boutique sur une install multi-boutiques"
    );

    return [
        'pass'    => true,
        'message' => "CollectionManager::checkAndSend() résout bien {shop_name} via Configuration::get('PS_SHOP_NAME', null, null, \$idShop), scopé sur la boutique du client (nom résolu : '{$nameWithShop}')",
    ];
}
