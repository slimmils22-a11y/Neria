<?php
/**
 * © 2026 NeriaSoftware - All rights reserved
 *
 * NERIA — Upgrade 1.0.33
 *
 * StatsManager::getRevenueStats() et getRevenueDailyByCategory() (chargées
 * à chaque ouverture de l'onglet Stats) filtrent neria_stat sur
 * `id_shop` + `event_type` + `date_add` (en plage), SANS `template` dans le
 * WHERE. L'index existant idx_shop_template_event (id_shop, template,
 * event_type, date_add) a `template` en 2e colonne : comme ces deux
 * requêtes ne contraignent pas `template`, MySQL ne peut utiliser que le
 * préfixe `id_shop` de cet index, pas les colonnes suivantes — scan de
 * plage sur le reste, coûteux sur un neria_stat à fort volume (100 000+
 * lignes déjà mesurées en réel pour un autre cas similaire, cf.
 * upgrade-1.0.27.php).
 *
 * Nouvel index complémentaire (id_shop, event_type, date_add) qui couvre
 * exactement le filtre de ces deux requêtes sans la colonne template.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_33(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $exists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_stat'
          AND INDEX_NAME   = 'idx_shop_event_date'
    ");

    if (!$exists) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_stat`
            ADD INDEX `idx_shop_event_date` (`id_shop`, `event_type`, `date_add`)
        ");
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
