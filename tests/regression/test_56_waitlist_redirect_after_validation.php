<?php
/**
 * Régression : controllers/front/waitlist.php doit calculer son URL de
 * repli par défaut ($redirect) SANS dépendre de $idProduct, et ne
 * recalculer le lien produit qu'APRÈS la validation Validate::isLoadedObject().
 *
 * Bug réel corrigé le 05/08/2026 (round 54) : $redirect était calculé avec
 * l'id_product brut AVANT toute validation — getProductLink() génère
 * quand même une URL "valide en apparence" pour un produit inexistant/
 * d'une autre boutique, PrestaShop ne vérifiant pas l'existence pour
 * construire un lien. Un id_product invalide (lien de désinscription avec
 * un ID supprimé/erroné) redirigeait donc l'utilisateur vers une page 404
 * au lieu du repli my-account déjà prévu pour le cas id_product absent.
 *
 * Ce contrôleur ne peut pas être invoqué directement en test (Tools::
 * redirect() termine le script) — ce test vérifie la structure du code
 * par position dans le fichier, ce qui reste un signal fiable pour ce
 * type de bug (ordre des opérations).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/waitlist.php');
    neria_assert($src !== false, 'controllers/front/waitlist.php introuvable');

    $redirectDefaultPos = strpos($src, "\$redirect  = 'index.php?controller=my-account';");
    neria_assert(
        $redirectDefaultPos !== false,
        "le repli par défaut \$redirect n'est plus 'index.php?controller=my-account' sans condition — régression du bug de redirection vers un produit invalide corrigé le 05/08/2026"
    );

    $validatePos = strpos($src, 'Validate::isLoadedObject($product)');
    neria_assert($validatePos !== false, "Validate::isLoadedObject(\$product) introuvable — le contrôleur a été restructuré, vérifier manuellement");

    $productLinkAfterValidationPos = strpos($src, 'getProductLink($product)', $validatePos);
    neria_assert(
        $productLinkAfterValidationPos !== false && $productLinkAfterValidationPos > $validatePos,
        "getProductLink() n'est plus recalculé APRÈS Validate::isLoadedObject() — régression possible : le lien produit pourrait de nouveau être utilisé avant validation"
    );

    // Le repli par défaut doit être calculé AVANT la validation (sinon
    // aucune sortie anticipée entre les deux n'aurait de repli défini).
    neria_assert(
        $redirectDefaultPos < $validatePos,
        "l'ordre du code a changé de façon inattendue — \$redirect par défaut devrait être défini avant la validation du produit"
    );

    return [
        'pass'    => true,
        'message' => 'waitlist.php calcule bien un repli sûr par défaut, et ne recalcule le lien produit qu\'après validation',
    ];
}
