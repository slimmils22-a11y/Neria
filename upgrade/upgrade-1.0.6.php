<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.5 → 1.0.6
 *
 * Ajoute la TABLE 27 (neria_queue) et la clé de configuration
 * NERIA_PURCHASE_WINDOW_ENABLED pour la fenêtre d'achat individuelle.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_6(\Neria $module): bool
{
    $db = \Db::getInstance();

    // TABLE 27 : file d'attente des emails programmés
    $ok = $db->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_queue` (
            `id_neria_queue`  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `id_customer`     INT UNSIGNED     NOT NULL,
            `id_shop`         INT UNSIGNED     NOT NULL DEFAULT 1,
            `id_lang`         INT UNSIGNED     NOT NULL DEFAULT 1,
            `template`        VARCHAR(100)     NOT NULL,
            `recipient_email` VARCHAR(255)     NOT NULL,
            `recipient_name`  VARCHAR(255)     NOT NULL DEFAULT \'\',
            `vars_json`       MEDIUMTEXT,
            `ref_id`          INT UNSIGNED     DEFAULT NULL,
            `send_at`         DATETIME         NOT NULL,
            `status`          ENUM(\'pending\',\'sent\',\'failed\') NOT NULL DEFAULT \'pending\',
            `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `error`           TEXT             DEFAULT NULL,
            `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `sent_at`         DATETIME         DEFAULT NULL,
            PRIMARY KEY (`id_neria_queue`),
            KEY `idx_send_at_status` (`send_at`, `status`),
            KEY `idx_customer`       (`id_customer`),
            KEY `idx_status`         (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (!\Configuration::getGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED')) {
        \Configuration::updateGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED', 1);
    }

    return (bool) $ok;
}
