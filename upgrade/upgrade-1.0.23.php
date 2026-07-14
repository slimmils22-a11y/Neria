<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.23
 *
 * L'email `birthday` référençait {voucher_code} sans jamais le générer
 * (variable envoyée vide en dur) : le bon de réduction anniversaire
 * n'existait pas vraiment. Nouvelle table neria_birthday_voucher (anti-
 * doublon par client+année) + génération d'un vrai CartRule PrestaShop,
 * montant/type configurables par le marchand (NERIA_BIRTHDAY_VOUCHER_AMOUNT
 * / NERIA_BIRTHDAY_VOUCHER_PERCENT).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_23(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $ok = $ok && $db->execute("
        CREATE TABLE IF NOT EXISTS `{$prefix}neria_birthday_voucher` (
            `id_voucher`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `id_customer`  INT UNSIGNED  NOT NULL,
            `year`         SMALLINT UNSIGNED NOT NULL,
            `id_cart_rule` INT UNSIGNED  NOT NULL DEFAULT 0,
            `voucher_code` VARCHAR(50)   NOT NULL DEFAULT '',
            `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_voucher`),
            UNIQUE KEY `uq_customer_year` (`id_customer`, `year`),
            KEY `idx_customer` (`id_customer`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    Configuration::updateValue('NERIA_CREATED_BIRTHDAY_VOUCHER_TABLE', 1);

    if (Configuration::getGlobalValue('NERIA_BIRTHDAY_VOUCHER_AMOUNT') === false) {
        Configuration::updateGlobalValue('NERIA_BIRTHDAY_VOUCHER_AMOUNT', 10);
    }
    if (Configuration::getGlobalValue('NERIA_BIRTHDAY_VOUCHER_PERCENT') === false) {
        Configuration::updateGlobalValue('NERIA_BIRTHDAY_VOUCHER_PERCENT', 1);
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return (bool) $ok;
}
