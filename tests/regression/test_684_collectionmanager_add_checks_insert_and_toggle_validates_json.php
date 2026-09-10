<?php
/**
 * Régression : le handler `collection_add` de `neria.php` affichait
 * "Collection ajoutée" (`msg.collection_added`) INCONDITIONNELLEMENT dès
 * que la validation de formulaire passait, sans jamais vérifier le retour
 * de `CollectionManager::create()` (`Db::insert()`) — même pattern déjà
 * corrigé round 320 pour `collection_toggle`/`collection_delete`/
 * `look_rule_toggle`/`look_rule_delete`, jamais étendu à `collection_add`.
 * Un échec d'INSERT (perte de connexion DB, erreur SQL) restait invisible :
 * le marchand voyait "succès" alors qu'aucune ligne n'avait réellement été
 * insérée.
 *
 * De plus, le handler `collection_toggle` passait `json_decode($col
 * ['product_ids'], true)` directement au 3ᵉ paramètre de
 * `CollectionManager::update()`, typé strictement `array` — sans valider
 * que le decode a bien produit un tableau, contrairement à la garde déjà
 * appliquée dans `CollectionManager::runDailyCheck()` pour cette même
 * donnée. Une ligne `product_ids` corrompue en base (JSON invalide) aurait
 * déclenché une `TypeError` fatale (erreur 500) au lieu d'un message
 * d'erreur propre, en cliquant simplement sur "activer/désactiver".
 *
 * Bugs identifiés le 10/09/2026 (round 335, audit GoldenHourManager/
 * CollectionManager).
 *
 * Corrigés le 10/09/2026 (round 335) : retour de `create()` désormais
 * vérifié dans `collection_add` ; `json_decode()` désormais validé via
 * `is_array()` AVANT l'appel à `update()` dans `collection_toggle`.
 *
 * Test structurel sur neria.php pour les deux handlers (dispatch d'action
 * BO complet, impraticable à invoquer isolément en CLI, même limite
 * acceptée pour les autres handlers de ce fichier — cf. test_675) +
 * comportemental réel sur `CollectionManager::create()`/`getById()`
 * (chemin nominal, garantit que le correctif n'a rien cassé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle des 2 correctifs dans neria.php ────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posAdd = strpos($src, "neria_action') === 'collection_add'");
    neria_assert($posAdd !== false, "Handler collection_add introuvable — jeu de test invalide");
    $bodyAdd = substr($src, $posAdd, 1300);
    neria_assert(
        strpos($bodyAdd, '$msgKey = (new CollectionManager($this))->create($name, $productIds)') !== false
            && strpos($bodyAdd, "? 'success:msg.collection_added'") !== false
            && strpos($bodyAdd, ": 'error:msg.collection_invalid';") !== false,
        "neria.php ne conditionne plus le message de succès de collection_add au retour réel de CollectionManager::create() — régression du bug corrigé le 10/09/2026 (round 335) : un succès serait de nouveau affiché même si l'INSERT échoue"
    );

    $posToggle = strpos($src, "neria_action') === 'collection_toggle'");
    neria_assert($posToggle !== false, "Handler collection_toggle introuvable — jeu de test invalide");
    $bodyToggle = substr($src, $posToggle, 1400);
    neria_assert(
        strpos($bodyToggle, '$colProductIds = $col ? json_decode($col[\'product_ids\'], true) : null;') !== false
            && strpos($bodyToggle, 'if ($col && is_array($colProductIds)) {') !== false,
        "neria.php ne valide plus is_array() sur le json_decode() de product_ids avant collection_toggle → CollectionManager::update() — régression du bug corrigé le 10/09/2026 (round 335) : une ligne product_ids corrompue provoquerait de nouveau une TypeError fatale (erreur 500) au lieu d'un message d'erreur propre"
    );

    // ── Vérification comportementale du chemin nominal ───────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $mgr    = new CollectionManager($module);

    $name = 'Regtest684 ' . uniqid();
    $created = $mgr->create($name, [1, 2]);
    neria_assert($created, "CollectionManager::create() a échoué de façon inattendue — comportement nominal cassé");

    $row = $db->getRow(
        "SELECT * FROM {$prefix}neria_collection WHERE name = '" . pSQL($name) . "' ORDER BY id_neria_collection DESC"
    );
    neria_assert($row !== false && $row !== null, "La collection créée n'est pas retrouvable en base — comportement nominal cassé");
    $id = (int) $row['id_neria_collection'];

    try {
        $col = $mgr->getById($id);
        neria_assert($col !== null, "getById() ne retrouve plus la collection fraîchement créée");
        $decoded = json_decode($col['product_ids'], true);
        neria_assert(is_array($decoded), "product_ids stocké par create() ne se décode plus en tableau JSON valide — comportement nominal cassé");

        $updated = $mgr->update($id, $col['name'], $decoded, !(bool) $col['active']);
        neria_assert($updated, "CollectionManager::update() a échoué sur le chemin nominal (toggle) — comportement nominal cassé");

        return [
            'pass'    => true,
            'message' => "collection_add vérifie désormais le retour de create() et collection_toggle valide is_array() avant update(), comportement nominal préservé — bug corrigé le 10/09/2026 (round 335)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_collection WHERE id_neria_collection = {$id}");
    }
}
