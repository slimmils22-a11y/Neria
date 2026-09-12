<?php
/**
 * Régression : les handlers `look_rule_add` et `look_rule_toggle` de
 * `neria.php` affichaient "règle ajoutée"/"activée"/"désactivée"
 * INCONDITIONNELLEMENT dès que la validation de formulaire passait, sans
 * jamais vérifier le retour de `LookCompletionManager::createRule()`/
 * `updateRule()` (`Db::insert()`/`Db::update()`) — même pattern déjà
 * corrigé round 335 pour `collection_add`/`collection_toggle`, jamais
 * étendu ici. `look_rule_toggle` porte même un aveu explicite dans son
 * propre commentaire depuis le round 320 ("jamais corrigé ici
 * contrairement au calendrier round 317").
 *
 * Bugs identifiés le 12/09/2026 (round 344, audit MultiClientPreviewManager/
 * LookCompletionManager).
 *
 * Corrigés le 12/09/2026 (round 344) : retour de `createRule()`/
 * `updateRule()` désormais vérifié dans les deux handlers.
 *
 * Test structurel sur neria.php (dispatch d'action BO complet,
 * impraticable à invoquer isolément en CLI, même limite acceptée pour les
 * autres handlers de ce fichier — cf. test_684) + comportemental réel sur
 * `LookCompletionManager::createRule()`/`updateRule()` (chemin nominal,
 * garantit que le correctif n'a rien cassé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle des 2 correctifs dans neria.php ────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posAdd = strpos($src, "neria_action') === 'look_rule_add'");
    neria_assert($posAdd !== false, "Handler look_rule_add introuvable — jeu de test invalide");
    $bodyAdd = substr($src, $posAdd, 1500);
    neria_assert(
        strpos($bodyAdd, '$msgKey = (new LookCompletionManager($this))->createRule($idCat, array_slice($pids, 0, 3))') !== false
            && strpos($bodyAdd, "? 'success:msg.look_rule_added'") !== false
            && strpos($bodyAdd, ": 'error:msg.look_rule_invalid';") !== false,
        "neria.php ne conditionne plus le message de succès de look_rule_add au retour réel de LookCompletionManager::createRule() — régression du bug corrigé le 12/09/2026 (round 344) : un succès serait de nouveau affiché même si l'INSERT échoue"
    );

    $posToggle = strpos($src, "neria_action') === 'look_rule_toggle'");
    neria_assert($posToggle !== false, "Handler look_rule_toggle introuvable — jeu de test invalide");
    $bodyToggle = substr($src, $posToggle, 2000);
    neria_assert(
        strpos($bodyToggle, '$toggled = $mgr->updateRule($id, (int) $r[\'id_category\'], json_decode($r[\'product_ids\'], true), !(bool) $r[\'active\']);') !== false
            && strpos($bodyToggle, 'if ($toggled) {') !== false,
        "neria.php ne conditionne plus le message de succès de look_rule_toggle au retour réel de LookCompletionManager::updateRule() — régression du bug corrigé le 12/09/2026 (round 344) : un succès serait de nouveau affiché même si l'UPDATE échoue"
    );

    // ── Vérification comportementale du chemin nominal ───────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $mgr    = new LookCompletionManager($module);

    $idCat   = (int) $db->getValue("SELECT id_category FROM {$prefix}category WHERE active = 1 ORDER BY id_category") ?: 2;
    $created = $mgr->createRule($idCat, [1, 2]);
    neria_assert($created, "LookCompletionManager::createRule() a échoué de façon inattendue — comportement nominal cassé");

    $row = $db->getRow(
        "SELECT * FROM {$prefix}neria_look_rule WHERE id_category = {$idCat} ORDER BY id_neria_look_rule DESC"
    );
    neria_assert($row !== false && $row !== null, "La règle créée n'est pas retrouvable en base — comportement nominal cassé");
    $id = (int) $row['id_neria_look_rule'];

    try {
        $decoded = json_decode($row['product_ids'], true);
        neria_assert(is_array($decoded), "product_ids stocké par createRule() ne se décode plus en tableau JSON valide — comportement nominal cassé");

        $updated = $mgr->updateRule($id, $idCat, $decoded, !(bool) $row['active']);
        neria_assert($updated, "LookCompletionManager::updateRule() a échoué sur le chemin nominal (toggle) — comportement nominal cassé");

        return [
            'pass'    => true,
            'message' => "look_rule_add vérifie désormais le retour de createRule() et look_rule_toggle celui de updateRule(), comportement nominal préservé — bug corrigé le 12/09/2026 (round 344)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_look_rule WHERE id_neria_look_rule = {$id}");
    }
}
