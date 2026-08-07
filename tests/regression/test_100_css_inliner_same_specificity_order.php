<?php
/**
 * Régression : CssInliner::inline() doit donner la victoire à la DERNIÈRE
 * règle CSS déclarée quand deux règles ciblent le même sélecteur avec la
 * MÊME spécificité — comme le fait la cascade CSS standard (interprétation
 * du bloc <style> par un navigateur/client mail qui le conserve).
 *
 * Bug réel corrigé le 07/08/2026 (round 96) : merge() donne la priorité à
 * la règle traitée en PREMIER (elle ignore ensuite toute propriété déjà
 * inlinée). Le tri par spécificité (usort, stable depuis PHP 8) conservait
 * l'ordre d'apparition dans le CSS pour deux règles de même spécificité —
 * la PREMIÈRE règle déclarée gagnait donc systématiquement, à l'inverse de
 * la cascade CSS standard. Un template avec une règle de base puis une
 * surcharge plus bas dans le même bloc <style> (même sélecteur, même
 * spécificité) s'inlinait avec la couleur de BASE sur Gmail/Outlook (qui
 * suppriment <style>), alors qu'Apple Mail (qui le garde) affichait la
 * bonne couleur — rendu divergent silencieux entre clients mail.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CssInliner.php';

    $html = '<html><head><style>
        .neria-btn { color: #999999; }
        .neria-btn { color: #b38b59; }
    </style></head><body>
        <a class="neria-btn" href="#">Regtest</a>
    </body></html>';

    $result = CssInliner::inline($html);

    neria_assert(
        strpos($result, 'color: #999999') === false || strpos($result, 'style="color: #b38b59') !== false,
        "CssInliner::inline() n'a pas produit de style inline exploitable — jeu de test invalide"
    );

    // Extrait le style inline effectivement posé sur le lien.
    preg_match('/<a[^>]*class="neria-btn"[^>]*style="([^"]*)"/i', $result, $m);
    neria_assert(!empty($m[1]), "aucun style inline trouvé sur l'élément .neria-btn après inlining — jeu de test invalide");
    $inlineStyle = $m[1];

    neria_assert(
        strpos($inlineStyle, '#b38b59') !== false,
        "CssInliner::inline() applique encore la PREMIÈRE règle déclarée (#999999) au lieu de la DERNIÈRE (#b38b59) à spécificité égale (obtenu : '{$inlineStyle}') — régression du bug corrigé le 07/08/2026 (round 96) : rendu divergent entre Apple Mail (garde <style>, bonne couleur) et Gmail/Outlook (style inline uniquement, mauvaise couleur)"
    );
    neria_assert(
        strpos($inlineStyle, '#999999') === false,
        "CssInliner::inline() applique encore la première règle EN PLUS de la dernière (les deux couleurs présentes : '{$inlineStyle}') — la seconde déclaration devrait totalement remplacer la première pour la même propriété"
    );

    return [
        'pass'    => true,
        'message' => "CssInliner::inline() donne bien la victoire à la dernière règle déclarée à spécificité CSS égale, conforme à la cascade standard",
    ];
}
