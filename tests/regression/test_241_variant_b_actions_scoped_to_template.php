<?php
/**
 * Régression : les actions reset_variant_b et save_variant_b doivent
 * vérifier que id_abtest_b appartient bien au template affiché ($tplKey),
 * pas seulement à la boutique — même correctif round 137 que
 * restore_variant_b (action jumelle), oublié sur ces deux-là.
 *
 * Bug réel corrigé le 09/08/2026 (round 153) : abtestBelongsToShop()
 * accepte un second paramètre $template optionnel qui, s'il est fourni,
 * vérifie EN PLUS que l'id_abtest correspond au template courant — sans
 * ce second argument, un id_abtest_b manipulé pointant vers un AUTRE test
 * A/B actif de la MÊME boutique passait le contrôle (même boutique = true).
 * Sur une boutique avec plusieurs tests A/B actifs simultanément, un
 * employé sur l'onglet Traductions du template A pouvait ainsi vider
 * (reset_variant_b) ou écraser (save_variant_b) silencieusement la
 * variante B d'un template B totalement différent.
 *
 * Test structurel : vérifie que les deux appels à abtestBelongsToShop()
 * dans reset_variant_b/save_variant_b passent bien $tplKey en second
 * argument, comme restore_variant_b le fait déjà (référence dans le même
 * fichier).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    // Référence : restore_variant_b utilise déjà correctement les 2 arguments.
    neria_assert(
        strpos($src, "\$idHistory > 0 && \$this->abtestBelongsToShop(\$idAbtestB, \$tplKey)") !== false,
        "restore_variant_b ne passe plus \$tplKey a abtestBelongsToShop() — jeu de test invalide (reference cassee)"
    );

    $posReset = strpos($src, "if (\$tradAction === 'reset_variant_b') {");
    neria_assert($posReset !== false, 'reset_variant_b introuvable — jeu de test invalide');
    $resetBody = substr($src, $posReset, 1000);
    neria_assert(
        strpos($resetBody, 'abtestBelongsToShop($idAbtestB, $tplKey)') !== false,
        "reset_variant_b n'appelle plus abtestBelongsToShop() avec \$tplKey en 2e argument — régression du bug corrigé le 09/08/2026 (round 153) : un id_abtest_b manipulé pourrait de nouveau vider la variante B d'un AUTRE template actif sur la même boutique"
    );

    $posSave = strpos($src, "if (\$tradAction === 'save_variant_b' && class_exists('ABTestManager')) {");
    neria_assert($posSave !== false, 'save_variant_b introuvable — jeu de test invalide');
    $saveBody = substr($src, $posSave, 600);
    neria_assert(
        strpos($saveBody, 'abtestBelongsToShop($idAbtestB, $tplKey)') !== false,
        "save_variant_b n'appelle plus abtestBelongsToShop() avec \$tplKey en 2e argument — régression du bug corrigé le 09/08/2026 (round 153) : un id_abtest_b manipulé pourrait de nouveau écraser la variante B d'un AUTRE template actif sur la même boutique"
    );

    return [
        'pass'    => true,
        'message' => "reset_variant_b et save_variant_b verifient bien id_abtest_b contre le template affiche (tplKey), comme restore_variant_b",
    ];
}
