<?php
/**
 * Régression : LookCompletionManager::buildProductBlocks() doit réellement
 * commuter le contexte boutique statique (Shop::setContext) avant de
 * charger les produits suggérés, pas seulement réassigner Context->shop.
 *
 * Bug réel corrigé le 08/08/2026 : Shop::$context_id_shop (consulté en
 * interne par le constructeur Product, Product::getCover() et
 * Product::isAvailableWhenOutOfStock() pour résoudre l'association
 * product_shop) n'est mis à jour QUE par Shop::setContext() — une simple
 * réassignation de Context->shop ne suffit pas (même piège que
 * CooldownManager/DomainReputationManager, round 129). Sur une
 * installation multi-boutiques, un produit actif sur la boutique du
 * contexte d'exécution du cron mais désactivé/non associé sur la boutique
 * du client passait à tort le test $product->active, ou getCover()
 * résolvait l'image via la mauvaise association de boutique.
 *
 * Test structurel + comportemental : vérifie la présence de
 * Shop::setContext() encadrant buildProductBlocks(), ET que
 * Shop::getContextShopID() est bien restauré après un appel réel.
 *
 * Fenêtre élargie à 5700 (round 184) : le remplacement de
 * StockAvailable::getQuantityAvailableByProduct() par un SUM SQL direct
 * et l'ajout de safeProductPrice() (cf. test_379/test_380) ont repoussé
 * le point de restauration du contexte plus loin dans la méthode.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LookCompletionManager.php');

    // Round 275 : signature élargie d'un paramètre int $idCurrency = 0.
    // Round 292 : élargie à nouveau d'un paramètre int $idCustomer = 0.
    $posMethod = strpos($src, 'private function buildProductBlocks(array $productIds, int $idLang, int $idShop, int $idCurrency = 0, int $idCustomer = 0): array');
    neria_assert($posMethod !== false, 'buildProductBlocks() introuvable — régression du bug corrigé le 08/08/2026');

    $body = substr($src, $posMethod, 6400);

    $posSetContext = strpos($body, 'Shop::setContext(\Shop::CONTEXT_SHOP, $idShop)');
    neria_assert(
        $posSetContext !== false,
        'buildProductBlocks() ne commute plus le contexte boutique statique via Shop::setContext() avant de charger les produits — régression du bug corrigé le 08/08/2026 : Product::getCover()/$product->active redeviendraient résolus via le mauvais id_shop'
    );

    $posRestore = strpos($body, 'Shop::setContext(\Shop::CONTEXT_SHOP, $originalShopId)');
    neria_assert(
        $posRestore !== false && $posRestore > $posSetContext,
        "buildProductBlocks() ne restaure plus le contexte boutique d'origine après la boucle — régression du bug corrigé le 08/08/2026"
    );

    // Vérification comportementale minimale : la commutation réelle du
    // contexte via Shop::setContext() puis sa restauration ne doit pas
    // laisser Shop::$context_id_shop dans un état différent de l'original.
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';
    $reflection = new ReflectionClass('LookCompletionManager');
    neria_assert($reflection->hasMethod('buildProductBlocks'), 'buildProductBlocks() absente de la classe LookCompletionManager');

    $originalShopId = (int) Shop::getContextShopID(true);
    $anyShopId = (int) Db::getInstance()->getValue('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert($anyShopId > 0, 'Aucune boutique active trouvée pour le test comportemental');

    $manager = new LookCompletionManager(neria_test_module());
    $method = $reflection->getMethod('buildProductBlocks');
    $method->setAccessible(true);
    $method->invoke($manager, [], 1, $anyShopId);

    neria_assert(
        (int) Shop::getContextShopID(true) === $originalShopId,
        "buildProductBlocks() a laissé Shop::\$context_id_shop dans un état différent de l'original après exécution — la restauration du contexte a échoué"
    );

    return [
        'pass'    => true,
        'message' => "LookCompletionManager::buildProductBlocks() commute bien le contexte boutique statique via Shop::setContext() et le restaure correctement après usage",
    ];
}
