<?php
/**
 * Régression : NeriaTools::timeAgo() avec une date NON PARSABLE par
 * strtotime() (chaîne vide, valeur corrompue) produisait un $diff énorme
 * et un texte absurde du type "il y a 682 mois", au lieu de signaler
 * l'échec.
 *
 * Bug réel identifié le 25/08/2026 (round 205) : strtotime() renvoie
 * false (pas 0) quand il ne peut pas parser la date. `time() - false`
 * caste false en 0 en arithmétique PHP, donnant $diff ≈ time() courant
 * (plusieurs dizaines d'années en secondes) — retombant systématiquement
 * dans la branche "il y a N mois" avec un N incohérent, sans aucune
 * exception ni log. Même piège déjà corrigé pour NeriaTools::formatDate()
 * au round 173 (voir son commentaire dans le même fichier), mais jamais
 * porté à timeAgo() alors que les deux méthodes utilisent strtotime() de
 * la même façon.
 *
 * timeAgo() est du code actuellement jamais appelé en production (aucun
 * appelant réel dans src/, views/, neria.php à ce jour — confirmé par
 * recherche exhaustive), mais reste une méthode publique documentée,
 * gardée correcte par cohérence avec le reste du fichier (même politique
 * déjà appliquée round 173 pour son autre cas limite, diff négatif).
 *
 * Corrigé le 25/08/2026 (round 205) : $ts = strtotime($date); if ($ts ===
 * false) { return $date; } — même garde que formatDate().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    // Chaîne non parsable par strtotime() — cas réel (donnée corrompue,
    // champ vide) plutôt qu'une simple chaîne vide (déjà un cas limite
    // trivial), pour bien couvrir le vrai déclencheur du bug : strtotime()
    // renvoyant false.
    $garbage = 'ceci n\'est pas une date valide !!';
    neria_assert(
        strtotime($garbage) === false,
        "strtotime() parse à tort la chaîne de test comme une date valide — jeu de test invalide, choisir une chaîne réellement non parsable"
    );

    $result = NeriaTools::timeAgo($garbage, 'fr');
    neria_assert(
        $result === $garbage,
        "NeriaTools::timeAgo() sur une date non parsable renvoie '{$result}' au lieu de la retourner telle quelle — régression du bug corrigé le 25/08/2026 (round 205) : une date corrompue produirait de nouveau un texte absurde type 'il y a N mois'"
    );

    // Chaîne vide : même cas, plus fréquent en pratique (champ DB vide).
    $emptyResult = NeriaTools::timeAgo('', 'fr');
    neria_assert(
        $emptyResult === '',
        "NeriaTools::timeAgo('') ne renvoie plus la chaîne vide telle quelle — régression du bug corrigé le 25/08/2026 (round 205)"
    );

    // Comportement de référence, toujours correct : une date valide passée.
    $pastHours = date('Y-m-d H:i:s', strtotime('-3 hours'));
    $pastResult = NeriaTools::timeAgo($pastHours, 'fr');
    neria_assert(
        strpos($pastResult, 'heure') !== false,
        "NeriaTools::timeAgo() sur une date passée valide de 3 heures ne renvoie plus un texte en heures (obtenu : '{$pastResult}') — comportement de base cassé par ce correctif"
    );

    return [
        'pass'    => true,
        'message' => "NeriaTools::timeAgo() gère bien l'échec de strtotime() en retournant la date telle quelle, comme formatDate() — bug corrigé le 25/08/2026 (round 205)",
    ];
}
