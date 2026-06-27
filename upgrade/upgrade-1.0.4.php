<?php
/**
 * NERIA — Mise à niveau vers la version 1.0.4
 *
 * Ajoute la fonctionnalité « Score de propension à l'achat » :
 *   - TABLE 26 : neria_propensity_score
 *
 * @author Neria
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_4($module)
{
    $db = Db::getInstance();

    $created = $db->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_propensity_score` (
            `id_propensity`      INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `id_customer`        INT UNSIGNED     NOT NULL,
            `id_shop`            INT              NOT NULL DEFAULT 1,
            `score`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `score_recency`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `score_frequency`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `score_engagement`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `score_seasonality`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `date_upd`           DATETIME         NOT NULL,
            PRIMARY KEY (`id_propensity`),
            UNIQUE KEY `uniq_customer_shop` (`id_customer`, `id_shop`),
            KEY `idx_score` (`score`),
            KEY `idx_shop_score` (`id_shop`, `score`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    return (bool) $created;
}
