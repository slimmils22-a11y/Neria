<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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
            'SELECT o.`id_order`, o.`total_paid_tax_incl`, o.`conversion_rate`, o.`date_add`
             FROM `' . _DB_PREFIX_ . 'orders` o
             WHERE o.`id_customer` = ' . $idCustomer . '
               AND o.`id_shop` = ' . $this->idShop . '
               AND o.`valid` = 1
             ORDER BY o.`date_add` ASC'
        ) ?: [];

        $orderCount  = count($orders);
        // conversion_rate (posé par PrestaShop au moment de chaque commande)
        // ramène chaque montant à la devise par défaut de la boutique avant
        // de sommer — sans ça, sur une boutique multi-devises, additionner
        // total_paid_tax_incl brut mélange des unités différentes (ex. 100
        // USD + 100 EUR devient silencieusement "200"), faussant le CLV et
        // le classement Top 20 utilisé pour le ciblage de segments.
        $totalRevenue = 0.0;
        foreach ($orders as $o) {
            $rate = (float) ($o['conversion_rate'] ?: 1.0);
            $totalRevenue += (float) $o['total_paid_tax_incl'] / ($rate ?: 1.0);
        }

        if ($orderCount === 0) {
            return $this->emptyResult($symbol);
        }

        // Remboursements (avoirs) déduits du CA — sans ça, un client remboursé
        // à 90%+ sur chaque commande obtenait le même CLV qu'un client fidèle
        // sans aucun remboursement, faussant le ciblage marketing vers des
        // clients à forte valeur apparente mais rentabilité réelle négative.
        // Une commande intégralement remboursée passe généralement à
        // valid=0 (déjà exclue par le filtre ci-dessus) ; ce cumul couvre
        // donc surtout les remboursements PARTIELS, qui laissent la commande
        // valid=1 avec son total_paid_tax_incl d'origine non réduit. Même
        // colonnes que le clawback fidélité déjà en place dans
        // OrderTriggersManager::hookActionOrderSlipAdd(). order_slip n'a pas
        // de colonne id_shop propre — restreint aux id_order déjà filtrés
        // par boutique ci-dessus, pas à id_customer seul (sinon un client
        // partagé entre boutiques verrait ses remboursements d'UNE AUTRE
        // boutique déduits à tort de ce CLV-ci).
        $orderIds = implode(',', array_map(static fn ($o) => (int) $o['id_order'], $orders));
        $totalRefunded = (float) $this->db->getValue(
            'SELECT SUM((os.`total_products_tax_incl` + os.`total_shipping_tax_incl`) / IF(os.`conversion_rate` = 0, 1, os.`conversion_rate`))
             FROM `' . _DB_PREFIX_ . 'order_slip` os
             WHERE os.`id_order` IN (' . $orderIds . ')'
        );
        $totalRevenue = max(0.0, $totalRevenue - $totalRefunded);

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
     *
     * Version batch (5 requêtes SQL au total, quel que soit le nombre de
     * candidats) — la version précédente appelait getCustomerClv() dans la
     * boucle, soit ~5 requêtes SQL PAR CLIENT (jusqu'à ~1000 requêtes pour
     * 200 candidats), chacune filtrant potentiellement plusieurs millions de
     * lignes de neria_stat sur une boutique ancienne. Ici, les 4 sources de
     * données (commandes, engagement email, segment, score de churn) sont
     * chacune récupérées en UNE requête groupée sur l'ensemble des candidats,
     * puis la formule CLV est appliquée en PHP sans nouvel accès DB.
     */
    public function getTopCustomers(int $limit = 20): array
    {
        // Récupère les clients ayant passé au moins une commande, en pré-triant
        // par CA total décroissant (proxy du CLV) pour que le pool des 200
        // candidats contienne bien les clients les plus probables du Top,
        // plutôt qu'un sous-ensemble arbitraire des plus anciens id_customer
        // (bug précédent : un client à forte valeur mais inscrit récemment
        // pouvait être exclu du Top 20 sur une boutique de > 200 clients).
        // IF(conversion_rate = 0, 1, ...) : même garde-fou que les
        // remboursements plus bas et que computeClv() en PHP (ligne 124) —
        // une commande à conversion_rate=0 (donnée legacy/import) rend le
        // SUM() de tout le client NULL en SQL (division par zéro), le
        // classant en dernier dans l'ORDER BY et écrasant son CA réel à 0
        // dans total_revenue plus bas, malgré un historique d'achat non nul.
        $customers = $this->db->executeS(
            'SELECT o.`id_customer`,
                    CONCAT(c.`firstname`, " ", c.`lastname`) AS customer_name,
                    c.`email`
             FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
             WHERE o.`id_shop` = ' . $this->idShop . ' AND o.`valid` = 1
               AND c.`deleted` = 0
             GROUP BY o.`id_customer`, c.`firstname`, c.`lastname`, c.`email`
             ORDER BY SUM(o.`total_paid_tax_incl` / IF(o.`conversion_rate` = 0, 1, o.`conversion_rate`)) DESC
             LIMIT 200'
        ) ?: [];

        if (empty($customers)) {
            return [];
        }

        // Détecte (sans changer le comportement) un pool de pré-sélection
        // insuffisant : le tri ci-dessus se fait par CA BRUT, un simple
        // proxy du vrai CLV (calculé plus bas avec des multiplicateurs
        // engagement/segment/churn pouvant aller de ×0.33 à ×1.5). Un client
        // hors des 200 premiers en CA brut mais au profil très favorable
        // peut légitimement avoir un CLV réel supérieur à un client présent
        // dans le pool — il est alors exclu du Top N AVANT même que sa vraie
        // CLV ne soit calculée, sans que l'admin n'en soit jamais informé.
        // Même famille de correctif que CalendarManager/SegmentManager
        // (détection de dépassement de plafond, round 69).
        if (count($customers) >= 200) {
            $totalCandidates = (int) $this->db->getValue(
                'SELECT COUNT(DISTINCT o.`id_customer`)
                 FROM `' . _DB_PREFIX_ . 'orders` o
                 INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
                 WHERE o.`id_shop` = ' . $this->idShop . ' AND o.`valid` = 1
                   AND c.`deleted` = 0'
            );
            if ($totalCandidates > 200) {
                $this->watchdog()->warning(
                    \WatchdogManager::i18nMsg('watchdog.clv_top_pool_capped', ['total' => $totalCandidates]),
                    '', 'ClvManager'
                );
            }
        }

        $idList = implode(',', array_map(fn($row) => (int) $row['id_customer'], $customers));
        $symbol = \Context::getContext()->currency->sign ?? '€';

        // ── 1 requête : agrégats de commandes pour TOUS les candidats ──────
        $ordersAgg = [];
        foreach ($this->db->executeS(
            'SELECT o.`id_customer`,
                    COUNT(*) AS order_count,
                    MIN(o.`date_add`) AS first_date,
                    SUM(o.`total_paid_tax_incl` / IF(o.`conversion_rate` = 0, 1, o.`conversion_rate`)) AS total_revenue
             FROM `' . _DB_PREFIX_ . 'orders` o
             WHERE o.`id_customer` IN (' . $idList . ')
               AND o.`id_shop` = ' . $this->idShop . ' AND o.`valid` = 1
             GROUP BY o.`id_customer`'
        ) ?: [] as $r) {
            $ordersAgg[(int) $r['id_customer']] = $r;
        }

        // ── 1 requête : remboursements (avoirs) pour TOUS les candidats ──────
        // Même correctif que computeClv() ci-dessus : order_slip n'a pas de
        // colonne id_shop propre, d'où le JOIN sur orders pour rester scopé
        // à cette boutique (et non aux remboursements toutes boutiques d'un
        // client partagé).
        $refundAgg = [];
        foreach ($this->db->executeS(
            'SELECT o.`id_customer`,
                    SUM((os.`total_products_tax_incl` + os.`total_shipping_tax_incl`) / IF(os.`conversion_rate` = 0, 1, os.`conversion_rate`)) AS total_refunded
             FROM `' . _DB_PREFIX_ . 'order_slip` os
             INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = os.`id_order`
             WHERE o.`id_customer` IN (' . $idList . ') AND o.`id_shop` = ' . $this->idShop . '
             GROUP BY o.`id_customer`'
        ) ?: [] as $r) {
            $refundAgg[(int) $r['id_customer']] = (float) $r['total_refunded'];
        }

        // ── 1 requête : engagement email (sent/open) pour TOUS les candidats ──
        $engagementAgg = [];
        foreach ($this->db->executeS(
            'SELECT `id_customer`,
                    SUM(`event_type` = \'sent\') AS sent,
                    SUM(`event_type` = \'open\' AND `is_mpp` = 0)  AS opened
             FROM `' . _DB_PREFIX_ . 'neria_stat`
             WHERE `id_customer` IN (' . $idList . ') AND `id_shop` = ' . $this->idShop . '
             GROUP BY `id_customer`'
        ) ?: [] as $r) {
            $engagementAgg[(int) $r['id_customer']] = $r;
        }

        // ── 1 requête : segment comportemental pour TOUS les candidats ─────
        $segmentAgg = [];
        if (class_exists('SegmentManager')) {
            foreach ($this->db->executeS(
                'SELECT `id_customer`, `segment`
                 FROM `' . _DB_PREFIX_ . 'neria_customer_segment`
                 WHERE `id_customer` IN (' . $idList . ') AND `id_shop` = ' . $this->idShop
            ) ?: [] as $r) {
                $segmentAgg[(int) $r['id_customer']] = (string) $r['segment'];
            }
        }

        // ── 1 requête : score de churn pour TOUS les candidats ─────────────
        $churnAgg = [];
        if (class_exists('ChurnScoreManager')) {
            foreach ($this->db->executeS(
                'SELECT `id_customer`, `score`
                 FROM `' . _DB_PREFIX_ . 'neria_churn_score`
                 WHERE `id_customer` IN (' . $idList . ') AND `id_shop` = ' . $this->idShop
            ) ?: [] as $r) {
                $churnAgg[(int) $r['id_customer']] = (int) $r['score'];
            }
        }

        $results = [];
        foreach ($customers as $row) {
            $idCustomer = (int) $row['id_customer'];
            $agg = $ordersAgg[$idCustomer] ?? null;
            if ($agg === null || (int) $agg['order_count'] === 0) {
                continue;
            }

            $sent   = (int) ($engagementAgg[$idCustomer]['sent'] ?? 0);
            $opened = (int) ($engagementAgg[$idCustomer]['opened'] ?? 0);
            $engagementRate = $sent > 0 ? min(1.0, $opened / $sent) : 0.0;

            $clv = $this->assembleClv(
                (int) $agg['order_count'],
                (float) $agg['total_revenue'],
                (string) $agg['first_date'],
                $engagementRate,
                $segmentAgg[$idCustomer] ?? null,
                $churnAgg[$idCustomer] ?? 0,
                $symbol,
                $refundAgg[$idCustomer] ?? 0.0
            );

            $results[] = array_merge($row, $clv);
        }

        usort($results, fn($a, $b) => $b['clv_12m'] <=> $a['clv_12m']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Applique la formule CLV à partir de données déjà agrégées (utilisée par
     * getTopCustomers() en mode batch). Factorisée depuis computeClv() pour
     * ne pas dupliquer la formule entre le chemin "un client" et le chemin
     * "plusieurs clients d'un coup".
     */
    private function assembleClv(
        int $orderCount,
        float $totalRevenue,
        string $firstDate,
        float $engagementRate,
        ?string $segment,
        int $churnScore,
        string $symbol,
        float $totalRefunded = 0.0
    ): array {
        $totalRevenue = max(0.0, $totalRevenue - $totalRefunded);
        $avgOrder = $totalRevenue / $orderCount;

        $firstDateObj = new \DateTime($firstDate);
        $now          = new \DateTime();
        $monthsActive = max(1, (int) $firstDateObj->diff($now)->days / 30.44);

        $frequencyMonthly = $orderCount / $monthsActive;

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

        $map = [
            'ambassador' => [1.25, 'ambassador'],
            'loyal'      => [1.10, 'loyal'],
            'warm'       => [1.00, 'warm'],
            'dormant'    => [0.80, 'dormant'],
            'ghost'      => [0.55, 'ghost'],
        ];
        [$segmentMult, $segmentLabel] = $map[$segment] ?? [1.0, $segment ?: 'unknown'];

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

        $base  = $avgOrder * $frequencyMonthly * 12;
        $clv12 = $base * $engagementMult * $segmentMult * $churnMult;
        $clv12 = max(0, round($clv12, 2));

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

    // ============================================================
    // HELPERS PRIVÉS
    // ============================================================

    private function getEngagementRate(int $idCustomer): float
    {
        // Une seule requête agrégée au lieu de deux COUNT(*) séparés sur la
        // même table avec les mêmes filtres id_customer/id_shop.
        $row = $this->db->getRow(
            'SELECT SUM(`event_type` = \'sent\') AS sent, SUM(`event_type` = \'open\' AND `is_mpp` = 0) AS opened
             FROM `' . _DB_PREFIX_ . 'neria_stat`
             WHERE `id_customer` = ' . $idCustomer . ' AND `id_shop` = ' . $this->idShop
        );
        $sent   = (int) ($row['sent'] ?? 0);
        $opened = (int) ($row['opened'] ?? 0);

        return $sent > 0 ? min(1.0, $opened / $sent) : 0.0;
    }

    private function getSegmentMultiplier(int $idCustomer): array
    {
        if (!class_exists('SegmentManager')) {
            return [1.0, 'unknown'];
        }

        $segment = (string) $this->db->getValue(
            'SELECT `segment` FROM `' . _DB_PREFIX_ . 'neria_customer_segment`
             WHERE `id_customer` = ' . $idCustomer . ' AND `id_shop` = ' . $this->idShop
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
             WHERE `id_customer` = ' . $idCustomer . ' AND `id_shop` = ' . $this->idShop
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
