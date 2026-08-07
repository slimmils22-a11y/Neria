<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.39
 *
 * neria_certificate n'a pas de colonne id_customer directe — la purge RGPD
 * (GdprAuditManager::purgeCustomerData()) doit donc passer par un JOIN sur
 * ps_orders pour retrouver les certificats d'un client. Si la commande liée
 * a déjà été supprimée physiquement de ps_orders (suppression manuelle
 * d'une commande dans le BO PrestaShop, ou tout autre nettoyage antérieur à
 * la demande RGPD), le JOIN ne matche plus rien : le certificat (nom client,
 * référence commande) n'est jamais purgé, alors que purgeCustomerData()
 * retourne quand même un total sans erreur — le marchand croit la donnée
 * effacée alors qu'elle survit indéfiniment (violation du droit à
 * l'effacement, art. 17 RGPD).
 *
 * Ajout de la colonne id_customer (backfillée depuis ps_orders pour les
 * certificats existants dont la commande existe encore) — la purge RGPD
 * peut désormais matcher directement par id_customer, indépendamment de la
 * survie de la commande liée.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_39(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $hasCol = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_certificate'
          AND COLUMN_NAME  = 'id_customer'
    ");
    if (!$hasCol) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_certificate`
            ADD COLUMN `id_customer` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id_shop`,
            ADD INDEX `idx_customer` (`id_customer`)
        ");
    }

    // Backfill depuis ps_orders pour les certificats existants dont la
    // commande n'a pas (encore) été supprimée — les certificats dont la
    // commande est déjà absente restent à id_customer=0 : rien à
    // backfiller pour eux (l'information n'existe plus nulle part), mais au
    // moins tous les futurs certificats et ceux liés à une commande encore
    // vivante deviennent purgeables directement.
    $ok = $ok && $db->execute("
        UPDATE `{$prefix}neria_certificate` nc
        INNER JOIN `{$prefix}orders` o ON o.id_order = nc.id_order
        SET nc.id_customer = o.id_customer
        WHERE nc.id_customer = 0
    ");

    return $ok && $module->importTranslations();
}
