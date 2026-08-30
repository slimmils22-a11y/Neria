<?php
/**
 * Régression round 253 (31/08/2026) : `StatsManager::getKpiTrends()`
 * (fenêtre "current" = "7 derniers jours") excluait TOTALEMENT la journée
 * en cours de son total — la borne haute était `DATE(date_add) < today`
 * (stricte), pas `<= today`. Or `StatsManager::getKpis(7)` (widget jumeau
 * affiché sur le MÊME onglet BO stats.tpl, via $stats.kpis alimenté par
 * `kpis_7`) utilise `date_add >= dateFrom` SANS aucune borne haute, et
 * inclut donc bien les événements du jour même.
 *
 * Conséquence concrète : le marchand voyait deux totaux "7 derniers jours"
 * différents côte à côte dès qu'il y avait de l'activité le jour même — la
 * carte KPI 7 jours (kpis_7) montrait un total supérieur à celui utilisé
 * comme base "current" par le widget de tendance, faussant aussi le delta
 * % de progression semaine-sur-semaine affiché juste à côté.
 *
 * Corrigé le 31/08/2026 (round 253) : bornes inclusives des deux côtés
 * (>= ... <= ...) dans getKpiTrends(), cohérentes avec getKpis().
 *
 * Test comportemental réel : mesure le total 'sent' de la période
 * 'current' de getKpiTrends() ET le total_sent de getKpis(7) AVANT
 * d'insérer un vrai événement 'sent' daté d'AUJOURD'HUI (NOW()), puis
 * après — les deux doivent augmenter EXACTEMENT de 1, prouvant que les
 * deux widgets comptent désormais bien la journée en cours de façon
 * cohérente entre eux.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $mgr    = new StatsManager($module);

    $before = $mgr->getKpiTrends();
    $kpisBefore = $mgr->getKpis(7);
    $sentCurrentBefore = (int) ($before['sent']['current'] ?? 0);
    $sentKpisBefore    = (int) ($kpisBefore['total_sent'] ?? 0);

    $db->execute(
        "INSERT INTO {$prefix}neria_stat (id_shop, event_type, template, lang, date_add)
         VALUES (" . (int) Context::getContext()->shop->id . ", 'sent', 'regtest488', 'fr', NOW())"
    );
    $idStat = (int) $db->Insert_ID();

    try {
        $after = $mgr->getKpiTrends();
        $kpisAfter = $mgr->getKpis(7);
        $sentCurrentAfter = (int) ($after['sent']['current'] ?? 0);
        $sentKpisAfter    = (int) ($kpisAfter['total_sent'] ?? 0);

        neria_assert(
            $sentCurrentAfter === $sentCurrentBefore + 1,
            "StatsManager::getKpiTrends() n'inclut plus les événements d'AUJOURD'HUI dans le total 'current' — régression du bug corrigé le 31/08/2026 (round 253) : sent.current est passé de {$sentCurrentBefore} à {$sentCurrentAfter} après insertion d'un événement daté NOW(), au lieu de {$sentCurrentBefore} + 1"
        );
        neria_assert(
            $sentKpisAfter === $sentKpisBefore + 1,
            "jeu de test invalide : getKpis(7) ne compte pas l'événement fraîchement inséré — comportement de référence cassé"
        );
        neria_assert(
            ($sentCurrentAfter - $sentCurrentBefore) === ($sentKpisAfter - $sentKpisBefore),
            "getKpiTrends() et getKpis(7) ne comptent plus le même delta pour un événement daté d'aujourd'hui — les deux widgets 'derniers 7 jours' affichés sur le même onglet stats redeviendraient incohérents entre eux"
        );

        return [
            'pass'    => true,
            'message' => "StatsManager::getKpiTrends() inclut bien aujourd'hui dans sa fenêtre 'current', cohérent avec getKpis(7) affiché sur le même onglet (les deux totaux 'sent' ont augmenté de 1 après insertion d'un événement daté NOW()) — bug corrigé le 31/08/2026 (round 253)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_stat = {$idStat}");
    }
}
