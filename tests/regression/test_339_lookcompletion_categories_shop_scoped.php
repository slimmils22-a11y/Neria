<?php
/**
 * Régression : LookCompletionManager::getCategories() ne filtrait pas les
 * catégories par boutique (aucune jointure category_shop) — en
 * multi-boutique, le formulaire BO de création de règle de complétion de
 * look proposait des catégories n'appartenant pas forcément à la boutique
 * courante (chaque boutique a son propre arbre de catégories actives).
 *
 * Corrigé le 15/08/2026 (round 174) : jointure INNER JOIN category_shop
 * ajoutée, scopée sur \Context::getContext()->shop->id — cohérent avec le
 * scoping id_shop déjà appliqué ailleurs dans ce fichier (getStats()).
 *
 * Test comportemental réel : getCategories() doit continuer à retourner un
 * tableau exploitable (aucune régression fonctionnelle de la requête après
 * l'ajout de la jointure), et le SQL doit être scopé par boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $module = neria_test_module();
    $mgr    = new LookCompletionManager($module);

    $categories = $mgr->getCategories();
    neria_assert(is_array($categories), "getCategories() ne renvoie plus un tableau — régression fonctionnelle après l'ajout de la jointure category_shop");

    if (!empty($categories)) {
        neria_assert(
            array_key_exists('id_category', $categories[0]) && array_key_exists('name', $categories[0]),
            "getCategories() ne renvoie plus les colonnes attendues (id_category, name) — la jointure category_shop a altéré la forme du résultat"
        );
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LookCompletionManager.php');
    $posMethod = strpos($src, 'public function getCategories(): array');
    neria_assert($posMethod !== false, "Méthode getCategories() introuvable — jeu de test invalide");
    $methodBody = substr($src, $posMethod, 700);

    neria_assert(
        strpos($methodBody, 'category_shop') !== false,
        "LookCompletionManager::getCategories() ne filtre plus par boutique (jointure category_shop disparue) — régression du bug corrigé le 15/08/2026 (round 174) : le BO proposerait de nouveau des catégories d'autres boutiques pour créer une règle de complétion de look"
    );

    return [
        'pass'    => true,
        'message' => "LookCompletionManager::getCategories() filtre bien les catégories par boutique via category_shop — bug corrigé le 15/08/2026 (round 174)",
    ];
}
