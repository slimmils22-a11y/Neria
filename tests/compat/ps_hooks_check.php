<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Diagnostic de compatibilité — existence et enregistrement des hooks Neria.
 *
 * Vérifie que chaque hook de neria.php::HOOKS est reconnu par le cœur PS
 * (Hook::getIdByName) ET bien enregistré pour le module (ps_hook_module).
 * registerHook() ignore silencieusement les échecs (neria.php:198-200,
 * pour rester compatible entre versions PS) — ce script sert à vérifier
 * qu'aucun hook n'a échoué à s'enregistrer sans qu'on le sache.
 *
 * Usage : identique à ps_core_diff.php — copier à la racine de chaque
 * install PS, appeler en HTTP, SUPPRIMER le fichier après lecture.
 *
 * Ne couvre PAS le timing réel de déclenchement (ordre relatif à d'autres
 * hooks, moment exact dans le cycle de vie PS) — seul un test de scénario
 * réel le confirme. Voir CARTOGRAPHY.md, axe 4.
 *
 * Dernier scan complet : 2026-07-19, PS8 8.1.7 vs PS9 9.0.2 → 14/14 hooks
 * reconnus et enregistrés sur les deux versions. Aucun problème.
 */
require_once __DIR__ . '/config/config.inc.php';

// Doit rester synchronisé avec neria.php::HOOKS
$hooks = [
    'actionEmailSendBefore',
    'actionMailAlterMessageBeforeSend',
    'displayBackOfficeHeader',
    'displayHeader',
    'displayAdminCustomersView',
    'displayAdminCustomers',
    'actionObjectOrderAddAfter',
    'actionOrderStatusPostUpdate',
    'actionOrderSlipAdd',
    'actionObjectOrderReturnAddAfter',
    'displayAdminOrderMainBottom',
    'displayProductAdditionalInfo',
    'actionUpdateQuantity',
    'actionDeleteGDPRCustomer',
];

echo '=== VERSION: ' . (defined('_PS_VERSION_') ? _PS_VERSION_ : 'unknown') . ' ===' . PHP_EOL;

foreach ($hooks as $h) {
    $idHook = Hook::getIdByName($h);
    if (!$idHook) {
        echo "HOOK_UNKNOWN_TO_CORE\t{$h}" . PHP_EOL;
        continue;
    }
    $registered = (int) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'hook_module hm
         WHERE hm.id_hook = ' . (int) $idHook . "
           AND hm.id_module = (SELECT id_module FROM " . _DB_PREFIX_ . "module WHERE name = 'neria')"
    );
    echo ($registered > 0 ? 'REGISTERED' : 'NOT_REGISTERED') . "\t{$h}\tid_hook={$idHook}" . PHP_EOL;
}
