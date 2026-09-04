<?php
/**
 * Régression : `LicenseManager::getStatusForDisplay()` ne calculait
 * `grace_days_left`/`in_grace_period` que pour 2 des 3 scénarios de
 * `isWithinGracePeriod()` (jamais activé, révoqué) — le 3e scénario
 * (round 176 : jeton expiré NATURELLEMENT pendant que le serveur de
 * licences reste injoignable en continu, `CONFIG_LAST_CHECK` plafonné à
 * `GRACE_LAST_CHECK_MAX_DAYS`) n'était JAMAIS reflété. Dans cet état
 * précis (clé présente, non révoquée, `expires` dans le passé, envois
 * toujours autorisés via ce repli), aucune des 3 branches du bandeau BO
 * (`navigation.tpl`) ne matchait : ni "!has_key" (une clé EST présente),
 * ni "revoked" (pas révoqué), ni "expires_soon" (l'échéance est déjà
 * passée) — le marchand consommait silencieusement ses 90 derniers jours
 * de grâce sans AUCUN signal visuel jusqu'à la coupure brutale des
 * envois au jour 90.
 *
 * Bug identifié et corrigé le 04/09/2026 (round 300, audit "tracking de
 * clic et licence").
 *
 * Corrigé le 04/09/2026 (round 300) : 3e branche ajoutée dans
 * `getStatusForDisplay()` (calcul de `grace_days_left` basé sur
 * `CONFIG_LAST_CHECK`) et dans `navigation.tpl` (nouvelle alerte dédiée,
 * clé `license.banner_grace_lastcheck`, 19 langues).
 *
 * Test comportemental réel : simule une clé présente, non révoquée,
 * expirée depuis hier, avec un `CONFIG_LAST_CHECK` récent (30 jours de
 * grâce théoriquement restants sur les 90) — vérifie que
 * `getStatusForDisplay()` reflète bien `in_grace_period=true` avec un
 * `grace_days_left` cohérent, alors qu'aucun des 2 scénarios existants
 * (jamais activé / révoqué) ne s'applique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    $origKey       = (string) Configuration::get('NERIA_LICENSE_KEY');
    $origExpires   = (string) Configuration::get('NERIA_LICENSE_EXPIRES');
    $origRevokedAt = (string) Configuration::get('NERIA_LICENSE_REVOKED_AT');
    $origLastCheck = (string) Configuration::get('NERIA_LICENSE_LAST_CHECK');
    $origToken     = (string) Configuration::get('NERIA_LICENSE_TOKEN');

    try {
        Configuration::updateGlobalValue('NERIA_LICENSE_KEY', 'NERIA-TEST-TEST-TEST');
        Configuration::updateGlobalValue('NERIA_LICENSE_EXPIRES', time() - 86400);
        Configuration::deleteByName('NERIA_LICENSE_REVOKED_AT');
        // 30 jours écoulés depuis la dernière validation serveur réussie —
        // dans la fenêtre de grâce de 90 jours (LicenseManager::GRACE_LAST_CHECK_MAX_DAYS).
        Configuration::updateGlobalValue('NERIA_LICENSE_LAST_CHECK', time() - (30 * 86400));

        $mgr    = new LicenseManager(neria_test_module());
        $status = $mgr->getStatusForDisplay();

        neria_assert(
            $status['has_key'] === true && $status['revoked'] === false && $status['expires_soon'] === false,
            "jeu de test invalide : l'état simulé ne correspond plus au scénario visé (clé présente, non révoquée, expirée)"
        );
        neria_assert(
            $status['in_grace_period'] === true,
            "LicenseManager::getStatusForDisplay() ne détecte plus la période de grâce du 3e scénario (jeton expiré + serveur injoignable, CONFIG_LAST_CHECK) — régression du bug corrigé le 04/09/2026 (round 300) : le bandeau BO n'afficherait de nouveau aucune alerte pendant que le marchand consomme silencieusement ses 90 jours de grâce"
        );
        neria_assert(
            $status['grace_days_left'] !== null && $status['grace_days_left'] >= 55 && $status['grace_days_left'] <= 65,
            "LicenseManager::getStatusForDisplay() calcule un grace_days_left incohérent (" . var_export($status['grace_days_left'], true) . ") pour 30 jours écoulés sur 90 — attendu ~60"
        );

        // Vérification structurelle du branchement navigation.tpl.
        $tplSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/navigation.tpl');
        neria_assert($tplSrc !== false, 'Impossible de lire views/templates/admin/navigation.tpl');
        neria_assert(
            strpos($tplSrc, '{elseif $ls.in_grace_period && $ls.has_key && !$ls.revoked}') !== false
                && strpos($tplSrc, "license.banner_grace_lastcheck") !== false,
            "navigation.tpl n'a plus de branche dédiée pour le 3e scénario de grâce — régression du bug corrigé le 04/09/2026 (round 300) : même avec in_grace_period=true côté PHP, aucune alerte ne s'afficherait dans le bandeau BO"
        );

        return [
            'pass'    => true,
            'message' => "LicenseManager::getStatusForDisplay() reflète désormais la période de grâce du 3e scénario (jeton expiré, serveur injoignable) et navigation.tpl affiche une alerte dédiée — bug corrigé le 04/09/2026 (round 300)",
        ];
    } finally {
        if ($origKey === '') { Configuration::deleteByName('NERIA_LICENSE_KEY'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_KEY', $origKey); }
        if ($origExpires === '') { Configuration::deleteByName('NERIA_LICENSE_EXPIRES'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_EXPIRES', $origExpires); }
        if ($origRevokedAt === '') { Configuration::deleteByName('NERIA_LICENSE_REVOKED_AT'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_REVOKED_AT', $origRevokedAt); }
        if ($origLastCheck === '') { Configuration::deleteByName('NERIA_LICENSE_LAST_CHECK'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_LAST_CHECK', $origLastCheck); }
        if ($origToken === '') { Configuration::deleteByName('NERIA_LICENSE_TOKEN'); } else { Configuration::updateGlobalValue('NERIA_LICENSE_TOKEN', $origToken); }
    }
}
