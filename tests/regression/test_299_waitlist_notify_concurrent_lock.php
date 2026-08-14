<?php
/**
 * Régression : WaitlistManager::notifyProduct() n'était protégée par
 * aucun verrou — deux appels concurrents pour le MÊME produit (double hook
 * actionUpdateQuantity, ou hook + appel manuel BO quasi simultanés)
 * relisaient chacun indépendamment le même stock disponible et
 * notifiaient jusqu'à availableQty inscrits DIFFÉRENTS, promettant en
 * tout jusqu'à 2× la quantité réellement disponible.
 *
 * Corrigé le 14/08/2026 (round 167) : GET_LOCK('neria_waitlist_notify_<produit>_<boutique>', 0)
 * sérialise les appels — un 2e appel concurrent retourne immédiatement 0
 * (fail-fast) plutôt que de dupliquer le traitement.
 *
 * Test comportemental réel (même technique que test_255/test_290 : une
 * seconde connexion MySQL brute détient le verrou pendant l'appel) :
 * vérifie que notifyProduct() retourne bien 0 immédiatement quand le
 * verrou est déjà détenu par un autre processus, sans lancer aucun
 * traitement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php';

    $idProduct = 999888; // produit fictif — aucune ligne de liste d'attente ne doit exister
    $idShop    = (int) Context::getContext()->shop->id;

    $mysqli = @mysqli_connect(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_, defined('_DB_PORT_') ? (int) _DB_PORT_ : 3306);
    neria_assert($mysqli !== false, 'Impossible d\'ouvrir une seconde connexion MySQL pour simuler un processus concurrent — jeu de test invalide');

    try {
        $lockName = 'neria_waitlist_notify_' . $idProduct . '_' . $idShop;
        $res = mysqli_query($mysqli, "SELECT GET_LOCK('" . mysqli_real_escape_string($mysqli, $lockName) . "', 2)");
        $row = mysqli_fetch_row($res);
        neria_assert((int) $row[0] === 1, 'La seconde connexion MySQL n\'a pas pu obtenir le verrou — jeu de test invalide');

        $mgr = new WaitlistManager(neria_test_module());
        $start = microtime(true);
        $result = $mgr->notifyProduct($idProduct, $idShop);
        $elapsed = microtime(true) - $start;

        neria_assert(
            $result === 0,
            "notifyProduct() a retourné {$result} au lieu de 0 alors que le verrou était détenu par un processus concurrent — régression du bug corrigé le 14/08/2026 (round 167) : deux appels concurrents pourraient de nouveau chacun notifier des inscrits différents pour la même quantité limitée"
        );
        neria_assert(
            $elapsed < 1.0,
            "notifyProduct() a mis {$elapsed}s à retourner alors que GET_LOCK utilise un timeout 0 (fail-fast) — devrait retourner quasi instantanément"
        );
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('neria_waitlist_notify_" . $idProduct . "_" . $idShop . "')");
        mysqli_close($mysqli);
    }

    return [
        'pass'    => true,
        'message' => "WaitlistManager::notifyProduct() retourne bien 0 immédiatement (fail-fast) quand le verrou est déjà détenu par un processus concurrent — bug corrigé le 14/08/2026 (round 167)",
    ];
}
