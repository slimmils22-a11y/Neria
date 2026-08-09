<?php
/**
 * Régression : ConfigManager::resetDesignConfig() ne doit plus réinitialiser
 * KEY_DESIGN_WIZARD_SEEN — ce n'est pas un réglage de design (couleurs/
 * police/bouton/espacement/séparateur/ombre), c'est un flag d'affichage BO.
 *
 * Bug réel corrigé le 08/08/2026 (round 136) : resetDesignConfig() incluait
 * KEY_DESIGN_WIZARD_SEEN dans sa liste de clés réinitialisées, faisant
 * réapparaître à tort le bandeau assistant "Nouveau sur Neria ?" après un
 * simple reset factory du Design — alors que le marchand l'avait déjà
 * fermé, et incohérent avec le commentaire du handler BO qui limite
 * explicitement le reset à ces réglages de design.
 *
 * Test comportemental réel : marque le wizard comme "vu", appelle
 * resetDesignConfig(), vérifie que le flag reste "vu" (pas réinitialisé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $mgr = new ConfigManager(neria_test_module());
    $originalValue = (bool) $mgr->get(ConfigManager::KEY_DESIGN_WIZARD_SEEN);

    try {
        $mgr->set(ConfigManager::KEY_DESIGN_WIZARD_SEEN, 1);
        neria_assert((bool) $mgr->get(ConfigManager::KEY_DESIGN_WIZARD_SEEN) === true, "Jeu de test invalide : le flag wizard n'a pas pu être marqué comme vu");

        $mgr->resetDesignConfig();

        neria_assert(
            (bool) $mgr->get(ConfigManager::KEY_DESIGN_WIZARD_SEEN) === true,
            "resetDesignConfig() a réinitialisé KEY_DESIGN_WIZARD_SEEN — régression du bug corrigé le 08/08/2026 (round 136) : le bandeau assistant réapparaîtrait à tort après un simple reset du Design"
        );
    } finally {
        $mgr->set(ConfigManager::KEY_DESIGN_WIZARD_SEEN, (int) $originalValue);
    }

    return [
        'pass'    => true,
        'message' => "ConfigManager::resetDesignConfig() ne touche plus KEY_DESIGN_WIZARD_SEEN, préservant l'état du bandeau assistant après un reset factory du Design",
    ];
}
