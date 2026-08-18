<?php
/**
 * Régression : la table `neria_abtest_history` (créée par
 * upgrade-1.0.14.php, alimentée par ABTestManager) était absente du
 * registre RGPD (GdprAuditManager::REGISTRY) — purgeAllRegistryTables()
 * (cron quotidien quand NERIA_GDPR_AUTO_PURGE_ENABLED est actif) ne la
 * purgeait donc jamais, contrairement à toutes les autres tables du
 * module, chacune couverte par une politique de rétention.
 *
 * Corrigé le 18/08/2026 (round 184) : entrée ajoutée au registre
 * (rétention 36 mois sur `date_end`, pas de PII).
 *
 * Test comportemental réel : vérifie que 'neria_abtest_history' apparaît
 * bien dans GdprAuditManager::getTables(), puis crée une ligne réelle
 * avec date_end ancienne (40 mois) et vérifie que purgeAllRegistryTables()
 * la purge effectivement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $tables = array_column(GdprAuditManager::getTables(), 'table');
    neria_assert(
        in_array('neria_abtest_history', $tables, true),
        "neria_abtest_history n'apparaît plus dans GdprAuditManager::getTables() — régression du bug corrigé le 18/08/2026 (round 184) : la table ne serait de nouveau jamais purgée par la rétention RGPD automatique"
    );

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $testTemplate = 'regtest_382_' . bin2hex(random_bytes(4));

    $db->execute(
        "INSERT INTO {$prefix}neria_abtest_history
            (id_shop, template, variant_a_name, variant_b_name, split_percent, applied, date_end)
         VALUES
            ({$idShop}, '{$testTemplate}', 'A', 'B', 50, 1, DATE_SUB(NOW(), INTERVAL 40 MONTH))"
    );

    try {
        $idHistory = (int) $db->getValue(
            "SELECT id_history FROM {$prefix}neria_abtest_history WHERE template = '{$testTemplate}'"
        );
        neria_assert($idHistory > 0, "Ligne de test neria_abtest_history non créée — jeu de test invalide");

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeAllRegistryTables();

        $stillExists = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_abtest_history WHERE id_history = {$idHistory}"
        );
        neria_assert(
            $stillExists === 0,
            "La ligne de test neria_abtest_history (40 mois, au-delà des 36 mois de rétention) n'a pas été purgée par purgeAllRegistryTables() — régression du bug corrigé le 18/08/2026 (round 184)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_abtest_history WHERE template = '{$testTemplate}'");
    }

    return [
        'pass'    => true,
        'message' => "neria_abtest_history est bien couverte par la rétention RGPD automatique — bug corrigé le 18/08/2026 (round 184)",
    ];
}
