<?php
/**
 * Régression : `upgrade_module_1_0_44()` ignorait le retour de
 * `$module->registerHook('actionObjectOrderDeleteAfter')` et renvoyait
 * toujours `true`. `Module::runUpgradeModule()` (cœur PrestaShop)
 * n'avance `ps_module.version` qu'au retour `true` du script — un faux
 * "true" committe la version cible même si l'enregistrement du hook a
 * réellement échoué (verrou transitoire sur `ps_hook_module`, etc.).
 * Une fois la version enregistrée atteinte, `Module::needUpgrade()` ne
 * redétecte plus jamais ce script comme "à rejouer" : le hook resterait
 * alors définitivement non enregistré, sans erreur ni log visible pour le
 * marchand, et le bug métier que 1.0.44 corrige (KPIs de revenu
 * surestimés après suppression de commande) persisterait indéfiniment
 * sans aucun rattrapage automatique possible.
 *
 * Bug identifié le 01/09/2026 (round 275, audit "robustesse du mécanisme
 * d'upgrade face à un échec partiel").
 *
 * Corrigé le 01/09/2026 (round 275) : le retour de `registerHook()` est
 * désormais chaîné (`$ok = ...`, `$ok = $ok && ...`) et propagé jusqu'au
 * `return` du script, même convention que les autres scripts récents
 * (ex. `upgrade-1.0.39.php`).
 *
 * Test réel : exécute réellement `upgrade_module_1_0_44()` sur l'instance
 * du module de test — le hook doit déjà être enregistré (installation
 * normale), donc `registerHook()` retourne `true` de façon idempotente
 * (aucune régression de comportement sur ce chemin nominal) — et vérifie
 * structurellement que le retour n'est plus un `true` inconditionnel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.44.php');
    neria_assert($src !== false, 'Impossible de lire upgrade/upgrade-1.0.44.php');

    neria_assert(
        strpos($src, "\$ok = \$module->registerHook('actionObjectOrderDeleteAfter');") !== false,
        "upgrade_module_1_0_44() ne capture plus le retour de registerHook() — régression du bug corrigé le 01/09/2026 (round 275) : un échec d'enregistrement du hook ne serait de nouveau jamais détecté"
    );
    neria_assert(
        strpos($src, "return \$ok;") !== false,
        "upgrade_module_1_0_44() ne propage plus \$ok jusqu'à son retour — régression du bug corrigé le 01/09/2026 (round 275) : le script renverrait de nouveau toujours true, indépendamment du succès réel de registerHook(), commitant la version cible même en cas d'échec silencieux"
    );
    neria_assert(
        strpos($src, "    return true;\n}") === false,
        "upgrade_module_1_0_44() renvoie de nouveau un true inconditionnel en fin de fonction — régression du bug corrigé le 01/09/2026 (round 275)"
    );

    // Vérification comportementale réelle : exécution effective sur le
    // module de test — chemin nominal (hook déjà enregistré à
    // l'installation), doit rester idempotent et retourner true.
    $module = neria_test_module();
    neria_assert(function_exists('upgrade_module_1_0_44') === false, 'jeu de test invalide : fonction déjà chargée avant require');
    require_once _PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.44.php';
    neria_assert(function_exists('upgrade_module_1_0_44'), 'upgrade_module_1_0_44() introuvable après require — jeu de test invalide');

    $result = upgrade_module_1_0_44($module);
    neria_assert(
        $result === true,
        "upgrade_module_1_0_44() a retourné false sur le chemin nominal (hook déjà enregistré, doit être idempotent) — comportement inattendu"
    );

    return [
        'pass'    => true,
        'message' => "upgrade_module_1_0_44() propage désormais le résultat réel de registerHook() jusqu'à son retour, au lieu d'un true inconditionnel — bug corrigé le 01/09/2026 (round 275)",
    ];
}
