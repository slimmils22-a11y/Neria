<?php
/**
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
        $keys = [
            'NERIA_BOUNCE_IMAP_PASS',
            'NERIA_BOUNCE_WEBHOOK_SECRET',
            'NERIA_POSTMASTER_CLIENT_SECRET',
            'NERIA_POSTMASTER_ACCESS_TOKEN',
            'NERIA_POSTMASTER_REFRESH_TOKEN',
            'NERIA_SC_CLIENT_SECRET',
            'NERIA_SC_ACCESS_TOKEN',
            'NERIA_SC_REFRESH_TOKEN',
            'NERIA_WEBHOOK_SECRET',
            'NERIA_DEEPL_KEY',
            'NERIA_PAGESPEED_API_KEY',
            'NERIA_SEMRUSH_API_KEY',
            'NERIA_MOZ_ACCESS_ID',
            'NERIA_MOZ_SECRET_KEY',
            'NERIA_LITMUS_KEY',
            'NERIA_EOA_KEY',
        ];

        foreach ($keys as $key) {
            $value = (string) Configuration::get($key);
            if ($value !== '' && !CryptoManager::isEncrypted($value)) {
                Configuration::updateValue($key, CryptoManager::encrypt($value));
            }
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
