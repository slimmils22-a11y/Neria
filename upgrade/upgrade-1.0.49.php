<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.49
 *
 * neria_queue : la clé unique `uq_customer_template_ref_shop` (id_customer, template, ref_id, id_shop) reçoit
 * `recipient_email` (préfixe 191, compatible avec la limite d'index de 767 octets).
 *
 * Problème corrigé : toutes les personnes SANS compte client partagent id_customer = 0. Avec l'ancienne clé, un
 * envoi manuel planifié d'un modèle à une adresse libre occupait la clé (0, modèle, 0, boutique) et refusait, avec
 * le message trompeur « déjà programmé pour ce client », le même modèle pour TOUTES les autres adresses libres de
 * la boutique jusqu'au traitement de la première. Pour un client identifié (id_customer > 0) le comportement est
 * inchangé (même adresse).
 *
 * Idempotent : ne fait rien si la clé porte déjà `recipient_email`. Migration sans perte (la nouvelle clé est plus
 * permissive que l'ancienne : toute ligne valide pour l'une l'est pour l'autre).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_49(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $table  = $prefix . 'neria_queue';
    $ok     = true;

    $tableExists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
    ");

    if ($tableExists) {
        $rows = $db->executeS("
            SELECT COLUMN_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = 'uq_customer_template_ref_shop'
            ORDER BY SEQ_IN_INDEX
        ");
        $columns = is_array($rows) ? array_column($rows, 'COLUMN_NAME') : [];

        if (!in_array('recipient_email', $columns, true)) {
            $drop = $columns ? 'DROP INDEX `uq_customer_template_ref_shop`, ' : '';
            $ok = (bool) $db->execute("
                ALTER TABLE `{$table}`
                {$drop}ADD UNIQUE KEY `uq_customer_template_ref_shop`
                    (`id_customer`, `template`, `ref_id`, `id_shop`, `recipient_email`(191))
            ");
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
