<?php
/**
 * NERIA — Upgrade 1.0.19
 *
 * Ajoute un interrupteur ON/OFF pour le point d'entrée cron externe
 * (onglet Aide) — NERIA_CRON_ENABLED. Sur une install déjà en 1.0.18,
 * cette clé n'existe pas encore ; sans initialisation explicite ici,
 * getGlobalValue() renverrait "faux" et désactiverait silencieusement
 * un cron externe déjà configuré et fonctionnel chez le marchand. On
 * l'initialise donc à 1 (activé) pour ne rien casser en silence.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_19(Neria $module): bool
{
    if (Configuration::getGlobalValue('NERIA_CRON_ENABLED') === false) {
        Configuration::updateGlobalValue('NERIA_CRON_ENABLED', 1);
    }

    Configuration::updateValue('NERIA_VERSION', $module->version);

    return true;
}
