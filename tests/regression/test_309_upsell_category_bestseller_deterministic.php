<?php
/**
 * Régression : UpsellManager::findByCategoryBestseller() n'avait pas de
 * GROUP BY, contrairement à findByAccessories()/findByCoPurchase() — un
 * produit appartenant à plusieurs des catégories candidates (JOIN
 * category_product) apparaissait en plusieurs lignes, rendant l'ORDER BY
 * non déterministe entre elles à égalité de ventes : getRow() pouvait
 * renvoyer tantôt la ligne catégorie A tantôt B pour le même appel
 * logique — l'étiquette catégorie affichée dans l'email upsell variait
 * sans raison fonctionnelle pour un même produit.
 *
 * Corrigé le 14/08/2026 (round 167) : GROUP BY p.id_product + MIN(pl.name)/
 * MIN(cp.id_category) (même pattern que findByAccessories()), plus un
 * tie-breaker p.id_product ASC pour un ordre totalement déterministe
 * même entre produits différents à égalité de ventes.
 *
 * Test structurel : vérifie la présence du GROUP BY et du pattern MIN().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($src !== false, 'Impossible de lire UpsellManager.php');

    $posFn = strpos($src, 'category_product` cp');
    neria_assert($posFn !== false, 'Requête de findByCategoryBestseller() introuvable — jeu de test invalide');
    $body = substr($src, max(0, $posFn - 600), 1900);

    neria_assert(
        strpos($body, 'MIN(pl.name) AS name, MIN(cp.id_category) AS id_category') !== false,
        "findByCategoryBestseller() n'utilise plus MIN(pl.name)/MIN(cp.id_category) — régression du bug corrigé le 14/08/2026 (round 167)"
    );
    neria_assert(
        strpos($body, 'GROUP BY p.id_product') !== false,
        "findByCategoryBestseller() n'a plus de GROUP BY — régression du bug corrigé le 14/08/2026 (round 167) : un produit multi-catégories redeviendrait affiché avec une catégorie non déterministe d'un appel à l'autre"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager::findByCategoryBestseller() est bien déterministe (GROUP BY + MIN()) pour un produit appartenant à plusieurs catégories candidates — bug corrigé le 14/08/2026 (round 167)",
    ];
}
