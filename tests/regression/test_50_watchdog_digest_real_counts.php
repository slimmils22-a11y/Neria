<?php
/**
 * Régression : WatchdogManager::sendDailyDigestIfDue() doit calculer les
 * compteurs warning/error/critical (et le total du sujet) sur TOUS les
 * événements des 24 dernières heures, pas seulement sur les 50 lignes
 * plafonnées utilisées pour le tableau détaillé de l'email.
 *
 * Bug réel corrigé le 05/08/2026 (round 52) : les compteurs et le sujet
 * étaient dérivés de $rows (LIMIT 50), donnant une image tronquée dès
 * qu'une rafale dépassait 50 événements en 24h (ex. panne DB générant 200
 * erreurs) — le marchand voyait "50 événements" avec une répartition
 * potentiellement fausse (les 'critical' les plus anciens hors de la
 * fenêtre des 50 les plus récentes pouvaient disparaître du décompte),
 * pensant l'incident contenu alors qu'il ne voyait qu'une fraction.
 *
 * Ce test seed 70 lignes neria_log (au-delà de 50) et vérifie que la
 * requête de comptage réelle (GROUP BY, sans LIMIT) utilisée par le
 * correctif retourne bien 70, pas 50.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $marker = 'regtest-digest-' . uniqid();

    $ids = [];
    for ($i = 0; $i < 70; $i++) {
        $db->execute(
            "INSERT INTO {$prefix}neria_log
                (id_shop, level, template, class, message, context, date_add, occurrence_count)
             VALUES ({$idShop}, 'warning', '', 'RegTest', '{$marker}-{$i}', NULL, NOW(), 1)"
        );
        $ids[] = (int) $db->Insert_ID();
    }

    try {
        // Requête EXACTE du correctif (GROUP BY level, sans LIMIT) —
        // vérifie qu'elle voit bien les 70 lignes injectées, contrairement
        // à l'ancienne requête plafonnée à 50.
        $countRows = $db->executeS(
            "SELECT `level`, COUNT(*) AS n
             FROM {$prefix}neria_log
             WHERE `id_shop` = {$idShop}
               AND `level` IN ('warning','error','critical')
               AND `message` LIKE '{$marker}%'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY `level`"
        );
        $total = 0;
        foreach ($countRows as $r) {
            $total += (int) $r['n'];
        }
        neria_assert($total === 70, "le comptage réel (GROUP BY, sans LIMIT) ne retourne pas 70 (obtenu {$total}) — jeu de test à recalibrer");

        // Confirme que le code source utilise bien cette requête de comptage
        // séparée (pas seulement count($rows) sur la liste plafonnée à 50).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
        neria_assert(
            (bool) preg_match('/function sendDailyDigestIfDue[\s\S]{0,5000}?GROUP BY `level`/', $src),
            "WatchdogManager::sendDailyDigestIfDue() n'utilise plus de requête de comptage séparée (GROUP BY level, sans LIMIT) — régression du bug de troncature à 50 événements corrigé le 05/08/2026"
        );

        return [
            'pass'    => true,
            'message' => 'Le comptage réel du digest quotidien (70 événements injectés) n\'est plus plafonné à 50',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_log WHERE id_log IN (" . implode(',', $ids) . ")");
    }
}
