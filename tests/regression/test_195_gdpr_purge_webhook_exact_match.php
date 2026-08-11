<?php
/**
 * Régression : GdprAuditManager::purgeCustomerData() ne doit PAS purger une
 * ligne neria_webhook_queue d'un client B sous prétexte que le customer_id
 * de A apparaît comme sous-chaîne dans une valeur de B.
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : le matching se faisait via
 * SQL `LIKE '%email%'` sur tout le payload JSON — une simple recherche de
 * sous-chaîne, sans ancrage sur les délimiteurs JSON. Un client B dont une
 * valeur du payload contient celle de A comme sous-chaîne voyait sa propre
 * ligne supprimée par la demande d'effacement de A. Corrigé en décodant
 * chaque payload en PHP et en comparant les valeurs EXACTES (customer_id
 * numérique).
 *
 * Test comportemental réel : deux lignes webhook, l'une pour le client A
 * (id_customer=1000042 par ex.) et l'autre pour un client B dont le
 * customer_id (10000420, qui contient 1000042 comme sous-chaîne
 * décimale) est différent. La purge de A ne doit affecter QUE sa ligne.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $idA = 1000042;
    $idB = 10000420; // contient "1000042" comme sous-chaîne décimale

    $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE id_shop = 999999");

    $db->execute(
        "INSERT INTO {$prefix}neria_webhook_queue (id_shop, event, payload, status, attempts, date_add)
         VALUES (999999, 'email_sent', '" . pSQL(json_encode(['template' => 'vip', 'customer_id' => $idA])) . "', 'pending', 0, NOW())"
    );
    $idRowA = (int) $db->Insert_ID();
    $db->execute(
        "INSERT INTO {$prefix}neria_webhook_queue (id_shop, event, payload, status, attempts, date_add)
         VALUES (999999, 'email_sent', '" . pSQL(json_encode(['template' => 'vip', 'customer_id' => $idB])) . "', 'pending', 0, NOW())"
    );
    $idRowB = (int) $db->Insert_ID();

    try {
        neria_assert($idRowA > 0 && $idRowB > 0, 'jeu de test invalide : INSERT échoué');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeCustomerData($idA, '');

        $rowAExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idRowA}");
        $rowBExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_webhook_queue WHERE id_webhook = {$idRowB}");

        neria_assert($rowAExists === 0, "la ligne du client A n'a pas été purgée — jeu de test invalide");
        neria_assert(
            $rowBExists === 1,
            "la ligne du client B (customer_id={$idB}) a été purgée par erreur suite à la demande d'effacement du client A (customer_id={$idA}) — régression du bug corrigé le 09/08/2026 (round 144) : le matching LIKE non ancré purgerait de nouveau les données d'un tiers non consentant"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_webhook_queue WHERE id_shop = 999999");
    }

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::purgeCustomerData() compare bien le customer_id exact du payload webhook, pas une sous-chaîne",
    ];
}
