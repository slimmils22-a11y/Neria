<?php
/**
 * Régression : WaitlistManager::purgeStaleEntries() était la SEULE méthode
 * de la classe sans aucun moyen de scoper par boutique (register()/
 * isRegistered() reçoivent $idShop en paramètre obligatoire, getStats()/
 * getTopProducts() acceptent déjà un ?int $idShop = null optionnel) — un
 * appel scopé à une boutique aurait silencieusement purgé les inscriptions
 * en attente de TOUTES les boutiques de l'installation.
 *
 * Corrigé le 16/08/2026 (round 179, audit transversal de fin de série) :
 * $idShop optionnel ajouté (défaut null = toutes boutiques, comportement
 * historique inchangé pour l'appel cron existant dans neria.php), cohérent
 * avec le pattern déjà établi par getStats()/getTopProducts().
 *
 * Test comportemental réel : deux inscriptions expirées fictives sur deux
 * "boutiques" (id_shop) distinctes — purgeStaleEntries(idShop=A) ne doit
 * retirer QUE celle de A, laissant celle de B intacte.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $idShopA = 999992;
    $idShopB = 999991;
    $idProduct = 999990;

    $db->execute("DELETE FROM {$prefix}neria_waitlist WHERE id_product = {$idProduct}");

    try {
        $oldDate = date('Y-m-d H:i:s', strtotime('-400 days'));
        $db->execute(
            "INSERT INTO {$prefix}neria_waitlist
                (id_customer, id_product, id_product_attribute, id_shop, notified_at, registered_at)
             VALUES
                (999989, {$idProduct}, 0, {$idShopA}, NULL, '{$oldDate}'),
                (999988, {$idProduct}, 0, {$idShopB}, NULL, '{$oldDate}')"
        );

        $mgr = new WaitlistManager($module);
        $deleted = $mgr->purgeStaleEntries(365, $idShopA);

        neria_assert($deleted === 1, "purgeStaleEntries(365, \$idShopA) a supprimé {$deleted} ligne(s) au lieu de 1 — jeu de test invalide ou régression");

        $remainingA = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_waitlist WHERE id_product = {$idProduct} AND id_shop = {$idShopA}"
        );
        $remainingB = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_waitlist WHERE id_product = {$idProduct} AND id_shop = {$idShopB}"
        );

        neria_assert($remainingA === 0, "purgeStaleEntries(365, \$idShopA) n'a pas purgé l'inscription de la boutique A");
        neria_assert(
            $remainingB === 1,
            "purgeStaleEntries(365, \$idShopA) a aussi supprimé l'inscription de la boutique B — régression du bug corrigé le 16/08/2026 (round 179) : la purge scopée par boutique redeviendrait globale, purgeant à tort les inscriptions d'une autre boutique"
        );

        return [
            'pass'    => true,
            'message' => "WaitlistManager::purgeStaleEntries() scope bien sa purge par boutique quand \$idShop est fourni — bug corrigé le 16/08/2026 (round 179)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_waitlist WHERE id_product = {$idProduct}");
    }
}
