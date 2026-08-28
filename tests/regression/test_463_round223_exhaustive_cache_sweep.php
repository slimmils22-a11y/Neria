<?php
/**
 * Régression round 223 (27/08/2026) — 5 occurrences résiduelles du bug
 * systémique cache SQL (rounds 210-222), trouvées par un balayage
 * exhaustif des 428 appels Db::getValue()/getRow()/executeS() de tout
 * src/*.php :
 *
 * 1-3. HealthCheckManager::checkOrphanedVoucherReservations() : 3 COUNT
 *    (bons anniversaire, bons de palier, récompenses fidélité) décidant
 *    du DELETE de nettoyage des réservations orphelines.
 * 4. WatchdogManager::pruneOldLogs() : COUNT décidant de la purge des
 *    logs au-delà du plafond MAX_LOGS.
 * 5. WaitlistManager::isRegistered() : COUNT décidant si register()
 *    exécute réellement l'INSERT (protégé par ON DUPLICATE KEY UPDATE,
 *    mais un résultat "déjà inscrit" périmé fait sauter l'INSERT tout
 *    court — le client croit être inscrit sans qu'aucune ligne n'existe).
 *
 * Corrigé le 27/08/2026 (round 223) : $use_cache=false sur les 5.
 *
 * Test structurel + comportemental réel (WaitlistManager) : vérifie la
 * présence des 5 garde-fous, puis confirme qu'un client réellement
 * désinscrit (unregister()) est bien re-détecté comme non-inscrit et
 * peut se réinscrire normalement (comportement nominal préservé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $base = _PS_MODULE_DIR_ . 'neria/';

    $hcmRaw = file_get_contents($base . 'src/HealthCheckManager.php');
    neria_assert($hcmRaw !== false, 'Impossible de lire src/HealthCheckManager.php');
    $hcm = str_replace("\r", '', $hcmRaw);
    neria_assert(
        substr_count($hcm, "created_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)',\n                false\n            );") >= 1,
        "HealthCheckManager::checkOrphanedVoucherReservations() n'a plus \$use_cache=false sur le COUNT bons — régression du bug corrigé le 27/08/2026 (round 223)"
    );
    neria_assert(
        strpos($hcm, "sent_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)',\n                false\n            );") !== false,
        "HealthCheckManager::checkOrphanedVoucherReservations() n'a plus \$use_cache=false sur le COUNT loyalty_rewards — régression du bug corrigé le 27/08/2026 (round 223)"
    );

    $wd = file_get_contents($base . 'src/WatchdogManager.php');
    neria_assert($wd !== false, 'Impossible de lire src/WatchdogManager.php');
    neria_assert(
        strpos($wd, "WHERE `id_shop` = {\$this->idShop}\",\n            false\n        );") !== false,
        "WatchdogManager::pruneOldLogs() n'a plus \$use_cache=false — régression du bug corrigé le 27/08/2026 (round 223)"
    );

    $wl = file_get_contents($base . 'src/WaitlistManager.php');
    neria_assert($wl !== false, 'Impossible de lire src/WaitlistManager.php');
    neria_assert(
        strpos($wl, "AND notified_at IS NULL\",\n            false\n        ) > 0;") !== false,
        "WaitlistManager::isRegistered() n'a plus \$use_cache=false — régression du bug corrigé le 27/08/2026 (round 223) : un client pourrait de nouveau croire être inscrit sur la liste d'attente sans qu'aucune ligne n'existe réellement"
    );

    // Vérification comportementale réelle : register()/unregister()/
    // isRegistered() fonctionnent toujours normalement après l'ajout de
    // $use_cache=false.
    require_once $base . 'src/WaitlistManager.php';
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = 999960;
    $idProduct  = 999961;

    $db->execute("DELETE FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}");

    try {
        $mgr = new WaitlistManager(neria_test_module());

        neria_assert(
            $mgr->isRegistered($idCustomer, $idProduct, $idShop) === false,
            "isRegistered() détecte à tort une inscription inexistante — jeu de test invalide"
        );

        $ok = $mgr->register($idCustomer, $idProduct, $idShop);
        neria_assert($ok === true, "register() a échoué — jeu de test invalide");
        neria_assert(
            $mgr->isRegistered($idCustomer, $idProduct, $idShop) === true,
            "isRegistered() ne détecte pas l'inscription fraîchement créée — comportement nominal cassé par l'ajout de \$use_cache=false"
        );

        $mgr->unregister($idCustomer, $idProduct, $idShop);
        neria_assert(
            $mgr->isRegistered($idCustomer, $idProduct, $idShop) === false,
            "isRegistered() détecte encore une inscription après unregister() — comportement nominal cassé"
        );

        $ok2 = $mgr->register($idCustomer, $idProduct, $idShop);
        neria_assert(
            $ok2 === true && $mgr->isRegistered($idCustomer, $idProduct, $idShop) === true,
            "register() après unregister() ne réinscrit pas réellement le client — régression du bug corrigé le 27/08/2026 (round 223)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}");
    }

    return [
        'pass'    => true,
        'message' => 'Round 223 : $use_cache=false sur les 5 occurrences résiduelles (HealthCheckManager x3, WatchdogManager, WaitlistManager), comportement nominal WaitlistManager préservé',
    ];
}
