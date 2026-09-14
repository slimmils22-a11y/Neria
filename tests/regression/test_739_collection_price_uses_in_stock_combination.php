<?php
/**
 * Régression : CollectionManager::resolveInStockAttributeId() doit
 * retourner la première déclinaison EN STOCK quand la combinaison par
 * défaut du produit est épuisée, pas systématiquement `null` (comportement
 * historique = laisser Product::getPriceStatic() résoudre lui-même la
 * combinaison par défaut, même si elle est en rupture).
 *
 * Bug identifié le 14/09/2026 (round 352, décision produit confirmée le
 * même jour — le module est vendu à de multiples commerçants, un
 * catalogue à fortes variations de stock par déclinaison rencontrerait
 * cette incohérence régulièrement) : la disponibilité globale d'un produit
 * dans une collection est calculée en agrégeant le stock de TOUTES les
 * déclinaisons (round 167/184/215), mais le prix affiché utilisait
 * systématiquement celui de la combinaison PAR DÉFAUT. Un produit dont la
 * combinaison par défaut est épuisée mais dont une autre a du stock
 * passait le test de disponibilité et l'email partait — le client
 * arrivait ensuite sur la fiche produit avec la déclinaison présélectionnée
 * affichée en rupture, incohérent avec l'email.
 *
 * Test comportemental réel : utilise un produit réel à déclinaisons
 * (id_product=1, combinaison par défaut id_product_attribute=1), met
 * temporairement son stock à 0, vérifie que resolveInStockAttributeId()
 * retourne bien une AUTRE déclinaison encore en stock (pas null), puis
 * restaure le stock d'origine dans tous les cas.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $idProduct = 1;

    $defaultAttrId = (int) $db->getValue(
        "SELECT id_product_attribute FROM {$prefix}product_attribute WHERE id_product = {$idProduct} AND default_on = 1"
    );
    neria_assert($defaultAttrId > 0, 'Jeu de test invalide : aucune combinaison par défaut trouvée pour id_product=1');

    $originalQty = $db->getValue(
        "SELECT quantity FROM {$prefix}stock_available
         WHERE id_product = {$idProduct} AND id_product_attribute = {$defaultAttrId} AND id_shop = {$idShop}"
    );
    neria_assert($originalQty !== false, 'Jeu de test invalide : pas de ligne stock_available pour la combinaison par défaut');

    try {
        $db->execute(
            "UPDATE {$prefix}stock_available SET quantity = 0
             WHERE id_product = {$idProduct} AND id_product_attribute = {$defaultAttrId} AND id_shop = {$idShop}"
        );

        $mgr = new CollectionManager(neria_test_module());
        $ref = new ReflectionMethod(CollectionManager::class, 'resolveInStockAttributeId');
        $ref->setAccessible(true);

        $result = $ref->invoke($mgr, $idProduct, $idShop);

        neria_assert(
            $result !== null,
            "resolveInStockAttributeId() retourne null (comportement 'combinaison par défaut') alors que la combinaison par défaut est en rupture — régression du bug corrigé le 14/09/2026 (round 353) : le prix affiché resterait celui d'une déclinaison épuisée"
        );
        neria_assert(
            $result !== $defaultAttrId,
            "resolveInStockAttributeId() retourne encore la combinaison par défaut ({$defaultAttrId}), qui est pourtant à 0 de stock — régression du bug corrigé le 14/09/2026 (round 353)"
        );

        $resultQty = (int) $db->getValue(
            "SELECT quantity FROM {$prefix}stock_available
             WHERE id_product = {$idProduct} AND id_product_attribute = {$result} AND id_shop = {$idShop}"
        );
        neria_assert(
            $resultQty > 0,
            "resolveInStockAttributeId() retourne une déclinaison ({$result}) elle-même sans stock réel (quantity={$resultQty}) — régression du bug corrigé le 14/09/2026 (round 353)"
        );

        return [
            'pass'    => true,
            'message' => "CollectionManager::resolveInStockAttributeId() retourne bien une déclinaison réellement en stock ({$result}) quand la combinaison par défaut ({$defaultAttrId}) est épuisée — bug corrigé le 14/09/2026 (round 353)",
        ];
    } finally {
        $db->execute(
            "UPDATE {$prefix}stock_available SET quantity = " . (int) $originalQty . "
             WHERE id_product = {$idProduct} AND id_product_attribute = {$defaultAttrId} AND id_shop = {$idShop}"
        );
    }
}
