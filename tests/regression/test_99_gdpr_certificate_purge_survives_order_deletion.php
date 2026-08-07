<?php
/**
 * Régression : GdprAuditManager::purgeCustomerData() doit purger un
 * certificat même si la commande liée (ps_orders) a déjà été supprimée
 * physiquement — pas seulement via un JOIN qui dépend de sa survie.
 *
 * Bug réel corrigé le 07/08/2026 (round 95) : neria_certificate n'avait pas
 * de colonne id_customer directe ; la purge RGPD passait par
 * `INNER JOIN ps_orders o ON o.id_order = nc.id_order WHERE o.id_customer = X`.
 * Si la commande liée avait été supprimée (suppression manuelle d'une
 * commande dans le BO PrestaShop), le JOIN ne matchait plus rien : le
 * certificat (nom client en clair, référence commande) n'était jamais
 * purgé, alors que purgeCustomerData() retournait quand même un total sans
 * erreur — le marchand croyait la donnée effacée alors qu'elle survivait
 * indéfiniment (violation du droit à l'effacement, art. 17 RGPD). Ajout de
 * la colonne id_customer (upgrade-1.0.39, backfillée depuis ps_orders,
 * renseignée à chaque nouvelle émission) : la purge matche désormais
 * directement par id_customer, indépendamment de la survie de la commande.
 *
 * Test comportemental réel : certificat de test avec un id_order qui
 * n'existe PAS dans ps_orders (simule une commande supprimée), mais avec
 * id_customer correctement renseigné. purgeCustomerData() doit le purger
 * quand même.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $fakeOrder  = 999888; // id_order fictif, garanti absent de ps_orders

    $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_order = {$fakeOrder}");

    // Certificat "orphelin" : sa commande n'existe plus, mais id_customer
    // est renseigné (comme le fait désormais issue() à chaque émission).
    $db->execute(
        "INSERT INTO {$prefix}neria_certificate
            (id_shop, id_customer, id_order, id_product, serial_number, customer_name, product_name, date_issued, date_add)
         VALUES (1, {$idCustomer}, {$fakeOrder}, 1, 'REGTEST99-" . uniqid() . "', 'Regtest', 'Regtest', NOW(), NOW())"
    );
    $idCertificate = (int) $db->Insert_ID();

    try {
        neria_assert(
            $idCertificate > 0,
            "jeu de test invalide : l'INSERT du certificat de test a échoué"
        );

        $orderExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}orders WHERE id_order = {$fakeOrder}");
        neria_assert(
            $orderExists === 0,
            "jeu de test invalide : la commande fictive {$fakeOrder} existe réellement (collision d'id)"
        );

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeCustomerData($idCustomer, '');

        $stillExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_certificate WHERE id_certificate = {$idCertificate}");
        neria_assert(
            $stillExists === 0,
            "GdprAuditManager::purgeCustomerData() n'a pas purgé un certificat dont la commande liée n'existe plus — régression du bug corrigé le 07/08/2026 (round 95) : le droit à l'effacement RGPD serait de nouveau incomplet pour ce cas"
        );

        return [
            'pass'    => true,
            'message' => "GdprAuditManager::purgeCustomerData() purge bien un certificat même si sa commande liée a été supprimée, via la colonne id_customer directe",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_order = {$fakeOrder}");
    }
}
