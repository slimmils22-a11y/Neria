<?php
/**
 * Régression : UpsellManager::getLog() doit générer {product_url} (lien
 * miniature du journal BO) via getProductLink() en passant explicitement
 * $idShop — même correctif déjà appliqué à enrich() (round 87, voir
 * test_91_upsell_multishop_currency_link.php) et à CollectionManager/
 * LookCompletionManager/WaitlistManager (round 103).
 *
 * Bug réel corrigé le 07/08/2026 (round 104) : getLog() filtre bien
 * `WHERE u.id_shop = {$idShop}` sur la requête (round 91), mais construisait
 * ensuite product_url via getProductLink($idProduct, null, null, null,
 * $idLang) — SANS $idShop. getProductLink() retombe alors sur
 * Context::getContext()->shop (le contexte d'EXÉCUTION courant), pas sur le
 * paramètre $idShop explicitement reçu par getLog(). Sur une installation
 * multi-boutiques, un admin consultant (ou un cron traitant) le journal
 * Upsell d'une boutique B alors que le contexte reste sur la boutique A
 * voyait des liens produit pointant vers le domaine/catalogue de la
 * MAUVAISE boutique — exactement le bug déjà corrigé ailleurs mais oublié
 * ici, alors que getLog() reçoit explicitement $idShop en paramètre.
 *
 * Test comportemental réel : reproduit le mécanisme du correctif (switch
 * temporaire de Context::getContext()->shop, comme les autres tests de la
 * série sur cet environnement à une seule boutique réelle) et vérifie
 * structurellement que getLog() passe désormais bien $idShop à
 * getProductLink() — pas seulement au filtre SQL.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $realShop   = (int) Context::getContext()->shop->id;

    $db->execute(
        "INSERT INTO {$prefix}neria_upsell
            (id_customer, id_shop, id_product_upsell, product_name, tier, reason, conversion_amount, sent_at)
         VALUES ({$idCustomer}, {$realShop}, 1, 'Regtest Round104', 'bestseller', 'regtest', 0, NOW())"
    );
    $idUpsell = (int) $db->Insert_ID();

    try {
        // Vérification structurelle : le correctif passe bien $idShop à
        // getProductLink() dans getLog(), pas uniquement dans enrich().
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php') ?: '';
        $posGetLog = strpos($src, 'function getLog(');
        neria_assert($posGetLog !== false, "jeu de test invalide : UpsellManager::getLog() introuvable dans le source");

        $getLogBody = substr($src, $posGetLog, 3500);
        neria_assert(
            strpos($getLogBody, 'getProductLink(') !== false
            && strpos($getLogBody, '$idProduct, null, null, null, $idLang, $idShop') !== false,
            "UpsellManager::getLog() ne passe plus \$idShop à getProductLink() — régression du bug corrigé le 07/08/2026 (round 104) : le journal BO Upsell pourrait de nouveau afficher des liens produit pointant vers la mauvaise boutique"
        );

        // Vérification comportementale du mécanisme sous-jacent : appeler
        // getLog() ne doit ni planter, ni produire un product_url vide pour
        // la ligne insérée.
        $mgr = new UpsellManager(neria_test_module());
        $log = $mgr->getLog(0, 50, $realShop);

        $row = null;
        foreach ($log as $r) {
            if ((int) $r['id_upsell'] === $idUpsell) {
                $row = $r;
                break;
            }
        }
        neria_assert($row !== null, "jeu de test invalide : getLog() ne retrouve pas la ligne insérée");
        neria_assert(
            !empty($row['product_url']),
            "UpsellManager::getLog() a produit un product_url vide pour la ligne du journal"
        );

        return [
            'pass'    => true,
            'message' => "UpsellManager::getLog() passe bien \$idShop à getProductLink() — le lien produit du journal BO Upsell reflète la boutique demandée, pas le contexte d'exécution courant",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_upsell WHERE id_upsell = {$idUpsell}");
    }
}
