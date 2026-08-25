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
    // Round 204 : chaque colonne doit être ajoutée APRÈS LA PRÉCÉDENTE de
    // cette liste (pas toutes "AFTER product_name") — sinon chaque ALTER
    // TABLE réinsère sa colonne juste après product_name en repoussant la
    // précédente, produisant l'ordre physique INVERSE de celui déclaré ici
    // et de celui d'install.sql (artisan_name, region, weaving_duration).
    // Sans impact fonctionnel tant que le code accède aux colonnes par nom
    // (c'est le cas ici), mais une install fraîche et une install
    // upgradée depuis <1.0.42 divergeaient silencieusement dans l'ordre
    // physique des colonnes de neria_certificate.
    $columns = [
        'artisan_name'     => ['def' => 'VARCHAR(255) DEFAULT NULL', 'after' => 'product_name'],
        'region'           => ['def' => 'VARCHAR(255) DEFAULT NULL', 'after' => 'artisan_name'],
        'weaving_duration' => ['def' => 'VARCHAR(255) DEFAULT NULL', 'after' => 'region'],
    ];

    foreach ($columns as $column => $spec) {
        $hasColumn = (bool) $db->getValue("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = '{$table}'
              AND COLUMN_NAME  = '{$column}'
        ");
        if (!$hasColumn) {
            $ok = $ok && (bool) $db->execute("
                ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$spec['def']} AFTER `{$spec['after']}`
            ");
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok && $module->importTranslations();
}
