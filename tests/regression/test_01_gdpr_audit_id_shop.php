<?php
/** Régression : GdprAuditManager::auditRetention() doit filtrer id_shop. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();

    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, event_type, template, lang, date_add)
        VALUES (99999, 'sent', 'regtest', 'fr', DATE_SUB(NOW(), INTERVAL 20 MONTH))");
    $id = (int) $db->Insert_ID();

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';
        $mgr = new GdprAuditManager(neria_test_module()->getLocalPath());
        $ref = new ReflectionMethod($mgr, 'auditRetention');
        $ref->setAccessible(true);
        $result = $ref->invoke($mgr);

        $row = null;
        foreach ($result['rows'] as $r) {
            if ($r['table'] === 'neria_stat') { $row = $r; break; }
        }
        neria_assert($row !== null, "ligne neria_stat absente du rapport");
        neria_assert($row['overdue'] === 0, "overdue={$row['overdue']}, attendu 0 (la ligne fictive est sur un autre id_shop)");

        return ['pass' => true, 'message' => 'auditRetention() correctement scopé id_shop'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_stat = {$id}");
    }
}
