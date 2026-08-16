<?php
/**
 * Régression : UpsellManager::findByCoPurchase()/findByCategoryBestseller()
 * scopaient bien le STOCK par boutique (sa.id_shop), mais le classement par
 * POPULARITÉ (fréquence de co-achat / nombre de ventes pour départager les
 * bestsellers) agrégeait order_detail de TOUTES les boutiques sans filtre
 * id_shop. Un produit populaire uniquement chez une boutique B2B pouvait
 * ainsi être poussé comme « souvent acheté ensemble » ou bestseller à un
 * client d'une tout autre boutique B2C — incohérence de scope entre deux
 * requêtes liées du même algorithme.
 *
 * Corrigé le 16/08/2026 (round 178) : order_detail a sa propre colonne
 * id_shop (pas besoin de JOIN orders) — filtre ajouté sur le COUNT(*) de
 * fréquence (findByCoPurchase) et sur le sous-COUNT(*) de ventes
 * (findByCategoryBestseller).
 *
 * Test structurel : vérifie que les deux requêtes filtrent bien par
 * id_shop sur order_detail — une vraie divergence de classement entre 2
 * boutiques distinctes n'est pas reproductible de façon fiable dans cette
 * suite mono-boutique (nécessiterait un historique de commandes réel sur 2
 * boutiques avec des popularités opposées).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($src !== false, 'Impossible de lire src/UpsellManager.php');

    $posCoPurchase = strpos($src, 'private function findByCoPurchase(');
    neria_assert($posCoPurchase !== false, "findByCoPurchase() introuvable — jeu de test invalide");
    $coPurchaseBody = substr($src, $posCoPurchase, 1500);
    neria_assert(
        strpos($coPurchaseBody, 'od2.id_shop = ' . '\' . (int) $idShop') !== false || strpos($coPurchaseBody, "od2.id_shop = ' . (int) \$idShop") !== false,
        "UpsellManager::findByCoPurchase() ne filtre plus le classement de popularité (COUNT(*) AS freq) par id_shop — régression du bug corrigé le 16/08/2026 (round 178) : un produit populaire uniquement sur une autre boutique pourrait de nouveau être recommandé à tort comme 'souvent acheté ensemble'"
    );

    $posBestseller = strpos($src, 'private function findByCategoryBestseller(');
    neria_assert($posBestseller !== false, "findByCategoryBestseller() introuvable — jeu de test invalide");
    $bestsellerBody = substr($src, $posBestseller, 1500);
    neria_assert(
        strpos($bestsellerBody, "od.id_shop = ' . (int) \$idShop") !== false,
        "UpsellManager::findByCategoryBestseller() ne filtre plus le classement de ventes par id_shop — régression du bug corrigé le 16/08/2026 (round 178) : un produit bestseller uniquement sur une autre boutique pourrait de nouveau être recommandé à tort"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager::findByCoPurchase()/findByCategoryBestseller() scopent bien leur classement de popularité par id_shop, cohérent avec le scoping déjà appliqué au stock — bug corrigé le 16/08/2026 (round 178)",
    ];
}
