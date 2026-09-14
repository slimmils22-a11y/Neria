<?php
/**
 * Régression : LookCompletionManager::resolveInStockAttributeId() doit
 * retourner la première déclinaison EN STOCK quand la combinaison par
 * défaut du produit est épuisée, pas systématiquement `null` — même
 * correctif que CollectionManager::resolveInStockAttributeId() (round 353,
 * cf. test_739), répliqué hors round le 14/09/2026 dans LookCompletionManager
 * qui présentait EXACTEMENT le même défaut (identifié par l'agent du round
 * 352 mais non traité dans CollectionManager faute de temps ce round-là).
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
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

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

        $mgr = new LookCompletionManager(neria_test_module());
        $ref = new ReflectionMethod(LookCompletionManager::class, 'resolveInStockAttributeId');
        $ref->setAccessible(true);

        $result = $ref->invoke($mgr, $idProduct, $idShop);

        neria_assert(
            $result !== null,
            "resolveInStockAttributeId() retourne null (comportement 'combinaison par défaut') alors que la combinaison par défaut est en rupture — régression du correctif du 14/09/2026 : le prix affiché resterait celui d'une déclinaison épuisée"
        );
        neria_assert(
            $result !== $defaultAttrId,
            "resolveInStockAttributeId() retourne encore la combinaison par défaut ({$defaultAttrId}), qui est pourtant à 0 de stock — régression du correctif du 14/09/2026"
        );

        $resultQty = (int) $db->getValue(
            "SELECT quantity FROM {$prefix}stock_available
             WHERE id_product = {$idProduct} AND id_product_attribute = {$result} AND id_shop = {$idShop}"
        );
        neria_assert(
            $resultQty > 0,
            "resolveInStockAttributeId() retourne une déclinaison ({$result}) elle-même sans stock réel (quantity={$resultQty}) — régression du correctif du 14/09/2026"
        );

        // La signature de safeProductPrice() doit avoir été étendue et le
        // 3e paramètre de Product::getPriceStatic() doit bien recevoir
        // $idProductAttribute au lieu d'un `null` figé — sinon
        // resolveInStockAttributeId() est calculé mais jamais réellement
        // utilisé pour le prix affiché (correctif silencieusement inerte).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
        neria_assert(
            strpos($src, 'private function safeProductPrice(int $idProduct, int $idShop, int $idCurrency = 0, int $idCustomer = 0, ?int $idProductAttribute = null): float') !== false,
            'safeProductPrice() ne semble plus accepter $idProductAttribute — régression du correctif du 14/09/2026'
        );
        neria_assert(
            strpos($src, '\Product::getPriceStatic($idProduct, true, $idProductAttribute, 2, null, false, true, 1, false,') !== false,
            "Product::getPriceStatic() n'utilise plus \$idProductAttribute comme 3e paramètre — régression du correctif du 14/09/2026"
        );

        return [
            'pass'    => true,
            'message' => "LookCompletionManager::resolveInStockAttributeId() retourne bien une déclinaison réellement en stock ({$result}) quand la combinaison par défaut ({$defaultAttrId}) est épuisée, et safeProductPrice() la transmet réellement à Product::getPriceStatic() — correctif du 14/09/2026 validé",
        ];
    } finally {
        $db->execute(
            "UPDATE {$prefix}stock_available SET quantity = " . (int) $originalQty . "
             WHERE id_product = {$idProduct} AND id_product_attribute = {$defaultAttrId} AND id_shop = {$idShop}"
        );
    }
}
