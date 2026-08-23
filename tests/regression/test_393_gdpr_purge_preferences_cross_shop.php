<?php
/**
 * Régression : GdprAuditManager::purgeCustomerData() supprimait les lignes
 * neria_preferences par simple correspondance d'EMAIL, sans filtre
 * id_customer, alors que la clé unique de la table est
 * (id_shop, id_customer, email, category) — deux clients DIFFÉRENTS sur
 * deux boutiques distinctes d'une même install multi-boutiques peuvent
 * légitimement partager le même email.
 *
 * Bug réel identifié le 23/08/2026 (round 187) : une demande d'effacement
 * RGPD traitée pour le client A (Boutique 1) supprimait AUSSI, silencieusement,
 * la ligne préférences (opt-in/out) d'un client B totalement différent
 * (Boutique 2) qui partage juste cet email — suppression non autorisée des
 * données d'un tiers.
 *
 * Corrigé le 23/08/2026 (round 187) : le DELETE sur neria_preferences est
 * désormais scopé par id_customer EN PLUS de l'email.
 *
 * Test comportemental réel : deux lignes préférences, même email, deux
 * id_customer différents (simulant deux clients distincts partageant un
 * email sur des boutiques différentes). La purge de A ne doit affecter QUE
 * sa ligne.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $idCustomerA = 999911;
    $idCustomerB = 999912;
    $sharedEmail = 'partage.round187@example.test';

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer IN ({$idCustomerA}, {$idCustomerB})");

    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
         VALUES (1, {$idCustomerA}, '" . pSQL($sharedEmail) . "', 'newsletter', 1, NOW())"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
         VALUES (2, {$idCustomerB}, '" . pSQL($sharedEmail) . "', 'newsletter', 1, NOW())"
    );

    try {
        $countA = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomerA}");
        $countB = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomerB}");
        neria_assert($countA === 1 && $countB === 1, 'jeu de test invalide : INSERT échoué');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeCustomerData($idCustomerA, $sharedEmail);

        $rowAExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomerA}");
        $rowBExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomerB}");

        neria_assert($rowAExists === 0, "la ligne préférences du client A n'a pas été purgée — jeu de test invalide");
        neria_assert(
            $rowBExists === 1,
            "la ligne préférences du client B (id_customer={$idCustomerB}) a été supprimée par erreur suite à la demande d'effacement du client A, sous prétexte qu'ils partagent le même email — régression du bug corrigé le 23/08/2026 (round 187)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer IN ({$idCustomerA}, {$idCustomerB})");
    }

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::purgeCustomerData() ne purge plus neria_preferences que pour l'id_customer exact, pas tout email partagé — bug corrigé le 23/08/2026 (round 187)",
    ];
}
