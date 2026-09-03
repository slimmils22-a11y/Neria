<?php
/**
 * Régression : CollectionManager::resolveProducts() doit filtrer par
 * boutique (pl.id_shop) et p.active, comme sa jumelle searchProducts().
 *
 * Bug réel corrigé le 09/08/2026 (round 143) : resolveProducts() ne
 * recevait même pas de paramètre $idShop et joignait product_lang sans
 * filtre id_shop — en environnement multi-boutiques, la jointure pouvait
 * renvoyer plusieurs lignes par id_product (une par boutique quand les
 * traductions diffèrent), et $byId[] indexait arbitrairement la dernière
 * lue selon l'ordre MySQL : le nom affiché dans l'écran BO "Collections"
 * d'une boutique pouvait être celui d'une AUTRE boutique.
 *
 * Test comportemental réel : insère une ligne product_lang supplémentaire
 * pour un produit réel sur une boutique "étrangère" simulée (id_shop
 * courant + 1000) avec un nom différent, vérifie que resolveProducts()
 * pour la boutique courante renvoie bien le nom RÉEL de cette boutique, pas
 * celui de la boutique étrangère.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $idShopOwn   = (int) \Context::getContext()->shop->id;
    $idShopOther = $idShopOwn + 1000;

    $product = $db->getRow("SELECT id_product FROM {$prefix}product WHERE active = 1");
    neria_assert($product !== false, 'Aucun produit actif disponible pour ce test — jeu de test invalide');
    $idProduct = (int) $product['id_product'];

    $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

    $ownRow = $db->getRow(
        "SELECT name FROM {$prefix}product_lang WHERE id_product = {$idProduct} AND id_shop = {$idShopOwn} AND id_lang = {$idLang}"
    );
    neria_assert($ownRow !== false, "Pas de ligne product_lang pour la boutique courante — jeu de test invalide");
    $ownName = $ownRow['name'];

    $ref = new ReflectionMethod(CollectionManager::class, 'resolveProducts');
    $ref->setAccessible(true);

    try {
        // Insère une ligne "boutique étrangère" avec un nom bien distinct
        $db->execute(
            "INSERT INTO {$prefix}product_lang
                (id_product, id_shop, id_lang, description, description_short, link_rewrite, name)
             VALUES ({$idProduct}, {$idShopOther}, {$idLang}, '', '', 'test-round143', 'NOM_BOUTIQUE_ETRANGERE_ROUND143')"
        );

        $result = $ref->invoke(null, [$idProduct], $idLang, $idShopOwn);
        neria_assert(!empty($result), "resolveProducts() n'a renvoyé aucun résultat — jeu de test invalide");

        $resolvedName = $result[0]['name'] ?? null;
        neria_assert(
            $resolvedName !== 'NOM_BOUTIQUE_ETRANGERE_ROUND143',
            "resolveProducts(idShop={$idShopOwn}) a renvoyé le nom de la boutique étrangère — régression du bug corrigé le 09/08/2026 (round 143) : resolveProducts() n'est plus scopé par boutique"
        );
        neria_assert(
            $resolvedName === $ownName,
            "resolveProducts(idShop={$idShopOwn}) a renvoyé '{$resolvedName}' au lieu du nom réel de la boutique courante ('{$ownName}')"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}product_lang WHERE id_product = {$idProduct} AND id_shop = {$idShopOther}");
    }

    return [
        'pass'    => true,
        'message' => "CollectionManager::resolveProducts() est bien scopé par boutique — le nom résolu correspond à la boutique demandée, pas à une autre",
    ];
}
