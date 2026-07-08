<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Mise à niveau vers la version 1.0.3
 *
 * Ajoute la fonctionnalité « Rappel fin de vie produit » :
 *   - TABLE 25 : neria_product_lifespan (durée de vie estimée par produit)
 *   - Clé de configuration NERIA_LIFESPAN_ENABLED (activée par défaut)
 *   - Import des traductions du nouveau template product_lifespan_reminder
 *
 * Toutes les opérations sont idempotentes.
 *
 * @author Neria
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Neria $module
 * @return bool
 */
function upgrade_module_1_0_3($module)
{
    $db = Db::getInstance();

    // ── TABLE 25 : neria_product_lifespan ─────────────────────────
    $created = $db->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_product_lifespan` (
            `id_lifespan`   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
            `id_shop`       INT               NOT NULL DEFAULT 1,
            `id_product`    INT UNSIGNED      NOT NULL,
            `lifespan_days` SMALLINT UNSIGNED NOT NULL,
            `alert_days`    SMALLINT UNSIGNED NOT NULL DEFAULT 7,
            `date_add`      DATETIME          NOT NULL,
            `date_upd`      DATETIME          NOT NULL,
            PRIMARY KEY (`id_lifespan`),
            UNIQUE KEY `uniq_shop_product` (`id_shop`, `id_product`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // ── Configuration : activée par défaut si absente ─────────────
    if (Configuration::get('NERIA_LIFESPAN_ENABLED') === false) {
        Configuration::updateValue('NERIA_LIFESPAN_ENABLED', 1);
    }

    // ── Import des traductions du nouveau template ─────────────────
    if (class_exists('TranslationInstaller')) {
        $jsonPath = _PS_MODULE_DIR_ . 'neria/data/translations.json';
        $installer = new TranslationInstaller($module);
        $installer->importTemplate($jsonPath, 'product_lifespan_reminder');
    }

    return (bool) $created;
}
