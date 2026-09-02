<?php
/**
 * Régression : la clé de traduction `waitlist_reservation` (2e phrase
 * visible du corps principal de l'email de retour en stock,
 * `data/translations.json`) codait en dur la forme plurielle du mot
 * "heure" pour la quasi-totalité des langues à accord variable (fr, en,
 * gb, de, it, es, pt, br, ru, sv, no, da), sans aucun mécanisme de suffixe
 * pluriel — même famille de bug que `waitlist_title`/`{days_waited}`
 * corrigée au round 273, mais sur une variable différente du même
 * template.
 *
 * `neria.php:5292` — `$hours = max(1, min(72, (int) Tools::getValue(
 * 'waitlist_reservation_hours')));` — garantit que la valeur minimale
 * configurable en BO est exactement 1 : un marchand peut explicitement
 * régler la fenêtre de réservation prioritaire à 1 heure, produisant
 * alors un texte grammaticalement incorrect (ex. "1 heures", "1 hours",
 * "1 Stunden").
 *
 * Bug identifié le 01/09/2026 (round 274, audit "accord singulier/pluriel
 * dans les traductions").
 *
 * Corrigé le 01/09/2026 (round 274) : convention parenthétique déjà
 * utilisée ailleurs dans ce fichier (ex. "heure(s)"), grammaticalement
 * correcte pour n=1 comme pour n>1 — sauf le russe (abréviation "ч."
 * pour sidestepper la déclinaison à 3 formes, même approche que
 * {days_waited} en russe au round 273).
 *
 * Test réel : lit directement data/translations.json et vérifie, pour
 * chacune des 12 langues concernées, que la forme plurielle figée
 * incorrecte a disparu et que le texte attendu est bien présent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/translations.json'), true);
    neria_assert(is_array($translations), 'data/translations.json illisible ou invalide');

    $found = [];
    $walk = function ($node) use (&$walk, &$found) {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['waitlist_reservation']) && is_string($node['waitlist_reservation'])) {
            $found[] = $node['waitlist_reservation'];
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($translations);

    neria_assert(count($found) > 0, "aucune clé 'waitlist_reservation' trouvée dans data/translations.json — jeu de test invalide");

    $expectedPresent = [
        'fr' => 'heure(s)',
        'en/gb' => 'hour(s)',
        'de' => 'Stunde(n)',
        'it' => 'ora/e',
        'es' => 'hora(s)',
        'pt/br' => 'hora(s)', // partagé avec es, vérifié par nombre d'occurrences ci-dessous
        'ru' => 'ч.',
        'sv' => 'timme/timmar',
        'no/da' => 'time(r)',
    ];

    foreach ($expectedPresent as $lang => $expectedSubstr) {
        $matchCount = 0;
        foreach ($found as $text) {
            if (strpos($text, $expectedSubstr) !== false) {
                $matchCount++;
            }
        }
        neria_assert(
            $matchCount > 0,
            "aucune occurrence de waitlist_reservation ne contient '{$expectedSubstr}' (langue {$lang}) — régression du bug corrigé le 01/09/2026 (round 274) : l'accord singulier/pluriel serait de nouveau incorrect pour reservation_hours=1"
        );
    }

    // 'hora(s)' doit apparaître au moins 3 fois (es + pt + br).
    $horaCount = 0;
    foreach ($found as $text) {
        $horaCount += substr_count($text, 'hora(s)');
    }
    neria_assert(
        $horaCount >= 3,
        "'hora(s)' n'apparaît que {$horaCount} fois dans waitlist_reservation (attendu >= 3, pour es/pt/br) — régression du bug corrigé le 01/09/2026 (round 274)"
    );

    // Formes plurielles figées incorrectes qui ne doivent plus apparaître.
    $forbidden = [
        'valable {reservation_hours} heures', 'valid for {reservation_hours} hours',
        'gilt für {reservation_hours} Stunden', 'valido per {reservation_hours} ore',
        'durante {reservation_hours} horas', 'por {reservation_hours} horas',
        'действителен {reservation_hours} часа', 'gäller i {reservation_hours} timmar. Där',
        'gyldig i {reservation_hours} timer',
    ];
    foreach ($forbidden as $needle) {
        $stillPresent = false;
        foreach ($found as $text) {
            if (strpos($text, $needle) !== false) {
                $stillPresent = true;
                break;
            }
        }
        neria_assert(
            !$stillPresent,
            "la forme plurielle figée incorrecte '{$needle}' est de nouveau présente dans waitlist_reservation — régression du bug corrigé le 01/09/2026 (round 274)"
        );
    }

    return [
        'pass'    => true,
        'message' => "waitlist_reservation utilise désormais un accord grammatical correct pour reservation_hours=1 dans les langues concernées — bug corrigé le 01/09/2026 (round 274)",
    ];
}
