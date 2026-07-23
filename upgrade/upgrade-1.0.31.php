<?php
/**
 * © 2026 NeriaSoftware - All rights reserved
 *
 * NERIA — Upgrade 1.0.31
 *
 * Le système d'authentification par licence (LicenseManager) a ajouté
 * de nouvelles clés de config (NERIA_LICENSE_KEY, NERIA_LICENSE_TOKEN,
 * NERIA_LICENSE_LAST_CHECK, NERIA_LICENSE_EXPIRES, NERIA_LICENSE_PLAN,
 * NERIA_LICENSE_SOURCE) uniquement dans setDefaultConfiguration(), qui
 * n'est appelée qu'à l'installation initiale ou lors d'une réinitialisation
 * complète (Zone de danger) — jamais lors d'une simple mise à jour de
 * version. Sur toute install déjà existante avant ce chantier, ces clés
 * n'existent donc pas en base.
 *
 * Sans conséquence fonctionnelle immédiate (LicenseManager traite déjà
 * une config absente comme une chaîne vide/0 partout, jamais de crash),
 * mais on sème quand même les valeurs par défaut ici pour rester cohérent
 * avec le reste du module et éviter des clés absentes en base.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_31(Neria $module): bool
{
    $defaults = [
        'NERIA_LICENSE_KEY'        => '',
        'NERIA_LICENSE_TOKEN'      => '',
        'NERIA_LICENSE_LAST_CHECK' => 0,
        'NERIA_LICENSE_EXPIRES'    => 0,
        'NERIA_LICENSE_PLAN'       => '',
        'NERIA_LICENSE_SOURCE'     => '',
    ];

    foreach ($defaults as $key => $value) {
        if (Configuration::get($key) === false) {
            Configuration::updateGlobalValue($key, $value);
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return $module->importTranslations();
}
