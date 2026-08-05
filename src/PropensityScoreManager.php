<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * PropensityScoreManager — Score de propension à l'achat (0–100)
 *
 * Formule transparente (4 facteurs) :
 *   Récence      (0–40) : décroît de 40 → 0 entre J+0 et J+90
 *   Fréquence    (0–25) : commandes/mois × 8, plafonné à 25
 *   Engagement   (0–25) : ouvertures (×1) + clics (×2) sur 30 j, plafonné à 25
 *   Saisonnalité (0–10) : % achats historiques dans le mois courant × 10
 *
 * Seuil d'alerte : 75 — "fenêtre d'achat optimale"
 *
 * @author Neria
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PropensityScoreManager
{
    const ALERT_THRESHOLD = 75;

    const W_RECENCY     = 40;
    const W_FREQUENCY   = 25;
    const W_ENGAGEMENT  = 25;
    const W_SEASONALITY = 10;

    // Récence : score plein jusqu'à J+7, nul à J+90
    const RECENCY_FULL_DAYS = 7;
    const RECENCY_ZERO_DAYS = 90;

    /** @var \Module */
    private $module;
    /** @var \Db */
    private $db;
    /** @var int */
    private $idShop;
    /** @var \WatchdogManager|null */
    private $watchdog = null;

    public function __construct(\Module $module)
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
    // CALCUL
    // ============================================================

    /**
     * Recalcule et persiste les scores de tous les clients actifs.
     * Appelé chaque nuit par le cron comportemental.
     */
    public function recalculateAll(): void
    {
        $customers = $this->db->executeS(
            'SELECT DISTINCT o.id_customer
             FROM `' . _DB_PREFIX_ . 'orders` o
             JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = o.id_customer
             WHERE o.id_shop = ' . $this->idShop . '
               AND o.valid = 1
               AND c.active = 1 AND c.deleted = 0'
        ) ?: [];

        $count = 0;
        foreach ($customers as $row) {
            try {
                $this->recalculateCustomer((int) $row['id_customer']);
                $count++;
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.propensity_error', [
                        'customer' => (int) $row['id_customer'],
                        'error'    => $e->getMessage(),
                    ]),
                    '', 'PropensityScore'
                );
            }
        }

        // Le cron a bien tourné même sans aucun client à commande valide
        // (boutique jeune ou faible volume) — tracé pour que
        // checkChurnPropensityFreshness() distingue "rien à recalculer pour
        // l'instant" d'un cron réellement en échec, plutôt que de se fier
        // uniquement à date_upd des lignes existantes, qui ne bouge jamais
        // dans ce cas (même correctif que ChurnScoreManager::recomputeAll()).
        \Configuration::updateValue('NERIA_PROPENSITY_LAST_RUN', date('Y-m-d H:i:s'), false, null, $this->idShop);

        // Purge les lignes des clients qui ne sont PLUS dans le recalcul de
        // ce run (plus aucune commande valide) — même correctif que
        // ChurnScoreManager::recomputeAll(). Sans ça, un client dont la
        // commande a été annulée/remboursée intégralement (sortie du
        // périmètre de la requête ci-dessus) gardait indéfiniment son
        // dernier score de propension calculé, continuant à apparaître dans
        // getAlertCustomers() avec une donnée totalement périmée.
        $keepIds = array_map(static fn (array $r): int => (int) $r['id_customer'], $customers);
        $purgeSql = 'DELETE FROM `' . _DB_PREFIX_ . 'neria_propensity_score` WHERE id_shop = ' . $this->idShop;
        if (!empty($keepIds)) {
            $purgeSql .= ' AND id_customer NOT IN (' . implode(',', $keepIds) . ')';
        }
        $this->db->execute($purgeSql);

        $alerts = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_propensity_score`
             WHERE score >= ' . self::ALERT_THRESHOLD . ' AND id_shop = ' . $this->idShop
        );

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.propensity_summary', [
                'n'         => $count,
                'alerts'    => $alerts,
                'threshold' => self::ALERT_THRESHOLD,
            ]),
            '', 'PropensityScore'
        );
    }

    public function recalculateCustomer(int $idCustomer): void
    {
        $scores = $this->computeScores($idCustomer);
        $total  = min(100, array_sum($scores));

        $this->db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'neria_propensity_score`
             (id_customer, id_shop, score, score_recency, score_frequency, score_engagement, score_seasonality, date_upd)
             VALUES (' . $idCustomer . ', ' . $this->idShop . ', ' . (int) $total . ',
             ' . (int) $scores['recency'] . ', ' . (int) $scores['frequency'] . ',
             ' . (int) $scores['engagement'] . ', ' . (int) $scores['seasonality'] . ', NOW())
             ON DUPLICATE KEY UPDATE
               score              = VALUES(score),
               score_recency      = VALUES(score_recency),
               score_frequency    = VALUES(score_frequency),
               score_engagement   = VALUES(score_engagement),
               score_seasonality  = VALUES(score_seasonality),
               date_upd           = NOW()'
        );
    }

    private function computeScores(int $idCustomer): array
    {
        return [
            'recency'     => $this->scoreRecency($idCustomer),
            'frequency'   => $this->scoreFrequency($idCustomer),
            'engagement'  => $this->scoreEngagement($idCustomer),
            'seasonality' => $this->scoreSeasonality($idCustomer),
        ];
    }

    // ── Récence ──────────────────────────────────────────────────

    private function scoreRecency(int $idCustomer): float
    {
        $lastOrder = $this->db->getValue(
            'SELECT MAX(date_add) FROM `' . _DB_PREFIX_ . 'orders`
             WHERE id_customer = ' . $idCustomer . ' AND id_shop = ' . $this->idShop . ' AND valid = 1'
        );
        if (!$lastOrder) {
            return 0.0;
        }

        $days = (int) (new \DateTime())->diff(new \DateTime($lastOrder))->days;

        if ($days <= self::RECENCY_FULL_DAYS) {
            return self::W_RECENCY;
        }
        if ($days >= self::RECENCY_ZERO_DAYS) {
            return 0.0;
        }

        $ratio = ($days - self::RECENCY_FULL_DAYS) / (self::RECENCY_ZERO_DAYS - self::RECENCY_FULL_DAYS);
        return round(self::W_RECENCY * (1 - $ratio), 1);
    }

    // ── Fréquence ─────────────────────────────────────────────────

    private function scoreFrequency(int $idCustomer): float
    {
        $row = $this->db->getRow(
            'SELECT COUNT(*) AS cnt, MIN(date_add) AS first_date
             FROM `' . _DB_PREFIX_ . 'orders`
             WHERE id_customer = ' . $idCustomer . ' AND id_shop = ' . $this->idShop . ' AND valid = 1'
        );
        if (!$row || (int) $row['cnt'] === 0) {
            return 0.0;
        }

        $months = max(1, (int) (new \DateTime($row['first_date']))->diff(new \DateTime())->days / 30.44);
        $perMonth = (int) $row['cnt'] / $months;

        return min(self::W_FREQUENCY, round($perMonth * 8, 1));
    }

    // ── Engagement email ──────────────────────────────────────────

    private function scoreEngagement(int $idCustomer): float
    {
        $opens = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
             WHERE id_customer = ' . $idCustomer . ' AND id_shop = ' . $this->idShop . '
               AND event_type = \'open\'
               AND date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $clicks = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
             WHERE id_customer = ' . $idCustomer . ' AND id_shop = ' . $this->idShop . '
               AND event_type = \'click\'
               AND date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );

        return min(self::W_ENGAGEMENT, (float) ($opens + $clicks * 2));
    }

    // ── Saisonnalité personnelle ──────────────────────────────────

    private function scoreSeasonality(int $idCustomer): float
    {
        $total = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
             WHERE id_customer = ' . $idCustomer . ' AND id_shop = ' . $this->idShop . ' AND valid = 1'
        );
        if ($total === 0) {
            return 0.0;
        }

        // Sur un très petit échantillon (1-2 commandes au total), le ratio
        // ne peut valoir que 0%, 50% ou 100% — une simple coïncidence de
        // calendrier suffit alors à déclencher le score plein, sans aucune
        // valeur prédictive réelle. On exige un minimum de commandes avant
        // d'accorder des points de saisonnalité.
        if ($total < 6) {
            return 0.0;
        }

        $currentMonth = (int) date('n');
        $inMonth = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
             WHERE id_customer = ' . $idCustomer . ' AND id_shop = ' . $this->idShop . '
               AND valid = 1 AND MONTH(date_add) = ' . $currentMonth
        );

        $ratio = $inMonth / $total;
        // Normaliser sur 12 mois : si répartition uniforme = 1/12 ≈ 8.3%
        // On considère un ratio > 25% = score plein
        $normalized = min(1.0, $ratio / 0.25);
        return round(self::W_SEASONALITY * $normalized, 1);
    }

    // ============================================================
    // LECTURE
    // ============================================================

    /**
     * Retourne les clients en fenêtre d'achat optimale (score ≥ seuil).
     */
    public function getAlertCustomers(int $limit = 20): array
    {
        return $this->db->executeS(
            'SELECT ps.id_customer, ps.score,
                    ps.score_recency, ps.score_frequency, ps.score_engagement, ps.score_seasonality,
                    ps.date_upd,
                    CONCAT(c.firstname, " ", c.lastname) AS customer_name,
                    c.email,
                    (SELECT MAX(o.date_add) FROM `' . _DB_PREFIX_ . 'orders` o
                     WHERE o.id_customer = ps.id_customer AND o.id_shop = ' . $this->idShop . ' AND o.valid = 1) AS last_order_date
             FROM `' . _DB_PREFIX_ . 'neria_propensity_score` ps
             JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = ps.id_customer
             WHERE ps.id_shop = ' . $this->idShop . '
               AND ps.score >= ' . self::ALERT_THRESHOLD . '
               AND c.active = 1 AND c.deleted = 0
             ORDER BY ps.score DESC
             LIMIT ' . (int) $limit
        ) ?: [];
    }

    /**
     * Retourne le score d'un client spécifique.
     */
    public function getCustomerScore(int $idCustomer): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'neria_propensity_score`
             WHERE id_customer = ' . $idCustomer . ' AND id_shop = ' . $this->idShop
        ) ?: null;
    }
}
