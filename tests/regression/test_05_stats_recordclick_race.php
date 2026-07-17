<?php
/**
 * Régression : StatsManager::recordClick() doit rester protégé par GET_LOCK
 * contre le double crédit fidélité (TOCTOU) sur des clics quasi simultanés.
 * Le vrai test de concurrence a été fait manuellement le 17/07/2026 avec deux
 * process PHP réels (voir commit 4552288) ; ici on vérifie que la protection
 * n'a pas été retirée par inadvertance, et on rejoue le cas séquentiel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $token = 'regtest_' . bin2hex(random_bytes(6));

    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'sent', '{$token}', NOW())");

    try {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
        neria_assert(
            str_contains($src, "GET_LOCK('") && str_contains($src, 'neria_click_'),
            "recordClick() n'utilise plus GET_LOCK pour sérialiser la décision de crédit — régression de la race corrigée le 17/07/2026 (commit 4552288)"
        );

        require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';
        $stats = new StatsManager(neria_test_module());
        $stats->recordClick($token, 'http://example.test');
        $stats->recordClick($token, 'http://example.test');

        $points = (int) $db->getValue(
            "SELECT COALESCE(SUM(points),0) FROM {$prefix}neria_loyalty_points ps
             JOIN {$prefix}neria_stat s ON s.id_stat = ps.id_stat
             WHERE s.tracking_token='{$token}'"
        );
        neria_assert($points <= 3, "points={$points} après 2 clics séquentiels, attendu <= 3 (un seul crédit)");

        return ['pass' => true, 'message' => 'recordClick() : verrou présent, dédup séquentielle correcte'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_loyalty_points WHERE id_stat IN (SELECT id_stat FROM {$prefix}neria_stat WHERE tracking_token='{$token}')");
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token='{$token}'");
    }
}
