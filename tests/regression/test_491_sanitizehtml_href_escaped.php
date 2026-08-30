<?php
/**
 * Durcissement round 255 (31/08/2026) : `NeriaTools::sanitizeHtml()`
 * réinjectait l'URL extraite d'un attribut `href` SANS aucun échappement.
 *
 * Vérification personnelle IMPORTANTE (à ne pas reproduire sans la refaire
 * si ce fichier est modifié) : l'hypothèse initiale d'un contournement
 * d'attribut exploitable (guillemet dans l'URL + `onmouseover=` injecté)
 * NE TIENT PAS à l'examen empirique — la classe de caractères `[^"\']` du
 * regex de validation exclut déjà tout guillemet de la valeur capturée
 * (`$href[2]` ne peut structurellement jamais contenir de guillemet), et
 * tout attribut hors `href` (comme `onmouseover=`) est de toute façon
 * ÉLIMINÉ par la reconstruction minimale du callback (`'<a href="' . ... .
 * '">'`, qui ne recopie jamais les autres attributs de la balise
 * d'origine) — AVEC OU SANS ce correctif. Testé empiriquement avant
 * d'écrire ce test : `sanitizeHtml('<a href="https://x.com/"
 * onmouseover="alert(1)">')` retourne `<a href="https://x.com/">` que le
 * correctif soit présent ou non — `onmouseover` disparaît dans les deux
 * cas, ce n'était donc pas un contournement d'attribut réellement
 * exploitable tel quel.
 *
 * Le VRAI comportement corrigé : une URL contenant un `&` (fréquent en
 * query string, ex: `?a=1&b=2`) produisait un attribut HTML avec un `&`
 * brut hors entité — non strictement conforme HTML (les navigateurs le
 * tolèrent en pratique, mais ce n'est pas correct). Corrigé en défense en
 * profondeur avec `htmlspecialchars(ENT_QUOTES)`, qui protège aussi contre
 * toute réouverture future si la logique de reconstruction venait à
 * changer (ex: recopie d'autres attributs).
 *
 * Test comportemental réel : vérifie qu'une URL avec `&` dans la query
 * string est bien HTML-entity-encodée (`&amp;`) dans la sortie, et que le
 * même `&` dans un href SANS échappement (comportement pré-correctif)
 * n'apparaîtrait plus brut.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $payload = '<a href="https://example.com/?a=1&b=2">clic</a>';
    $result  = NeriaTools::sanitizeHtml($payload);

    neria_assert(
        strpos($result, '<a href="') !== false,
        "jeu de test invalide : sanitizeHtml() ne produit plus de balise <a href=\"...\"> — comportement de base cassé"
    );
    neria_assert(
        strpos($result, '&amp;') !== false,
        "NeriaTools::sanitizeHtml() n'échappe plus le '&' d'une URL (query string) avant réinjection dans l'attribut href — régression du durcissement du 31/08/2026 (round 255) : sortie obtenue = '{$result}'"
    );
    neria_assert(
        !preg_match('/href="[^"]*&[^a][^"]*"/', $result),
        "NeriaTools::sanitizeHtml() laisse passer un '&' brut (non entity-encodé) dans l'attribut href — sortie = '{$result}'"
    );

    return [
        'pass'    => true,
        'message' => "NeriaTools::sanitizeHtml() échappe bien le '&' d'une URL (query string) avant réinjection dans l'attribut href (&amp;) — durcissement du 31/08/2026 (round 255). Note : l'hypothèse initiale d'un contournement d'attribut via onmouseover= s'est révélée non exploitable à l'examen empirique (l'attribut est de toute façon éliminé par la reconstruction, avec ou sans ce correctif) — documenté dans le docblock plutôt que testé comme un exploit réel",
    ];
}
