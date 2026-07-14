<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.24
 *
 * milestone_order (paliers de commandes 5/10/25/50/100) était un email de
 * pure reconnaissance, sans aucune récompense possible. Nouveau toggle BO
 * (désactivé par défaut, ne change rien au comportement existant) pour
 * offrir un vrai bon de réduction à chaque palier atteint, sur le même
 * principe que le bon d'anniversaire (nouvelle table neria_milestone_voucher,
 * anti-doublon par client+palier, CartRule PrestaShop réel).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_24(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $ok = $ok && $db->execute("
        CREATE TABLE IF NOT EXISTS `{$prefix}neria_milestone_voucher` (
            `id_voucher`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `id_customer`  INT UNSIGNED  NOT NULL,
            `milestone`    SMALLINT UNSIGNED NOT NULL,
            `id_cart_rule` INT UNSIGNED  NOT NULL DEFAULT 0,
            `voucher_code` VARCHAR(50)   NOT NULL DEFAULT '',
            `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_voucher`),
            UNIQUE KEY `uq_customer_milestone` (`id_customer`, `milestone`),
            KEY `idx_customer` (`id_customer`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    Configuration::updateValue('NERIA_CREATED_MILESTONE_VOUCHER_TABLE', 1);

    if (Configuration::getGlobalValue('NERIA_MILESTONE_VOUCHER_ENABLED') === false) {
        Configuration::updateGlobalValue('NERIA_MILESTONE_VOUCHER_ENABLED', 0);
    }
    if (Configuration::getGlobalValue('NERIA_MILESTONE_VOUCHER_AMOUNT') === false) {
        Configuration::updateGlobalValue('NERIA_MILESTONE_VOUCHER_AMOUNT', 10);
    }
    if (Configuration::getGlobalValue('NERIA_MILESTONE_VOUCHER_PERCENT') === false) {
        Configuration::updateGlobalValue('NERIA_MILESTONE_VOUCHER_PERCENT', 1);
    }

    // Nouvelle clé milestone_voucher_value dans milestone_order — sans cet
    // import, {milestone_voucher_block} resterait sans libellé sur les
    // installs existantes (cf. règle [[feedback_upgrade_translations]]).
    $module->importTranslations();

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return (bool) $ok;
}
