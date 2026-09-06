<?php
/**
 * Régression : OrderTriggersManager::handleStatusChange() posait un
 * GET_LOCK() nommé pour order_partial_shipped/order_on_hold (round 280,
 * protection anti-doublon indépendante du toggle Mode Silence), mais ne
 * le libérait JAMAIS via RELEASE_LOCK() — contrairement à handleRefund()/
 * handleReturn() dans ce même fichier, qui libèrent bien leur verrou dans
 * un bloc finally.
 *
 * Sans impact fonctionnel visible en usage web classique (PrestaShop ferme
 * la connexion PHP-FPM en fin de requête, ce qui libère automatiquement
 * tout GET_LOCK() côté MySQL), mais une fuite réelle de verrous nommés
 * jamais libérés sur toute connexion réutilisée au-delà d'une seule
 * requête (worker de file, script CLI de retraitement en masse de
 * statuts) — incohérence de robustesse par rapport aux méthodes sœurs du
 * même fichier.
 *
 * Corrigé le 05/09/2026 (round 306) : RELEASE_LOCK() ajouté dans un bloc
 * finally pour les deux déclencheurs, même pattern que handleRefund()/
 * handleReturn().
 *
 * Test comportemental réel : construit un OrderState fictif satisfaisant
 * la condition order_on_hold, appelle handleStatusChange(), puis vérifie
 * via IS_USED_LOCK() que le verrou nommé n'est PLUS détenu par PERSONNE
 * (y compris la connexion appelante elle-même) après l'appel — GET_LOCK()
 * de MySQL est ré-entrant pour une même connexion, donc seul un vrai
 * RELEASE_LOCK() peut faire disparaître la détention, jamais un simple
 * changement de connexion.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php';

    $db = Db::getInstance();
    $row = $db->getRow(
        "SELECT o.id_order FROM " . _DB_PREFIX_ . "orders o
         INNER JOIN " . _DB_PREFIX_ . "customer c ON c.id_customer = o.id_customer
         WHERE c.deleted = 0 AND c.active = 1"
    );
    neria_assert($row !== false, "Aucune commande avec un client actif trouvée — jeu de test invalide");
    $idOrder = (int) $row['id_order'];

    $fakeStatusId = 999888; // hors STANDARD_STATUS_IDS, jamais une vraie transition core

    $oldStatus = new OrderState();
    $oldStatus->id = 1; // statut standard quelconque, peu importe pour ce test

    $newStatus = new OrderState();
    $newStatus->id = $fakeStatusId;
    $newStatus->send_email = true;
    $newStatus->paid = false;
    $newStatus->shipped = false;
    $newStatus->delivery = false;
    $newStatus->logable = false;
    $newStatus->name = [(int) Configuration::get('PS_LANG_DEFAULT') => 'Regtest306 Hold'];

    $lockName = 'neria_order_on_hold_' . $idOrder . '_' . $fakeStatusId;
    // Nettoyage défensif : un run précédent interrompu pourrait avoir
    // laissé ce verrou détenu par cette même connexion CLI.
    $db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");

    $mgr = new OrderTriggersManager(neria_test_module());
    $mgr->handleStatusChange($newStatus, $oldStatus, $idOrder);

    $stillHeld = $db->getValue("SELECT IS_USED_LOCK('" . pSQL($lockName) . "')");

    neria_assert(
        $stillHeld === false || $stillHeld === null || $stillHeld === '',
        "Le verrou '{$lockName}' est encore détenu (IS_USED_LOCK renvoie " . var_export($stillHeld, true) . ") après le retour de handleStatusChange() — régression du bug corrigé le 05/09/2026 (round 306) : le GET_LOCK() posé pour order_on_hold n'est de nouveau jamais libéré par RELEASE_LOCK()"
    );

    // Vérification structurelle complémentaire : les 2 blocs try/finally
    // sont bien présents dans le code source.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');
    neria_assert(
        substr_count($src, "\$this->db->execute(\"SELECT RELEASE_LOCK('\" . pSQL(\$lockNamePs) . \"')\");") === 1
        && substr_count($src, "\$this->db->execute(\"SELECT RELEASE_LOCK('\" . pSQL(\$lockNameOh) . \"')\");") === 1,
        "Les RELEASE_LOCK() de \$lockNamePs/\$lockNameOh ont disparu du code source — régression du bug corrigé le 05/09/2026 (round 306)"
    );

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager::handleStatusChange() libère bien ses verrous nommés (order_partial_shipped/order_on_hold) via RELEASE_LOCK() dans un bloc finally, même pattern que handleRefund()/handleReturn() — bug corrigé le 05/09/2026 (round 306)",
    ];
}
