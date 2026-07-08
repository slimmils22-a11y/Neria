<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Upgrade 1.0.10 → 1.0.11
 * Panier fantôme récurrent (ghost_cart) — pas de nouvelle table,
 * utilise neria_behavioral_sent (UNIQUE customer+template+ref_id).
 * Recharge toutes les traductions depuis translations.json (fix bug
 * nouvelles clés absentes de DB sur installs existantes).
 */

if (!defined('_PS_VERSION_')) exit;

function upgrade_module_1_0_11(Neria $module): bool
{
    Configuration::updateGlobalValue('NERIA_GHOST_CART_ENABLED', 1);

    // Recharge les traductions depuis le JSON pour injecter les nouvelles clés
    // ghost_cart (et toute autre clé ajoutée depuis la dernière installation)
    $installer = new TranslationInstaller($module);
    $installer->importFromJson(__DIR__ . '/../data/translations.json');

    return true;
}
