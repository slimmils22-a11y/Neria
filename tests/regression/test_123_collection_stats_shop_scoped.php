<?php
/**
 * Régression : CollectionManager::getStats() doit filtrer sent/sentLast30
 * par id_shop — comme hasSent() dans le même fichier, et comme
 * UpsellManager::getStats()/WaitlistManager::getStats()/
 * QueueManager::getStats() ailleurs dans le module.
 *
 * Bug réel corrigé le 08/08/2026 (round 119) : neria_collection_sent EST
 * scopée par boutique (id_shop fait partie de la clé d'unicité, utilisée
 * par hasSent() dans ce même fichier), mais getStats() comptait sent/
 * sentLast30 sans filtre id_shop — sur une installation multi-boutiques, le
 * BO d'une boutique affichait dans son KPI « Complétion de collection » les
 * envois de TOUTES les boutiques de l'installation, pas seulement les
 * siens. neria_collection (les définitions de collection elles-mêmes) n'a
 * en revanche pas de colonne id_shop — total/active restent donc
 * légitimement globaux, non concernés par ce correctif.
 *
 * Test comportemental réel : une ligne neria_collection_sent pour une
 * boutique fictive ne doit pas être comptée dans getStats() de la vraie
 * boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CollectionManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $realShop   = (int) Context::getContext()->shop->id;
    $otherShop  = 999998; // boutique fictive, isolée des vraies données

    $mgr = new CollectionManager(neria_test_module());

    $statsBefore = $mgr->getStats();

    $db->execute(
        "INSERT INTO {$prefix}neria_collection_sent
            (id_neria_collection, id_customer, id_shop, sent_at)
         VALUES (999999, {$idCustomer}, {$otherShop}, NOW())"
    );

    try {
        $statsAfter = $mgr->getStats();

        neria_assert(
            $statsAfter['sent'] === $statsBefore['sent'],
            "getStats() (boutique réelle {$realShop}) compte {$statsAfter['sent']} envois contre {$statsBefore['sent']} avant l'insertion d'une ligne pour la boutique fictive {$otherShop} — régression du bug corrigé le 08/08/2026 (round 119) : sent/sentLast30 ne sont plus filtrés par id_shop"
        );
        neria_assert(
            $statsAfter['sentLast30'] === $statsBefore['sentLast30'],
            "getStats() (boutique réelle {$realShop}) compte {$statsAfter['sentLast30']} envois (30j) contre {$statsBefore['sentLast30']} avant l'insertion d'une ligne pour la boutique fictive {$otherShop} — régression du bug corrigé le 08/08/2026 (round 119)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_collection_sent WHERE id_shop = {$otherShop}");
    }

    return [
        'pass'    => true,
        'message' => "CollectionManager::getStats() filtre bien sent/sentLast30 par id_shop, isolant correctement les KPIs entre boutiques",
    ];
}
