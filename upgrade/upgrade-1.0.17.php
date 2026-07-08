<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.17
 *
 * Chiffre rétroactivement (AES-256-GCM via CryptoManager) tous les
 * identifiants sensibles déjà enregistrés en clair dans ps_configuration :
 * mot de passe IMAP et secret HMAC (relance bounces), identifiants OAuth
 * Google (Postmaster Tools, Search Console), secret de signature du
 * webhook sortant, et les clés API tierces (DeepL, PageSpeed, SEMrush,
 * Moz, Litmus, Email on Acid). Idempotent : les valeurs déjà préfixées
 * ENC: (ou vides) sont ignorées.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_17(Neria $module): bool
{
    if (class_exists('CryptoManager') && CryptoManager::isAvailable()) {
        foreach (CryptoManager::SENSITIVE_CONFIG_KEYS as $key) {
            $value = (string) Configuration::get($key);
            if ($value !== '' && !CryptoManager::isEncrypted($value)) {
                Configuration::updateValue($key, CryptoManager::encrypt($value));
            }
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
