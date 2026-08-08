<?php
/**
 * Régression : BehavioralCronManager::sendGhostCarts() doit instancier
 * Product avec le 4e argument $idShop ET commuter le contexte boutique
 * statique via Shop::setContext() avant Product::getCover(), pas seulement
 * réassigner Context->shop.
 *
 * Bug réel corrigé le 08/08/2026 (round 132) : Shop::$context_id_shop
 * (consulté en interne par le constructeur Product et par
 * Shop::addSqlAssociation(), utilisée par Product::getCover()) n'est mis à
 * jour QUE par Shop::setContext() — la réassignation de Context->shop dans
 * la boucle multi-boutique de BehavioralCronManager::run() ne suffit pas
 * (même piège que CooldownManager/DomainReputationManager round 129,
 * LookCompletionManager round 131). Sur une install multi-boutiques avec
 * des catalogues différents par boutique, l'email "panier fantôme" pouvait
 * afficher le nom/description/image du produit tel que catalogué dans une
 * AUTRE boutique que celle du client concerné.
 *
 * Test structurel : vérifie la présence de Shop::setContext() encadrant
 * le bloc Product/getCover() dans sendGhostCarts(), et que le constructeur
 * Product reçoit bien le 4e argument $idShop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');

    $posMethod = strpos($src, 'private function sendGhostCarts(');
    neria_assert($posMethod !== false, 'sendGhostCarts() introuvable — régression du bug corrigé le 08/08/2026');

    $body = substr($src, $posMethod, 4200);

    $posCtor = strpos($body, 'new \Product($idProduct, false, $idLang, $ghostShopId)');
    neria_assert(
        $posCtor !== false,
        "sendGhostCarts() n'instancie plus Product avec le 4e argument \$idShop explicite — régression du bug corrigé le 08/08/2026 (round 132) : le nom/description/link_rewrite pourraient de nouveau provenir de la mauvaise boutique"
    );

    $posSetContext = strpos($body, 'Shop::setContext(\Shop::CONTEXT_SHOP, $ghostShopId)');
    neria_assert(
        $posSetContext !== false && $posSetContext < $posCtor,
        "sendGhostCarts() ne commute plus le contexte boutique statique via Shop::setContext() avant d'instancier Product/appeler getCover() — régression du bug corrigé le 08/08/2026 (round 132)"
    );

    $posRestore = strpos($body, 'Shop::setContext(\Shop::CONTEXT_SHOP, $originalGhostShopId)');
    neria_assert(
        $posRestore !== false && $posRestore > $posCtor,
        "sendGhostCarts() ne restaure plus le contexte boutique d'origine après le bloc Product/getCover() — régression du bug corrigé le 08/08/2026 (round 132)"
    );

    $posCover = strpos($body, 'Product::getCover($idProduct)');
    neria_assert(
        $posCover !== false && $posCover > $posSetContext && $posCover < $posRestore,
        "sendGhostCarts() : Product::getCover() n'est plus appelé à l'intérieur du bloc de contexte boutique commuté — régression du bug corrigé le 08/08/2026 (round 132)"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::sendGhostCarts() instancie bien Product avec l'idShop explicite et commute le contexte boutique statique via Shop::setContext() avant Product::getCover()",
    ];
}
