<?php
/**
 * Régression : MonthlyReportManager::isDue() ne comparait qu'au mois
 * précédent immédiat — si aucun hook ne se déclenche pour une boutique
 * pendant toute la fenêtre de rattrapage (1er-7 du mois), le rapport du
 * mois manqué n'était jamais rattrapé NI signalé : l'écart se refermait
 * silencieusement au mois suivant.
 *
 * Corrigé le 13/08/2026 (round 165) : isDue() détecte désormais un écart
 * de plus d'un mois entre CONFIG_LAST_SENT et le mois cible et journalise
 * un WARNING Watchdog (watchdog.monthly_report_gap_detected) — pas de
 * rattrapage automatique (jugé trop risqué), juste une visibilité de
 * l'incident.
 *
 * Test réel : simule un dernier envoi vieux de 3 mois, appelle isDue(),
 * vérifie qu'une ligne Watchdog a bien été ajoutée (via SUM(occurrence_count),
 * cf. consolidation horaire des logs identiques).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $idShop = (int) Context::getContext()->shop->id;
    $key    = MonthlyReportManager::CONFIG_LAST_SENT . '_' . $idShop;
    $original = (string) Configuration::get($key);

    // 3 mois avant le mois cible (mois précédent le mois courant).
    $staleMonth = date('Y-m', strtotime('-3 months', strtotime('last month')));

    try {
        Configuration::updateValue($key, $staleMonth);

        $sumBefore = (int) $db->getValue(
            "SELECT COALESCE(SUM(occurrence_count), 0) FROM {$prefix}neria_log WHERE class = 'MonthlyReportManager'"
        );

        $mgr = new MonthlyReportManager($module);
        $ref = new ReflectionMethod(MonthlyReportManager::class, 'isDue');
        $ref->setAccessible(true);
        $ref->invoke($mgr, $idShop);

        $sumAfter = (int) $db->getValue(
            "SELECT COALESCE(SUM(occurrence_count), 0) FROM {$prefix}neria_log WHERE class = 'MonthlyReportManager'"
        );

        neria_assert(
            $sumAfter > $sumBefore,
            "isDue() n'a ajouté/incrémenté aucune ligne Watchdog malgré un écart de 3 mois entre CONFIG_LAST_SENT et le mois cible — régression du bug corrigé le 13/08/2026 (round 165) : un rapport mensuel manqué redeviendrait indétectable"
        );
    } finally {
        if ($original !== '') {
            Configuration::updateValue($key, $original);
        } else {
            Configuration::deleteByName($key);
        }
    }

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::isDue() journalise bien un WARNING Watchdog quand un écart de plusieurs mois est détecté — bug corrigé le 13/08/2026 (round 165)",
    ];
}
