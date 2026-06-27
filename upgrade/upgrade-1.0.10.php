<?php
/**
 * Upgrade 1.0.9 → 1.0.10
 * Ajout table neria_waitlist (liste d'attente produits)
 */

if (!defined('_PS_VERSION_')) exit;

function upgrade_module_1_0_10(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;

    $ok = $db->execute("
        CREATE TABLE IF NOT EXISTS `{$prefix}neria_waitlist` (
            `id_neria_waitlist` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_customer`       INT UNSIGNED NOT NULL,
            `id_product`        INT UNSIGNED NOT NULL,
            `id_shop`           INT UNSIGNED NOT NULL DEFAULT 1,
            `registered_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `notified_at`       DATETIME     NULL DEFAULT NULL,
            PRIMARY KEY (`id_neria_waitlist`),
            UNIQUE KEY `uq_customer_product` (`id_customer`, `id_product`),
            KEY `idx_product`   (`id_product`),
            KEY `idx_notified`  (`notified_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    Configuration::updateGlobalValue('NERIA_WAITLIST_ENABLED', 1);

    // Charge les traductions du template waitlist_available en DB
    $installer = new TranslationInstaller($module);
    $installer->importFromJson(__DIR__ . '/../data/translations.json');

    return (bool) $ok;
}
