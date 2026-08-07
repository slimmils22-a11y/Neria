<?php
/**
 * Régression : UpsellManager::getStats() et ::getLog() doivent filtrer
 * id_shop — comme recordSuggestion()/checkConversions()/findUpsellForCustomer()
 * dans le même fichier, et comme WaitlistManager::getStats()/CertificateManager::
 * getStats() partout ailleurs dans le module.
 *
 * Bug réel corrigé le 07/08/2026 (round 91) : les deux méthodes qui
 * alimentent l'onglet BO Upsell (KPIs + journal) interrogeaient
 * ps_neria_upsell sans aucun filtre id_shop, alors que la table a bien une
 * colonne id_shop correctement utilisée partout ailleurs dans ce fichier.
 * Sur une installation multi-boutiques, les KPIs et le journal d'une
 * boutique mélangeaient silencieusement les suggestions/clics/conversions
 * de TOUTES les boutiques de l'installation.
 *
 * Test comportemental réel : une ligne neria_upsell pour une boutique
 * fictive (id_shop=999997) ne doit apparaître ni dans getStats() ni dans
 * getLog() de la vraie boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $realShop   = (int) Context::getContext()->shop->id;
    $otherShop  = 999997; // boutique fictive, isolée des vraies données

    $db->execute(
        "INSERT INTO {$prefix}neria_upsell
            (id_customer, id_shop, id_product_upsell, product_name, tier, reason, conversion_amount, sent_at)
         VALUES ({$idCustomer}, {$otherShop}, 1, 'Regtest Round91', 'bestseller', 'regtest', 999.99, NOW())"
    );
    $idUpsell = (int) $db->Insert_ID();

    try {
        $mgr = new UpsellManager(neria_test_module());

        $statsReal  = $mgr->getStats(90, $realShop);
        $statsOther = $mgr->getStats(90, $otherShop);

        neria_assert(
            $statsOther['total_sent'] >= 1 && (float) $statsOther['total_revenue'] >= 999.99,
            "jeu de test invalide : getStats() de la boutique fictive ne voit pas la ligne insérée"
        );

        $revenueLeakedIntoReal = (float) $statsReal['total_revenue'] >= 999.99;
        neria_assert(
            !$revenueLeakedIntoReal,
            "UpsellManager::getStats() de la vraie boutique compte encore le revenu (999.99) de la boutique fictive — régression du bug corrigé le 07/08/2026 (round 91) : les KPIs BO mélangeraient de nouveau toutes les boutiques"
        );

        $logOther = $mgr->getLog(0, 50, $otherShop);
        $foundInOther = false;
        foreach ($logOther as $row) {
            if ((int) $row['id_upsell'] === $idUpsell) {
                $foundInOther = true;
                break;
            }
        }
        neria_assert($foundInOther, "jeu de test invalide : getLog() de la boutique fictive ne voit pas la ligne insérée");

        $logReal = $mgr->getLog(0, 50, $realShop);
        $foundInReal = false;
        foreach ($logReal as $row) {
            if ((int) $row['id_upsell'] === $idUpsell) {
                $foundInReal = true;
                break;
            }
        }
        neria_assert(
            !$foundInReal,
            "UpsellManager::getLog() de la vraie boutique voit encore une ligne appartenant à la boutique fictive — régression du bug corrigé le 07/08/2026 (round 91)"
        );

        return [
            'pass'    => true,
            'message' => "UpsellManager::getStats()/getLog() filtrent bien id_shop — les données d'une autre boutique ne fuitent plus dans les KPIs/journal BO",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_upsell WHERE id_upsell = {$idUpsell}");
    }
}
