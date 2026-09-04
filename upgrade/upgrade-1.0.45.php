<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.45
 *
 * Round 301 : ajoute la colonne `signature_path` à ps_neria_certificate.
 * CertificateManager::generatePdf() résolvait jusqu'ici la signature
 * manuscrite active EN LIVE (requête sur neria_signature) à CHAQUE appel,
 * y compris pour redownload() — un certificat déjà émis changeait donc
 * rétroactivement de signature à chaque re-téléchargement dès que le
 * marchand changeait la signature manuscrite active en BO, alors que le
 * numéro de série et la date d'émission imprimés restent, eux, figés.
 * Cette colonne fige désormais la signature réellement utilisée au
 * moment de l'émission — voir generatePdf()/redownload().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_45(Neria $module): bool
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
        return true;
    }

    $columnExists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'signature_path'
    ");

    $ok = true;
    if (!$columnExists) {
        $ok = (bool) $db->execute("
            ALTER TABLE `{$table}`
            ADD COLUMN `signature_path` VARCHAR(500) DEFAULT NULL
            COMMENT 'round 301 - signature manuscrite figee a emission'
            AFTER `pdf_path`
        ");
    }

    $ok = $ok && Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
