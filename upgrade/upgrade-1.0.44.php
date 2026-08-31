<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.44
 *
 * Round 261 : enregistre le hook actionObjectOrderDeleteAfter, absent
 * depuis le début. StatsManager::adjustConversionRevenueForOrder() existait
 * déjà (utilisée par OrderTriggersManager::handleRefund() sur un
 * remboursement réel) mais rien ne la déclenchait sur une SUPPRESSION
 * physique de commande (BO > Commandes > Supprimer) — les KPIs de revenu/
 * ROI par campagne (dashboard, tendances, ABTest) restaient surestimés
 * indéfiniment du montant de toute commande supprimée.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_44(Neria $module): bool
{
    $module->registerHook('actionObjectOrderDeleteAfter');

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
