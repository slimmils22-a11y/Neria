<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.43
 *
 * Round 241 : QueueManager::processSingle() et WebhookManager::processQueue()
 * réservent désormais atomiquement chaque ligne (status='sending') AVANT
 * l'envoi, pour survivre à un crash du process entre l'envoi réussi et
 * l'écriture du statut final — auparavant un crash à ce moment précis
 * laissait la ligne 'pending' et le prochain passage du cron la renvoyait
 * en double.
 *
 * Les colonnes `status` de ps_neria_queue et ps_neria_webhook_queue sont des
 * ENUM ne listant PAS 'sending' — sans cette migration, une valeur non
 * listée dans un ENUM est silencieusement tronquée en chaîne vide par MySQL
 * en mode non strict (constaté en réel : la ligne finit avec status=''`,
 * qui ne correspond à AUCUNE des valeurs filtrées par le SELECT
 * (status='pending') NI par le nettoyage de reprise après crash
 * (status='sending') — la ligne reste alors bloquée EN PERMANENCE, plus
 * grave que le bug d'origine (email/webhook jamais retenté, silencieusement,
 * au lieu d'être occasionnellement dupliqué).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_43(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $tables = [
        $prefix . 'neria_queue'         => "ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending'",
        $prefix . 'neria_webhook_queue' => "ENUM('pending','sending','done','failed') NOT NULL DEFAULT 'pending'",
    ];

    foreach ($tables as $table => $enumDef) {
        $tableExists = (bool) $db->getValue("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
        ");
        if (!$tableExists) {
            continue;
        }

        $currentType = (string) $db->getValue("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = '{$table}'
              AND COLUMN_NAME  = 'status'
        ");
        if (strpos($currentType, "'sending'") !== false) {
            continue; // déjà migré (réinstallation depuis un install.sql à jour)
        }

        $ok = $ok && (bool) $db->execute("
            ALTER TABLE `{$table}` MODIFY COLUMN `status` {$enumDef}
        ");
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok && $module->importTranslations();
}
