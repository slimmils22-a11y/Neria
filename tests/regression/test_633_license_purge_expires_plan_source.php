<?php
/**
 * Régression : LicenseManager::validateLicense() purgeait CONFIG_LAST_CHECK/
 * CONFIG_REVOKED_AT/CONFIG_TOKEN quand CONFIG_KEY est vide avec un résidu
 * d'activation antérieure (round 160), mais PAS CONFIG_EXPIRES/CONFIG_PLAN/
 * CONFIG_SOURCE/CONFIG_EXPIRY_WARNED_FOR — pourtant lus inconditionnellement
 * par getStatusForDisplay(), indépendamment de has_key. Le bandeau BO
 * affichait donc simultanément has_key=false (aucune licence) ET
 * expires_at/plan/source d'une licence qui n'existe plus (résidu d'une clé
 * supprimée directement en base sans passer par le flux de désinstallation).
 *
 * Corrigé le 08/09/2026 (round 321) : ces 4 clés sont désormais purgées dans
 * le même bloc que le correctif round 160.
 *
 * Test comportemental réel : simule l'état résiduel exact (CONFIG_KEY vide,
 * CONFIG_LAST_CHECK/CONFIG_EXPIRES/CONFIG_PLAN/CONFIG_SOURCE non vides),
 * appelle validateLicense(), puis vérifie via getStatusForDisplay() que
 * expires_at/plan/source sont bien vides (cohérents avec has_key=false).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    $module = neria_test_module();
    $mgr    = new LicenseManager($module);

    $savedKey      = Configuration::get(LicenseManager::CONFIG_KEY);
    $savedLastCheck = Configuration::get(LicenseManager::CONFIG_LAST_CHECK);
    $savedExpires   = Configuration::get(LicenseManager::CONFIG_EXPIRES);
    $savedPlan      = Configuration::get(LicenseManager::CONFIG_PLAN);
    $savedSource    = Configuration::get(LicenseManager::CONFIG_SOURCE);
    $savedWarnedFor = Configuration::get(LicenseManager::CONFIG_EXPIRY_WARNED_FOR);

    try {
        // État résiduel : clé effacée directement en base, mais le reste
        // d'une activation antérieure traîne encore.
        Configuration::deleteByName(LicenseManager::CONFIG_KEY);
        Configuration::updateGlobalValue(LicenseManager::CONFIG_LAST_CHECK, time() - 100000);
        Configuration::updateGlobalValue(LicenseManager::CONFIG_EXPIRES, time() + (30 * 86400));
        Configuration::updateGlobalValue(LicenseManager::CONFIG_PLAN, 'pro');
        Configuration::updateGlobalValue(LicenseManager::CONFIG_SOURCE, 'purchase');
        Configuration::updateGlobalValue(LicenseManager::CONFIG_EXPIRY_WARNED_FOR, (string) date('Y-m-d'));

        $mgr->validateLicense();

        $status = $mgr->getStatusForDisplay();

        neria_assert(
            $status['has_key'] === false,
            "jeu de test invalide : has_key devrait être false (CONFIG_KEY vide)"
        );
        neria_assert(
            $status['expires_at'] === '',
            "getStatusForDisplay() affiche encore expires_at='{$status['expires_at']}' alors que has_key=false — régression du bug corrigé le 08/09/2026 (round 321) : CONFIG_EXPIRES résiduel non purgé"
        );
        neria_assert(
            $status['plan'] === '',
            "getStatusForDisplay() affiche encore plan='{$status['plan']}' alors que has_key=false — régression du bug corrigé le 08/09/2026 (round 321) : CONFIG_PLAN résiduel non purgé"
        );
        neria_assert(
            $status['source'] === '',
            "getStatusForDisplay() affiche encore source='{$status['source']}' alors que has_key=false — régression du bug corrigé le 08/09/2026 (round 321) : CONFIG_SOURCE résiduel non purgé"
        );
        neria_assert(
            (string) Configuration::get(LicenseManager::CONFIG_EXPIRY_WARNED_FOR) === '',
            "CONFIG_EXPIRY_WARNED_FOR résiduel non purgé — régression du bug corrigé le 08/09/2026 (round 321)"
        );

        return [
            'pass'    => true,
            'message' => "LicenseManager::validateLicense() purge bien CONFIG_EXPIRES/CONFIG_PLAN/CONFIG_SOURCE/CONFIG_EXPIRY_WARNED_FOR (pas seulement CONFIG_LAST_CHECK/REVOKED_AT/TOKEN) quand CONFIG_KEY devient vide hors désinstallation — bug corrigé le 08/09/2026 (round 321)",
        ];
    } finally {
        Configuration::deleteByName(LicenseManager::CONFIG_KEY);
        Configuration::deleteByName(LicenseManager::CONFIG_LAST_CHECK);
        Configuration::deleteByName(LicenseManager::CONFIG_EXPIRES);
        Configuration::deleteByName(LicenseManager::CONFIG_PLAN);
        Configuration::deleteByName(LicenseManager::CONFIG_SOURCE);
        Configuration::deleteByName(LicenseManager::CONFIG_EXPIRY_WARNED_FOR);
        if ($savedKey !== false && $savedKey !== null && $savedKey !== '') {
            Configuration::updateGlobalValue(LicenseManager::CONFIG_KEY, (string) $savedKey);
        }
        if ($savedLastCheck !== false && $savedLastCheck !== null && $savedLastCheck !== '') {
            Configuration::updateGlobalValue(LicenseManager::CONFIG_LAST_CHECK, (string) $savedLastCheck);
        }
        if ($savedExpires !== false && $savedExpires !== null && $savedExpires !== '') {
            Configuration::updateGlobalValue(LicenseManager::CONFIG_EXPIRES, (string) $savedExpires);
        }
        if ($savedPlan !== false && $savedPlan !== null && $savedPlan !== '') {
            Configuration::updateGlobalValue(LicenseManager::CONFIG_PLAN, (string) $savedPlan);
        }
        if ($savedSource !== false && $savedSource !== null && $savedSource !== '') {
            Configuration::updateGlobalValue(LicenseManager::CONFIG_SOURCE, (string) $savedSource);
        }
        if ($savedWarnedFor !== false && $savedWarnedFor !== null && $savedWarnedFor !== '') {
            Configuration::updateGlobalValue(LicenseManager::CONFIG_EXPIRY_WARNED_FOR, (string) $savedWarnedFor);
        }
    }
}
