<?php
/**
 * © 2026 NeriaSoftware - All rights reserved
 *
 * NERIA — Upgrade 1.0.34
 *
 * GdprAuditManager::auditRetention() (chargée à chaque ouverture de l'onglet
 * RGPD) filtre neria_stat sur `id_shop` + `date_col` (en plage), SANS
 * `event_type` dans le WHERE. Les index existants (idx_shop_template_event,
 * idx_shop_event_date) ont tous deux `event_type` avant `date_add` : comme
 * cette requête ne contraint pas `event_type`, MySQL ne peut utiliser que le
 * préfixe `id_shop` de ces index, pas les colonnes suivantes — scan complet
 * du reste de la table pour cette boutique à chaque ouverture de l'onglet,
 * coûteux sur un neria_stat à fort volume (boutique de plusieurs années).
 *
 * Nouvel index complémentaire (id_shop, date_add) qui couvre exactement le
 * filtre de cette requête sans la colonne event_type.
 *
 * Ajoute aussi la purge RGPD automatique quotidienne (GdprAuditManager::
 * purgeAllRegistryTables(), câblée dans BehavioralCronManager::run()) —
 * activée par défaut pour les NOUVELLES installs via le tableau DEFAULTS de
 * neria.php, mais ce défaut ne s'applique jamais aux installs déjà
 * existantes (Configuration::get() sur une clé absente renvoie false, pas
 * le défaut du tableau) : on la seed donc explicitement ici.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_34(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $exists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_stat'
          AND INDEX_NAME   = 'idx_shop_date'
    ");

    if (!$exists) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_stat`
            ADD INDEX `idx_shop_date` (`id_shop`, `date_add`)
        ");
    }

    if (Configuration::get('NERIA_GDPR_AUTO_PURGE_ENABLED') === false) {
        Configuration::updateValue('NERIA_GDPR_AUTO_PURGE_ENABLED', 1);
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
