<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — Upgrade 1.0.46
 *
 * Chiffre rétroactivement (AES-256-GCM via CryptoManager) la clé et le
 * jeton de licence NeriaSoftware déjà enregistrés en clair dans
 * ps_configuration (NERIA_LICENSE_KEY, NERIA_LICENSE_TOKEN) — ajoutés à
 * CryptoManager::SENSITIVE_CONFIG_KEYS au round 341 (hors round,
 * 12/09/2026). Idempotent : les valeurs déjà préfixées ENC: (ou vides)
 * sont ignorées.
 *
 * Contrairement à upgrade-1.0.17.php (qui parcourt CHAQUE boutique active,
 * car les clés qu'il migre sont légitimement scopées par boutique — ex.
 * NERIA_WEBHOOK_SECRET), NERIA_LICENSE_KEY/_TOKEN sont GLOBAUX à
 * l'installation : ils sont TOUJOURS écrits via
 * Configuration::updateGlobalValue() par LicenseManager (activateLicense()/
 * storeToken()), jamais scopés par id_shop. Reproduire ici la boucle par
 * boutique de 1.0.17 écrirait à tort une valeur chiffrée SCOPÉE À LA
 * BOUTIQUE (Configuration::updateValue() avec un $idShop explicite crée une
 * ligne d'override PAR BOUTIQUE) au lieu de mettre à jour la seule ligne
 * globale réellement lue par LicenseManager — laissant la ligne globale en
 * clair indéfiniment tout en créant des doublons chiffrés inertes. On lit
 * et on écrit donc uniquement la valeur globale (id_shop forcé à 0, même
 * pattern que CryptoManager::generateAndStoreKey()).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_46(Neria $module): bool
{
    if (class_exists('CryptoManager') && CryptoManager::isAvailable()) {
        // Sonde la clé AVANT de chiffrer quoi que ce soit — même garde-fou
        // que upgrade-1.0.17.php (voir son commentaire détaillé) : sans
        // cette sonde, un marchand croirait sa clé/jeton de licence chiffrés
        // en base alors qu'ils resteraient en clair, sans aucune trace.
        $keyProbe = CryptoManager::encrypt('neria_key_probe');
        if (!CryptoManager::isEncrypted($keyProbe)) {
            if (class_exists('WatchdogManager')) {
                (new WatchdogManager($module))->error(
                    'Chiffrement rétroactif de la licence (upgrade 1.0.46) annulé : clé de chiffrement illisible (NERIA_ENCRYPTION_KEY absente ou corrompue). La clé/le jeton de licence restent en clair en base.',
                    '', 'upgrade-1.0.46'
                );
            }
        } else {
            foreach (['NERIA_LICENSE_KEY', 'NERIA_LICENSE_TOKEN'] as $key) {
                $value = (string) Configuration::get($key, null, null, 0);
                if ($value !== '' && !CryptoManager::isEncrypted($value)) {
                    Configuration::updateGlobalValue($key, CryptoManager::encrypt($value));
                }
            }
        }
    }

    // updateGlobalValue() : cohérent avec le correctif round 312
    // (upgrade-1.0.45.php) — updateValue() sans $idShop explicite retombe
    // sur la boutique du contexte d'exécution dès que le multi-boutique est
    // actif, désynchronisant ce compteur de version (bug historique déjà
    // documenté, scripts 1.0.12 à 1.0.44 non retouchés rétroactivement).
    Configuration::updateGlobalValue('NERIA_INSTALLED_VERSION', $module->version);

    return true;
}
