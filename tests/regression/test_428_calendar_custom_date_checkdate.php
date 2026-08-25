<?php
/**
 * Régression : cal_custom_date (MM-DD saisi par le marchand pour une
 * occasion calendaire personnalisée) n'était validé que par un format
 * regex (`^\d{2}-\d{2}$`), jamais par sa validité calendaire réelle. Une
 * date inexistante comme "04-31" (31 avril) ou "13-05" (mois 13) passait
 * donc la validation.
 *
 * Bug réel identifié le 24/08/2026 (round 202) :
 * CalendarManager::resolveMonthDay() utilise
 * DateTime::createFromFormat('Y-n-j', ...), qui est TOLÉRANT aux valeurs
 * hors plage — il fait un "rollover" arithmétique silencieux
 * ("2026-4-31" devient le 1er mai 2026) au lieu de retourner false. Le
 * reste du code (processEvent(), getUpcomingDates()...) traite tout
 * retour non-null comme une date valide et volontaire — un marchand
 * configurant "31 avril" par erreur (aucun garde-fou UI) voyait l'email
 * partir un mois plus tard que prévu, sans aucune alerte.
 *
 * Corrigé le 24/08/2026 (round 202) : neria.php valide désormais
 * cal_custom_date par checkdate() (année bissextile 2028 de référence,
 * pour accepter 29/02) avant insertion en base, et
 * CalendarManager::resolveMonthDay() rejette explicitement (retourne null)
 * toute date hors plage en défense en profondeur.
 *
 * Test comportemental réel : appelle resolveMonthDay() (via une méthode
 * publique qui l'utilise) avec des dates invalides connues et vérifie
 * qu'aucune ne produit de rollover silencieux.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';

    $module = neria_test_module();
    $mgr = new CalendarManager($module);
    $ref = new ReflectionMethod('CalendarManager', 'resolveMonthDay');
    $ref->setAccessible(true);

    // 1) Dates réellement invalides : doivent retourner null, jamais un
    // DateTime rollové vers une autre date réelle.
    $invalidCases = [
        [2026, 4, 31],  // avril n'a que 30 jours
        [2026, 2, 30],  // février n'a jamais 30 jours
        [2026, 13, 5],  // mois 13 n'existe pas
        [2026, 0, 15],  // mois 0 n'existe pas
    ];
    foreach ($invalidCases as [$y, $m, $d]) {
        $result = $ref->invoke($mgr, $y, $m, $d);
        neria_assert(
            $result === null,
            "CalendarManager::resolveMonthDay($y, $m, $d) devrait retourner null pour une date calendaire invalide, pas faire un rollover silencieux — régression du bug corrigé le 24/08/2026 (round 202)"
        );
    }

    // 2) Dates valides : doivent continuer à fonctionner normalement,
    // y compris le cas spécial du 29 février en année non bissextile.
    $validCases = [
        [2026, 6, 15, '2026-06-15'],
        [2026, 2, 29, '2026-02-28'], // 2026 non bissextile : repli sur le 28
        [2028, 2, 29, '2028-02-29'], // 2028 bissextile : accepté tel quel
    ];
    foreach ($validCases as [$y, $m, $d, $expected]) {
        $result = $ref->invoke($mgr, $y, $m, $d);
        neria_assert(
            $result instanceof DateTime && $result->format('Y-m-d') === $expected,
            "CalendarManager::resolveMonthDay($y, $m, $d) devrait résoudre à $expected — régression potentielle du garde-fou round 202 sur un cas valide"
        );
    }

    // 3) Validation en amont dans neria.php (checkdate avant insertion).
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(
        strpos($src, 'checkdate((int) $mCal[1], (int) $mCal[2], 2028)') !== false,
        "neria.php ne valide plus cal_custom_date par checkdate() avant insertion en base — régression du bug corrigé le 24/08/2026 (round 202)"
    );

    return [
        'pass'    => true,
        'message' => "CalendarManager::resolveMonthDay() rejette bien les dates calendaires invalides (checkdate()) au lieu de faire un rollover silencieux, et neria.php valide cal_custom_date avant insertion — bug corrigé le 24/08/2026 (round 202)",
    ];
}
