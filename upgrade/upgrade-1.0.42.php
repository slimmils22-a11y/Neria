<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.42
 *
 * Ajoute les colonnes `artisan_name`, `region`, `weaving_duration` à
 * `neria_certificate` — nouvelle page de traçabilité publique (front
 * controller `certificate`, ciblée par le QR code du certificat PDF) qui
 * montre la fiche précise de la pièce achetée (artisane, région, temps de
 * tissage) plutôt qu'un certificat générique.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_42(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $table  = $prefix . 'neria_certificate';

    $tableExists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
    ");
    if (!$tableExists) {
        Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);
        return $module->importTranslations();
    }

    $ok = true;
    $columns = [
        'artisan_name'     => "VARCHAR(255) DEFAULT NULL",
        'region'           => "VARCHAR(255) DEFAULT NULL",
        'weaving_duration' => "VARCHAR(255) DEFAULT NULL",
    ];

    foreach ($columns as $column => $definition) {
        $hasColumn = (bool) $db->getValue("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = '{$table}'
              AND COLUMN_NAME  = '{$column}'
        ");
        if (!$hasColumn) {
            $ok = $ok && (bool) $db->execute("
                ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition} AFTER `product_name`
            ");
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok && $module->importTranslations();
}
