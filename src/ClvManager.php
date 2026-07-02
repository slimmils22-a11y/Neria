<?php
/**
 * ClvManager — Valeur client estimée sur 12 mois
 *
 * Formule transparente :
 *   Base      = panier moyen × fréquence mensuelle × 12
 *   × Engagement email  (multiplicateur selon taux d'ouverture)
 *   × Segment           (bonus/malus selon profil comportemental)
 *   × Churn risk        (pénalité si risque élevé)
 *
 * @author Neria
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ClvManager
{
    // Seuils engagement email
    const ENGAGEMENT_HIGH   = 0.40; // > 40 % opens → +20 %
    const ENGAGEMENT_MEDIUM = 0.20; // > 20 % opens → neutre
    // < 20 % → -15 %

    // Seuils churn (ChurnScoreManager, 0–100)
    const CHURN_HIGH   = 70; // → -30 %
    const CHURN_MEDIUM = 40; // → -15 %

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
    // CALCUL CLV
    // ============================================================

    /**
     * Retourne toutes les données CLV pour un client.
     *
     * @return array {
     *   clv_12m          : float  valeur estimée 12 mois (devise boutique)
     *   clv_label        : string 'high'|'medium'|'low'
     *   avg_order        : float  panier moyen
     *   order_count      : int    nombre de commandes
     *   frequency_monthly: float  commandes/mois
     *   engagement_rate  : float  taux d'ouverture (0–1)
     *   engagement_mult  : float  multiplicateur engagement
     *   segment_mult     : float  multiplicateur segment
     *   churn_score      : int    score churn (0–100)
     *   churn_mult       : float  multiplicateur churn
     *   months_active    : int    ancienneté client en mois
     *   currency_symbol  : string
     *   details          : array  détail des facteurs pour affichage
     * }
     */
    public function getCustomerClv(int $idCustomer): array
    {
        try {
            return $this->computeClv($idCustomer);
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                'CLV client #' . $idCustomer . ' : ' . $e->getMessage(),
                '', 'ClvManager'
            );
            return $this->emptyClv();
        }
    }

    private function emptyClv(): array
    {
        $symbol = \Context::getContext()->currency->sign ?? '€';
        return ['clv_12m' => 0.0, 'clv_label' => 'low', 'avg_order' => 0.0, 'order_count' => 0,
                'frequency_monthly' => 0.0, 'engagement_rate' => 0.0, 'engagement_mult' => 1.0,
                'segment_mult' => 1.0, 'churn_score' => 50, 'churn_mult' => 1.0,
                'months_active' => 0, 'currency_symbol' => $symbol, 'details' => []];
    }

    private function computeClv(int $idCustomer): array
    {
        $currency = \Context::getContext()->currency;
        $symbol   = $currency->sign ?? '€';

        // ── Historique d'achats ───────────────────────────────────
        $orders = $this->db->executeS(
            'SELECT o.`id_order`, o.`total_paid_tax_incl`, o.`date_add`
             FROM `' . _DB_PREFIX_ . 'orders` o
             WHERE o.`id_customer` = ' . $idCustomer . '
               AND o.`id_shop` = ' . $this->idShop . '
               AND o.`valid` = 1
             ORDER BY o.`date_add` ASC'
        ) ?: [];

        $orderCount  = count($orders);
        $totalRevenue = array_sum(array_column($orders, 'total_paid_tax_incl'));

        if ($orderCount === 0) {
            return $this->emptyResult($symbol);
        }

        $avgOrder = $totalRevenue / $orderCount;

        // Ancienneté en mois (minimum 1)
        $firstDate    = new \DateTime($orders[0]['date_add']);
        $now          = new \DateTime();
        $monthsActive = max(1, (int) $firstDate->diff($now)->days / 30.44);

        $frequencyMonthly = $orderCount / $monthsActive;

        // ── Engagement email ──────────────────────────────────────
        $engagementRate = $this->getEngagementRate($idCustomer);
        if ($engagementRate >= self::ENGAGEMENT_HIGH) {
            $engagementMult  = 1.20;
            $engagementLabel = 'high';
        } elseif ($engagementRate >= self::ENGAGEMENT_MEDIUM) {
            $engagementMult  = 1.00;
            $engagementLabel = 'medium';
        } else {
            $engagementMult  = 0.85;
            $engagementLabel = 'low';
        }

        // ── Segment ───────────────────────────────────────────────
        [$segmentMult, $segmentLabel] = $this->getSegmentMultiplier($idCustomer);

        // ── Churn score ───────────────────────────────────────────
        $churnScore = $this->getChurnScore($idCustomer);
        if ($churnScore >= self::CHURN_HIGH) {
            $churnMult  = 0.70;
            $churnLabel = 'high';
        } elseif ($churnScore >= self::CHURN_MEDIUM) {
            $churnMult  = 0.85;
            $churnLabel = 'medium';
        } else {
            $churnMult  = 1.00;
            $churnLabel = 'low';
        }

        // ── Calcul final ──────────────────────────────────────────
        $base  = $avgOrder * $frequencyMonthly * 12;
        $clv12 = $base * $engagementMult * $segmentMult * $churnMult;
        $clv12 = max(0, round($clv12, 2));

        // Label global
        if ($clv12 >= $avgOrder * 3) {
            $label = 'high';
        } elseif ($clv12 >= $avgOrder) {
            $label = 'medium';
        } else {
            $label = 'low';
        }

        return [
            'clv_12m'           => $clv12,
            'clv_label'         => $label,
            'avg_order'         => round($avgOrder, 2),
            'order_count'       => $orderCount,
            'total_revenue'     => round($totalRevenue, 2),
            'frequency_monthly' => round($frequencyMonthly, 2),
            'months_active'     => (int) $monthsActive,
            'engagement_rate'   => round($engagementRate * 100, 1),
            'engagement_mult'   => $engagementMult,
            'engagement_label'  => $engagementLabel,
            'segment_label'     => $segmentLabel,
            'segment_mult'      => $segmentMult,
            'churn_score'       => $churnScore,
            'churn_mult'        => $churnMult,
            'churn_label'       => $churnLabel,
            'currency_symbol'   => $symbol,
            'base_projection'   => round($base, 2),
        ];
    }

    /**
     * Retourne les CLV de tous les clients, triés par valeur décroissante.
     * Utilisé pour l'onglet Segments.
     */
    public function getTopCustomers(int $limit = 20): array
    {
        // Récupère les clients ayant passé au moins une commande, en pré-triant
        // par CA total décroissant (proxy du CLV) pour que le pool des 200
        // candidats contienne bien les clients les plus probables du Top,
        // plutôt qu'un sous-ensemble arbitraire des plus anciens id_customer
        // (bug précédent : un client à forte valeur mais inscrit récemment
        // pouvait être exclu du Top 20 sur une boutique de > 200 clients).
        $customers = $this->db->executeS(
            'SELECT o.`id_customer`,
                    CONCAT(c.`firstname`, " ", c.`lastname`) AS customer_name,
                    c.`email`
             FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
             WHERE o.`id_shop` = ' . $this->idShop . ' AND o.`valid` = 1
               AND c.`deleted` = 0
             GROUP BY o.`id_customer`, c.`firstname`, c.`lastname`, c.`email`
             ORDER BY SUM(o.`total_paid_tax_incl`) DESC
             LIMIT 200'
        ) ?: [];

        $results = [];
        foreach ($customers as $row) {
            $clv = $this->getCustomerClv((int) $row['id_customer']);
            if ($clv['order_count'] === 0) {
                continue;
            }
            $results[] = array_merge($row, $clv);
        }

        usort($results, fn($a, $b) => $b['clv_12m'] <=> $a['clv_12m']);

        return array_slice($results, 0, $limit);
    }

    // ============================================================
    // HELPERS PRIVÉS
    // ============================================================

    private function getEngagementRate(int $idCustomer): float
    {
        $sent = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
             WHERE `id_customer` = ' . $idCustomer . ' AND `event_type` = \'sent\''
        );
        $opened = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
             WHERE `id_customer` = ' . $idCustomer . ' AND `event_type` = \'open\''
        );

        return $sent > 0 ? min(1.0, $opened / $sent) : 0.0;
    }

    private function getSegmentMultiplier(int $idCustomer): array
    {
        if (!class_exists('SegmentManager')) {
            return [1.0, 'unknown'];
        }

        $segment = (string) $this->db->getValue(
            'SELECT `segment` FROM `' . _DB_PREFIX_ . 'neria_customer_segment`
             WHERE `id_customer` = ' . $idCustomer
        );

        $map = [
            'ambassador' => [1.25, 'ambassador'],
            'loyal'      => [1.10, 'loyal'],
            'warm'       => [1.00, 'warm'],
            'dormant'    => [0.80, 'dormant'],
            'ghost'      => [0.55, 'ghost'],
        ];

        return $map[$segment] ?? [1.0, $segment ?: 'unknown'];
    }

    private function getChurnScore(int $idCustomer): int
    {
        if (!class_exists('ChurnScoreManager')) {
            return 0;
        }

        $row = $this->db->getRow(
            'SELECT `score` FROM `' . _DB_PREFIX_ . 'neria_churn_score`
             WHERE `id_customer` = ' . $idCustomer
        );

        return $row ? (int) $row['score'] : 0;
    }

    private function emptyResult(string $symbol): array
    {
        return [
            'clv_12m'           => 0.0,
            'clv_label'         => 'none',
            'avg_order'         => 0.0,
            'order_count'       => 0,
            'total_revenue'     => 0.0,
            'frequency_monthly' => 0.0,
            'months_active'     => 0,
            'engagement_rate'   => 0.0,
            'engagement_mult'   => 1.0,
            'engagement_label'  => 'none',
            'segment_label'     => 'unknown',
            'segment_mult'      => 1.0,
            'churn_score'       => 0,
            'churn_mult'        => 1.0,
            'churn_label'       => 'low',
            'currency_symbol'   => $symbol,
            'base_projection'   => 0.0,
        ];
    }
}
