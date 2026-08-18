<?php
/**
 * Régression : WaitlistManager::notifyProduct() doit réellement commuter
 * le contexte boutique statique via Shop::setContext() (pas seulement
 * Context->shop) avant le constructeur Product et Product::getCover().
 *
 * Bug réel corrigé le 08/08/2026 (round 138) : le commentaire prétendait
 * appliquer "le même correctif que CollectionManager/LookCompletionManager",
 * mais ne faisait en réalité que réassigner Context->shop (version
 * PARTIELLE) — Product::getCover() et le constructeur Product résolvent
 * leurs données via le contexte boutique STATIQUE (Shop::$context_id_shop),
 * jamais mis à jour par une simple réassignation. Round 137 avait déjà
 * révélé que CollectionManager, cité ici comme référence, n'avait
 * lui-même jamais reçu la version complète — même défaut ici.
 * hookActionUpdateQuantity() boucle sur toutes les boutiques d'un stock
 * partagé ; sans le switch complet, un client de la Boutique B recevait
 * le nom/prix/image/lien produit de la Boutique A.
 *
 * Test comportemental réel : la commutation via Shop::setContext() puis
 * sa restauration ne doit pas laisser Shop::$context_id_shop dans un état
 * différent de l'original.
 *
 * Fenêtre élargie à 9300 (round 184) : l'ajout de safeProductPrice() et
 * du garde-fou !$product->active a repoussé le point d'instanciation
 * Product plus loin dans la méthode.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php';

    $originalShopId = (int) Shop::getContextShopID(true);
    $anyShopId = (int) Db::getInstance()->getValue('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert($anyShopId > 0, 'Aucune boutique active trouvée pour le test');

    $mgr = new WaitlistManager(neria_test_module());
    $method = new ReflectionMethod(WaitlistManager::class, 'notifyProduct');
    $method->setAccessible(true);
    // id_product=0, aucune ligne d'attente ne matchera — juste vérifier
    // que la commutation de contexte est propre même sans traitement réel.
    $method->invoke($mgr, 0, $anyShopId);

    neria_assert(
        (int) Shop::getContextShopID(true) === $originalShopId,
        "WaitlistManager::notifyProduct() a laissé Shop::\$context_id_shop dans un état différent de l'original — la restauration du contexte a échoué, régression du bug corrigé le 08/08/2026 (round 138)"
    );

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de relire src/WaitlistManager.php');

    $posMethod = strpos($src, 'public function notifyProduct(');
    neria_assert($posMethod !== false, 'notifyProduct() introuvable');
    $body = substr($src, $posMethod, 9300);
    neria_assert(
        strpos($body, 'Shop::setContext(\Shop::CONTEXT_SHOP, $idShop)') !== false,
        "notifyProduct() ne commute plus le contexte boutique statique via Shop::setContext() — régression du bug corrigé le 08/08/2026 (round 138)"
    );
    neria_assert(
        strpos($body, 'new \Product($idProduct, false, $idLang, $idShop)') !== false,
        "notifyProduct() n'instancie plus Product avec l'idShop explicite — régression du bug corrigé le 08/08/2026 (round 138)"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager::notifyProduct() commute bien le contexte boutique statique via Shop::setContext() et le restaure correctement, et Product est instancié avec l'idShop explicite",
    ];
}
