<?php
/**
 * Régression : le compilateur maison d'EmailRenderer (compileNeriaTemplate()
 * pour l'envoi réel, buildCompiledHtml() pour l'aperçu BO) doit résoudre le
 * bloc `{if $neria_dir == 'rtl'}...{else}...{/if}` de ghost_cart.html —
 * pas le supprimer intégralement (les deux branches, y compris leur
 * contenu commun {neria_trad key='ghost_cart_note'}).
 *
 * Bug identifié le 13/09/2026 (round 350, audit multi-agents, angle
 * templates email) : le handler de blocs conditionnels à variable NUE
 * (`{if $var}...{else}...{/if}`) n'accepte qu'un nom de variable seul —
 * `{if $neria_dir == 'rtl'}` (comparaison d'égalité) ne matche pas sa regex
 * et tombait dans le nettoyage générique des résidus Smarty juste après,
 * qui supprime tout le fragment {if}...{/if} SANS distinction de branche.
 * La note de clôture "Pas de pression..." disparaissait donc de TOUS les
 * emails ghost_cart réellement envoyés, LTR comme RTL — et de l'aperçu BO
 * identique.
 *
 * Corrigé en ajoutant une variable booléenne dédiée `{neria_is_rtl}` au
 * pipeline de templateVars (les deux chemins), et en réécrivant
 * ghost_cart.html pour utiliser la forme reconnue `{if neria_is_rtl}`.
 *
 * Test comportemental réel : génère un aperçu ghost_cart en français (LTR)
 * ET en arabe (RTL) via EmailRenderer::renderPreviewHtml(), vérifie que la
 * note de clôture traduite est bien présente dans les deux cas, et que le
 * style de bordure appliqué correspond bien au sens de lecture attendu
 * (border-left en fr, border-right en ar).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $renderer = new EmailRenderer(neria_test_module());

    $htmlFr = $renderer->renderPreviewHtml('ghost_cart', 'fr');
    neria_assert(
        strpos($htmlFr, 'Pas de pression') !== false,
        "L'aperçu ghost_cart en français ne contient plus la note de clôture ('ghost_cart_note') — régression du bug corrigé le 13/09/2026 (round 350) : le bloc RTL/LTR entier (y compris son contenu commun) redeviendrait supprimé par le nettoyage générique"
    );
    neria_assert(
        strpos($htmlFr, 'padding-left: 14px') !== false,
        "L'aperçu ghost_cart en français n'affiche plus la bordure LTR (padding-left) attendue — régression du bug corrigé le 13/09/2026 (round 350)"
    );

    $htmlAr = $renderer->renderPreviewHtml('ghost_cart', 'ar');
    neria_assert(
        strpos($htmlAr, 'لا ضغط على الإطلاق') !== false,
        "L'aperçu ghost_cart en arabe ne contient plus la note de clôture traduite — régression du bug corrigé le 13/09/2026 (round 350)"
    );
    neria_assert(
        strpos($htmlAr, 'padding-right: 14px') !== false,
        "L'aperçu ghost_cart en arabe n'affiche plus la bordure RTL (padding-right) attendue — régression du bug corrigé le 13/09/2026 (round 350)"
    );

    // Vérification structurelle symétrique du chemin d'envoi RÉEL
    // (compileNeriaTemplate()) — même correctif, non testable en
    // comportemental isolé (méthode privée, appelée uniquement au fil
    // d'un vrai envoi Mail::Send()).
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert($src !== false, 'Impossible de lire src/EmailRenderer.php');
    $posCompile = strpos($src, 'private function compileNeriaTemplate(');
    neria_assert($posCompile !== false, 'compileNeriaTemplate() introuvable');
    $windowCompile = substr($src, $posCompile, 17000);
    neria_assert(
        strpos($windowCompile, "\$templateVars['{neria_is_rtl}'] = \$this->engine->isRtl(\$lang);") !== false,
        "compileNeriaTemplate() (envoi réel) ne propage plus {neria_is_rtl} dans templateVars — régression du bug corrigé le 13/09/2026 (round 350) pour le chemin d'envoi réel"
    );

    return [
        'pass'    => true,
        'message' => "EmailRenderer résout bien le bloc conditionnel RTL/LTR de ghost_cart.html — note de clôture et bordure correctement affichées en français ET en arabe (aperçu), et le même correctif est bien propagé au chemin d'envoi réel — bug corrigé le 13/09/2026 (round 350)",
    ];
}
