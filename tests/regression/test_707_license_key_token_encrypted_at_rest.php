<?php
/**
 * Régression : NERIA_LICENSE_KEY et NERIA_LICENSE_TOKEN doivent être
 * chiffrés au repos (AES-256-GCM via CryptoManager), au même titre que les
 * autres identifiants sensibles du module (mot de passe IMAP, tokens
 * OAuth, secret webhook...).
 *
 * Bug identifié hors round (12/09/2026, suite round 341, finding agent) :
 * NERIA_LICENSE_KEY/NERIA_LICENSE_TOKEN étaient absents de
 * CryptoManager::SENSITIVE_CONFIG_KEYS et jamais chiffrés — ni à
 * l'écriture (LicenseManager::activateLicense()/storeToken()), ni
 * rétroactivement par un script upgrade, ni audités par
 * HealthCheckManager::checkSecretsEncrypted().
 *
 * Corrigé : les deux clés ajoutées à SENSITIVE_CONFIG_KEYS ; storeToken()/
 * activateLicense() chiffrent désormais la valeur écrite ; les 6 sites de
 * lecture (isEmailSendingAllowed(), verifyTokenSignature(), validateLicense(),
 * checkDomainChange(), getStatusForDisplay() ×2) déchiffrent désormais la
 * valeur lue ; upgrade-1.0.46.php chiffre rétroactivement la valeur GLOBALE
 * existante (pas de boucle par boutique, contrairement à upgrade-1.0.17.php
 * — ces 2 clés sont toujours écrites via Configuration::updateGlobalValue(),
 * jamais scopées par boutique).
 *
 * Test comportemental réel : écrit une clé/jeton en clair (simulateur d'un
 * état pré-migration), exécute upgrade_module_1_0_46(), vérifie que la
 * valeur brute en base est bien préfixée ENC: et que LicenseManager::
 * getStatusForDisplay() la déchiffre correctement (round-trip complet à
 * travers la vraie classe, pas seulement CryptoManager isolément). Vérifie
 * aussi l'idempotence (2e exécution sans double-chiffrement) et la
 * rétrocompatibilité (une valeur restée en clair reste lisible malgré tout,
 * CryptoManager::decrypt() étant un passthrough sur une valeur non
 * préfixée ENC:).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.46.php';

    neria_assert(
        in_array('NERIA_LICENSE_KEY', CryptoManager::SENSITIVE_CONFIG_KEYS, true)
            && in_array('NERIA_LICENSE_TOKEN', CryptoManager::SENSITIVE_CONFIG_KEYS, true),
        "NERIA_LICENSE_KEY/NERIA_LICENSE_TOKEN ne figurent plus dans CryptoManager::SENSITIVE_CONFIG_KEYS — régression du correctif hors round 12/09/2026 (suite round 341)"
    );

    $module = neria_test_module();

    // Sauvegarde de l'état réel avant modification (restauré en finally).
    $origKey       = Configuration::get('NERIA_LICENSE_KEY', null, null, 0);
    $origToken     = Configuration::get('NERIA_LICENSE_TOKEN', null, null, 0);

    try {
        $plainKey   = 'NERIA-T707-T707-T707';
        $plainToken = json_encode(['valid' => true, 'expires' => 0, 'sig' => 'fake_sig_707'], JSON_UNESCAPED_SLASHES);

        // Étape 1 : simule un état pré-migration (valeurs en clair, comme
        // avant ce correctif).
        Configuration::updateGlobalValue('NERIA_LICENSE_KEY', $plainKey);
        Configuration::updateGlobalValue('NERIA_LICENSE_TOKEN', $plainToken);

        neria_assert(
            !CryptoManager::isEncrypted((string) Configuration::get('NERIA_LICENSE_KEY', null, null, 0)),
            "jeu de test invalide : la valeur devrait être en clair avant migration"
        );

        // Rétrocompatibilité AVANT migration : LicenseManager doit rester
        // capable de lire une valeur encore en clair (CryptoManager::decrypt()
        // est un passthrough sur une valeur non préfixée ENC:).
        $mgrBefore = new LicenseManager($module);
        $statusBefore = $mgrBefore->getStatusForDisplay();
        neria_assert(
            $statusBefore['has_key'] === true,
            "LicenseManager::getStatusForDisplay() ne détecte plus une clé de licence encore en clair (rétrocompatibilité pré-migration cassée)"
        );

        // Étape 2 : exécute la migration rétroactive.
        $upgradeOk = upgrade_module_1_0_46($module);
        neria_assert($upgradeOk === true, "upgrade_module_1_0_46() n'a pas retourné true");

        $storedKeyRaw   = (string) Configuration::get('NERIA_LICENSE_KEY', null, null, 0);
        $storedTokenRaw = (string) Configuration::get('NERIA_LICENSE_TOKEN', null, null, 0);

        neria_assert(
            CryptoManager::isEncrypted($storedKeyRaw),
            "upgrade_module_1_0_46() n'a pas chiffré NERIA_LICENSE_KEY — régression du correctif hors round 12/09/2026 (suite round 341)"
        );
        neria_assert(
            CryptoManager::isEncrypted($storedTokenRaw),
            "upgrade_module_1_0_46() n'a pas chiffré NERIA_LICENSE_TOKEN — régression du correctif hors round 12/09/2026 (suite round 341)"
        );
        neria_assert(
            CryptoManager::decrypt($storedKeyRaw) === $plainKey,
            "la valeur chiffrée de NERIA_LICENSE_KEY ne redonne pas la clé d'origine après déchiffrement"
        );
        neria_assert(
            CryptoManager::decrypt($storedTokenRaw) === $plainToken,
            "la valeur chiffrée de NERIA_LICENSE_TOKEN ne redonne pas le jeton d'origine après déchiffrement"
        );

        // Round-trip via la vraie classe (pas seulement CryptoManager isolément) :
        // getStatusForDisplay() doit afficher exactement la même clé masquée
        // qu'avant migration, preuve que le chemin de lecture déchiffre bien.
        $mgrAfter = new LicenseManager($module);
        $statusAfter = $mgrAfter->getStatusForDisplay();
        neria_assert(
            $statusAfter['key_masked'] === $statusBefore['key_masked'],
            "getStatusForDisplay() affiche une clé masquée différente après chiffrement au repos (key_masked avant='" . $statusBefore['key_masked'] . "', après='" . $statusAfter['key_masked'] . "') — le déchiffrement à la lecture serait cassé"
        );

        // Idempotence : une 2e exécution ne doit PAS re-chiffrer une valeur
        // déjà chiffrée (isEncrypted() court-circuite dans le script).
        $upgradeOk2 = upgrade_module_1_0_46($module);
        neria_assert($upgradeOk2 === true, "upgrade_module_1_0_46() n'a pas retourné true à la 2e exécution");
        $storedKeyRaw2 = (string) Configuration::get('NERIA_LICENSE_KEY', null, null, 0);
        neria_assert(
            $storedKeyRaw2 === $storedKeyRaw,
            "upgrade_module_1_0_46() n'est plus idempotent — une valeur déjà chiffrée a été modifiée par une 2e exécution"
        );

        // Aucune ligne d'override PAR BOUTIQUE ne doit avoir été créée —
        // seule la ligne GLOBALE (id_shop NULL) doit exister pour ces clés.
        $prefix = neria_test_prefix();
        $db     = neria_test_db();
        $shopScopedRows = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration
             WHERE name IN ('NERIA_LICENSE_KEY', 'NERIA_LICENSE_TOKEN') AND id_shop IS NOT NULL"
        );
        neria_assert(
            $shopScopedRows === 0,
            "upgrade_module_1_0_46() a créé " . $shopScopedRows . " ligne(s) d'override par boutique pour une clé pourtant globale — régression : ces clés doivent rester écrites via updateGlobalValue(), jamais Configuration::updateValue() avec un \$idShop explicite"
        );

        return [
            'pass'    => true,
            'message' => "NERIA_LICENSE_KEY/NERIA_LICENSE_TOKEN sont désormais chiffrés au repos, migration rétroactive idempotente et sans création d'override par boutique, rétrocompatibilité pré-migration préservée, round-trip de lecture via LicenseManager confirmé",
        ];
    } finally {
        if ($origKey === false || $origKey === '') {
            Configuration::deleteByName('NERIA_LICENSE_KEY');
        } else {
            Configuration::updateGlobalValue('NERIA_LICENSE_KEY', (string) $origKey);
        }
        if ($origToken === false || $origToken === '') {
            Configuration::deleteByName('NERIA_LICENSE_TOKEN');
        } else {
            Configuration::updateGlobalValue('NERIA_LICENSE_TOKEN', (string) $origToken);
        }
    }
}
