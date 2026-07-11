<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Upgrade 1.0.21 → 1.0.22
 * Scission de l'anglais en deux variantes distinctes : "en" (anglais
 * américain, code inchangé) et "gb" (anglais britannique, nouveau code),
 * sur le modèle déjà en place pour le portugais (pt/br). Le pack de
 * langue PrestaShop "United Kingdom" (ISO 'gb') n'est plus écrasé vers
 * "en" dans TranslationEngine::normalizeLang() et dispose désormais de
 * son propre bloc de traductions (orthographe britannique : colour,
 * organise, jewellery, cancelled, apologise, etc.), sur les 125 templates.
 */

if (!defined('_PS_VERSION_')) exit;

function upgrade_module_1_0_22(Neria $module): bool
{
    // Recharge les traductions depuis le JSON pour injecter le nouveau
    // bloc "gb" (anglais britannique) sur les installs existantes.
    $installer = new TranslationInstaller($module);
    $installer->importFromJson(__DIR__ . '/../data/translations.json');

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
