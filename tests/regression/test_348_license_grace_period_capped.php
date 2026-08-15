<?php
/**
 * Régression : LicenseManager::isWithinGracePeriod() n'avait AUCUN plafond
 * de durée sur la branche "dernière validation réussie" (CONFIG_LAST_CHECK
 * > 0) — une fois qu'un client avait eu une licence valide au moins une
 * fois, cette grâce restait accordée INDÉFINIMENT si le serveur de licences
 * ne répondait plus jamais (fournisseur définitivement fermé, domaine
 * abandonné), retirant tout effet réel au mécanisme de licence pour ce
 * client après expiration naturelle.
 *
 * Corrigé le 15/08/2026 (round 176) : GRACE_LAST_CHECK_MAX_DAYS (90 jours,
 * volontairement généreux pour ne pas réintroduire le bug d'une panne
 * serveur prolongée bloquant un client en règle) plafonne désormais cette
 * grâce.
 *
 * Test comportemental réel : force CONFIG_LAST_CHECK à une date récente
 * (dans la fenêtre) → grâce accordée ; force CONFIG_LAST_CHECK à une date
 * au-delà du plafond → grâce refusée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LicenseManager.php';

    $module = neria_test_module();
    $mgr    = new LicenseManager($module);

    $ref = new ReflectionMethod(LicenseManager::class, 'isWithinGracePeriod');
    $ref->setAccessible(true);

    $originalLastCheck  = \Configuration::get(LicenseManager::CONFIG_LAST_CHECK);
    $originalRevokedAt  = \Configuration::get(LicenseManager::CONFIG_REVOKED_AT);
    $originalInstalledAt = \Configuration::get('NERIA_INSTALLED_AT');

    try {
        // Aucune révocation explicite, install très ancienne (hors scénario
        // A "jamais activé") pour isoler la branche testée.
        \Configuration::updateValue(LicenseManager::CONFIG_REVOKED_AT, 0);
        \Configuration::updateValue('NERIA_INSTALLED_AT', (string) strtotime('-2 years'));

        // Dans la fenêtre (1 jour avant le plafond de 90j) → grâce accordée.
        \Configuration::updateValue(
            LicenseManager::CONFIG_LAST_CHECK,
            time() - ((LicenseManager::GRACE_LAST_CHECK_MAX_DAYS - 1) * 86400)
        );
        $withinCap = $ref->invoke($mgr);
        neria_assert(
            $withinCap === true,
            "isWithinGracePeriod() refuse la grâce alors que CONFIG_LAST_CHECK est dans la fenêtre de " . LicenseManager::GRACE_LAST_CHECK_MAX_DAYS . " jours — jeu de test invalide ou plafond mal calculé"
        );

        // Au-delà du plafond (91 jours) → grâce refusée.
        \Configuration::updateValue(
            LicenseManager::CONFIG_LAST_CHECK,
            time() - ((LicenseManager::GRACE_LAST_CHECK_MAX_DAYS + 1) * 86400)
        );
        $beyondCap = $ref->invoke($mgr);
        neria_assert(
            $beyondCap === false,
            "isWithinGracePeriod() accorde encore la grâce alors que CONFIG_LAST_CHECK dépasse le plafond de " . LicenseManager::GRACE_LAST_CHECK_MAX_DAYS . " jours — régression du bug corrigé le 15/08/2026 (round 176) : un client dont le serveur de licences ne répond plus jamais après expiration naturelle enverrait de nouveau indéfiniment"
        );

        return [
            'pass'    => true,
            'message' => "LicenseManager::isWithinGracePeriod() plafonne bien la grâce 'dernière validation réussie' à " . LicenseManager::GRACE_LAST_CHECK_MAX_DAYS . " jours — bug corrigé le 15/08/2026 (round 176)",
        ];
    } finally {
        \Configuration::updateValue(LicenseManager::CONFIG_LAST_CHECK, $originalLastCheck !== false ? $originalLastCheck : '');
        \Configuration::updateValue(LicenseManager::CONFIG_REVOKED_AT, $originalRevokedAt !== false ? $originalRevokedAt : '');
        \Configuration::updateValue('NERIA_INSTALLED_AT', $originalInstalledAt !== false ? $originalInstalledAt : '');
    }
}
