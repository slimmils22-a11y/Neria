<?php
/**
 * Régression : DeliverabilityScorer::score() (critère 5, lien de
 * désabonnement) recherchait les mots-clés ('unsubscribe', 'désabonnement',
 * etc.) directement dans $htmlContent, SANS normaliser la casse —
 * contrairement aux critères 2/4 de ce même fichier (mb_strtolower($subject)/
 * mb_strtolower($visible)). Un lien/bouton affiché en majuscules ou en
 * casse mixte ("UNSUBSCRIBE", "Se Désabonner" — usage typographique
 * courant pour un bouton stylé) ne matchait aucune des chaînes (toutes en
 * minuscules dans le code) : le critère comptait à tort "lien de
 * désabonnement absent" (-10 points, recommandation trompeuse) alors qu'il
 * est bel et bien présent.
 *
 * Corrigé le 08/09/2026 (round 322) : $htmlContent normalisé en minuscules
 * (mb_strtolower) avant la recherche des mots-clés.
 *
 * Test comportemental réel : score() avec un lien "UNSUBSCRIBE" en
 * majuscules doit obtenir le même résultat (pas de pénalité -10) qu'avec
 * la casse minuscule normale.
 */
require_once __DIR__ . '/bootstrap.php';

function findUnsubCriterion(array $criteria): ?array
{
    $label = class_exists('AdminTranslator') ? AdminTranslator::t('score.criterion_unsub') : 'score.criterion_unsub';
    foreach ($criteria as $c) {
        if (($c['name'] ?? '') === $label) {
            return $c;
        }
    }
    return null;
}

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    $scorer = new DeliverabilityScorer();

    $htmlUpper = '<html><body><p>Contenu de test.</p><a href="#">UNSUBSCRIBE</a></body></html>';
    $resultUpper = $scorer->score($htmlUpper, 'Sujet de test');
    $critUpper = findUnsubCriterion($resultUpper['criteria']);

    neria_assert(
        $critUpper !== null && $critUpper['type'] === 'success',
        "DeliverabilityScorer::score() ne détecte plus un lien de désabonnement en MAJUSCULES ('UNSUBSCRIBE') — régression du bug corrigé le 08/09/2026 (round 322) : la recherche redeviendrait sensible à la casse, contrairement aux critères 2/4 du même fichier"
    );

    $htmlMixed = '<html><body><p>Contenu de test.</p><a href="#">Se Désabonner</a></body></html>';
    $resultMixed = $scorer->score($htmlMixed, 'Sujet de test');
    $critMixed = findUnsubCriterion($resultMixed['criteria']);

    neria_assert(
        $critMixed !== null && $critMixed['type'] === 'success',
        "DeliverabilityScorer::score() ne détecte plus un lien de désabonnement en casse mixte ('Se Désabonner') — régression du bug corrigé le 08/09/2026 (round 322)"
    );

    // Contre-épreuve : absence réelle de lien de désabonnement doit
    // toujours être détectée (pas de régression du chemin nominal).
    $htmlNone = '<html><body><p>Contenu de test sans aucun lien de désinscription.</p></body></html>';
    $resultNone = $scorer->score($htmlNone, 'Sujet de test');
    $critNone = findUnsubCriterion($resultNone['criteria']);
    neria_assert(
        $critNone !== null && $critNone['type'] === 'error',
        "DeliverabilityScorer::score() ne détecte plus l'ABSENCE réelle d'un lien de désabonnement — régression : le correctif round 322 masquerait à tort un vrai email non conforme"
    );

    return [
        'pass'    => true,
        'message' => "DeliverabilityScorer::score() détecte bien le lien de désabonnement quelle que soit sa casse (MAJUSCULES/mixte), sans régression sur le cas d'absence réelle — bug corrigé le 08/09/2026 (round 322)",
    ];
}
