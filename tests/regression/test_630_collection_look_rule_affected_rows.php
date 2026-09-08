<?php
/**
 * Régression : les actions BO `collection_toggle`/`collection_delete`/
 * `look_rule_toggle`/`look_rule_delete` (neria.php) affichaient un succès
 * inconditionnel sans vérifier l'effet réel :
 * - toggle : $msgKey par défaut ('msg.item_activated') restait affiché en
 *   neria_success même quand la collection/règle n'existait plus (id déjà
 *   supprimé, double-clic) — le bloc de mise à jour était sauté mais la
 *   redirection affichait quand même un succès.
 * - delete : CollectionManager::delete()/LookCompletionManager::deleteRule()
 *   renvoyaient (bool) Db::delete(), qui reflète le succès de la requête SQL
 *   (cœur PrestaShop, classes/db/Db.php::delete()), PAS le nombre de lignes
 *   réellement supprimées — un id déjà supprimé/inexistant affichait quand
 *   même "supprimé".
 *
 * Même pattern que calendar_save/toggle/delete (round 317, voir test_623),
 * jamais porté ici alors que ces 4 actions partagent le même fichier.
 *
 * Corrigé le 08/09/2026 (round 320) :
 * - CollectionManager::delete()/LookCompletionManager::deleteRule() renvoient
 *   désormais Affected_Rows()>0 (fiable pour un DELETE).
 * - neria.php assigne neria_error (msg.collection_not_found /
 *   msg.look_rule_not_found) dans les 4 cas d'échec.
 *
 * Test comportemental réel (partie manager) : appelle delete()/deleteRule()
 * sur un id inexistant et vérifie qu'ils renvoient bien false — puis sur un
 * id existant fraîchement créé, vérifie qu'ils renvoient bien true.
 * Test structurel (partie neria.php) : vérifie que les 4 handlers assignent
 * bien neria_error dans le cas "introuvable".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $module = neria_test_module();

    // --- Partie comportementale : CollectionManager::delete() ---
    $collMgr = new CollectionManager($module);
    $fakeId  = 999999991;
    $resultOnMissing = $collMgr->delete($fakeId);
    neria_assert(
        $resultOnMissing === false,
        "CollectionManager::delete() renvoie " . var_export($resultOnMissing, true) . " pour un id inexistant (attendu false) — régression du bug corrigé le 08/09/2026 (round 320) : le message de succès s'afficherait de nouveau même pour une collection déjà supprimée"
    );

    $created = $collMgr->create('regtest630_collection_' . time(), [1, 2]);
    neria_assert($created === true, "CollectionManager::create() a échoué — jeu de test invalide");
    $newId = (int) Db::getInstance()->Insert_ID();
    neria_assert($newId > 0, "Insert_ID() n'a pas renvoyé d'id valide après create() — jeu de test invalide");
    $resultOnReal = $collMgr->delete($newId);
    neria_assert(
        $resultOnReal === true,
        "CollectionManager::delete() renvoie " . var_export($resultOnReal, true) . " pour une collection réellement supprimée (attendu true) — régression : le correctif round 320 bloquerait à tort le chemin nominal"
    );

    // --- Partie comportementale : LookCompletionManager::deleteRule() ---
    $lookMgr = new LookCompletionManager($module);
    $resultOnMissingRule = $lookMgr->deleteRule($fakeId);
    neria_assert(
        $resultOnMissingRule === false,
        "LookCompletionManager::deleteRule() renvoie " . var_export($resultOnMissingRule, true) . " pour un id inexistant (attendu false) — régression du bug corrigé le 08/09/2026 (round 320)"
    );

    // --- Partie structurelle : neria.php (les 4 handlers) ---
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    foreach (['collection_toggle', 'look_rule_toggle'] as $action) {
        $pos = strpos($src, "Tools::getValue('neria_action') === '{$action}'");
        neria_assert($pos !== false, "action {$action} introuvable — jeu de test invalide");
        $body = substr($src, $pos, 1500);
        $expectedKey = $action === 'collection_toggle' ? 'msg.collection_not_found' : 'msg.look_rule_not_found';
        neria_assert(
            strpos($body, "AdminTranslator::t('{$expectedKey}')") !== false,
            "{$action} n'assigne plus de neria_error dédié quand la ligne est introuvable — régression du bug corrigé le 08/09/2026 (round 320)"
        );
    }

    foreach (['collection_delete', 'look_rule_delete'] as $action) {
        $pos = strpos($src, "Tools::getValue('neria_action') === '{$action}'");
        neria_assert($pos !== false, "action {$action} introuvable — jeu de test invalide");
        $body = substr($src, $pos, 1200);
        $expectedKey = $action === 'collection_delete' ? 'msg.collection_not_found' : 'msg.look_rule_not_found';
        neria_assert(
            strpos($body, '$deleted') !== false,
            "{$action} ne vérifie plus le résultat réel de delete()/deleteRule() — régression du bug corrigé le 08/09/2026 (round 320)"
        );
        neria_assert(
            strpos($body, "AdminTranslator::t('{$expectedKey}')") !== false,
            "{$action} n'assigne plus de neria_error dédié quand 0 ligne n'est réellement supprimée — régression du bug corrigé le 08/09/2026 (round 320)"
        );
    }

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['msg.collection_not_found', 'msg.look_rule_not_found'] as $key) {
        neria_assert(
            isset($translations[$key]) && count($translations[$key]) === 19,
            "clé {$key} manquante ou incomplète dans admin_translations.json (19 langues attendues)"
        );
    }

    return [
        'pass'    => true,
        'message' => "CollectionManager::delete()/LookCompletionManager::deleteRule() reflètent bien l'effet réel (Affected_Rows), et neria.php affiche un message d'erreur dédié pour collection_toggle/collection_delete/look_rule_toggle/look_rule_delete — bug corrigé le 08/09/2026 (round 320)",
    ];
}
