<?php
/**
 * © 2026 NeriaSoftware - All rights reserved
 *
 * NERIA — Upgrade 1.0.37
 *
 * CooldownManager (Mode Silence) ne pouvait scoper la déduplication que par
 * id_order (déjà présent) — les templates non liés à une commande mais
 * scopés par entité (waitlist_available par produit, collection_completion
 * par collection) retombaient sur (template + client + fenêtre) seul :
 * une deuxième notification légitime pour une entité DIFFÉRENTE dans la
 * fenêtre de cooldown était bloquée à tort comme doublon de la première.
 * Ajout de la colonne ref_scope pour porter ce discriminant générique
 * (ex. "product:123", "collection:45"), lue depuis {cooldown_scope} dans
 * templateVars — même mécanisme que {id_order}.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_37(Neria $module): bool
{
    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $ok     = true;

    $exists = (bool) $db->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$prefix}neria_stat'
          AND COLUMN_NAME  = 'ref_scope'
    ");

    if (!$exists) {
        $ok = $ok && $db->execute("
            ALTER TABLE `{$prefix}neria_stat`
            ADD COLUMN `ref_scope` VARCHAR(40) NOT NULL DEFAULT ''
                COMMENT 'Portée du Mode Silence pour les envois non liés à une commande (ex: product:123, collection:45)'
                AFTER `id_order`
        ");
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $ok;
}
