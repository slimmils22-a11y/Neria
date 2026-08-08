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
        // Sonde la clé AVANT de chiffrer quoi que ce soit : CryptoManager::
        // encrypt() retourne la valeur EN CLAIR inchangée (pas d'exception,
        // pas de false) si la clé maîtresse est illisible (absente/corrompue
        // au moment précis de cet upgrade) — sans cette sonde, le marchand
        // croit ses secrets (mot de passe IMAP, tokens OAuth, clés API
        // tierces) chiffrés en base alors qu'ils restent en clair, sans
        // aucune trace. Même garde-fou déjà appliqué par
        // GdprAuditManager::encryptExistingRecords() pour le chiffrement
        // rétroactif des snapshots neria_stat.
        $keyProbe = CryptoManager::encrypt('neria_key_probe');
        if (!CryptoManager::isEncrypted($keyProbe)) {
            if (class_exists('WatchdogManager')) {
                (new WatchdogManager($module))->error(
                    'Chiffrement rétroactif des secrets (upgrade 1.0.17) annulé : clé de chiffrement illisible (NERIA_ENCRYPTION_KEY absente ou corrompue). Les secrets restent en clair en base.',
                    '', 'upgrade-1.0.17'
                );
            }
        } else {
            foreach (CryptoManager::SENSITIVE_CONFIG_KEYS as $key) {
                $value = (string) Configuration::get($key);
                if ($value !== '' && !CryptoManager::isEncrypted($value)) {
                    Configuration::updateValue($key, CryptoManager::encrypt($value));
                }
            }
        }
    }

    Configuration::updateValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
