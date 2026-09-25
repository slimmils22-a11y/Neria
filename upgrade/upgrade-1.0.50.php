<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.50
 *
 * Sujets des e-mails traduits par Neria (clé « subject » du dictionnaire, 19 langues) pour tous les modèles natifs
 * PrestaShop : nom d'état de commande, mot de passe, création de compte, bons, retours, alertes de stock...
 * Jusqu'ici le sujet fourni par PrestaShop était conservé tel quel (anglais dans toutes les langues ajoutées après
 * l'installation). Recharge le dictionnaire depuis data/translations.json ; les textes personnalisés par le
 * marchand (is_custom = 1) sont conservés par l'importeur. Idempotent.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_50(Neria $module): bool
{
    if (!class_exists('TranslationInstaller')) {
        require_once _PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php';
    }
    $installer = new TranslationInstaller($module);
    $ok = $installer->importFromJson(__DIR__ . '/../data/translations.json');
    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return (bool) $ok;
}
