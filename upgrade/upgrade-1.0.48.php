<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.48
 *
 * `neria_bounces` devient scopable par boutique (round 351/bloc (d) de la
 * feuille de route Addons — ce module étant vendu à de multiples
 * commerçants, un bounce détecté sur une boutique d'une installation
 * multi-boutiques ne doit plus nécessairement bloquer TOUTES les autres
 * boutiques de l'installation par défaut).
 *
 * Ajoute la colonne `id_shop` (0 = global/héritage, N = scopé à la
 * boutique N) et fait passer la clé unique de `uq_email (email)` à
 * `uq_email_shop (email, id_shop)` pour permettre plusieurs lignes par
 * email (une globale + une par boutique le cas échéant).
 *
 * Idempotent : vérifie l'existence de la colonne avant de la créer, et
 * l'existence de l'ancienne clé unique avant de la remplacer.
 *
 * Sécurité de la migration : `ALTER TABLE ... ADD COLUMN id_shop ...
 * DEFAULT 0` fait passer TOUTES les lignes historiques à `id_shop = 0`
 * (portée globale) — c'est-à-dire que leur comportement de blocage
 * (elles bloquaient déjà tout l'envoi avant cette version) est
 * strictement inchangé par la migration elle-même. Le nouveau réglage
 * `NERIA_BOUNCE_CROSS_SHOP_ENABLED` (défaut désactivé = scopé) ne
 * s'applique qu'aux NOUVEAUX bounces enregistrés après la mise à jour,
 * via webhook ou ajout manuel (le canal IMAP, sans signal fiable de
 * boutique d'origine, continue toujours à écrire en portée globale).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_48(Neria $module): bool
{
    $db = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $table = $prefix . 'neria_bounces';

    $exists = $db->executeS("SHOW TABLES LIKE '" . pSQL($table) . "'");
    if (!is_array($exists) || empty($exists)) {
        return true;
    }

    $columns = $db->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'id_shop'");
    if (!is_array($columns) || empty($columns)) {
        $ok = $db->execute(
            "ALTER TABLE `{$table}` ADD COLUMN `id_shop` INT(11) NOT NULL DEFAULT 0
             COMMENT '0 = global (bloque toutes les boutiques), N = scopé à la boutique N'
             AFTER `email`"
        );
        if (!$ok && class_exists('WatchdogManager')) {
            (new WatchdogManager($module))->error(
                'Migration upgrade 1.0.48 (ajout colonne id_shop) échouée sur neria_bounces — le scoping par boutique des bounces restera indisponible jusqu\'à résolution manuelle.',
                '', 'upgrade-1.0.48'
            );

            return false;
        }
    }

    $indexes = $db->executeS("SHOW INDEX FROM `{$table}` WHERE Key_name = 'uq_email'");
    if (is_array($indexes) && !empty($indexes)) {
        $db->execute("ALTER TABLE `{$table}` DROP INDEX `uq_email`");
    }

    $newIndex = $db->executeS("SHOW INDEX FROM `{$table}` WHERE Key_name = 'uq_email_shop'");
    if (!is_array($newIndex) || empty($newIndex)) {
        $ok = $db->execute("ALTER TABLE `{$table}` ADD UNIQUE KEY `uq_email_shop` (`email`, `id_shop`)");
        if (!$ok && class_exists('WatchdogManager')) {
            (new WatchdogManager($module))->error(
                'Migration upgrade 1.0.48 (clé unique uq_email_shop) échouée sur neria_bounces — vérifier l\'absence de doublons (email, id_shop) résiduels avant de réessayer manuellement.',
                '', 'upgrade-1.0.48'
            );

            return false;
        }
    }

    $shopIndex = $db->executeS("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_shop'");
    if (!is_array($shopIndex) || empty($shopIndex)) {
        $db->execute("ALTER TABLE `{$table}` ADD KEY `idx_shop` (`id_shop`)");
    }

    Configuration::updateGlobalValue('NERIA_BOUNCE_CROSS_SHOP_ENABLED', 0);
    Configuration::updateGlobalValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
