<?php
/**
 * Régression : Neria::hookDisplayBackOfficeHeaderImpl() ne doit
 * enregistrer/désenregistrer ses 4 hooks (registerHook/unregisterHook)
 * qu'UNE SEULE FOIS (flag NERIA_HOOKS_MIGRATED_V2), pas à chaque
 * chargement de page back-office.
 *
 * Bug réel corrigé le 05/08/2026 (round 53) : sans ce flag, ces 4 appels
 * tournaient sur CHAQUE page BO — coût de requêtes inutile, et un admin
 * qui désactivait manuellement un de ces hooks via l'onglet natif
 * PrestaShop "Hooks" le voyait silencieusement réinstallé dès la page
 * suivante, sans comprendre pourquoi son changement ne "tenait" pas.
 *
 * Ce test appelle la méthode privée deux fois : après le 1er appel, il
 * désactive manuellement un des hooks concernés (simule l'action admin) ;
 * après le 2e appel, il vérifie que ce hook reste désactivé — la
 * désactivation manuelle doit maintenant "tenir".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $previousFlag = Configuration::get('NERIA_HOOKS_MIGRATED_V2');
    Configuration::deleteByName('NERIA_HOOKS_MIGRATED_V2');

    $ref = new ReflectionMethod($module, 'hookDisplayBackOfficeHeaderImpl');
    $ref->setAccessible(true);

    try {
        // 1er appel : doit enregistrer les hooks et poser le flag.
        $ref->invoke($module);
        neria_assert(
            (bool) Configuration::get('NERIA_HOOKS_MIGRATED_V2'),
            "hookDisplayBackOfficeHeaderImpl() n'a pas posé le flag NERIA_HOOKS_MIGRATED_V2 après le 1er appel"
        );

        // Simule un admin désactivant manuellement 'actionObjectOrderAddAfter'
        // pour ce module via l'onglet natif "Hooks" — dans PrestaShop,
        // "désactiver" un hook pour un module = supprimer sa ligne de
        // ps_hook_module (pas de colonne "active" sur cette table).
        $idModule = (int) $module->id;
        $idHook   = (int) Hook::getIdByName('actionObjectOrderAddAfter');
        neria_assert($idHook > 0, "hook actionObjectOrderAddAfter introuvable dans ps_hook — environnement de test invalide");
        $db->execute("DELETE FROM {$prefix}hook_module WHERE id_hook = {$idHook} AND id_module = {$idModule}");

        // 2e appel : le flag doit empêcher tout nouveau registerHook().
        $ref->invoke($module);

        $rowCountAfter = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}hook_module WHERE id_hook = {$idHook} AND id_module = {$idModule}"
        );
        neria_assert(
            $rowCountAfter === 0,
            "la désactivation manuelle du hook a été annulée par un 2e appel de hookDisplayBackOfficeHeaderImpl() — régression du bug corrigé le 05/08/2026 : les hooks sont de nouveau réenregistrés à chaque page BO"
        );

        return [
            'pass'    => true,
            'message' => 'Les 4 hooks ne sont enregistrés/nettoyés qu\'une seule fois — une désactivation manuelle admin persiste bien ensuite',
        ];
    } finally {
        // Réenregistre le hook et restaure l'état du flag pour ne pas
        // polluer l'environnement de dev au-delà de ce test.
        $module->registerHook('actionObjectOrderAddAfter');
        if ($previousFlag === false || $previousFlag === null || $previousFlag === '') {
            Configuration::deleteByName('NERIA_HOOKS_MIGRATED_V2');
        } else {
            Configuration::updateValue('NERIA_HOOKS_MIGRATED_V2', $previousFlag);
        }
    }
}
