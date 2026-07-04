<?php
/**
 * NERIA — Upgrade 1.0.20
 *
 * Deux dernières fonctionnalités avant le packaging final :
 *  - Témoin silencieux : NERIA_ARCHIVE_EMAIL (copie BCC de chaque email
 *    envoyé). Déjà géré par les valeurs par défaut à l'install ; ici on
 *    l'initialise pour les installs existantes.
 *  - Empreinte vocale : nouvelle table neria_voice_profile (mots
 *    bannis/préférés + notes de ton par langue).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_20(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $ok = $ok && $db->execute("
        CREATE TABLE IF NOT EXISTS `{$prefix}neria_voice_profile` (
            `id_voice_profile` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop`          INT UNSIGNED NOT NULL DEFAULT 1,
            `lang`             VARCHAR(5)   NOT NULL,
            `banned_words`     TEXT         DEFAULT NULL,
            `preferred_words`  TEXT         DEFAULT NULL,
            `tone_notes`       TEXT         DEFAULT NULL,
            `date_upd`         DATETIME     NOT NULL,
            PRIMARY KEY (`id_voice_profile`),
            UNIQUE KEY `uq_shop_lang` (`id_shop`, `lang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (Configuration::getGlobalValue('NERIA_ARCHIVE_EMAIL') === false) {
        Configuration::updateGlobalValue('NERIA_ARCHIVE_EMAIL', '');
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return (bool) $ok;
}
