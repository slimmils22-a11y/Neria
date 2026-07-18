<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.27
 *
 * `neria_stat` avait déjà un index composite `idx_shop_template_event`
 * (id_shop, template, event_type), mais sans `date_add` — or
 * StatsManager::detectAnomalies()/getTemplateWeekRates() (exécutées sur
 * CHAQUE page BO, pas seulement l'onglet stats, via l'assignation
 * inconditionnelle `anomaly_warnings` dans neria.php) filtrent aussi sur
 * `date_add` en plage. Sans cette colonne finale, MySQL ne pouvait pas
 * couvrir entièrement le filtre avec un seul index — mesuré en réel :
 * une page BO passant de ~0,85s à ~5,1s à 100 000 lignes dans neria_stat.
 * Index étendu pour inclure `date_add` en 4e colonne (équalités d'abord,
 * plage en dernier — ordre optimal MySQL).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_27(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $indexColumns = $db->executeS("
        SELECT COLUMN_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_stat'
          AND INDEX_NAME   = 'idx_shop_template_event'
        ORDER BY SEQ_IN_INDEX
    ");
    $hasDateAdd = false;
    foreach ((array) $indexColumns as $col) {
        if ($col['COLUMN_NAME'] === 'date_add') {
            $hasDateAdd = true;
        }
    }

    if (!empty($indexColumns) && !$hasDateAdd) {
        $ok = $ok && $db->execute("ALTER TABLE `{$prefix}neria_stat` DROP INDEX `idx_shop_template_event`");
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_stat`
            ADD INDEX `idx_shop_template_event` (`id_shop`, `template`, `event_type`, `date_add`)
        ");
    } elseif (empty($indexColumns)) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_stat`
            ADD INDEX `idx_shop_template_event` (`id_shop`, `template`, `event_type`, `date_add`)
        ");
    }

    return $ok && $module->importTranslations();
}
