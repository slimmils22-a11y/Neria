<?php
/**
 * NERIA — Upgrade 1.0.6 → 1.0.7
 *
 * Ajout de la colonne gift_mode sur neria_seasonal_campaign.
 * Idempotent : ALTER IGNORE ou vérification préalable.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_7(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;

    // Ajoute gift_mode si la colonne n'existe pas encore
    $cols = $db->executeS(
        "SHOW COLUMNS FROM `{$prefix}neria_seasonal_campaign` LIKE 'gift_mode'"
    );
    if (empty($cols)) {
        if (!$db->execute(
            "ALTER TABLE `{$prefix}neria_seasonal_campaign`
             ADD COLUMN `gift_mode` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT '1 = mode idées cadeaux (ton offrir, segments fidèles)'"
        )) {
            return false;
        }
    }

    return true;
}
