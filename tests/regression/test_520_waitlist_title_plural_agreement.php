<?php
/**
 * Régression : la clé de traduction `waitlist_title` (titre principal de
 * l'email de retour en stock, `data/translations.json`) utilisait un
 * mécanisme d'accord singulier/pluriel (`{days_waited_plural}`, résolu en
 * PHP à `'s'` ou `''`) UNIQUEMENT pour fr/en — les 10 autres langues à
 * accord variable (de, it, es, pt, br, ru, sv, no, da, nl) codaient en
 * dur la forme PLURIELLE du mot "jour", produisant un texte grammaticalement
 * incorrect (ex. allemand "Sie haben 1 Tage gewartet" au lieu de "1 Tag")
 * dès que `days_waited = 1`.
 *
 * `WaitlistManager.php` (`$daysWaited = max(1, (int) $row['days_waited'])`)
 * garantit que la valeur minimale possible est exactement 1, et ce sera
 * un cas fréquent : un client dont le produit revient en stock le jour
 * même ou le lendemain de son inscription reçoit cet email avec
 * `days_waited = 1`. C'est le titre principal d'un email transactionnel à
 * fort enjeu commercial (relance sur retour de stock).
 *
 * Bug identifié le 01/09/2026 (round 273, audit "accord singulier/pluriel
 * dans les traductions").
 *
 * Corrigé le 01/09/2026 (round 273) : les 10 langues concernées utilisent
 * désormais la même convention parenthétique déjà employée ailleurs dans
 * ce fichier pour ce type d'accord (ex. "jour(s)"), grammaticalement
 * correcte pour n=1 comme pour n>1 — sauf le russe, où la déclinaison
 * complète (1 день / 2-4 дня / 5+ дней) ne se prête pas à une parenthèse
 * simple : l'abréviation "дн." (courante en russe pour sidestepper cette
 * déclinaison) est utilisée à la place.
 *
 * Test réel : lit directement data/translations.json et vérifie, pour
 * chacune des 10 langues corrigées, que la forme plurielle figée
 * incorrecte a disparu et que le texte attendu (grammaticalement neutre
 * pour n=1) est bien présent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/translations.json'), true);
    neria_assert(is_array($translations), 'data/translations.json illisible ou invalide');

    // Recherche récursive de toutes les occurrences de la clé 'waitlist_title'
    // (structure imbriquée par thème/langue dans ce fichier).
    $found = [];
    $walk = function ($node) use (&$walk, &$found) {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['waitlist_title']) && is_string($node['waitlist_title'])) {
            $found[] = $node['waitlist_title'];
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($translations);

    neria_assert(count($found) > 0, "aucune clé 'waitlist_title' trouvée dans data/translations.json — jeu de test invalide");

    $expectedPresent = [
        'de' => 'Tag(e)',
        'it' => 'giorno/i',
        'es' => 'día(s)',
        'pt' => 'dia(s)', // couvre aussi br (même sous-chaîne)
        'ru' => 'дн.',
        'sv' => 'dag(ar)',
        'no' => 'dag(er)',
        'da' => 'dag(e)',
        'nl' => 'dag(en)',
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
            "aucune occurrence de waitlist_title ne contient '{$expectedSubstr}' (langue {$lang}) — régression du bug corrigé le 01/09/2026 (round 273) : l'accord singulier/pluriel serait de nouveau incorrect pour days_waited=1"
        );
    }

    // Formes plurielles figées incorrectes qui ne doivent plus apparaître seules.
    $forbidden = ['Tage gewartet', 'aspettato {days_waited} giorni', 'días. No', 'dias. Não', 'ждали {days_waited} дней', 'väntade {days_waited} dagar', 'ventet {days_waited} dager', 'ventede {days_waited} dage', 'heeft {days_waited} dagen'];
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
            "la forme plurielle figée incorrecte '{$needle}' est de nouveau présente dans waitlist_title — régression du bug corrigé le 01/09/2026 (round 273)"
        );
    }

    return [
        'pass'    => true,
        'message' => "waitlist_title utilise désormais un accord grammatical correct pour days_waited=1 dans les 10 langues concernées — bug corrigé le 01/09/2026 (round 273)",
    ];
}
