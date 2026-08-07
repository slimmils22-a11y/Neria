<?php
/**
 * Régression : ClvManager::getCustomerClv() (via getEngagementRate()) et
 * getTopCustomers() doivent exclure les pré-chargements automatiques
 * d'Apple Mail Privacy Protection (is_mpp = 1) du taux d'engagement email
 * utilisé dans la formule CLV — même filtre que StatsManager/SegmentManager/
 * ChurnScoreManager/PropensityScoreManager/CustomerEmailHistoryManager.
 *
 * Bug réel corrigé le 07/08/2026 (round 82) : les deux requêtes comptaient
 * event_type='open' sans filtrer is_mpp=0. Un client Apple Mail qui n'ouvre
 * jamais réellement ses emails voyait son taux d'engagement gonflé à 100%,
 * ce qui appliquait à tort le multiplicateur "high" (x1.20) au lieu de
 * "low" (x0.85) dans le calcul du CLV 12 mois.
 *
 * Test comportemental réel : un client de test avec 1 commande valide et
 * 3 emails envoyés dont les 3 "ouvertures" sont des pré-chargements MPP
 * (is_mpp=1), aucune vraie ouverture. Avec le correctif, engagement_rate
 * doit être 0% (label "low", mult 0.85) et non 100% (label "high").
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ClvManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $token      = 'regtest86-' . uniqid();

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token = '" . pSQL($token) . "'");

    try {
        for ($i = 0; $i < 3; $i++) {
            $tok = $token . '-' . $i;
            $db->execute(
                "INSERT INTO {$prefix}neria_stat
                    (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
                 VALUES ({$idShop}, 'newsletter', 'fr', {$idCustomer}, '{$tok}', 'sent', 0, DATE_SUB(NOW(), INTERVAL 5 DAY))"
            );
            // Seule "ouverture" : pré-chargement Apple MPP, pas une vraie
            // ouverture — même token, is_mpp=1.
            $db->execute(
                "INSERT INTO {$prefix}neria_stat
                    (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
                 VALUES ({$idShop}, 'newsletter', 'fr', {$idCustomer}, '{$tok}', 'open', 1, DATE_SUB(NOW(), INTERVAL 5 DAY))"
            );
        }

        $mgr = new ClvManager(neria_test_module());
        $getEngagementRate = new ReflectionMethod(ClvManager::class, 'getEngagementRate');
        $getEngagementRate->setAccessible(true);
        $rate = (float) $getEngagementRate->invoke($mgr, $idCustomer);

        neria_assert(
            $rate === 0.0,
            "ClvManager::getEngagementRate() compte encore les ouvertures MPP (rate={$rate} au lieu de 0) — régression du bug corrigé le 07/08/2026 (round 82) : le CLV appliquerait de nouveau le multiplicateur d'engagement 'high' (x1.20) à un client qui n'ouvre jamais réellement ses emails"
        );

        return [
            'pass'    => true,
            'message' => "ClvManager::getEngagementRate() exclut bien les pré-chargements Apple MPP du taux d'engagement utilisé dans le calcul CLV",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token LIKE '" . pSQL($token) . "%'");
    }
}
