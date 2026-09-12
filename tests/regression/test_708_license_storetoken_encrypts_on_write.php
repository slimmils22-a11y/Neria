<?php
/**
 * Régression : LicenseManager::storeToken()/activateLicense() doivent
 * chiffrer NERIA_LICENSE_TOKEN/NERIA_LICENSE_KEY à l'ÉCRITURE (pas
 * seulement la migration rétroactive testée par test_707), pour qu'un
 * nouveau jeton reçu du serveur de licences ne reparte jamais en clair.
 *
 * Bug corrigé hors round (12/09/2026, suite round 341) : voir test_707
 * pour le détail complet. Ce test cible spécifiquement storeToken()
 * (méthode privée, invoquée via Reflection — activateLicense()/
 * validateLicense() nécessitent un vrai appel réseau au serveur de
 * licences, non reproductible ici).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    $module = neria_test_module();
    $mgr    = new LicenseManager($module);

    $origToken = Configuration::get('NERIA_LICENSE_TOKEN', null, null, 0);

    try {
        $fakeResponse = [
            'ok'      => true,
            'valid'   => true,
            'expires' => 0,
            'plan'    => 'pro',
            'source'  => 'direct',
            'sig'     => 'fake_sig_708',
        ];

        $ref = new ReflectionMethod(LicenseManager::class, 'storeToken');
        $ref->setAccessible(true);
        $ref->invoke($mgr, $fakeResponse);

        $storedRaw = (string) Configuration::get('NERIA_LICENSE_TOKEN', null, null, 0);
        neria_assert(
            CryptoManager::isEncrypted($storedRaw),
            "LicenseManager::storeToken() n'écrit plus NERIA_LICENSE_TOKEN chiffré — régression du correctif hors round 12/09/2026 (suite round 341) : un nouveau jeton reçu du serveur de licences repartirait de nouveau en clair en base"
        );

        $expectedPlain = json_encode($fakeResponse, JSON_UNESCAPED_SLASHES);
        neria_assert(
            CryptoManager::decrypt($storedRaw) === $expectedPlain,
            "la valeur déchiffrée de NERIA_LICENSE_TOKEN après storeToken() ne correspond pas au jeton attendu"
        );

        return [
            'pass'    => true,
            'message' => "LicenseManager::storeToken() chiffre bien NERIA_LICENSE_TOKEN à l'écriture, comportement nominal préservé (round-trip chiffré/déchiffré identique au jeton d'origine)",
        ];
    } finally {
        if ($origToken === false || $origToken === '') {
            Configuration::deleteByName('NERIA_LICENSE_TOKEN');
        } else {
            Configuration::updateGlobalValue('NERIA_LICENSE_TOKEN', (string) $origToken);
        }
    }
}
