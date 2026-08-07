<?php
/**
 * Régression : ChurnScoreManager::recomputeAll() doit écrire
 * NERIA_CHURN_LAST_RUN même quand $rowsPeriods est vide (aucune ligne
 * sent/open dans la fenêtre de 90 jours pour cette boutique) — à l'image de
 * PropensityScoreManager::recalculateAll(), qui écrit son propre repère
 * inconditionnellement.
 *
 * Bug réel corrigé le 07/08/2026 (round 86) : l'early return
 * `if (!is_array($rowsPeriods) || empty($rowsPeriods)) { return 0; }`
 * s'exécutait AVANT l'écriture de NERIA_CHURN_LAST_RUN. Une boutique sans
 * aucune donnée neria_stat (installation récente) sortait donc sans jamais
 * écrire ce repère, laissant HealthCheckManager::checkChurnPropensityFreshness()
 * aveugle indéfiniment pour la partie churn (lastRun toujours null → aucune
 * alerte de staleness possible, même si le cron plantait réellement à une
 * étape ultérieure d'une exécution suivante).
 *
 * Test comportemental réel : boutique fictive (id_shop = 999997) garantie
 * sans aucune ligne neria_stat. Après appel de recomputeAll(), vérifie que
 * NERIA_CHURN_LAST_RUN est bien écrit pour cette boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';

    $db      = neria_test_db();
    $prefix  = neria_test_prefix();
    $idShop  = 999997; // boutique fictive, isolée des vraies données

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_shop = {$idShop}");
    $db->execute("DELETE FROM {$prefix}neria_churn_score WHERE id_shop = {$idShop}");
    // Suppression ciblée sur cette seule boutique fictive (pas
    // Configuration::deleteByName(), qui supprimerait la valeur pour TOUTES
    // les boutiques y compris la vraie boutique de dev).
    $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'NERIA_CHURN_LAST_RUN' AND id_shop = {$idShop}");

    try {
        $mgr = new ChurnScoreManager(neria_test_module());
        $ref = new ReflectionProperty(ChurnScoreManager::class, 'idShop');
        $ref->setAccessible(true);
        $ref->setValue($mgr, $idShop);

        $updated = $mgr->recomputeAll();
        neria_assert($updated === 0, "jeu de test invalide : la boutique fictive 999997 ne devrait avoir aucune donnée à recalculer");

        $lastRun = Configuration::get('NERIA_CHURN_LAST_RUN', null, null, $idShop);
        neria_assert(
            !empty($lastRun),
            "ChurnScoreManager::recomputeAll() n'écrit plus NERIA_CHURN_LAST_RUN quand aucune ligne sent/open n'existe — régression du bug corrigé le 07/08/2026 (round 86) : checkChurnPropensityFreshness() resterait aveugle pour cette boutique"
        );

        return [
            'pass'    => true,
            'message' => "ChurnScoreManager::recomputeAll() écrit bien NERIA_CHURN_LAST_RUN même sans aucune donnée à recalculer",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_shop = {$idShop}");
        $db->execute("DELETE FROM {$prefix}neria_churn_score WHERE id_shop = {$idShop}");
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'NERIA_CHURN_LAST_RUN' AND id_shop = {$idShop}");
    }
}
