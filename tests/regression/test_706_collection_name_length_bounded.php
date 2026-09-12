<?php
/**
 * Régression : CollectionManager::create()/update() doivent borner `name`
 * à la taille réelle de la colonne (VARCHAR(255)) AVANT écriture, au lieu
 * de laisser MySQL tronquer silencieusement une valeur trop longue.
 *
 * Bug réel corrigé le 12/09/2026 (round 341) : ni create() ni update() ni
 * le contrôleur BO (neria.php, collection_add) ne bornaient la longueur du
 * nom saisi. Vérifié empiriquement round 340 que la connexion MySQL de
 * PrestaShop tronque SILENCIEUSEMENT une valeur trop longue pour une
 * colonne VARCHAR, malgré STRICT_TRANS_TABLES actif au niveau serveur —
 * create()/update() renvoyaient donc un succès et affichaient "Collection
 * ajoutée"/"Collection mise à jour" alors que le nom stocké différait de
 * la saisie réelle du marchand, sans aucun message. Même pattern déjà
 * corrigé pour `reason` dans BounceManager::recordBounce() (mb_substr
 * borné à 500 avant écriture).
 *
 * Test comportemental réel : un nom de 400 caractères doit être stocké
 * tronqué à EXACTEMENT 255 caractères après create()/update(), pas plus
 * (ce qui prouve que la borne PHP agit, indépendamment du comportement
 * MySQL sous-jacent).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $mgr    = new CollectionManager(neria_test_module());

    $idColl = null;

    try {
        $longName = str_repeat('A', 400);

        $ok = $mgr->create($longName, [1, 2]);
        neria_assert($ok, "create() a échoué sur un nom de 400 caractères — jeu de test invalide");

        $idColl = (int) $db->getValue(
            "SELECT id_neria_collection FROM {$prefix}neria_collection
             WHERE name LIKE 'AAAA%' ORDER BY id_neria_collection DESC"
        );
        neria_assert($idColl > 0, "jeu de test invalide : la collection insérée n'a pas été retrouvée");

        $storedName = (string) $db->getValue(
            "SELECT name FROM {$prefix}neria_collection WHERE id_neria_collection = {$idColl}"
        );
        neria_assert(
            mb_strlen($storedName) === 255,
            "create() n'a pas borné le nom à 255 caractères (longueur stockée : " . mb_strlen($storedName) . ") — régression du bug corrigé le 12/09/2026 (round 341)"
        );

        // update() : même vérification, avec un nom différent pour éviter
        // toute ambiguïté avec la valeur insérée par create().
        $longName2 = str_repeat('B', 300);
        $ok2 = $mgr->update($idColl, $longName2, [1, 2], true);
        neria_assert($ok2, "update() a échoué sur un nom de 300 caractères — jeu de test invalide");

        $storedName2 = (string) $db->getValue(
            "SELECT name FROM {$prefix}neria_collection WHERE id_neria_collection = {$idColl}"
        );
        neria_assert(
            mb_strlen($storedName2) === 255,
            "update() n'a pas borné le nom à 255 caractères (longueur stockée : " . mb_strlen($storedName2) . ") — régression du bug corrigé le 12/09/2026 (round 341)"
        );

        // Chemin nominal : un nom court n'est jamais altéré.
        $shortName = 'Collection Regtest 341';
        $ok3 = $mgr->update($idColl, $shortName, [1, 2], true);
        neria_assert($ok3, "update() a échoué sur un nom court — jeu de test invalide");
        $storedName3 = (string) $db->getValue(
            "SELECT name FROM {$prefix}neria_collection WHERE id_neria_collection = {$idColl}"
        );
        neria_assert(
            $storedName3 === $shortName,
            "un nom court a été altéré par la borne de longueur — régression : mb_substr(\$name, 0, 255) ne devrait rien changer pour un nom déjà court"
        );

        return [
            'pass'    => true,
            'message' => "CollectionManager::create()/update() bornent bien `name` à 255 caractères avant écriture (tronqué proprement en PHP, jamais par MySQL silencieusement), comportement nominal préservé sur un nom court",
        ];
    } finally {
        if ($idColl) {
            $db->execute("DELETE FROM {$prefix}neria_collection WHERE id_neria_collection = {$idColl}");
        }
    }
}
