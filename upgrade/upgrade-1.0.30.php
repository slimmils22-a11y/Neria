<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.30
 *
 * Certificat d'authenticité, Upsell post-achat et Fidélité par email
 * disposaient d'un vrai bouton Actif/Inactif dans le BO, mais leur clé
 * de config (NERIA_CERT_ENABLED, NERIA_UPSELL_ENABLED,
 * NERIA_LOYALTY_ENABLED) n'a jamais été semée dans
 * setDefaultConfiguration() ni install.sql — contrairement au reste du
 * module (checkout_abandonment, relationship_anniversary...), qui est
 * actif dès l'installation. Sur toute install jamais touchée par le
 * marchand, les 3 features restaient silencieusement Inactif alors
 * qu'elles sont présentées comme des fonctionnalités phares du module
 * (CHANGELOG.md) et disposent déjà de contenus de repli sûrs si activées
 * sans configuration préalable (CertificateManager : titre/sous-titre/
 * corps génériques ; LoyaltyManager::DEFAULT_TIERS).
 *
 * Décision (confirmée) : les 3 passent actives par défaut, comme le
 * reste du module. Sur une install existante, on ne force la valeur que
 * si le marchand n'a JAMAIS touché le réglage (Configuration::get()
 * renvoie strictement false uniquement quand la ligne n'existe pas en
 * base — un marchand ayant déjà désactivé explicitement une des 3
 * features a une ligne avec la valeur '0', jamais touchée par ce script).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_30(Neria $module): bool
{
    foreach (['NERIA_CERT_ENABLED', 'NERIA_UPSELL_ENABLED', 'NERIA_LOYALTY_ENABLED'] as $key) {
        if (Configuration::get($key) === false) {
            Configuration::updateGlobalValue($key, 1);
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $module->importTranslations();
}
