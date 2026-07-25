<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — ChurnScoreManager
 *
 * Calcule un score de risque de désabonnement (0-100) pour chaque client,
 * basé sur l'évolution du taux d'ouverture sur 3 périodes de 30 jours.
 *
 * Algorithme (3 composantes) :
 *   Récence       (0-40 pts) — nombre de jours depuis la dernière ouverture
 *   Taux récent   (0-30 pts) — taux d'ouverture des 30 derniers jours inversé
 *   Tendance      (0-30 pts) — déclin relatif du taux P3→P1 (ancien→récent)
 *
 * Score > 70 = risque élevé → alerte visible sur la fiche client.
 * Recalcul quotidien via BehavioralCronManager::run().
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ChurnScoreManager
{
    const TABLE              = 'neria_churn_score';
    const HIGH_RISK_THRESHOLD = 70;

    private \Neria $module;
    private \Db    $db;
    private int    $idShop;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(\Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // RECALCUL
    // ============================================================

    /**
     * Recalcule les scores de tous les clients actifs ayant reçu au moins
     * un email. Traitement en PHP pour lisibilité de l'algorithme.
     *
     * @return int Nombre de clients mis à jour
     */
    public function recomputeAll(): int
    {
        $stat  = _DB_PREFIX_ . 'neria_stat';
        $shop  = $this->idShop;

        // Récupère les métriques par période + tranches horaires pour tous les clients
        $rows = $this->db->executeS("
            SELECT
                id_customer,
                -- Période 1 : 0-30 j (la plus récente)
                SUM(CASE WHEN date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND event_type = 'sent' THEN 1 ELSE 0 END) AS sent_p1,
                SUM(CASE WHEN date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND event_type = 'open' THEN 1 ELSE 0 END) AS open_p1,
                -- Période 2 : 31-60 j
                SUM(CASE WHEN date_add < DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND date_add >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                          AND event_type = 'sent' THEN 1 ELSE 0 END) AS sent_p2,
                SUM(CASE WHEN date_add < DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND date_add >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                          AND event_type = 'open' THEN 1 ELSE 0 END) AS open_p2,
                -- Période 3 : 61-90 j (la plus ancienne)
                SUM(CASE WHEN date_add < DATE_SUB(NOW(), INTERVAL 60 DAY)
                          AND date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                          AND event_type = 'sent' THEN 1 ELSE 0 END) AS sent_p3,
                SUM(CASE WHEN date_add < DATE_SUB(NOW(), INTERVAL 60 DAY)
                          AND date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                          AND event_type = 'open' THEN 1 ELSE 0 END) AS open_p3,
                MAX(CASE WHEN event_type = 'open' THEN date_add END)  AS last_open,
                -- Tranches horaires d'ouverture (tous temps)
                SUM(CASE WHEN event_type = 'open' AND HOUR(date_add) >= 6
                          AND HOUR(date_add) < 12 THEN 1 ELSE 0 END) AS open_morning,
                SUM(CASE WHEN event_type = 'open' AND HOUR(date_add) >= 12
                          AND HOUR(date_add) < 18 THEN 1 ELSE 0 END) AS open_afternoon,
                SUM(CASE WHEN event_type = 'open' AND HOUR(date_add) >= 18
                          AND HOUR(date_add) < 23 THEN 1 ELSE 0 END) AS open_evening,
                SUM(CASE WHEN event_type = 'open'
                          AND (HOUR(date_add) >= 23 OR HOUR(date_add) < 6)
                          THEN 1 ELSE 0 END) AS open_night
            FROM `{$stat}`
            WHERE id_shop = {$shop} AND id_customer > 0
            GROUP BY id_customer
        ");

        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        // Bug du 2026-07-21 : un client tout juste inscrit (aucun envoi
        // au-delà des 30 derniers jours, donc sent_p2 = sent_p3 = 0) n'a
        // logiquement pas encore ouvert grand-chose — mais computeScore()
        // traitait ce manque de données comme un signal de risque MAXIMAL
        // (récence=40 car jamais ouvert, taux récent=30 car 0 ouverture,
        // tendance=10 par défaut) : score ≈ 80, "risque élevé" immédiat.
        // Un score de CHURN suppose un déclin depuis un engagement passé —
        // sans au moins 30 jours d'historique antérieur, il n'y a rien à
        // comparer. On exclut donc ces clients du recalcul (aucune ligne
        // insérée = getCustomerScore()/getHighRiskCustomers() les ignorent
        // naturellement), plutôt que de leur assigner un faux risque élevé
        // qui pollue la liste des clients réellement à relancer.
        $rows = array_values(array_filter($rows, function (array $r): bool {
            return (int) $r['sent_p2'] > 0 || (int) $r['sent_p3'] > 0;
        }));

        // Le cron a bien tourné même si aucun client n'a encore 30 jours
        // d'historique passé à comparer (boutique jeune ou faible volume) —
        // on le trace pour que checkChurnPropensityFreshness() distingue
        // "rien à recalculer pour l'instant" d'un cron réellement en échec,
        // plutôt que de se fier uniquement à computed_at des lignes
        // existantes, qui ne bouge jamais dans ce cas.
        \Configuration::updateValue('NERIA_CHURN_LAST_RUN', date('Y-m-d H:i:s'), false, null, $this->idShop);

        if (empty($rows)) {
            return 0;
        }

        $table    = _DB_PREFIX_ . self::TABLE;
        $now      = date('Y-m-d H:i:s');
        $inserted = 0;

        // Batch INSERT de 50 en 50 pour éviter les requêtes trop lourdes
        $chunks = array_chunk($rows, 50);

        foreach ($chunks as $chunk) {
            $values = [];
            foreach ($chunk as $r) {
                [$score, $rate1, $rate2, $rate3] = $this->computeScore($r);
                $slot     = $this->computePreferredSlot($r);
                $lastOpen = $r['last_open'] ? "'" . pSQL($r['last_open']) . "'" : 'NULL';
                $slotSql  = $slot ? "'" . pSQL($slot) . "'" : 'NULL';
                $values[] = sprintf(
                    '(%d, %d, %d, %.4f, %.4f, %.4f, %s, %s, \'%s\')',
                    $shop,
                    (int) $r['id_customer'],
                    $score,
                    $rate1,
                    $rate2,
                    $rate3,
                    $lastOpen,
                    $slotSql,
                    pSQL($now)
                );
            }

            $this->db->execute(
                "INSERT INTO `{$table}`
                    (`id_shop`, `id_customer`, `score`, `rate_p1`, `rate_p2`, `rate_p3`,
                     `last_open`, `preferred_slot`, `computed_at`)
                 VALUES " . implode(',', $values) . "
                 ON DUPLICATE KEY UPDATE
                    `score`          = VALUES(`score`),
                    `rate_p1`        = VALUES(`rate_p1`),
                    `rate_p2`        = VALUES(`rate_p2`),
                    `rate_p3`        = VALUES(`rate_p3`),
                    `last_open`      = VALUES(`last_open`),
                    `preferred_slot` = VALUES(`preferred_slot`),
                    `computed_at`    = VALUES(`computed_at`)"
            );

            $inserted += count($chunk);
        }

        $atRisk   = $this->countHighRisk();
        $withSlot = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . self::TABLE . "`
             WHERE id_shop = {$this->idShop} AND preferred_slot IS NOT NULL"
        );
        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.churn_score_summary', [
                'n'        => $inserted,
                'atRisk'   => $atRisk,
                'threshold' => self::HIGH_RISK_THRESHOLD,
                'withSlot' => $withSlot,
            ]),
            '', 'ChurnScore'
        );

        return $inserted;
    }

    /**
     * Calcule le score et les taux pour une ligne de métriques.
     *
     * @param array $r Ligne avec sent_p1, open_p1, sent_p2, open_p2, sent_p3, open_p3, last_open
     * @return array [score(int), rate1(float), rate2(float), rate3(float)]
     */
    /**
     * Détermine la tranche horaire préférée d'un client selon ses ouvertures historiques.
     * Nécessite au moins 2 ouvertures pour être significatif.
     *
     * @return string|null 'morning'|'afternoon'|'evening'|'night' ou null si insuffisant
     */
    private function computePreferredSlot(array $r): ?string
    {
        $counts = [
            'morning'   => (int) ($r['open_morning']   ?? 0),
            'afternoon' => (int) ($r['open_afternoon'] ?? 0),
            'evening'   => (int) ($r['open_evening']   ?? 0),
            'night'     => (int) ($r['open_night']     ?? 0),
        ];
        if (array_sum($counts) < 2) {
            return null;
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }

    private function computeScore(array $r): array
    {
        $rate1 = (int) $r['sent_p1'] > 0 ? (int) $r['open_p1'] / (int) $r['sent_p1'] : 0.0;
        $rate2 = (int) $r['sent_p2'] > 0 ? (int) $r['open_p2'] / (int) $r['sent_p2'] : 0.0;
        $rate3 = (int) $r['sent_p3'] > 0 ? (int) $r['open_p3'] / (int) $r['sent_p3'] : 0.0;

        // Composante 1 — Récence (0-40 pts)
        if ($r['last_open']) {
            $days    = (time() - strtotime($r['last_open'])) / 86400;
            $recency = min(1.0, max(0.0, $days / 90)) * 40;
        } else {
            $recency = 40; // jamais ouvert
        }

        // Composante 2 — Taux récent inversé (0-30 pts)
        $recentRisk = (1.0 - $rate1) * 30;

        // Composante 3 — Tendance de déclin P3 → P1 (0-30 pts)
        if ((int) $r['sent_p3'] === 0) {
            // Pas d'historique 61-90j : client récent, risque modéré par défaut
            $trend = 10.0;
        } elseif ($rate3 > 0.0) {
            $decline = max(0.0, $rate3 - $rate1) / $rate3;
            $trend   = $decline * 30;
        } else {
            // Taux ancien = 0 (n'ouvrait déjà pas) → déclin neutre
            $trend = 15.0;
        }

        $score = (int) round($recency + $recentRisk + $trend);
        $score = max(0, min(100, $score));

        return [$score, round($rate1, 4), round($rate2, 4), round($rate3, 4)];
    }

    // ============================================================
    // LECTURE
    // ============================================================

    /**
     * Score d'un client donné (pour la fiche client).
     */
    public function getCustomerScore(int $idCustomer): ?array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $row   = $this->db->getRow(sprintf(
            "SELECT * FROM `%s` WHERE `id_shop` = %d AND `id_customer` = %d",
            $table, $this->idShop, $idCustomer
        ));
        return $row ?: null;
    }

    /**
     * Clients dont le score dépasse le seuil de risque élevé.
     */
    public function getHighRiskCustomers(int $limit = 50): array
    {
        $table  = _DB_PREFIX_ . self::TABLE;
        $cTable = _DB_PREFIX_ . 'customer';

        $rows = $this->db->executeS(sprintf(
            "SELECT s.id_customer, s.score, s.rate_p1, s.rate_p2, s.rate_p3, s.last_open,
                    c.firstname, c.lastname, c.email
             FROM `%s` s
             INNER JOIN `%s` c ON c.id_customer = s.id_customer
             WHERE s.id_shop = %d AND s.score >= %d
               AND c.active = 1 AND c.deleted = 0
             ORDER BY s.score DESC
             LIMIT %d",
            $table, $cTable,
            $this->idShop,
            self::HIGH_RISK_THRESHOLD,
            $limit
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Nombre de clients à risque élevé.
     */
    public function countHighRisk(): int
    {
        $table = _DB_PREFIX_ . self::TABLE;
        return (int) $this->db->getValue(sprintf(
            "SELECT COUNT(*) FROM `%s`
             WHERE `id_shop` = %d AND `score` >= %d",
            $table, $this->idShop, self::HIGH_RISK_THRESHOLD
        ));
    }

    // ============================================================
    // HELPERS STATIQUES
    // ============================================================

    /**
     * Label couleur selon le score.
     */
    public static function getRiskLevel(int $score): string
    {
        if ($score >= 85) {
            return 'critical';
        }
        if ($score >= 70) {
            return 'high';
        }
        if ($score >= 50) {
            return 'medium';
        }
        return 'low';
    }
}
