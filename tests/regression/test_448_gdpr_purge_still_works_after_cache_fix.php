<?php
/**
 * Régression round 214 (26/08/2026) : garde-fou de non-régression pour
 * l'ajout de $use_cache=false dans GdprAuditManager::purgeCustomerData()
 * (voir test_447 pour le détail du bug et sa vérification structurelle).
 *
 * Test comportemental réel : une ligne neria_preferences seedée pour un
 * client de test doit toujours être réellement supprimée par
 * purgeCustomerData() — confirme que l'ajout du paramètre $use_cache=false
 * n'a pas cassé le comportement nominal de la purge (signature getValue()
 * correctement respectée, DELETE toujours déclenché).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $idCustomer = 999950;
    $email      = 'round214.purge.test@example.test';

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer}");
    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
         VALUES (1, {$idCustomer}, '" . pSQL($email) . "', 'newsletter', 1, NOW())"
    );

    try {
        $before = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer}");
        neria_assert($before === 1, 'jeu de test invalide : INSERT échoué');

        $mgr   = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $total = $mgr->purgeCustomerData($idCustomer, $email);

        $after = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer}");

        neria_assert(
            $after === 0,
            "purgeCustomerData() n'a pas supprimé la ligne neria_preferences du client de test après l'ajout de \$use_cache=false — régression du correctif du 26/08/2026 (round 214), la signature getValue() aurait été mal respectée"
        );
        neria_assert(
            $total >= 1,
            "purgeCustomerData() a retourné un total ({$total}) ne reflétant pas la suppression réelle effectuée — régression du correctif du 26/08/2026 (round 214)"
        );

        return [
            'pass'    => true,
            'message' => "GdprAuditManager::purgeCustomerData() supprime toujours réellement les données du client après l'ajout de \$use_cache=false — round 214",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer}");
    }
}
