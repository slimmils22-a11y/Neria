<?php
/**
 * Régression : DeliverabilityScorer::hasHiddenWhiteText() excluait toute
 * règle CSS contenant simplement le MOT "background", peu importe la
 * couleur réellement déclarée — la technique classique de masquage
 * (`color:#fff; background:#fff` ou `background-color:#ffffff`) contient ce
 * mot et échappait donc entièrement à la détection, exactement le cas que
 * cette fonction existe pour repérer.
 *
 * Bug réel identifié le 23/08/2026 (round 195) : un email réellement
 * spammy (texte blanc caché sur fond blanc) pouvait obtenir un meilleur
 * score anti-spam qu'il ne devrait, faussant le Critère 7 (patterns
 * techniques, jusqu'à -9).
 *
 * Corrigé le 23/08/2026 (round 195) : la valeur de la déclaration
 * background/background-color est désormais extraite et comparée — le
 * texte blanc sur un fond BLANC déclenche toujours la détection ; le texte
 * blanc sur un fond RÉELLEMENT coloré (légitime, ex. bouton) reste exclu.
 *
 * Test comportemental réel (méthode privée, via Reflection).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    $scorer = new DeliverabilityScorer();
    $ref = new ReflectionMethod(DeliverabilityScorer::class, 'hasHiddenWhiteText');
    $ref->setAccessible(true);

    // Cas 1 : technique classique de masquage — texte blanc sur fond BLANC.
    $htmlHidden = '<div style="color:#fff;background:#fff;">contenu caché</div>';
    $resultHidden = $ref->invoke($scorer, $htmlHidden);
    neria_assert(
        $resultHidden === true,
        "hasHiddenWhiteText() ne détecte plus 'color:#fff;background:#fff' (masquage classique white-on-white) — régression du bug corrigé le 23/08/2026 (round 195) : la présence du simple MOT 'background' excluait à tort cette technique de spam de la détection"
    );

    // Variante background-color.
    $htmlHidden2 = '<div style="color:#ffffff;background-color:#ffffff;">contenu caché</div>';
    $resultHidden2 = $ref->invoke($scorer, $htmlHidden2);
    neria_assert(
        $resultHidden2 === true,
        "hasHiddenWhiteText() ne détecte plus 'color:#ffffff;background-color:#ffffff' — régression du bug corrigé le 23/08/2026 (round 195)"
    );

    // Cas 2 : non-régression — texte blanc sur un fond RÉELLEMENT coloré
    // (légitime, ex. bouton bleu) doit rester exclu.
    $htmlLegit = '<div style="color:#fff;background-color:#0056b3;">Acheter maintenant</div>';
    $resultLegit = $ref->invoke($scorer, $htmlLegit);
    neria_assert(
        $resultLegit === false,
        "hasHiddenWhiteText() détecte à tort un faux positif sur du texte blanc légitime avec un fond réellement coloré — faux positif du correctif round 195"
    );

    // Cas 3 : non-régression — texte blanc sans AUCUN fond déclaré (comportement d'origine).
    $htmlNoBg = '<div style="color:#fff;">contenu caché</div>';
    $resultNoBg = $ref->invoke($scorer, $htmlNoBg);
    neria_assert(
        $resultNoBg === true,
        "hasHiddenWhiteText() ne détecte plus le texte blanc sans aucun fond déclaré — régression du comportement d'origine"
    );

    return [
        'pass'    => true,
        'message' => "DeliverabilityScorer::hasHiddenWhiteText() détecte bien le texte blanc sur fond BLANC (masquage réel), pas seulement l'absence de fond — bug corrigé le 23/08/2026 (round 195)",
    ];
}
