<?php
/**
 * Régression : OrderTriggersManager::checkMilestone() doit libérer sa
 * réservation anti-doublon (neria_milestone_voucher) en cas d'échec
 * d'envoi de milestone_order, sinon aucun mécanisme de retry n'existe pour
 * ce template et le client perd définitivement ce palier.
 *
 * Bug réel corrigé le 06/08/2026 (round 61) : contrairement à
 * generateMilestoneVoucher() (qui libère bien sa réservation si
 * CartRule::add() échoue), checkMilestone() ne le faisait jamais en cas
 * d'échec silencieux de Mail::Send() ou d'exception — la réservation
 * restait posée à vie.
 *
 * Ce test vérifie directement releaseMilestoneClaim() (nouvelle méthode) :
 * elle doit libérer une réservation "en cours" (id_cart_rule=0), mais ne
 * JAMAIS supprimer une réservation dont un vrai bon a déjà été créé
 * (id_cart_rule > 0) — sinon un futur retry recréerait un second CartRule
 * orphelin pour le même palier.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop = (int) Context::getContext()->shop->id;
    $milestone = 60000 + random_int(1, 5000); // palier fictif (smallint unsigned, max 65535), isolé des vraies données

    $mgr = new OrderTriggersManager(neria_test_module());

    $claim   = new ReflectionMethod(OrderTriggersManager::class, 'claimMilestone');
    $claim->setAccessible(true);
    $release = new ReflectionMethod(OrderTriggersManager::class, 'releaseMilestoneClaim');
    $release->setAccessible(true);

    try {
        // Cas 1 : réservation "en cours" (id_cart_rule=0, comme un échec
        // d'envoi sans bon généré) — doit être libérée.
        $claimed = $claim->invoke($mgr, $idCustomer, $milestone, $idShop);
        neria_assert($claimed === true, "claimMilestone() a échoué — jeu de test invalide");

        $release->invoke($mgr, $idCustomer, $milestone, $idShop);
        $stillThere = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_milestone_voucher
             WHERE id_customer = {$idCustomer} AND milestone = {$milestone} AND id_shop = {$idShop}"
        );
        neria_assert(
            $stillThere === 0,
            "releaseMilestoneClaim() ne libère plus une réservation en cours (id_cart_rule=0) — régression du bug corrigé le 06/08/2026 (round 61) : un client perdrait à nouveau définitivement ce palier après un échec d'envoi"
        );

        // Cas 2 : réservation avec un vrai bon déjà créé (id_cart_rule > 0,
        // comme après un generateMilestoneVoucher() réussi) — ne doit JAMAIS
        // être supprimée, même si l'envoi de l'email échoue ensuite.
        $claim->invoke($mgr, $idCustomer, $milestone, $idShop);
        $db->execute(
            "UPDATE {$prefix}neria_milestone_voucher
             SET id_cart_rule = 999999, voucher_code = 'NERIA-TEST-REGTEST'
             WHERE id_customer = {$idCustomer} AND milestone = {$milestone} AND id_shop = {$idShop}"
        );
        $release->invoke($mgr, $idCustomer, $milestone, $idShop);
        $row = $db->getRow(
            "SELECT id_cart_rule FROM {$prefix}neria_milestone_voucher
             WHERE id_customer = {$idCustomer} AND milestone = {$milestone} AND id_shop = {$idShop}"
        );
        neria_assert(
            $row !== false && (int) $row['id_cart_rule'] === 999999,
            "releaseMilestoneClaim() a supprimé une réservation dont un vrai bon avait déjà été créé — un retry recréerait un second CartRule orphelin pour le même palier"
        );

        return [
            'pass'    => true,
            'message' => "releaseMilestoneClaim() libère bien une réservation en cours, sans jamais toucher une réservation dont le bon a déjà été créé",
        ];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_milestone_voucher WHERE id_customer = {$idCustomer} AND milestone = {$milestone}"
        );
    }
}
