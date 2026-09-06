<?php
/**
 * Régression : WatchdogManager::record() persistait date_add via
 * date('Y-m-d H:i:s') PHP pour une NOUVELLE ligne de log, alors que le
 * SELECT de dédoublonnage juste au-dessus et l'UPDATE de consolidation
 * juste en dessous utilisent tous deux NOW() MySQL sur cette même colonne
 * — sur le logger CENTRAL du module lui-même. Si le serveur PHP et le
 * serveur MySQL n'ont pas le même fuseau horaire, une ligne fraîchement
 * insérée avec date() PHP pouvait tomber hors de la fenêtre
 * DATE_SUB(NOW(), INTERVAL 1 HOUR) du dédoublonnage (créant plusieurs
 * lignes distinctes au lieu d'un occurrence_count cumulé) ou élargir la
 * fenêtre de throttle anti-spam bien au-delà d'1h.
 *
 * Corrigé le 06/09/2026 (round 309) : NOW() MySQL au lieu de date() PHP.
 *
 * Test comportemental réel : bascule le fuseau horaire PHP vers un
 * décalage extrême (Pacific/Kiritimati, UTC+14) AVANT d'appeler
 * WatchdogManager::info(), puis vérifie que date_add persisté reste
 * proche de NOW() MySQL.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $message = 'Regtest587 message unique ' . bin2hex(random_bytes(8));
    $class   = 'Regtest587Class';

    $db->execute("DELETE FROM {$prefix}neria_log WHERE class = '" . pSQL($class) . "'");

    $originalTz = date_default_timezone_get();

    try {
        date_default_timezone_set('Pacific/Kiritimati');

        $mgr = new WatchdogManager(neria_test_module());
        $mgr->info($message, '', $class);

        $mysqlNow = (string) $db->getValue('SELECT NOW()');

        date_default_timezone_set($originalTz);
    } catch (\Throwable $e) {
        date_default_timezone_set($originalTz);
        throw $e;
    }

    $row = $db->getRow(
        "SELECT date_add FROM {$prefix}neria_log WHERE class = '" . pSQL($class) . "' ORDER BY id_log DESC"
    );

    try {
        neria_assert($row !== false, "aucune ligne de log trouvée après info() — jeu de test invalide");

        $diff = abs(strtotime($mysqlNow) - strtotime((string) $row['date_add']));
        neria_assert(
            $diff <= 10,
            "date_add ('{$row['date_add']}') est décalé de {$diff}s par rapport à NOW() MySQL ('{$mysqlNow}') — régression du bug corrigé le 06/09/2026 (round 309) : date_add redevenu sourcé via l'horloge PHP au lieu de MySQL sur le logger central du module"
        );

        return [
            'pass'    => true,
            'message' => "WatchdogManager::record() persiste bien date_add via l'horloge MySQL (NOW()), insensible au fuseau horaire du serveur applicatif PHP — bug corrigé le 06/09/2026 (round 309)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_log WHERE class = '" . pSQL($class) . "'");
    }
}
