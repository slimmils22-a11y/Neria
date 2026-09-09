<?php
/**
 * Régression : GoldenHourManager::computeRecommendations() (via
 * getRecommendations()) sélectionnait le meilleur créneau par langue avec
 * une comparaison strictement '>' sur une requête SQL SANS `ORDER BY` — en
 * cas d'égalité EXACTE de taux d'ouverture entre deux créneaux (jour+heure)
 * éligibles (≥3 envois), c'était le premier rencontré dans l'ordre de
 * retour MySQL (non garanti sans ORDER BY) qui l'emportait, pouvant faire
 * "changer tout seul" best_day/best_hour affiché en BO à chaque recalcul
 * (cache 15 min) sans que les données sous-jacentes n'aient bougé.
 *
 * Corrigé le 09/09/2026 (round 327) : ORDER BY lang ASC, dow ASC, hour ASC
 * ajouté à la requête, rendant le choix déterministe (premier jour/heure
 * par ordre croissant en cas d'égalité de taux).
 *
 * Test comportemental réel : 2 créneaux avec un taux d'ouverture IDENTIQUE
 * (3 envois / 3 ouvertures = 100%) le même jour à 2 heures différentes (9h
 * et 15h) pour la même langue — vérifie que best_hour retourne
 * systématiquement 9 (la plus petite), pas 15.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GoldenHourManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $lang       = 'zzt65'; // VARCHAR(5) — code court dédié à ce test
    $tomorrow   = date('Y-m-d', strtotime('+1 day'));

    $tokens = [];
    try {
        // 2 créneaux à égalité stricte (5 envois / 5 ouvertures chacun —
        // MIN_OPENS=10 exige un total ≥10 ouvertures tous créneaux confondus
        // pour que la langue apparaisse dans le résultat final), même jour,
        // heures différentes (9h et 15h).
        foreach ([9, 15] as $hour) {
            for ($i = 0; $i < 5; $i++) {
                $token = 'regtest654_' . $hour . '_' . $i . '_' . uniqid();
                $tokens[] = $token;
                $sentDate = "{$tomorrow} " . sprintf('%02d', $hour) . ":0{$i}:00";
                $db->execute(
                    "INSERT INTO {$prefix}neria_stat (id_shop, template, lang, tracking_token, event_type, date_add, is_mpp)
                     VALUES ({$idShop}, 'regtest654_tpl', '{$lang}', '{$token}', 'sent', '{$sentDate}', 0)"
                );
                $db->execute(
                    "INSERT INTO {$prefix}neria_stat (id_shop, template, lang, tracking_token, event_type, date_add, is_mpp)
                     VALUES ({$idShop}, 'regtest654_tpl', '{$lang}', '{$token}', 'open', '{$sentDate}', 0)"
                );
            }
        }

        $mgr = new GoldenHourManager();
        $ref = new ReflectionMethod(GoldenHourManager::class, 'computeRecommendations');
        $ref->setAccessible(true);
        $recs = $ref->invoke($mgr, 90);

        $recForLang = null;
        foreach ($recs as $r) {
            if ($r['lang'] === $lang) {
                $recForLang = $r;
                break;
            }
        }
        neria_assert($recForLang !== null, "Aucune recommandation trouvée pour la langue de test '{$lang}' — jeu de test invalide");

        neria_assert(
            (int) $recForLang['best_hour'] === 9,
            "GoldenHourManager::computeRecommendations() retourne best_hour=" . $recForLang['best_hour'] . " au lieu de 9 en cas d'égalité stricte de taux — régression du bug corrigé le 09/09/2026 (round 327) : la recommandation redeviendrait non déterministe (pourrait basculer entre 9 et 15 selon l'ordre de retour MySQL)"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/GoldenHourManager.php');
        neria_assert(
            strpos($src, "ORDER BY s.`lang` ASC, dow ASC, hour ASC") !== false,
            "GoldenHourManager ne trie plus explicitement sa requête (ORDER BY) — régression du bug corrigé le 09/09/2026 (round 327)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = 'regtest654_tpl'");
    }

    return [
        'pass'    => true,
        'message' => "GoldenHourManager::computeRecommendations() choisit bien le créneau le plus tôt de façon déterministe en cas d'égalité de taux d'ouverture — bug corrigé le 09/09/2026 (round 327)",
    ];
}
