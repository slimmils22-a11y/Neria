<?php
/**
 * Régression : NeriaTools::formatDate() traitait le timestamp epoch 0
 * (1970-01-01T00:00:00Z) comme un échec de parsing et retournait la chaîne
 * brute d'entrée au lieu de la date formatée.
 *
 * Bug réel corrigé le 15/08/2026 (round 173) : `$ts = is_numeric($date) ?
 * (int) $date : strtotime($date); if (!$ts) return $date;` — `!$ts` est
 * vrai aussi bien quand strtotime() échoue réellement (false) que quand
 * $ts vaut 0, un timestamp Unix parfaitement valide. Confusion classique
 * entre "échec" et "valeur normale" : passer explicitement le timestamp 0
 * (ex. valeur par défaut d'un champ non renseigné en base, ou calcul
 * produisant délibérément l'epoch) retournait "0" tel quel au lieu d'une
 * date formatée.
 *
 * Test comportemental réel : appelle formatDate('0', 'fr') et vérifie que
 * le résultat est bien une date formatée (01/01/1970), pas la chaîne "0".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $result = NeriaTools::formatDate('0', 'fr');

    neria_assert(
        $result !== '0',
        "NeriaTools::formatDate('0', 'fr') renvoie '0' brut au lieu d'une date formatée — régression du bug corrigé le 15/08/2026 (round 173) : le timestamp epoch 0 (1970-01-01) est de nouveau traité à tort comme un échec de parsing"
    );

    neria_assert(
        $result === '01/01/1970',
        "NeriaTools::formatDate('0', 'fr') renvoie '{$result}' au lieu de '01/01/1970' — le timestamp epoch 0 n'est pas correctement formaté"
    );

    // Une vraie chaîne invalide doit toujours retomber sur l'entrée brute.
    $invalid = NeriaTools::formatDate('ceci-n-est-pas-une-date', 'fr');
    neria_assert(
        $invalid === 'ceci-n-est-pas-une-date',
        "NeriaTools::formatDate() sur une entrée réellement invalide ne retourne plus l'entrée brute (obtenu : '{$invalid}') — le comportement d'échec historique a changé"
    );

    return [
        'pass'    => true,
        'message' => "NeriaTools::formatDate() distingue bien un timestamp epoch 0 valide d'un échec réel de parsing — bug corrigé le 15/08/2026 (round 173)",
    ];
}
