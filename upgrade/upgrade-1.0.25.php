<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.25
 *
 * neria_preferences avait une clé unique (id_shop, id_customer, category)
 * qui ne distinguait pas deux clients différents partageant id_customer=0
 * (compte supprimé/purgé RGPD, ou jamais inscrit). La préférence de l'un
 * pouvait silencieusement écraser celle de l'autre (INSERT ... ON DUPLICATE
 * KEY UPDATE dans PreferencesManager::saveByCustomer()). La clé inclut
 * désormais l'email pour lever cette ambiguïté.
 *
 * Aucun nettoyage de doublons nécessaire avant la migration : l'ancienne
 * clé (déjà unique sur id_shop+id_customer+category) garantit qu'aucune
 * ligne existante ne peut déjà entrer en collision sur la nouvelle clé
 * plus large.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_25(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $hasOldKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_preferences'
          AND INDEX_NAME   = 'uq_shop_customer_cat'
    ");
    if ($hasOldKey) {
        $ok = $ok && $db->execute("ALTER TABLE `{$prefix}neria_preferences` DROP INDEX `uq_shop_customer_cat`");
    }

    $hasNewKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_preferences'
          AND INDEX_NAME   = 'uq_shop_customer_email_cat'
    ");
    if (!$hasNewKey) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_preferences`
            ADD UNIQUE KEY `uq_shop_customer_email_cat` (`id_shop`,`id_customer`,`email`,`category`)
        ");
    }

    return $ok && $module->importTranslations();
}
