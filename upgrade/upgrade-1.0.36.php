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

    // id_shop obligatoire dans la contrainte — même motif que
    // upgrade-1.0.29.php pour neria_behavioral_sent (commentaire détaillé
    // là-bas) : un client partagé entre boutiques doit pouvoir recevoir le
    // même événement de queue séparément par boutique. Sans id_shop ici,
    // le second INSERT IGNORE (boutique B) échouait à tort sur la ligne déjà
    // en file pour la boutique A — email jamais envoyé pour B, sans erreur.
    $exists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_queue'
          AND INDEX_NAME   = 'uq_customer_template_ref_shop'
    ");

    // Supprime l'ancienne contrainte sans id_shop si elle a été créée par une
    // exécution précédente et buguée de ce même script — sinon elle reste
    // active en parallèle de la nouvelle et continue de bloquer à tort les
    // INSERT IGNORE d'une boutique B pour un événement déjà en file côté A.
    $oldExists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_queue'
          AND INDEX_NAME   = 'uq_customer_template_ref'
    ");
    if ($oldExists) {
        $db->execute("ALTER TABLE `{$prefix}neria_queue` DROP INDEX `uq_customer_template_ref`");
    }

    if (!$exists) {
        // Purge préalable des doublons déjà existants (le plus ancien
        // conservé), scopée par id_shop — sans ce filtre, une install
        // multi-boutiques avec des lignes 'pending' légitimes pour deux
        // boutiques différentes (même client/template/ref_id) perdait
        // silencieusement l'une des deux AVANT même que la contrainte
        // n'existe (perte réelle d'emails en attente au moment de l'upgrade).
        $db->execute("
            DELETE q1 FROM `{$prefix}neria_queue` q1
            INNER JOIN `{$prefix}neria_queue` q2
                ON q1.id_customer = q2.id_customer
               AND q1.template    = q2.template
               AND q1.ref_id      = q2.ref_id
               AND q1.id_shop     = q2.id_shop
               AND q1.id_neria_queue > q2.id_neria_queue
        ");

        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_queue`
            ADD UNIQUE KEY `uq_customer_template_ref_shop` (`id_customer`, `template`, `ref_id`, `id_shop`)
        ");
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
