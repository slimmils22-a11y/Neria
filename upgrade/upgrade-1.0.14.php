<?php
/**
 * Upgrade 1.0.13 → 1.0.14
 * A/B Testing : historique des tests terminés (TABLE 28).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_14(Neria $module): bool
{
    $db    = Db::getInstance();
    $table = _DB_PREFIX_ . 'neria_abtest_history';

    $db->execute("
        CREATE TABLE IF NOT EXISTS `{$table}` (
            `id_history`      INT(11)        NOT NULL AUTO_INCREMENT,
            `id_shop`         INT(11)        NOT NULL DEFAULT 1,
            `template`        VARCHAR(100)   NOT NULL,
            `variant_a_name`  VARCHAR(100)   NOT NULL DEFAULT '',
            `variant_b_name`  VARCHAR(100)   NOT NULL DEFAULT '',
            `split_percent`   TINYINT(3)     NOT NULL DEFAULT 50,
            `sent_a`          INT(11)        NOT NULL DEFAULT 0,
            `sent_b`          INT(11)        NOT NULL DEFAULT 0,
            `rate_open_a`     DECIMAL(5,2)   NOT NULL DEFAULT 0,
            `rate_open_b`     DECIMAL(5,2)   NOT NULL DEFAULT 0,
            `rate_click_a`    DECIMAL(5,2)   NOT NULL DEFAULT 0,
            `rate_click_b`    DECIMAL(5,2)   NOT NULL DEFAULT 0,
            `revenue_a`       DECIMAL(10,2)  NOT NULL DEFAULT 0,
            `revenue_b`       DECIMAL(10,2)  NOT NULL DEFAULT 0,
            `winner`          CHAR(1)        NULL,
            `confidence`      TINYINT(3)     NULL,
            `applied`         TINYINT(1)     NOT NULL DEFAULT 0,
            `date_start`      DATETIME       NULL,
            `date_end`        DATETIME       NOT NULL,
            PRIMARY KEY (`id_history`),
            INDEX `idx_shop_template` (`id_shop`, `template`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Historique des tests A/B terminés'
    ");

    Configuration::updateValue('NERIA_VERSION', $module->version);
    return true;
}
