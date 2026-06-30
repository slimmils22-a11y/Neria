<?php
/**
 * NERIA — Upgrade 1.0.15
 *
 * - Nouvelle table ps_neria_cron_health (monitoring des crons)
 * - Colonne occurrence_count sur ps_neria_log (consolidation des erreurs)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!function_exists('upgrade_module_1_0_15')) {
function upgrade_module_1_0_15(Neria $module): bool
{
    $db        = Db::getInstance();
    $tableCron = _DB_PREFIX_ . 'neria_cron_health';
    $tableLog  = _DB_PREFIX_ . 'neria_log';

    // ── Table de monitoring des crons ────────────────────────────────
    $db->execute("CREATE TABLE IF NOT EXISTS `{$tableCron}` (
        `id_shop`     INT(11)      NOT NULL DEFAULT 1,
        `cron_key`    VARCHAR(50)  NOT NULL,
        `last_run`    DATETIME     NULL,
        `last_status` ENUM('ok','warning','error') NOT NULL DEFAULT 'ok',
        `last_count`  INT(11)      NOT NULL DEFAULT 0,
        PRIMARY KEY (`id_shop`, `cron_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Colonne occurrence_count (déduplication des logs répétés) ────
    $col = $db->getRow("
        SELECT `COLUMN_NAME`
        FROM `INFORMATION_SCHEMA`.`COLUMNS`
        WHERE `TABLE_SCHEMA` = DATABASE()
          AND `TABLE_NAME`   = '{$tableLog}'
          AND `COLUMN_NAME`  = 'occurrence_count'
    ");
    if (!$col) {
        $db->execute(
            "ALTER TABLE `{$tableLog}`
             ADD COLUMN `occurrence_count` INT(11) NOT NULL DEFAULT 1 AFTER `context`"
        );
    }

    Configuration::updateValue('NERIA_VERSION', $module->version);
    return true;
}
}
