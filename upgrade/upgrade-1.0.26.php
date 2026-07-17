<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.26
 *
 * WaitlistManager::notifyProduct() posait directement `notified_at` au
 * moment du claim atomique, avant l'envoi réel de l'email. Si le process
 * PHP mourait entre le claim et l'envoi (crash serveur, timeout), la ligne
 * restait bloquée avec `notified_at` posé mais aucun email jamais envoyé —
 * indiscernable d'une notification réellement réussie, donc impossible à
 * nettoyer automatiquement sans risquer de redéclencher des emails en
 * double sur de vraies réussites.
 *
 * Ajout de `claim_started_at`, distincte de `notified_at` : le claim pose
 * désormais `claim_started_at`, et `notified_at` n'est posé qu'après
 * confirmation réelle de l'envoi. Un claim resté sans `notified_at` au-delà
 * d'1h est un crash confirmé, nettoyable sans ambiguïté par
 * HealthCheckManager.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_26(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $hasColumn = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_waitlist'
          AND COLUMN_NAME  = 'claim_started_at'
    ");
    if (!$hasColumn) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_waitlist`
            ADD COLUMN `claim_started_at` DATETIME NULL DEFAULT NULL AFTER `notified_at`
        ");
    }

    return $ok && $module->importTranslations();
}
