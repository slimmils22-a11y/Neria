<?php
/**
 * © 2026 NeriaSoftware - All rights reserved
 *
 * NERIA — Upgrade 1.0.35
 *
 * UpsellManager::checkConversions() attribuait une commande de conversion en
 * cherchant uniquement par id_customer + produit + fenêtre de 7 jours, SANS
 * filtre de boutique — car la table neria_upsell n'avait tout simplement
 * aucune colonne id_shop. Sur une install multi-boutiques, un client partagé
 * entre boutiques qui rachetait le même produit sur une AUTRE boutique dans
 * la fenêtre de 7 jours voyait cette conversion/ce revenu attribué à tort à
 * la suggestion envoyée par la boutique d'origine.
 *
 * Ajoute la colonne id_shop, avec backfill best-effort depuis
 * orders.id_shop (via id_order_source) pour les lignes déjà existantes —
 * les lignes dont la commande source a depuis été supprimée restent à la
 * valeur par défaut (1), cas résiduel rare et sans meilleure source
 * d'information disponible.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_35(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $hasColumn = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_upsell'
          AND COLUMN_NAME  = 'id_shop'
    ");

    if (!$hasColumn) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_upsell`
            ADD COLUMN `id_shop` INT UNSIGNED NOT NULL DEFAULT 1
                COMMENT 'Boutique d\'origine de la suggestion'
                AFTER `id_customer`,
            ADD INDEX `idx_shop` (`id_shop`)
        ");

        $ok = $ok && $db->execute("
            UPDATE `{$prefix}neria_upsell` u
            INNER JOIN `{$prefix}orders` o ON o.id_order = u.id_order_source
            SET u.id_shop = o.id_shop
        ");
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
