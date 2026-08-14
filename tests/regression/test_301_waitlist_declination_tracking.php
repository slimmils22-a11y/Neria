<?php
/**
 * Régression : register()/unregister()/isRegistered()/notifyProduct() ne
 * portaient que sur id_product, jamais id_product_attribute — un client
 * inscrit en attendant qu'une déclinaison PRÉCISE (taille/couleur)
 * revienne en stock était notifié dès que N'IMPORTE QUELLE combinaison du
 * produit repassait au-dessus de 0, même si la déclinaison qu'il attendait
 * restait à 0.
 *
 * Corrigé le 14/08/2026 (round 167) : nouvelle colonne id_product_attribute
 * (0 = toute déclinaison confondue, comportement historique préservé),
 * register()/unregister()/isRegistered() acceptent un paramètre optionnel,
 * et notifyProductLocked() filtre chaque ligne à déclinaison précise sur
 * le stock RÉEL de cette combinaison avant de l'inclure dans le lot notifié.
 *
 * Test réel : inscrit un client fictif sur une déclinaison précise
 * (id_product_attribute=1, stock supposé à 0 pour ce produit fictif — le
 * SUM du produit lui-même sera nul aussi, donc notifyProduct() ne notifie
 * rien du tout ici — le vrai test porte sur register()/isRegistered()/
 * unregister() qui doivent bien distinguer 2 déclinaisons différentes du
 * même produit comme 2 inscriptions indépendantes, coexistant sans
 * s'écraser (contrainte UNIQUE étendue par upgrade-1.0.41.php).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idProduct  = 999889; // produit fictif dédié à ce test
    $idShop     = (int) Context::getContext()->shop->id;
    $mgr        = new WaitlistManager(neria_test_module());

    try {
        // Inscription sur 2 déclinaisons DIFFÉRENTES du même produit — ne
        // doit PAS s'écraser mutuellement (contrainte UNIQUE étendue).
        $ok1 = $mgr->register($idCustomer, $idProduct, $idShop, 1);
        $ok2 = $mgr->register($idCustomer, $idProduct, $idShop, 2);
        neria_assert($ok1 && $ok2, "register() a échoué pour au moins une des 2 déclinaisons — jeu de test invalide");

        $count = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_waitlist
             WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND notified_at IS NULL"
        );
        neria_assert(
            $count === 2,
            "Seules {$count} ligne(s) trouvée(s) au lieu de 2 pour 2 déclinaisons distinctes du même produit — régression du bug corrigé le 14/08/2026 (round 167) : la contrainte UNIQUE ne distinguerait de nouveau plus les déclinaisons"
        );

        neria_assert(
            $mgr->isRegistered($idCustomer, $idProduct, $idShop, 1) === true,
            "isRegistered() ne détecte plus l'inscription sur la déclinaison 1 — régression du bug corrigé le 14/08/2026 (round 167)"
        );
        neria_assert(
            $mgr->isRegistered($idCustomer, $idProduct, $idShop, 3) === false,
            "isRegistered() détecte à tort une inscription sur une déclinaison jamais demandée (3) — comportement incorrect"
        );

        // Désinscription d'UNE SEULE déclinaison ne doit pas affecter l'autre.
        $mgr->unregister($idCustomer, $idProduct, $idShop, 1);
        neria_assert(
            $mgr->isRegistered($idCustomer, $idProduct, $idShop, 1) === false,
            "unregister() n'a pas retiré l'inscription sur la déclinaison 1"
        );
        neria_assert(
            $mgr->isRegistered($idCustomer, $idProduct, $idShop, 2) === true,
            "unregister() sur la déclinaison 1 a par erreur affecté l'inscription sur la déclinaison 2 — régression du bug corrigé le 14/08/2026 (round 167) : les inscriptions par déclinaison ne seraient de nouveau plus indépendantes"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_waitlist WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}");
    }

    return [
        'pass'    => true,
        'message' => "WaitlistManager suit bien les inscriptions par déclinaison précise, indépendantes les unes des autres — bug corrigé le 14/08/2026 (round 167)",
    ];
}
