<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.38
 *
 * neria_collection_sent avait une clé unique (id_neria_collection,
 * id_customer) sans id_shop, alors que processCollection() groupe déjà
 * explicitement les achats par (id_customer, id_shop) pour ne pas mélanger
 * les catalogues multi-boutiques. Un même client (email partagé) complétant
 * RÉELLEMENT la même collection sur deux boutiques distinctes voyait sa 2e
 * complétion bloquée à tort par la réservation compare-and-swap
 * (claimSend()) posée pour la 1re — l'email n'était jamais envoyé pour la
 * 2e boutique, silencieusement, sans erreur ni log.
 *
 * Ajout de la colonne id_shop (défaut 1, comme neria_behavioral_sent) et
 * élargissement de la clé unique pour permettre une réservation distincte
 * par boutique — même correctif que neria_waitlist (upgrade 1.0.28).
 *
 * Aucun nettoyage de doublons nécessaire avant la migration : l'ancienne
 * clé (déjà unique sur id_neria_collection+id_customer) garantit qu'aucune
 * ligne existante ne peut entrer en collision sur la nouvelle clé plus
 * large.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_38(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $hasShopCol = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_collection_sent'
          AND COLUMN_NAME  = 'id_shop'
    ");
    if (!$hasShopCol) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_collection_sent`
            ADD COLUMN `id_shop` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id_customer`
        ");
    }

    $hasOldKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_collection_sent'
          AND INDEX_NAME   = 'uq_col_customer'
    ");
    if ($hasOldKey) {
        $ok = $ok && $db->execute("ALTER TABLE `{$prefix}neria_collection_sent` DROP INDEX `uq_col_customer`");
    }

    $hasNewKey = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_collection_sent'
          AND INDEX_NAME   = 'uq_col_customer_shop'
    ");
    if (!$hasNewKey) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_collection_sent`
            ADD UNIQUE KEY `uq_col_customer_shop` (`id_neria_collection`, `id_customer`, `id_shop`)
        ");
    }

    return $ok && $module->importTranslations();
}
