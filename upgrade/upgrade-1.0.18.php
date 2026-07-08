<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.18
 *
 * Renforcement du Watchdog :
 *  - Nouveau point d'entrée cron externe (controllers/front/cron.php) pour
 *    une surveillance active indépendante du trafic visiteurs. Génère le
 *    jeton NERIA_CRON_TOKEN s'il n'existe pas encore (installs existantes).
 *  - 4 tâches de fond supplémentaires remontent désormais leur statut au
 *    Watchdog (rapport mensuel, conversions upsell, récaps fidélité,
 *    campagnes saisonnières), plus la file d'envoi (queue).
 *  - Contrôle de santé "crypto_key" renforcé : tente un vrai déchiffrement
 *    d'un échantillon de secrets stockés au lieu de vérifier seulement la
 *    présence de la clé.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_18(Neria $module): bool
{
    if (!Configuration::getGlobalValue('NERIA_CRON_TOKEN')) {
        Configuration::updateGlobalValue('NERIA_CRON_TOKEN', bin2hex(random_bytes(24)));
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
