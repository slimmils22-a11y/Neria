<?php
/**
 * Régression : ChurnScoreManager::recomputeAll() et
 * PropensityScoreManager::scoreEngagement() doivent exclure les
 * pré-chargements automatiques d'Apple Mail Privacy Protection
 * (is_mpp = 1) de leurs comptages d'ouverture — même filtre que
 * SegmentManager/StatsManager/MonthlyReportManager.
 *
 * Bug réel corrigé le 06/08/2026 (round 80) : sans ce filtre, un client
 * dont le seul événement 'open' est un pré-chargement MPP (jamais une
 * vraie ouverture) était compté comme un client engagé — sous-estimant
 * son risque de désabonnement (ChurnScoreManager) et gonflant à tort son
 * score de propension à l'achat (PropensityScoreManager).
 *
 * Test comportemental réel : un client de test avec 1 email envoyé et 1
 * "ouverture" MPP (is_mpp=1) dans les 30 derniers jours, aucune vraie
 * ouverture. Avec le correctif, il doit être classé À RISQUE (score de
 * churn élevé) et son engagement ne doit PAS être gonflé par l'ouverture
 * MPP — sans le correctif, il apparaîtrait au contraire comme parfaitement
 * engagé (rate1=100%, last_open="maintenant").
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $token1     = 'regtest84-' . uniqid();
    $token2     = 'regtest84-' . uniqid();

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token IN ('" . pSQL($token1) . "', '" . pSQL($token2) . "')");
    $db->execute("DELETE FROM {$prefix}neria_churn_score WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
    $db->execute("DELETE FROM {$prefix}neria_propensity_score WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");

    try {
        // 1 email envoyé il y a 5 jours (période 1, 0-30j).
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
             VALUES ({$idShop}, 'newsletter', 'fr', {$idCustomer}, '{$token1}', 'sent', 0, DATE_SUB(NOW(), INTERVAL 5 DAY))"
        );
        // Sa seule "ouverture" : un pré-chargement Apple MPP, PAS une vraie
        // ouverture — même token, is_mpp=1.
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
             VALUES ({$idShop}, 'newsletter', 'fr', {$idCustomer}, '{$token1}', 'open', 1, DATE_SUB(NOW(), INTERVAL 5 DAY))"
        );
        // Historique en période 2 (31-60j) requis par recomputeAll() pour
        // exclure les clients "tout juste inscrits" (voir son commentaire
        // dédié) — sans ouverture, comme un client déjà en déclin.
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add)
             VALUES ({$idShop}, 'newsletter', 'fr', {$idCustomer}, '{$token2}', 'sent', 0, DATE_SUB(NOW(), INTERVAL 40 DAY))"
        );

        $churnMgr = new ChurnScoreManager(neria_test_module());
        $churnMgr->recomputeAll();
        $churnScore = $churnMgr->getCustomerScore($idCustomer);

        neria_assert($churnScore !== null, "getCustomerScore() n'a retourné aucun score — jeu de test invalide");
        neria_assert(
            (float) $churnScore['rate_p1'] === 0.0,
            "ChurnScoreManager compte encore l'ouverture MPP comme une vraie ouverture (rate_p1={$churnScore['rate_p1']} au lieu de 0) — régression du bug corrigé le 06/08/2026 (round 80)"
        );
        neria_assert(
            (int) $churnScore['score'] >= 70,
            "ChurnScoreManager sous-estime le risque de désabonnement d'un client dont la seule 'ouverture' est un pré-chargement MPP (score={$churnScore['score']}, attendu >= 70) — régression du bug corrigé le 06/08/2026 (round 80)"
        );

        $propensityMgr = new PropensityScoreManager(neria_test_module());
        $scoreEngagement = new ReflectionMethod(PropensityScoreManager::class, 'scoreEngagement');
        $scoreEngagement->setAccessible(true);
        $engagement = (float) $scoreEngagement->invoke($propensityMgr, $idCustomer);

        neria_assert(
            $engagement === 0.0,
            "PropensityScoreManager::scoreEngagement() compte encore l'ouverture MPP (engagement={$engagement} au lieu de 0) — régression du bug corrigé le 06/08/2026 (round 80) : une fausse alerte de fenêtre d'achat 'optimale' pourrait de nouveau se déclencher"
        );

        return [
            'pass'    => true,
            'message' => "ChurnScoreManager/PropensityScoreManager excluent bien les pré-chargements Apple MPP de leurs comptages d'ouverture",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token IN ('" . pSQL($token1) . "', '" . pSQL($token2) . "')");
        $db->execute("DELETE FROM {$prefix}neria_churn_score WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
        $db->execute("DELETE FROM {$prefix}neria_propensity_score WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
    }
}
