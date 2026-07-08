<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Upgrade 1.0.20 → 1.0.21
 * Le certificat d'authenticité PDF envoyé au client (CertificateManager)
 * utilisait mail() natif avec un HTML codé en dur en français, entièrement
 * hors du système de traduction client — un client non-francophone recevait
 * son certificat en français quelle que soit sa langue. Nouveau template
 * certificate_email (18 langues), envoi désormais via Mail::Send() avec
 * détection automatique de la langue du client, comme tous les autres
 * templates du module.
 */

if (!defined('_PS_VERSION_')) exit;

function upgrade_module_1_0_21(Neria $module): bool
{
    // Recharge les traductions depuis le JSON pour injecter le nouveau
    // template certificate_email (et toute autre clé ajoutée depuis la
    // dernière installation) sur les installs existantes.
    $installer = new TranslationInstaller($module);
    $installer->importFromJson(__DIR__ . '/../data/translations.json');

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
