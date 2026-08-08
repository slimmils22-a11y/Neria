<?php
/**
 * Régression : LookCompletionManager::getOrderCategoryIds() doit exclure
 * id_category_default = 0 en plus de NULL — un produit mal configuré côté
 * catalogue (id_category_default corrompu à 0 plutôt qu'à NULL) ne doit
 * pas polluer la liste FIELD() utilisée par findMatchingRule() et
 * potentiellement faire correspondre à tort une règle sur la catégorie 0.
 *
 * Bug réel corrigé le 08/08/2026 (round 131, reporté au round 132) : le
 * filtre `p.id_category_default IS NOT NULL` (round antérieur) n'excluait
 * pas la valeur 0, un autre cas de donnée catalogue corrompue possible.
 *
 * Test structurel (déclencher une vraie commande avec un produit
 * id_category_default=0 corromprait les données de test partagées) :
 * vérifie la présence du filtre `!= 0` en plus du filtre NULL existant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LookCompletionManager.php');

    $posMethod = strpos($src, 'private function getOrderCategoryIds(int $idOrder): array');
    neria_assert($posMethod !== false, 'getOrderCategoryIds() introuvable — régression du bug corrigé le 08/08/2026');

    $body = substr($src, $posMethod, 1300);

    neria_assert(
        strpos($body, 'id_category_default IS NOT NULL') !== false,
        "getOrderCategoryIds() ne filtre plus id_category_default IS NOT NULL — régression d'un correctif antérieur"
    );
    neria_assert(
        strpos($body, 'id_category_default != 0') !== false,
        "getOrderCategoryIds() ne filtre plus id_category_default != 0 — régression du bug corrigé le 08/08/2026 (round 132) : une catégorie 0 (donnée catalogue corrompue) pourrait de nouveau polluer la liste FIELD() de findMatchingRule()"
    );

    return [
        'pass'    => true,
        'message' => "LookCompletionManager::getOrderCategoryIds() exclut bien id_category_default = 0 en plus de NULL, protégeant findMatchingRule() contre une donnée catalogue corrompue",
    ];
}
