<?php
/**
 * Régression : ChurnScoreManager::recomputeAll() sortait via un early
 * return AVANT d'atteindre le bloc de purge (DELETE des lignes
 * neria_churn_score des clients qui ne sont plus dans le recalcul) dès que
 * $rowsPeriods était vide (aucune ligne sent/open dans les 90 derniers
 * jours pour cette boutique — boutique redevenue dormante, ou purge RGPD
 * massive de neria_stat).
 *
 * Bug réel corrigé le 15/08/2026 (round 176) : sur une boutique qui a eu
 * des clients à risque (score ≥70) puis redevient dormante (plus aucun
 * envoi/ouverture depuis 90 jours), les anciennes lignes neria_churn_score
 * n'étaient JAMAIS supprimées et continuaient d'apparaître indéfiniment
 * dans getHighRiskCustomers() — exactement le bug que le correctif du
 * round 166 (test_293) visait à régler, mais qui ne couvrait que le cas
 * "$rows vide APRÈS filtrage", pas "$rowsPeriods vide DÈS la requête SQL".
 *
 * Test comportemental réel : insère une ligne neria_churn_score à score
 * élevé pour une boutique fictive sans AUCUNE ligne neria_stat récente,
 * appelle recomputeAll(), vérifie que cette ligne obsolète est bien purgée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = 999996; // boutique fictive, isolée des vraies données

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_shop = {$idShop}");
    $db->execute("DELETE FROM {$prefix}neria_churn_score WHERE id_shop = {$idShop}");
    $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'NERIA_CHURN_LAST_RUN' AND id_shop = {$idShop}");

    try {
        // Ligne obsolète : un client à risque élevé calculé lors d'un
        // précédent recalcul, mais la boutique n'a plus AUCUNE ligne
        // neria_stat dans les 90 derniers jours (redevenue dormante).
        $db->execute(
            "INSERT INTO {$prefix}neria_churn_score
                (id_shop, id_customer, score, computed_at)
             VALUES ({$idShop}, 999995, 85, NOW())"
        );

        $mgr = new ChurnScoreManager(neria_test_module());
        $ref = new ReflectionProperty(ChurnScoreManager::class, 'idShop');
        $ref->setAccessible(true);
        $ref->setValue($mgr, $idShop);

        $mgr->recomputeAll();

        $remaining = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_churn_score WHERE id_shop = {$idShop} AND id_customer = 999995"
        );

        neria_assert(
            $remaining === 0,
            "ChurnScoreManager::recomputeAll() n'a pas purgé la ligne neria_churn_score obsolète d'une boutique dormante (aucun sent/open dans les 90 derniers jours) — régression du bug corrigé le 15/08/2026 (round 176) : un client à risque élevé resterait affiché indéfiniment dans getHighRiskCustomers() même après que la boutique soit redevenue dormante"
        );

        return [
            'pass'    => true,
            'message' => "ChurnScoreManager::recomputeAll() purge bien les lignes neria_churn_score obsolètes même quand rowsPeriods est vide dès la requête SQL — bug corrigé le 15/08/2026 (round 176)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_shop = {$idShop}");
        $db->execute("DELETE FROM {$prefix}neria_churn_score WHERE id_shop = {$idShop}");
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'NERIA_CHURN_LAST_RUN' AND id_shop = {$idShop}");
    }
}
