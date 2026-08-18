<?php
/**
 * Régression : PostmasterManager::applyTokenResponse()/refreshAccessToken()
 * et SearchConsoleManager (même architecture OAuth Google) écrivaient les
 * tokens OAuth (CONFIG_ACCESS_TOKEN/REFRESH_TOKEN/TOKEN_EXPIRY/LAST_ERROR)
 * ainsi que les credentials (CONFIG_CLIENT_ID/CLIENT_SECRET, via neria.php)
 * avec Configuration::updateValue() — alors que le commentaire de
 * conception du fichier documente explicitement une connexion GLOBALE à
 * l'installation (une seule connexion Google pour toutes les boutiques,
 * comme LicenseManager). Configuration::updateValue() sans $idShop retombe
 * sur la boutique du CONTEXTE COURANT dès que le multi-boutique est actif
 * (vérifié dans le cœur PrestaShop, classes/Configuration.php) — une
 * connexion établie depuis la Boutique A restait donc invisible pour la
 * Boutique B (isConnected() y renvoyait faussement false).
 *
 * Corrigé le 18/08/2026 (round 185) : toutes ces clés utilisent désormais
 * Configuration::updateGlobalValue(), qui force id_shop = NULL quelle que
 * soit la boutique du contexte au moment de l'appel.
 *
 * Test comportemental réel : appelle applyTokenResponse() (privée, via
 * réflexion) avec une réponse OAuth fictive, puis vérifie DIRECTEMENT en
 * base que la ligne ps_configuration créée a bien id_shop IS NULL (global),
 * pas l'id_shop du contexte courant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $originalPmAccess = $db->getValue("SELECT value FROM {$prefix}configuration WHERE name = 'NERIA_POSTMASTER_ACCESS_TOKEN' AND id_shop IS NULL");
    $originalScAccess = $db->getValue("SELECT value FROM {$prefix}configuration WHERE name = 'NERIA_SC_ACCESS_TOKEN' AND id_shop IS NULL");

    try {
        // Configuration::deleteByName() (pas un DELETE SQL brut) : réinitialise
        // aussi le cache statique interne de la classe Configuration
        // (self::$_cache = null), indispensable ici — un DELETE SQL direct
        // laisse le cache croire que la clé existe encore avec l'ancienne
        // valeur, faisant passer le prochain updateGlobalValue() par la
        // branche UPDATE (0 ligne affectée, silencieux) au lieu d'INSERT.
        Configuration::deleteByName('NERIA_POSTMASTER_ACCESS_TOKEN');
        Configuration::deleteByName('NERIA_SC_ACCESS_TOKEN');

        $pm = new PostmasterManager($module);
        $refPm = new ReflectionMethod(PostmasterManager::class, 'applyTokenResponse');
        $refPm->setAccessible(true);
        $refPm->invoke($pm, ['access_token' => 'fake-token-regtest-383', 'expires_in' => 3600]);

        $pmScopedRow = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration WHERE name = 'NERIA_POSTMASTER_ACCESS_TOKEN' AND id_shop IS NOT NULL"
        );
        neria_assert(
            $pmScopedRow === 0,
            "PostmasterManager::applyTokenResponse() a créé une ligne id_shop-scopée pour CONFIG_ACCESS_TOKEN — régression du bug corrigé le 18/08/2026 (round 185) : une connexion OAuth établie depuis une boutique resterait invisible pour les autres"
        );
        $pmGlobalRow = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration WHERE name = 'NERIA_POSTMASTER_ACCESS_TOKEN' AND id_shop IS NULL"
        );
        neria_assert($pmGlobalRow === 1, "applyTokenResponse() n'a créé aucune ligne globale — jeu de test invalide");

        $sc = new SearchConsoleManager($module);
        $refSc = new ReflectionMethod(SearchConsoleManager::class, 'applyTokenResponse');
        $refSc->setAccessible(true);
        $refSc->invoke($sc, ['access_token' => 'fake-token-regtest-383', 'expires_in' => 3600]);

        $scScopedRow = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration WHERE name = 'NERIA_SC_ACCESS_TOKEN' AND id_shop IS NOT NULL"
        );
        neria_assert(
            $scScopedRow === 0,
            "SearchConsoleManager::applyTokenResponse() a créé une ligne id_shop-scopée pour CONFIG_ACCESS_TOKEN — régression du bug corrigé le 18/08/2026 (round 185)"
        );
    } finally {
        Configuration::deleteByName('NERIA_POSTMASTER_ACCESS_TOKEN');
        Configuration::deleteByName('NERIA_SC_ACCESS_TOKEN');
        if ($originalPmAccess !== false && $originalPmAccess !== null) {
            Configuration::updateGlobalValue('NERIA_POSTMASTER_ACCESS_TOKEN', $originalPmAccess);
        }
        if ($originalScAccess !== false && $originalScAccess !== null) {
            Configuration::updateGlobalValue('NERIA_SC_ACCESS_TOKEN', $originalScAccess);
        }
    }

    return [
        'pass'    => true,
        'message' => "PostmasterManager/SearchConsoleManager écrivent bien leurs tokens OAuth en global (id_shop NULL), visibles depuis toutes les boutiques — bug corrigé le 18/08/2026 (round 185)",
    ];
}
