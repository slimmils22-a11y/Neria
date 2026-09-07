<?php
/**
 * Régression : CollectionManager::claimSend() écrivait sent_at via
 * date('Y-m-d H:i:s') (horloge PHP) alors que getStats() filtre ensuite
 * "envoyés depuis 30 jours" via `sent_at >= DATE_SUB(NOW(), INTERVAL 30
 * DAY)`, comparaison purement côté MySQL — même piège horloge PHP/MySQL
 * corrigé le même round dans LookCompletionManager::claimSend() (pattern
 * identique, KPI voisin sur le tableau de bord BO).
 *
 * Corrigé le 07/09/2026 (round 314) : sent_at écrit via NOW() SQL.
 *
 * Test comportemental réel : appelle claimSend() via réflexion pour un
 * client/collection/boutique de test, puis compare le sent_at écrit en
 * base à un SELECT NOW() immédiat — l'écart doit être de l'ordre de la
 * seconde, complété par une vérification structurelle ciblant le littéral
 * NOW() exact.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $colId      = 999888;

    try {
        $mgr = new CollectionManager(neria_test_module());
        $ref = new ReflectionMethod(CollectionManager::class, 'claimSend');
        $ref->setAccessible(true);
        $claimed = $ref->invoke($mgr, $colId, $idCustomer, $idShop);
        neria_assert($claimed === true, 'claimSend() n\'a pas remporté la réservation — jeu de test invalide (ligne déjà présente ?)');

        $sentAt = $db->getValue(
            "SELECT sent_at FROM {$prefix}neria_collection_sent
             WHERE id_neria_collection = {$colId} AND id_customer = {$idCustomer} AND id_shop = {$idShop}"
        );
        neria_assert($sentAt !== false, 'sent_at introuvable après claimSend() — jeu de test invalide');

        $diffSeconds = (int) $db->getValue("SELECT TIMESTAMPDIFF(SECOND, '" . pSQL((string) $sentAt) . "', NOW())");
        neria_assert(
            abs($diffSeconds) <= 5,
            "sent_at écrit par claimSend() diffère de NOW() MySQL de {$diffSeconds}s — jeu de test invalide ou horloge incohérente"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php');
        neria_assert($src !== false, 'Impossible de lire src/CollectionManager.php');
        neria_assert(
            strpos($src, "VALUES ({\$colId}, {\$idCustomer}, {\$idShop}, NOW())") !== false,
            "CollectionManager::claimSend() n'écrit plus sent_at via NOW() SQL dans son propre corps — régression du bug corrigé le 07/09/2026 (round 314) : sent_at redeviendrait basé sur l'horloge PHP, source d'incohérence avec le filtre MySQL de getStats()"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_collection_sent WHERE id_neria_collection = {$colId} AND id_customer = {$idCustomer} AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "CollectionManager::claimSend() écrit bien sent_at via NOW() SQL, cohérent avec le filtre MySQL de getStats() — bug corrigé le 07/09/2026 (round 314)",
    ];
}
