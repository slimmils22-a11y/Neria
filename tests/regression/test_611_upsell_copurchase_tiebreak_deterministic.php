<?php
/**
 * Régression : UpsellManager::findByCoPurchase() triait ses candidats
 * uniquement `ORDER BY freq DESC` — sans tie-break, MySQL ne garantit
 * aucun ordre stable entre lignes à égalité de fréquence de co-achat, donc
 * getRow() (qui renvoie la 1re ligne du jeu de résultats) pouvait retourner
 * un produit différent d'un appel à l'autre pour la même commande, sans
 * raison fonctionnelle. Les deux méthodes sœurs du même fichier
 * (findByAccessories() : `ORDER BY p.id_product ASC`, et
 * findByCategoryBestseller() : tie-break déjà corrigé au round 167
 * précisément pour cette même raison) départagent pourtant déjà
 * correctement leurs égalités — seule findByCoPurchase() ne le faisait
 * pas.
 *
 * Corrigé le 07/09/2026 (round 314) : tie-break `od2.product_id ASC`
 * ajouté après `freq DESC`.
 *
 * Vérification structurelle ciblée (une égalité de fréquence réaliste et
 * reproductible nécessiterait de fabriquer des commandes/order_detail/stock
 * factices sur plusieurs produits réels — fragile et hors de proportion
 * pour un correctif de déterminisme pur SQL) : confirme que le tie-break
 * est bien présent dans la clause ORDER BY du corps de la méthode.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($src !== false, 'Impossible de lire src/UpsellManager.php');

    $posFn = strpos($src, 'private function findByCoPurchase(array $productIds, array $excluded, int $idLang, ?int $idShop = null): ?array');
    neria_assert($posFn !== false, 'findByCoPurchase() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 3200);

    neria_assert(
        strpos($body, 'ORDER BY freq DESC, od2.product_id ASC') !== false,
        "UpsellManager::findByCoPurchase() ne départage plus les égalités de fréquence via od2.product_id ASC — régression du bug corrigé le 07/09/2026 (round 314) : le produit suggéré en 'Souvent acheté ensemble' pourrait de nouveau varier de façon non déterministe entre deux appels identiques"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager::findByCoPurchase() départage bien ses égalités de fréquence de co-achat via un tie-break déterministe (od2.product_id ASC), cohérent avec findByAccessories()/findByCategoryBestseller() — bug corrigé le 07/09/2026 (round 314)",
    ];
}
