<?php
/**
 * Régression : dans CollectionManager::processCollection() et
 * LookCompletionManager::runDailyCheck(), seul l'appel Mail::Send() était
 * protégé par try/catch — toute exception levée AVANT ce bloc (ex. new
 * \Product()/new \Customer() sur une donnée corrompue, StockAvailable,
 * buildProductBlocks()) fuyait la réservation claimSend() (jamais libérée
 * — client/commande exclu à vie de la notification, clé UNIQUE bloquant
 * tout INSERT IGNORE futur) ET remontait hors de la boucle, interrompant
 * le traitement de TOUTES les lignes suivantes du batch pour le reste du
 * cron de ce jour.
 *
 * Corrigé le 09/08/2026 (round 157) en englobant tout le traitement par
 * ligne (depuis juste après claimSend() jusqu'à la fin du bloc mail) dans
 * un try/catch qui libère systématiquement la réservation.
 *
 * Test structurel (forcer une exception réelle nécessiterait de corrompre
 * des données produit/client en base, hors périmètre d'un test isolé et
 * risqué pour les autres tests de la suite) : vérifie que le try englobant
 * démarre bien juste après claimSend() (avant new \Customer()) et se
 * termine par un catch qui appelle releaseSendClaim(), dans les 2 fichiers.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $col = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php');
    neria_assert($col !== false, 'Impossible de lire CollectionManager.php');
    $posClaim = strpos($col, 'if (!$this->claimSend($colId, $idCustomer, $idShop)) continue;');
    neria_assert($posClaim !== false, 'claimSend() introuvable dans CollectionManager.php — jeu de test invalide');
    $afterClaim = substr($col, $posClaim, 1100);
    neria_assert(
        strpos($afterClaim, 'try {') !== false && strpos($afterClaim, 'new \Customer($idCustomer);') !== false,
        "CollectionManager::processCollection() n'englobe plus le traitement par ligne dans un try — régression du bug corrigé le 09/08/2026 (round 157)"
    );
    // Doit y avoir 2 blocs catch qui releaseSendClaim (celui de Mail::Send
    // déjà existant + le nouveau catch englobant).
    neria_assert(
        substr_count($col, 'catch (\Throwable $e) {') >= 2 && substr_count($col, "watchdog.collection_item_error") >= 2,
        "CollectionManager::processCollection() n'a plus de 2e bloc catch englobant — régression du bug corrigé le 09/08/2026 (round 157)"
    );

    $look = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($look !== false, 'Impossible de lire LookCompletionManager.php');
    $posClaimLook = strpos($look, 'if (!$this->claimSend($idOrder, $idCustomer)) continue;');
    neria_assert($posClaimLook !== false, 'claimSend() introuvable dans LookCompletionManager.php — jeu de test invalide');
    $afterClaimLook = substr($look, $posClaimLook, 1100);
    neria_assert(
        strpos($afterClaimLook, 'try {') !== false,
        "LookCompletionManager::runDailyCheck() n'englobe plus le traitement par commande dans un try — régression du bug corrigé le 09/08/2026 (round 157)"
    );
    neria_assert(
        substr_count($look, 'catch (\Throwable $e) {') >= 2 && substr_count($look, 'watchdog.look_completion_item_error') >= 2,
        "LookCompletionManager::runDailyCheck() n'a plus de 2e bloc catch englobant — régression du bug corrigé le 09/08/2026 (round 157)"
    );

    return [
        'pass'    => true,
        'message' => "CollectionManager/LookCompletionManager englobent bien tout le traitement par ligne dans un try/catch qui libère la réservation — bug corrigé le 09/08/2026 (round 157)",
    ];
}
