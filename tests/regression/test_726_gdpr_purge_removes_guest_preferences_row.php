<?php
/**
 * Régression : GdprAuditManager::purgeCustomerData() doit aussi supprimer
 * une éventuelle ligne "invité" (id_customer=0) de neria_preferences
 * partageant le même email que le client dont on demande l'effacement RGPD
 * — sans quoi cet email en clair survivait indéfiniment à la propre demande
 * d'effacement du client qui l'a créée.
 *
 * Bug identifié le 13/09/2026 (round 348, audit multi-agents, angle
 * couverture RGPD exhaustive) : la clé unique de neria_preferences inclut
 * l'email pour distinguer deux clients invités différents (round 178) — un
 * client achetant d'abord en invité (ligne id_customer=0), puis créant un
 * compte avec la même adresse, se retrouve avec DEUX lignes distinctes pour
 * le même email. purgeCustomerData() ne supprimait que
 * `email = ... AND id_customer = {idCustomer}`, laissant la ligne
 * id_customer=0 intacte — jamais atteinte par aucune purge (ni ancienneté,
 * ni demande explicite) une fois le compte supprimé.
 *
 * La protection anti-tiers du round 187 (ne jamais purger un AUTRE
 * id_customer>0 partageant le même email par coïncidence) doit rester
 * intacte : ce test vérifie aussi qu'une ligne d'un VRAI tiers
 * (id_customer différent, >0) n'est PAS supprimée.
 *
 * Test comportemental réel : insère 3 lignes dans neria_preferences (client
 * ciblé, ligne invité id_customer=0 même email, tiers id_customer différent
 * même email), appelle purgeCustomerData(), vérifie l'état final des 3.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $table  = "{$prefix}neria_preferences";
    $idShop = (int) Context::getContext()->shop->id;
    $email  = 'round348test' . time() . '@example.invalid';
    $idCustomer = 555348001; // fictif, isolé
    $idCustomerTiers = 555348002; // fictif, isolé, tiers homonyme

    $inserted = [];
    try {
        $db->execute(
            "INSERT INTO `{$table}` (id_shop, id_customer, email, category, subscribed, date_upd)
             VALUES ({$idShop}, {$idCustomer}, '" . pSQL($email) . "', 'newsletter', 1, NOW())"
        );
        $inserted[] = (int) $db->Insert_ID();

        $db->execute(
            "INSERT INTO `{$table}` (id_shop, id_customer, email, category, subscribed, date_upd)
             VALUES ({$idShop}, 0, '" . pSQL($email) . "', 'newsletter', 1, NOW())"
        );
        $inserted[] = (int) $db->Insert_ID();

        $db->execute(
            "INSERT INTO `{$table}` (id_shop, id_customer, email, category, subscribed, date_upd)
             VALUES ({$idShop}, {$idCustomerTiers}, '" . pSQL($email) . "', 'newsletter', 1, NOW())"
        );
        $inserted[] = (int) $db->Insert_ID();

        require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';
        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeCustomerData($idCustomer, $email, $idShop);

        $remainingTarget = (int) $db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE email = '" . pSQL($email) . "' AND id_customer = {$idCustomer}",
            false
        );
        neria_assert(
            $remainingTarget === 0,
            "purgeCustomerData() n'a pas supprimé la ligne du client ciblé lui-même — régression basique, sans rapport avec round 348"
        );

        $remainingGuest = (int) $db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE email = '" . pSQL($email) . "' AND id_customer = 0",
            false
        );
        neria_assert(
            $remainingGuest === 0,
            "purgeCustomerData() ne supprime plus la ligne 'invité' (id_customer=0) partageant le même email — régression du bug corrigé le 13/09/2026 (round 348) : cet email en clair survivrait de nouveau indéfiniment à sa propre demande d'effacement RGPD"
        );

        $remainingTiers = (int) $db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE email = '" . pSQL($email) . "' AND id_customer = {$idCustomerTiers}",
            false
        );
        neria_assert(
            $remainingTiers === 1,
            "purgeCustomerData() a supprimé à tort la ligne d'un TIERS (id_customer différent, >0) partageant le même email — régression de la protection anti-tiers du round 187"
        );

        return [
            'pass'    => true,
            'message' => "GdprAuditManager::purgeCustomerData() supprime bien la ligne invité (id_customer=0) partageant le même email, sans jamais toucher un tiers homonyme légitime (id_customer>0 différent) — bug corrigé le 13/09/2026 (round 348)",
        ];
    } finally {
        $db->execute(
            "DELETE FROM `{$table}` WHERE email = '" . pSQL($email) . "'"
        );
    }
}
