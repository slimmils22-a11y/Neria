<?php
/**
 * Régression : PropensityScoreManager::recalculateCustomer() doit garantir
 * score === score_recency + score_frequency + score_engagement +
 * score_seasonality (aux limites de min(100, ...) près), pas une somme
 * tronquée séparément de 4 troncatures individuelles.
 *
 * Bug réel corrigé le 08/08/2026 (round 124) : $total était calculé comme
 * (int) array_sum($scores) — troncature de la SOMME des floats bruts —
 * alors que chaque score_* stocké était (int) $scores[...] — troncature de
 * CHAQUE sous-score INDIVIDUELLEMENT. (int) tronque vers zéro (pas
 * d'arrondi), donc la somme de 4 troncatures individuelles peut différer de
 * la troncature de leur somme : ex. 24.7+20.3+15.9+6.8=67.7 → total=67,
 * mais 24+20+15+6=65. Le BO affiche score ET sa ventilation par facteur
 * côte à côte (getCustomerScore()/getAlertCustomers()) — un marchand voyait
 * un écart visible et reproductible entre le score total affiché et la
 * somme du détail, minant la confiance dans "la formule transparente" que
 * ce détail est censé démontrer.
 *
 * Test fonctionnel réel : recalcule le score d'un vrai client et vérifie en
 * base que la ligne stockée respecte score = somme des 4 composantes.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $mgr = new PropensityScoreManager(neria_test_module());
    $mgr->recalculateCustomer($idCustomer);

    $row = $db->getRow(
        "SELECT score, score_recency, score_frequency, score_engagement, score_seasonality
         FROM {$prefix}neria_propensity_score
         WHERE id_customer = {$idCustomer}"
    );
    neria_assert($row !== false, "recalculateCustomer() n'a inséré aucune ligne pour ce client — jeu de test invalide");

    $sumParts = (int) $row['score_recency'] + (int) $row['score_frequency']
              + (int) $row['score_engagement'] + (int) $row['score_seasonality'];
    $expected = min(100, $sumParts);

    neria_assert(
        (int) $row['score'] === $expected,
        "score={$row['score']} mais la somme des 4 composantes stockées (recency={$row['score_recency']} + frequency={$row['score_frequency']} + engagement={$row['score_engagement']} + seasonality={$row['score_seasonality']} = {$sumParts}) donne {$expected} — régression du bug corrigé le 08/08/2026 (round 124) : score et son détail ne correspondraient de nouveau plus (troncatures incohérentes)"
    );

    return [
        'pass'    => true,
        'message' => "PropensityScoreManager::recalculateCustomer() garantit bien score = somme des 4 composantes affichées dans le détail",
    ];
}
