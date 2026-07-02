<?php
/**
 * NERIA — Upgrade 1.0.16
 *
 * Enregistre le hook RGPD natif actionDeleteGDPRCustomer, absent depuis
 * le début : GdprAuditManager::purgeCustomerData() existait déjà mais
 * n'était jamais déclenchée, aucun hook ne l'appelait. Sans ce hook, la
 * suppression native d'un client par le marchand (BO Clients, ou via le
 * module psgdpr) n'effaçait jamais les données Neria liées à ce client.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_16(Neria $module): bool
{
    $module->registerHook('actionDeleteGDPRCustomer');

    Configuration::updateValue('NERIA_VERSION', $module->version);

    return true;
}
