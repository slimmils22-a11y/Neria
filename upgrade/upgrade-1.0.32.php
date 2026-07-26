<?php
/**
 * © 2026 NeriaSoftware - All rights reserved
 *
 * NERIA — Upgrade 1.0.32
 *
 * Ajout de l'onglet "Automatisations comportementales" :
 * tableau de bord centralisé de tous les crons quotidiens
 * avec toggle individuel, statistiques d'envoi et bouton
 * "Forcer l'exécution".
 *
 * Nouveaux toggles pour les crons qui n'en avaient pas :
 * NERIA_BIRTHDAY_ENABLED, NERIA_FIRST_ANNIVERSARY_ENABLED,
 * NERIA_REORDER_ENABLED, NERIA_WIN_BACK_ENABLED,
 * NERIA_REWARD_EXPIRY_ENABLED, NERIA_WISHLIST_ENABLED,
 * NERIA_ABANDONED_CART_ENABLED, NERIA_POST_PURCHASE_ENABLED,
 * NERIA_SHIPPED_DELAY_ENABLED.
 * Tous activés par défaut (1) pour préserver le comportement
 * existant sur les installations déjà en production.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_32(Neria $module): bool
{
    $defaults = [
        'NERIA_BIRTHDAY_ENABLED'         => 1,
        'NERIA_FIRST_ANNIVERSARY_ENABLED' => 1,
        'NERIA_REORDER_ENABLED'          => 1,
        'NERIA_WIN_BACK_ENABLED'         => 1,
        'NERIA_REWARD_EXPIRY_ENABLED'    => 1,
        'NERIA_WISHLIST_ENABLED'         => 1,
        'NERIA_ABANDONED_CART_ENABLED'   => 1,
        'NERIA_POST_PURCHASE_ENABLED'    => 1,
        'NERIA_SHIPPED_DELAY_ENABLED'    => 1,
    ];

    foreach ($defaults as $key => $value) {
        if (Configuration::get($key) === false) {
            Configuration::updateGlobalValue($key, $value);
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $module->importTranslations();
}
