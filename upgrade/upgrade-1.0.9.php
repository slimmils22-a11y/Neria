<?php
/**
 * Upgrade 1.0.8 → 1.0.9
 * Ajout des tables neria_look_rule et neria_look_sent
 */

if (!defined('_PS_VERSION_')) exit;

function upgrade_module_1_0_9(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $ok &= $db->execute("
        CREATE TABLE IF NOT EXISTS `{$prefix}neria_look_rule` (
            `id_neria_look_rule` INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `id_category`        INT UNSIGNED     NOT NULL,
            `product_ids`        TEXT             NOT NULL,
            `active`             TINYINT(1)       NOT NULL DEFAULT 1,
            `created_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_neria_look_rule`),
            KEY `idx_category`   (`id_category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ok &= $db->execute("
        CREATE TABLE IF NOT EXISTS `{$prefix}neria_look_sent` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order`    INT UNSIGNED NOT NULL,
            `id_customer` INT UNSIGNED NOT NULL,
            `sent_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_order` (`id_order`),
            KEY `idx_customer`    (`id_customer`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Charge les traductions du template complete_your_look en DB
    $installer = new TranslationInstaller($module);
    $installer->importFromJson(__DIR__ . '/../data/translations.json');

    return (bool) $ok;
}
