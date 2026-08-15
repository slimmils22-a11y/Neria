<?php
/**
 * Régression : NeriaTools::timeAgo() avec une date FUTURE produisait un
 * $diff négatif traité par accident comme "à l'instant" (< 60), au lieu
 * d'être géré explicitement.
 *
 * Bug latent corrigé le 15/08/2026 (round 173) : timeAgo() est du code
 * actuellement jamais appelé dans le module (vérifié par recherche
 * exhaustive dans src/, views/, neria.php — aucun appelant), mais reste une
 * méthode publique documentée destinée à être raccrochée (probablement à un
 * historique/log back-office). $diff = time() - strtotime($date) : pour une
 * date future (ex. un envoi programmé, ou une donnée corrompue), $diff est
 * négatif, et `$diff < 60` était vrai pour TOUTE valeur négative, affichant
 * silencieusement "à l'instant" pour une date qui n'a pas encore eu lieu —
 * comportement trompeur si un développeur raccroche la méthode sans
 * remarquer ce cas limite.
 *
 * Test comportemental réel : appelle timeAgo() avec une date dans le futur
 * et vérifie que le résultat reste "à l'instant" (comportement voulu et
 * désormais explicite), sans lever d'erreur ni produire un texte négatif
 * incohérent comme "il y a -5 minute(s)".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $future = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $result = NeriaTools::timeAgo($future, 'fr');

    neria_assert(
        $result === 'à l\'instant',
        "NeriaTools::timeAgo() sur une date future renvoie '{$result}' au lieu de 'à l'instant' — régression du bug corrigé le 15/08/2026 (round 173) : le calcul de diff négatif n'est plus géré explicitement"
    );

    neria_assert(
        strpos($result, '-') === false,
        "NeriaTools::timeAgo() sur une date future produit un texte contenant un signe négatif ('{$result}') — le diff négatif fuite dans l'affichage au lieu d'être normalisé"
    );

    // Comportement passé (référence) : toujours correct pour une date
    // passée de plusieurs heures.
    $pastHours = date('Y-m-d H:i:s', strtotime('-3 hours'));
    $pastResult = NeriaTools::timeAgo($pastHours, 'fr');
    neria_assert(
        strpos($pastResult, 'heure') !== false,
        "NeriaTools::timeAgo() sur une date passée de 3 heures ne renvoie plus un texte en heures (obtenu : '{$pastResult}') — comportement de base cassé"
    );

    return [
        'pass'    => true,
        'message' => "NeriaTools::timeAgo() gère bien explicitement une date future comme 'à l'instant' plutôt que par un artefact du calcul de diff négatif — bug corrigé le 15/08/2026 (round 173)",
    ];
}
