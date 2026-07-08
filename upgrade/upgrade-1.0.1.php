<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Mise à niveau vers la version 1.0.1
 *
 * Ajoute la fonctionnalité « Relances Devis B2B » :
 *   - TABLE 23 : neria_quote (devis suivis + flags de relance)
 *   - Clé de configuration NERIA_QUOTE_REMINDERS_ENABLED (activée par défaut)
 *
 * Ce script est exécuté automatiquement par PrestaShop lorsqu'un marchand
 * met à jour le module sans le réinstaller (version en base < 1.0.1).
 * Toutes les opérations sont idempotentes : rejouer le script est sans danger.
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
function upgrade_module_1_0_1($module)
{
    $db = Db::getInstance();

    // ── TABLE 23 : neria_quote ────────────────────────────────────
    $created = $db->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_quote` (
            `id_quote`        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `id_shop`         INT             NOT NULL DEFAULT 1,
            `id_customer`     INT UNSIGNED    NOT NULL,
            `quote_ref`       VARCHAR(50)     NOT NULL DEFAULT \'\',
            `quote_total`     DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            `id_currency`     INT UNSIGNED    NOT NULL DEFAULT 1,
            `expiry_date`     DATE            NOT NULL,
            `status`          ENUM(\'active\',\'won\',\'lost\',\'expired\',\'extended\') NOT NULL DEFAULT \'active\',
            `sent_48h`        TINYINT(1)      NOT NULL DEFAULT 0,
            `sent_day`        TINYINT(1)      NOT NULL DEFAULT 0,
            `sent_extension`  TINYINT(1)      NOT NULL DEFAULT 0,
            `date_add`        DATETIME        NOT NULL,
            `date_upd`        DATETIME        NOT NULL,
            PRIMARY KEY (`id_quote`),
            KEY `idx_shop_status`  (`id_shop`, `status`),
            KEY `idx_customer`     (`id_customer`),
            KEY `idx_expiry`       (`expiry_date`),
            KEY `idx_shop_expiry`  (`id_shop`, `expiry_date`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // ── Configuration : activée par défaut si absente ─────────────
    if (Configuration::get('NERIA_QUOTE_REMINDERS_ENABLED') === false) {
        Configuration::updateValue('NERIA_QUOTE_REMINDERS_ENABLED', 1);
    }

    // ── Import des traductions des nouveaux templates ─────────────
    // translations.json n'est importé en base (ps_neria_translation) qu'à
    // l'installation. Les templates ajoutés après coup doivent être importés
    // explicitement, sinon {neria_trad} et le sujet (dérivé de greeting_main)
    // restent vides à l'envoi. importTemplate() est idempotent (INSERT IGNORE
    // + suppression préalable des seules lignes is_custom = 0).
    if (class_exists('TranslationInstaller')) {
        $jsonPath = _PS_MODULE_DIR_ . 'neria/data/translations.json';
        $installer = new TranslationInstaller($module);
        // checkout_abandonment partage le même bug latent (jamais importé).
        $templates = [
            'quote_expiry_48h',
            'quote_expiry_day',
            'quote_extension_offer',
            'checkout_abandonment',
        ];
        foreach ($templates as $tpl) {
            $installer->importTemplate($jsonPath, $tpl);
        }
    }

    return (bool) $created;
}
