<?php
/**
 * Régression : LicenseManager::validateLicense() ne doit plus confondre un
 * ÉCHEC DE DÉCHIFFREMENT de NERIA_LICENSE_KEY (clé de chiffrement du module
 * altérée sur ce shop/worker, valeur ENC:... corrompue) avec une licence
 * JAMAIS ACTIVÉE (CONFIG_KEY réellement vide).
 *
 * Bug identifié le 14/09/2026 (round 356, audit dédié LicenseManager) :
 * $key = CryptoManager::decrypt(Configuration::get(CONFIG_KEY)) retourne ''
 * dans les DEUX cas — avant le chiffrement AES de CONFIG_KEY (round 160,
 * commit c149835 le 12/09/2026), $key === '' ne pouvait signifier QUE
 * "jamais activé". Depuis ce commit, $key === '' peut AUSSI survenir sur un
 * client payant et parfaitement activé, à cause d'un simple accroc de
 * déchiffrement local — le code purgeait alors CONFIG_LAST_CHECK/TOKEN/
 * REVOKED_AT/EXPIRES/PLAN/SOURCE/EXPIRY_WARNED_FOR, faisant perdre le jeton
 * en cache ET la fenêtre de grâce de 90 jours (lastCheck), retombant sur la
 * grâce "jamais activé" de 30 jours qui expire nécessairement pour une
 * boutique en prod ancienne — exactement l'incident que le principe
 * fondateur du fichier (« une panne ne devient JAMAIS un incident chez le
 * client ») interdit.
 *
 * Corrigé le 14/09/2026 : même idiome que SearchConsoleManager/
 * PostmasterManager (round 353) — $rawKey !== '' && $key === '' distingue
 * l'échec de déchiffrement réel, traité comme une panne technique locale
 * (ni purge, ni bascule, juste un avertissement Watchdog, retour anticipé).
 *
 * Test comportemental réel : simule un client déjà activé (CONFIG_LAST_CHECK
 * récent + jeton/expires/plan/source en base), corrompt CONFIG_KEY avec une
 * valeur "ENC:..." invalide (force un vrai échec de decrypt()), appelle
 * validateLicense(), et vérifie qu'AUCUNE purge n'a eu lieu.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    $keys = [
        LicenseManager::CONFIG_KEY,
        LicenseManager::CONFIG_TOKEN,
        LicenseManager::CONFIG_LAST_CHECK,
        LicenseManager::CONFIG_EXPIRES,
        LicenseManager::CONFIG_PLAN,
        LicenseManager::CONFIG_SOURCE,
        LicenseManager::CONFIG_REVOKED_AT,
        'NERIA_LICENSE_EXPIRY_WARNED_FOR',
    ];
    $original = [];
    foreach ($keys as $k) {
        $original[$k] = Configuration::get($k);
    }

    try {
        // Simule un client déjà activé de longue date.
        Configuration::updateGlobalValue(LicenseManager::CONFIG_LAST_CHECK, (string) (time() - 3600));
        Configuration::updateGlobalValue(LicenseManager::CONFIG_TOKEN, 'ENC:not-a-real-token-marker');
        Configuration::updateGlobalValue(LicenseManager::CONFIG_EXPIRES, (string) (time() + 30 * 86400));
        Configuration::updateGlobalValue(LicenseManager::CONFIG_PLAN, 'pro');
        Configuration::updateGlobalValue(LicenseManager::CONFIG_SOURCE, 'addons');

        // Corrompt CONFIG_KEY : préfixe "ENC:" reconnu comme chiffré par
        // CryptoManager::isEncrypted(), mais contenu invalide -> decrypt()
        // échoue réellement et retourne '' (pas un simple champ vide).
        Configuration::updateGlobalValue(LicenseManager::CONFIG_KEY, 'ENC:regtest750-corrupted-not-valid-ciphertext');

        $mgr = new LicenseManager(neria_test_module());
        $mgr->validateLicense(false);

        neria_assert(
            (string) Configuration::get(LicenseManager::CONFIG_LAST_CHECK) !== '',
            "validateLicense() a purgé CONFIG_LAST_CHECK suite à un échec de déchiffrement de CONFIG_KEY — régression du bug corrigé le 14/09/2026 (round 356) : un client activé perdrait sa fenêtre de grâce de 90 jours à cause d'un simple accroc technique local"
        );
        neria_assert(
            (string) Configuration::get(LicenseManager::CONFIG_TOKEN) !== '',
            "validateLicense() a purgé CONFIG_TOKEN suite à un échec de déchiffrement de CONFIG_KEY — régression du bug corrigé le 14/09/2026 (round 356)"
        );
        neria_assert(
            (string) Configuration::get(LicenseManager::CONFIG_PLAN) === 'pro',
            "validateLicense() a purgé CONFIG_PLAN suite à un échec de déchiffrement de CONFIG_KEY — régression du bug corrigé le 14/09/2026 (round 356)"
        );

        return [
            'pass'    => true,
            'message' => "LicenseManager::validateLicense() ne purge plus l'état d'activation quand CONFIG_KEY subit un échec de déchiffrement réel (distinct d'une licence jamais activée) — bug corrigé le 14/09/2026 (round 356)",
        ];
    } finally {
        foreach ($original as $k => $v) {
            if ($v !== false && $v !== '') {
                Configuration::updateGlobalValue($k, $v);
            } else {
                Configuration::deleteByName($k);
            }
        }
    }
}
