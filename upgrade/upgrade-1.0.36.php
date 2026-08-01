<?php
/**
 * © 2026 NeriaSoftware - All rights reserved
 *
 * NERIA — Upgrade 1.0.36
 *
 * QueueManager::enqueue() n'avait aucune protection anti-doublon (pas de
 * contrainte UNIQUE, pas d'INSERT IGNORE) : si BehavioralCronManager::send()
 * était invoqué deux fois pour le même événement (double exécution de cron,
 * webhook rejoué), le même client recevait le même email en double à
 * l'heure programmée. $refId est déjà le même identifiant utilisé pour la
 * déduplication de neria_behavioral_sent (id_customer+template+ref_id) —
 * on applique désormais la même contrainte à neria_queue.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_36(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $exists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_queue'
          AND INDEX_NAME   = 'uq_customer_template_ref'
    ");

    if (!$exists) {
        // Purge préalable des doublons déjà existants (le plus ancien
        // conservé) — sinon ADD UNIQUE échoue sur une install déjà en
        // production avec des doublons historiques.
        $db->execute("
            DELETE q1 FROM `{$prefix}neria_queue` q1
            INNER JOIN `{$prefix}neria_queue` q2
                ON q1.id_customer = q2.id_customer
               AND q1.template    = q2.template
               AND q1.ref_id      = q2.ref_id
               AND q1.id_neria_queue > q2.id_neria_queue
        ");

        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_queue`
            ADD UNIQUE KEY `uq_customer_template_ref` (`id_customer`, `template`, `ref_id`)
        ");
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
