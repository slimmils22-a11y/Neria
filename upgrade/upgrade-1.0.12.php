<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Upgrade 1.0.11 → 1.0.12
 * Centre de préférences email — TABLE 33 neria_preferences
 */

if (!defined('_PS_VERSION_')) exit;

function upgrade_module_1_0_12(Neria $module): bool
{
    $db = Db::getInstance();

    $db->execute(
        "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "neria_preferences` (
            `id_preference` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop`       INT UNSIGNED NOT NULL DEFAULT 1,
            `id_customer`   INT UNSIGNED NOT NULL DEFAULT 0,
            `email`         VARCHAR(150) NOT NULL DEFAULT '',
            `category`      ENUM('cart','post','loyalty','behav','season','b2b','newsletter') NOT NULL,
            `subscribed`    TINYINT(1) NOT NULL DEFAULT 1,
            `date_upd`      DATETIME NOT NULL,
            PRIMARY KEY (`id_preference`),
            UNIQUE KEY `uq_shop_customer_cat` (`id_shop`,`id_customer`,`category`),
            KEY `idx_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Centre de préférences email Neria — opt-in/out par catégorie'"
    );

    // Enregistre la version installée pour le health check #19
    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
