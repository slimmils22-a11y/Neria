<?php
/**
 * NERIA — Mise à niveau vers la version 1.0.2
 *
 * Ajoute la fonctionnalité « Réconciliation post-remboursement » :
 *   - TABLE 24 : neria_reconciliation (séquence J+1/J+3/J+7)
 *   - Clé de configuration NERIA_REFUND_RECONCILIATION_ENABLED (activée par défaut)
 *   - Import des traductions des 3 nouveaux templates
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
function upgrade_module_1_0_2($module)
{
    $db = Db::getInstance();

    // ── TABLE 24 : neria_reconciliation ───────────────────────────
    $created = $db->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neria_reconciliation` (
            `id_reconciliation` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `id_order`          INT UNSIGNED  NOT NULL,
            `id_customer`       INT UNSIGNED  NOT NULL,
            `id_shop`           INT           NOT NULL DEFAULT 1,
            `send_1_date`       DATE          NOT NULL,
            `send_2_date`       DATE          NOT NULL,
            `send_3_date`       DATE          NOT NULL,
            `sent_1`            TINYINT(1)    NOT NULL DEFAULT 0,
            `sent_2`            TINYINT(1)    NOT NULL DEFAULT 0,
            `sent_3`            TINYINT(1)    NOT NULL DEFAULT 0,
            `status`            ENUM(\'active\',\'cancelled\') NOT NULL DEFAULT \'active\',
            `date_add`          DATETIME      NOT NULL,
            PRIMARY KEY (`id_reconciliation`),
            UNIQUE KEY `uniq_order` (`id_order`),
            KEY `idx_status`   (`status`),
            KEY `idx_customer` (`id_customer`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // ── Configuration : activée par défaut si absente ─────────────
    if (Configuration::get('NERIA_REFUND_RECONCILIATION_ENABLED') === false) {
        Configuration::updateValue('NERIA_REFUND_RECONCILIATION_ENABLED', 1);
    }

    // ── Import des traductions des 3 nouveaux templates ───────────
    if (class_exists('TranslationInstaller')) {
        $jsonPath = _PS_MODULE_DIR_ . 'neria/data/translations.json';
        $installer = new TranslationInstaller($module);
        foreach (['refund_reconciliation_1', 'refund_reconciliation_2', 'refund_reconciliation_3'] as $tpl) {
            $installer->importTemplate($jsonPath, $tpl);
        }
    }

    return (bool) $created;
}
