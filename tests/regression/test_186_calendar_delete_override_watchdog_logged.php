<?php
/**
 * Régression : CalendarManager::deleteManualOverride() doit journaliser
 * l'action via Watchdog, comme setManualOverride().
 *
 * Bug réel corrigé le 09/08/2026 (round 142) : setManualOverride()
 * journalisait la création d'un override (auparavant via
 * $this->module->log(), migré vers le canal Watchdog cohérent avec le
 * reste de la classe), mais deleteManualOverride() ne journalisait RIEN —
 * trou de traçabilité pur : si le calcul automatique qui reprend ensuite
 * donne une date erronée, rien ne relie le problème à cette suppression.
 *
 * Test comportemental réel : pose un override, le supprime, vérifie
 * qu'une trace 'CalendarManager' apparaît bien dans neria_log après coup.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $eventKey  = 'neria_test_round142_del';
    $year      = 2098;
    $idShop    = (int) \Context::getContext()->shop->id;
    $configKey = 'NERIA_CAL_DATE_' . strtoupper($eventKey) . '_' . $year . '_SHOP' . $idShop;

    $calendar = new CalendarManager($module);

    try {
        $ok = $calendar->setManualOverride($eventKey, $year, '2098-03-10');
        neria_assert($ok === true, "setManualOverride() a échoué — jeu de test invalide");

        $countBefore = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE class = 'CalendarManager' AND message LIKE '%{$eventKey}%'"
        );

        $calendar->deleteManualOverride($eventKey, $year);

        $countAfter = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_log WHERE class = 'CalendarManager' AND message LIKE '%{$eventKey}%' AND message LIKE '%supprim%'"
        );

        neria_assert(
            $countAfter > 0,
            "Aucune trace Watchdog n'a été créée pour la suppression de l'override — régression du bug corrigé le 09/08/2026 (round 142) : deleteManualOverride() ne journalise de nouveau rien"
        );

        // La config elle-même doit bien avoir disparu
        $remaining = \Configuration::get($configKey);
        neria_assert($remaining === false || $remaining === '', "deleteManualOverride() n'a pas supprimé la config elle-même");
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = '{$configKey}'");
        $db->execute("DELETE FROM {$prefix}neria_log WHERE class = 'CalendarManager' AND message LIKE '%{$eventKey}%'");
    }

    return [
        'pass'    => true,
        'message' => "CalendarManager::deleteManualOverride() journalise bien la suppression via Watchdog",
    ];
}
