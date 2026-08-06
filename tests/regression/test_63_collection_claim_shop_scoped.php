<?php
/**
 * Régression : CollectionManager::claimSend()/releaseSendClaim() doivent
 * scoper leur réservation compare-and-swap par id_shop, pas seulement par
 * (id_neria_collection, id_customer).
 *
 * Bug réel corrigé le 06/08/2026 (round 60, upgrade 1.0.38) :
 * processCollection() groupe déjà les achats par (id_customer, id_shop)
 * pour ne pas mélanger les catalogues multi-boutiques, mais la réservation
 * anti-doublon ne l'était pas. Un même client (email partagé) complétant
 * RÉELLEMENT la même collection sur deux boutiques distinctes voyait sa 2e
 * complétion bloquée à tort par la réservation posée pour la 1re — email
 * jamais envoyé pour la 2e boutique, silencieusement.
 *
 * Test comportemental réel (pas juste structurel) : id_shop n'a pas de
 * contrainte de clé étrangère sur neria_collection_sent, donc vérifiable
 * même sur cet environnement de dev à une seule boutique réelle, en
 * utilisant un 2e id_shop arbitraire.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $colId = 900000 + random_int(1, 99999); // id de collection fictif, isolé des vraies données

    $mgr = neria_test_module();
    $collectionMgr = new CollectionManager($mgr);

    $claim   = new ReflectionMethod(CollectionManager::class, 'claimSend');
    $claim->setAccessible(true);
    $release = new ReflectionMethod(CollectionManager::class, 'releaseSendClaim');
    $release->setAccessible(true);

    try {
        $r1 = $claim->invoke($collectionMgr, $colId, $idCustomer, 1);
        neria_assert($r1 === true, "1re réservation (boutique 1) a échoué — jeu de test invalide");

        $r2 = $claim->invoke($collectionMgr, $colId, $idCustomer, 1);
        neria_assert($r2 === false, "une 2e réservation pour le MÊME client/collection/boutique n'a pas été bloquée — la déduplication ne fonctionne plus du tout");

        $r3 = $claim->invoke($collectionMgr, $colId, $idCustomer, 2);
        neria_assert(
            $r3 === true,
            "la réservation pour le même client/collection sur une AUTRE boutique (id_shop=2) a été bloquée à tort — régression du bug corrigé le 06/08/2026 (round 60) : la réservation n'est plus scopée par id_shop"
        );

        return [
            'pass'    => true,
            'message' => 'claimSend()/releaseSendClaim() restent bien scopés par id_shop : doublon bloqué sur la même boutique, autorisé sur une autre',
        ];
    } finally {
        $release->invoke($collectionMgr, $colId, $idCustomer, 1);
        $release->invoke($collectionMgr, $colId, $idCustomer, 2);
        $db->execute("DELETE FROM {$prefix}neria_collection_sent WHERE id_neria_collection = {$colId}");
    }
}
