<?php
/**
 * Régression : aucune méthode de purge n'existait pour neria_waitlist —
 * une inscription jamais suivie d'un retour en stock restait indéfiniment
 * en base (notified_at IS NULL), grossissant la table sans limite et
 * faussant à terme getStats()/getTopProducts().
 *
 * Corrigé le 14/08/2026 (round 167) : nouvelle méthode purgeStaleEntries(),
 * appelée depuis runBackgroundJobs() avec un throttle interne de 24h.
 * N'affecte que les inscriptions non satisfaites (notified_at IS NULL) —
 * un historique de notification déjà envoyée est préservé.
 *
 * Test réel : crée une inscription très ancienne (registered_at il y a 2
 * ans) et une inscription récente pour le même client/produit fictif,
 * appelle purgeStaleEntries(30), vérifie que seule l'ancienne est
 * supprimée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $oldProduct = 999890;
    $recentProduct = 999891;

    $db->execute(
        "INSERT INTO {$prefix}neria_waitlist (id_customer, id_product, id_product_attribute, id_shop, registered_at, notified_at)
         VALUES ({$idCustomer}, {$oldProduct}, 0, {$idShop}, DATE_SUB(NOW(), INTERVAL 2 YEAR), NULL)"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_waitlist (id_customer, id_product, id_product_attribute, id_shop, registered_at, notified_at)
         VALUES ({$idCustomer}, {$recentProduct}, 0, {$idShop}, NOW(), NULL)"
    );

    try {
        $mgr = new WaitlistManager(neria_test_module());
        $mgr->purgeStaleEntries(30);

        $oldExists = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product = {$oldProduct}"
        );
        $recentExists = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product = {$recentProduct}"
        );

        neria_assert(
            $oldExists === 0,
            "purgeStaleEntries(30) n'a pas supprimé une inscription non satisfaite vieille de 2 ans — régression du bug corrigé le 14/08/2026 (round 167) : neria_waitlist grossirait de nouveau indéfiniment"
        );
        neria_assert(
            $recentExists === 1,
            "purgeStaleEntries(30) a supprimé à tort une inscription récente — la purge ne devrait affecter que les inscriptions plus anciennes que le seuil"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product IN ({$oldProduct}, {$recentProduct})");
    }

    return [
        'pass'    => true,
        'message' => "WaitlistManager::purgeStaleEntries() supprime bien les inscriptions non satisfaites au-delà du seuil, sans affecter les récentes — bug corrigé le 14/08/2026 (round 167)",
    ];
}
