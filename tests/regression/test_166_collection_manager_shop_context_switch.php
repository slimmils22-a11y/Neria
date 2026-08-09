<?php
/**
 * Régression : CollectionManager::getProductImageUrl() doit réellement
 * commuter le contexte boutique statique via Shop::setContext() (pas
 * seulement Context->shop) avant Product::getCover(), et le produit
 * "manquant" de runDailyCheck() doit être instancié avec l'idShop
 * explicite.
 *
 * Bug réel corrigé le 08/08/2026 (round 137) : bien que plusieurs autres
 * fichiers du projet citent "même correctif que
 * CollectionManager::processCollection()" dans leurs commentaires,
 * CollectionManager lui-même n'avait JAMAIS reçu la version complète du
 * correctif Shop::setContext() — resté à la réassignation partielle de
 * Context->shop, insuffisante pour Product::getCover() (qui résout via
 * Shop::addSqlAssociation(), lui-même basé sur le contexte statique
 * Shop::$context_id_shop, jamais mis à jour par une simple réassignation).
 *
 * Test comportemental réel : la commutation réelle du contexte via
 * Shop::setContext() puis sa restauration ne doit pas laisser
 * Shop::$context_id_shop dans un état différent de l'original.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $originalShopId = (int) Shop::getContextShopID(true);
    $anyShopId = (int) Db::getInstance()->getValue('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert($anyShopId > 0, 'Aucune boutique active trouvée pour le test');

    $mgr = new CollectionManager(neria_test_module());
    $method = new ReflectionMethod(CollectionManager::class, 'getProductImageUrl');
    $method->setAccessible(true);
    $method->invoke($mgr, 0, 1, $anyShopId);

    neria_assert(
        (int) Shop::getContextShopID(true) === $originalShopId,
        "CollectionManager::getProductImageUrl() a laissé Shop::\$context_id_shop dans un état différent de l'original après exécution — la restauration du contexte a échoué, régression du bug corrigé le 08/08/2026 (round 137)"
    );

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php');
    neria_assert($src !== false, 'Impossible de relire src/CollectionManager.php');

    $posMethod = strpos($src, 'private function getProductImageUrl(int $idProduct, int $idLang, int $idShop): string');
    neria_assert($posMethod !== false, 'getProductImageUrl() introuvable');
    $body = substr($src, $posMethod, 1600);
    neria_assert(
        strpos($body, 'Shop::setContext(\Shop::CONTEXT_SHOP, $idShop)') !== false,
        "getProductImageUrl() ne commute plus le contexte boutique statique via Shop::setContext() avant Product::getCover() — régression du bug corrigé le 08/08/2026 (round 137)"
    );

    neria_assert(
        strpos($src, 'new \Product($missingId, false, $idLang, $idShop)') !== false,
        "runDailyCheck() n'instancie plus Product avec l'idShop explicite pour le produit manquant — régression du bug corrigé le 08/08/2026 (round 137) : \$product->active pourrait de nouveau refléter le statut d'une autre boutique"
    );

    return [
        'pass'    => true,
        'message' => "CollectionManager::getProductImageUrl() commute bien le contexte boutique statique via Shop::setContext() et le restaure correctement, et le produit manquant est instancié avec l'idShop explicite",
    ];
}
