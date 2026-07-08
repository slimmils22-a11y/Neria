<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Upgrade 1.0.7 → 1.0.8
 * Ajout des tables neria_collection et neria_collection_sent
 */

if (!defined('_PS_VERSION_')) exit;

function upgrade_module_1_0_8(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    // TABLE 28 : neria_collection
    $ok &= $db->execute("
        CREATE TABLE IF NOT EXISTS `{$prefix}neria_collection` (
            `id_neria_collection` INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `name`                VARCHAR(255)     NOT NULL,
            `product_ids`         TEXT             NOT NULL,
            `active`              TINYINT(1)       NOT NULL DEFAULT 1,
            `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_neria_collection`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // TABLE 29 : neria_collection_sent
    $ok &= $db->execute("
        CREATE TABLE IF NOT EXISTS `{$prefix}neria_collection_sent` (
            `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `id_neria_collection` INT UNSIGNED     NOT NULL,
            `id_customer`         INT UNSIGNED     NOT NULL,
            `sent_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_col_customer` (`id_neria_collection`, `id_customer`),
            KEY `idx_customer`    (`id_customer`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Charge les traductions du template collection_completion en DB
    $installer = new TranslationInstaller($module);
    $installer->importFromJson(__DIR__ . '/../data/translations.json');

    return (bool) $ok;
}
