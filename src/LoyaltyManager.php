<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — LoyaltyManager
 *
 * Programme de fidélité par email.
 * Attribution de points à chaque interaction (ouverture, clic, achat)
 * et envoi automatique d'un bon de réduction à chaque palier atteint.
 *
 * Points par défaut : open=1, click=3, conversion=10
 * Paliers (configurables) : Bronze 50pts / Argent 150pts / Or 300pts
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class LoyaltyManager
{
    const TABLE_POINTS  = 'neria_loyalty_points';
    const TABLE_REWARDS = 'neria_loyalty_rewards';
    const CONFIG_TIERS  = 'NERIA_LOYALTY_TIERS';
    const CONFIG_ENABLED = 'NERIA_LOYALTY_ENABLED';

    const POINTS_OPEN       = 1;
    const POINTS_CLICK      = 3;
    const POINTS_CONVERSION = 10;

    const DEFAULT_TIERS = [
        ['key' => 'bronze', 'name' => 'Bronze', 'points' => 50,  'amount' => 5,  'is_percent' => false],
        ['key' => 'silver', 'name' => 'Argent', 'points' => 150, 'amount' => 10, 'is_percent' => false],
        ['key' => 'gold',   'name' => 'Or',     'points' => 300, 'amount' => 20, 'is_percent' => false],
    ];

    private Neria $module;
    private \Db $db;
    private \Context $context;
    private string $prefix;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(Neria $module)
    {
        $this->module  = $module;
        $this->db      = \Db::getInstance();
        $this->context = \Context::getContext();
        $this->prefix  = _DB_PREFIX_;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // ATTRIBUTION DE POINTS
    // ============================================================

    /**
     * Attribue des points à un client après un événement de tracking.
     * Idempotent : la clé UNIQUE (id_stat, event_type) empêche les doublons.
     */
    public function awardPoints(int $idCustomer, int $idStat, string $eventType): void
    {
        if ($idCustomer <= 0 || $idStat <= 0) {
            return;
        }

        $pointsMap = [
            'open'       => self::POINTS_OPEN,
            'click'      => self::POINTS_CLICK,
            'conversion' => self::POINTS_CONVERSION,
        ];

        $points = $pointsMap[$eventType] ?? 0;
        if ($points === 0) {
            return;
        }

        $inserted = $this->db->execute(
            "INSERT IGNORE INTO `{$this->prefix}" . self::TABLE_POINTS . "`
                (id_customer, id_stat, event_type, points, date_add)
             VALUES (
                " . (int) $idCustomer . ",
                " . (int) $idStat . ",
                '" . pSQL($eventType) . "',
                " . (int) $points . ",
                NOW()
             )"
        );

        if ($inserted && $this->db->Affected_Rows() > 0) {
            $this->checkAndReward($idCustomer);
        }
    }

    /**
     * Vérifie si un palier est atteint et envoie le bon de récompense.
     * Chaque palier n'est récompensé qu'une seule fois par client.
     */
    public function checkAndReward(int $idCustomer): void
    {
        $total  = $this->getCustomerPoints($idCustomer);
        $tiers  = $this->getTiers();

        foreach ($tiers as $tier) {
            if ($total < $tier['points']) {
                continue;
            }

            // Déjà récompensé pour ce palier ?
            $alreadySent = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$this->prefix}" . self::TABLE_REWARDS . "`
                 WHERE id_customer = " . (int) $idCustomer . "
                   AND tier_key = '" . pSQL($tier['key']) . "'"
            );
            if ($alreadySent > 0) {
                continue;
            }

            // Génère le bon et envoie l'email
            try {
                $code = $this->generateVoucher($idCustomer, $tier);
                if ($code === '') {
                    // Réservation perdue face à une requête concurrente —
                    // comportement attendu (anti-doublon), pas une erreur.
                    continue;
                }
                $amount = $tier['is_percent']
                    ? $tier['amount'] . '%'
                    : number_format((float) $tier['amount'], 2, ',', ' ') . "\u{202F}" . ($this->context->currency->sign ?? '€');

                $this->sendRewardEmail($idCustomer, $tier, $code, $amount, $total);
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.loyalty_tier_reached', [
                        'tier' => $tier['name'], 'customer' => $idCustomer, 'points' => $total, 'amount' => $amount, 'code' => $code,
                    ]),
                    'loyalty_tier_upgrade',
                    'Loyalty'
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.loyalty_reward_error', [
                        'tier' => $tier['name'], 'customer' => $idCustomer, 'error' => $e->getMessage(),
                    ]),
                    'loyalty_tier_upgrade',
                    'Loyalty'
                );
            }
        }
    }

    // ============================================================
    // GÉNÉRATION DU BON PS (CartRule)
    // ============================================================

    private function generateVoucher(int $idCustomer, array $tier): string
    {
        // Réservation atomique du palier AVANT de créer un vrai bon de
        // réduction : la contrainte UNIQUE (id_customer, tier_key) sur
        // neria_loyalty_rewards est le véritable garde-fou anti-course.
        // Sans cette réservation en premier, deux requêtes quasi simultanées
        // (ex : ouverture + clic sur deux appareils au même moment) pourraient
        // chacune passer le contrôle "déjà récompensé ?" fait plus haut dans
        // checkAndReward() et créer chacune un CartRule réel et valide pour
        // le même palier, avant que la contrainte d'unicité n'intervienne.
        $reserved = $this->db->execute(
            "INSERT IGNORE INTO `{$this->prefix}" . self::TABLE_REWARDS . "`
                (id_customer, tier_key, tier_name, points_at_reward, id_cart_rule,
                 voucher_code, voucher_amount, is_percent, sent_at)
             VALUES (
                " . (int) $idCustomer . ",
                '" . pSQL($tier['key'])  . "',
                '" . pSQL($tier['name']) . "',
                " . (int) $this->getCustomerPoints($idCustomer) . ",
                0, '', " . (float) $tier['amount'] . ", " . (int) $tier['is_percent'] . ", NOW()
             )"
        );

        if (!$reserved || (int) $this->db->Affected_Rows() === 0) {
            // Un autre processus a déjà réservé ce palier entre-temps —
            // comportement attendu, pas une erreur.
            return '';
        }

        $code = 'NERIA-' . strtoupper($tier['key']) . '-' . strtoupper(\Tools::passwdGen(6));

        $langs = \Language::getLanguages(false);
        $names = [];
        $prevLang = \AdminTranslator::currentLang();
        foreach ($langs as $l) {
            $iso = \Language::getIsoById((int) $l['id_lang']) ?: 'en';
            \AdminTranslator::setLang($iso);
            $names[(int) $l['id_lang']] = \AdminTranslator::tVars('msg.loyalty_reward_name', ['tier' => $tier['name']]);
        }
        \AdminTranslator::setLang($prevLang);

        $cartRule = new \CartRule();
        $cartRule->name                    = $names;
        $cartRule->code                    = $code;
        $cartRule->id_customer             = $idCustomer;
        $cartRule->quantity                = 1;
        $cartRule->quantity_per_user       = 1;
        $cartRule->active                  = 1;
        $cartRule->date_from               = date('Y-m-d H:i:s');
        $cartRule->date_to                 = date('Y-m-d H:i:s', strtotime('+1 year'));
        $cartRule->minimum_amount          = 0;
        $cartRule->minimum_amount_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cartRule->highlight               = false;
        $cartRule->free_shipping           = false;

        if ($tier['is_percent']) {
            $cartRule->reduction_percent = (float) $tier['amount'];
            $cartRule->reduction_amount  = 0;
        } else {
            $cartRule->reduction_amount  = (float) $tier['amount'];
            $cartRule->reduction_percent = 0;
            $cartRule->reduction_tax     = 1;
            $cartRule->reduction_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        }

        if (!$cartRule->add()) {
            // Libère la réservation pour permettre une nouvelle tentative
            // (sinon ce client resterait bloqué à vie pour ce palier).
            $this->db->execute(
                "DELETE FROM `{$this->prefix}" . self::TABLE_REWARDS . "`
                 WHERE id_customer = " . (int) $idCustomer . "
                   AND tier_key = '" . pSQL($tier['key']) . "'
                   AND id_cart_rule = 0"
            );
            throw new \RuntimeException(\AdminTranslator::tVars('msg.loyalty_cartrule_add_failed', ['customer' => $idCustomer]));
        }

        // Complète la réservation avec le vrai bon de réduction généré.
        $this->db->execute(
            "UPDATE `{$this->prefix}" . self::TABLE_REWARDS . "`
             SET id_cart_rule = " . (int) $cartRule->id . ",
                 voucher_code = '" . pSQL($code) . "'
             WHERE id_customer = " . (int) $idCustomer . "
               AND tier_key = '" . pSQL($tier['key']) . "'"
        );

        return $code;
    }

    private function sendRewardEmail(int $idCustomer, array $tier, string $code, string $amount, int $points): void
    {
        $customer = new \Customer($idCustomer);
        if (!\Validate::isLoadedObject($customer)) {
            return;
        }

        $idLang = (int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT');

        \Mail::Send(
            $idLang,
            'loyalty_tier_upgrade',
            '',
            [
                '{firstname}'     => $customer->firstname,
                '{lastname}'      => $customer->lastname,
                '{shop_name}'     => \Configuration::get('PS_SHOP_NAME'),
                '{new_tier_name}' => $tier['name'],
                '{voucher_code}'  => $code,
                '{voucher_amount}'=> $amount,
                '{total_points}'  => (string) $points,
                '{history_url}'   => \Context::getContext()->link->getPageLink('history', true, $idLang),
            ],
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null, null, null, null,
            _PS_MODULE_DIR_ . 'neria/mails/',
            false,
            (int) \Context::getContext()->shop->id
        );
    }

    // ============================================================
    // CONFIGURATION DES PALIERS
    // ============================================================

    public function getTiers(): array
    {
        $json = \Configuration::get(self::CONFIG_TIERS);
        if ($json) {
            $tiers = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tiers)) {
                return $this->sortTiersByPoints($tiers);
            }
        }
        return self::DEFAULT_TIERS;
    }

    /**
     * checkAndReward() (ordre d'envoi des récompenses) et getCustomerTier()
     * (palier actuel = dernier match) supposent tous deux un tableau trié par
     * points croissants — le marchand peut réordonner/ajouter des paliers
     * dans le BO sans garantie d'ordre dans le JSON stocké. On trie ici une
     * bonne fois pour toutes, au point d'entrée commun aux deux méthodes.
     */
    private function sortTiersByPoints(array $tiers): array
    {
        usort($tiers, fn ($a, $b) => ($a['points'] ?? 0) <=> ($b['points'] ?? 0));
        return $tiers;
    }

    public function saveTiers(array $tiers): void
    {
        \Configuration::updateValue(self::CONFIG_TIERS, json_encode($tiers, JSON_UNESCAPED_UNICODE));
    }

    // ============================================================
    // STATISTIQUES CLIENT
    // ============================================================

    public function getCustomerPoints(int $idCustomer): int
    {
        return (int) $this->db->getValue(
            "SELECT COALESCE(SUM(points), 0)
             FROM `{$this->prefix}" . self::TABLE_POINTS . "`
             WHERE id_customer = " . (int) $idCustomer
        );
    }

    public function getCustomerTier(int $idCustomer): ?array
    {
        $total  = $this->getCustomerPoints($idCustomer);
        $tiers  = $this->getTiers();
        $current = null;

        foreach ($tiers as $tier) {
            if ($total >= $tier['points']) {
                $current = $tier;
            }
        }

        return $current;
    }

    public function getNextTier(int $idCustomer): ?array
    {
        $total = $this->getCustomerPoints($idCustomer);
        foreach ($this->getTiers() as $tier) {
            if ($total < $tier['points']) {
                return $tier;
            }
        }
        return null; // Palier maximum atteint
    }

    /**
     * Toutes les données de fidélité d'un client pour l'affichage BO.
     */
    public function getCustomerStats(int $idCustomer): array
    {
        $total    = $this->getCustomerPoints($idCustomer);
        $tier     = $this->getCustomerTier($idCustomer);
        $next     = $this->getNextTier($idCustomer);
        $tiers    = $this->getTiers();

        // Barre de progression vers le prochain palier
        $progressPct = 0;
        $prevPoints  = 0;
        if ($next !== null) {
            $prevPoints  = $tier ? $tier['points'] : 0;
            $range       = $next['points'] - $prevPoints;
            $done        = $total - $prevPoints;
            $progressPct = $range > 0 ? min(100, round($done / $range * 100)) : 0;
        } else {
            $progressPct = 100;
        }

        // Historique récent (10 derniers événements)
        $history = $this->db->executeS(
            "SELECT event_type, points, date_add
             FROM `{$this->prefix}" . self::TABLE_POINTS . "`
             WHERE id_customer = " . (int) $idCustomer . "
             ORDER BY date_add DESC
             LIMIT 10"
        ) ?: [];

        // Bons déjà reçus
        $rewards = $this->db->executeS(
            "SELECT tier_name, voucher_code, voucher_amount, is_percent, sent_at
             FROM `{$this->prefix}" . self::TABLE_REWARDS . "`
             WHERE id_customer = " . (int) $idCustomer . "
             ORDER BY sent_at DESC"
        ) ?: [];

        return [
            'total_points' => $total,
            'tier'         => $tier,
            'next_tier'    => $next,
            'progress_pct' => $progressPct,
            'prev_points'  => $prevPoints,
            'all_tiers'    => $tiers,
            'history'      => $history,
            'rewards'      => $rewards,
        ];
    }

    /**
     * Statistiques globales pour l'onglet Configure.
     */
    public function getGlobalStats(): array
    {
        $ptable = $this->prefix . self::TABLE_POINTS;
        $rtable = $this->prefix . self::TABLE_REWARDS;

        $row = $this->db->getRow(
            "SELECT
                COALESCE(SUM(points), 0)                          AS total_points,
                COUNT(DISTINCT id_customer)                        AS active_customers,
                SUM(event_type = 'open')                          AS cnt_open,
                SUM(event_type = 'click')                         AS cnt_click,
                SUM(event_type = 'conversion')                    AS cnt_conversion
             FROM `{$ptable}`"
        ) ?: [];

        $rewards = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$rtable}`");

        return [
            'total_points'      => (int) ($row['total_points']      ?? 0),
            'active_customers'  => (int) ($row['active_customers']  ?? 0),
            'cnt_open'          => (int) ($row['cnt_open']          ?? 0),
            'cnt_click'         => (int) ($row['cnt_click']         ?? 0),
            'cnt_conversion'    => (int) ($row['cnt_conversion']    ?? 0),
            'rewards_sent'      => $rewards,
        ];
    }

    // ============================================================
    // RECAP MENSUEL
    // ============================================================

    const CONFIG_RECAP_LAST_SENT = 'NERIA_LOYALTY_RECAP_LAST_SENT';

    /**
     * Envoie un recap mensuel à tous les clients ayant au moins 1 point.
     * Protégé par un flag pour ne tourner qu'une fois par mois (28 jours min).
     * Retourne le nombre d'emails envoyés.
     */
    public function sendMonthlyRecaps(): int
    {
        // Comparaison par mois calendaire (comme MonthlyReportManager::isDue())
        // plutôt qu'une fenêtre glissante de 28 jours, qui dérive avec le temps
        // et peut finir par envoyer le récap 13 fois par an au lieu de 12.
        $lastSent = (string) \Configuration::get(self::CONFIG_RECAP_LAST_SENT);
        $thisMonth = date('Y-m');
        if ($lastSent === $thisMonth) {
            return 0; // Déjà envoyé ce mois-ci
        }

        $customers = $this->db->executeS(
            "SELECT DISTINCT id_customer
             FROM `{$this->prefix}" . self::TABLE_POINTS . "`
             WHERE id_customer > 0"
        ) ?: [];

        $sent = 0;
        foreach ($customers as $row) {
            try {
                if ($this->sendRecapToCustomer((int) $row['id_customer'])) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.loyalty_recap_error', ['customer' => (int) $row['id_customer'], 'error' => $e->getMessage()]),
                    'loyalty_recap', 'Loyalty'
                );
            }
        }

        \Configuration::updateValue(self::CONFIG_RECAP_LAST_SENT, $thisMonth);

        return $sent;
    }

    private function sendRecapToCustomer(int $idCustomer): bool
    {
        $customer = new \Customer($idCustomer);
        if (!\Validate::isLoadedObject($customer) || !$customer->active) {
            return false;
        }

        // Points gagnés dans les 30 derniers jours
        $pointsMonth = (int) $this->db->getValue(
            "SELECT COALESCE(SUM(points), 0)
             FROM `{$this->prefix}" . self::TABLE_POINTS . "`
             WHERE id_customer = " . (int) $idCustomer . "
               AND date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        // Pas de points ce mois → pas d'email (évite le spam pour inactifs)
        if ($pointsMonth === 0) {
            return false;
        }

        $total    = $this->getCustomerPoints($idCustomer);
        $nextTier = $this->getNextTier($idCustomer);
        $currTier = $this->getCustomerTier($idCustomer);

        $prevPoints  = $currTier ? $currTier['points'] : 0;
        $progressPct = 0;
        $remaining   = 0;

        if ($nextTier !== null) {
            $range       = $nextTier['points'] - $prevPoints;
            $done        = $total - $prevPoints;
            $progressPct = $range > 0 ? min(100, (int) round($done / $range * 100)) : 0;
            $remaining   = max(0, $nextTier['points'] - $total);
        } else {
            $progressPct = 100;
        }

        $idLang = (int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT');
        $link   = \Context::getContext()->link;

        \Mail::Send(
            $idLang,
            'loyalty_recap',
            '',
            [
                '{firstname}'        => $customer->firstname,
                '{lastname}'         => $customer->lastname,
                '{shop_name}'        => \Configuration::get('PS_SHOP_NAME'),
                '{shop_url}'         => $link->getBaseLink(),
                '{history_url}'      => $link->getPageLink('history', true, $idLang),
                '{points_this_month}'=> (string) $pointsMonth,
                '{points_total}'     => (string) $total,
                '{next_tier_name}'   => $nextTier ? $nextTier['name'] : '',
                '{next_tier_points}' => $nextTier ? (string) $nextTier['points'] : '',
                '{points_remaining}' => (string) $remaining,
                '{progress_pct}'     => (string) $progressPct,
            ],
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null, null, null, null,
            _PS_MODULE_DIR_ . 'neria/mails/',
            false,
            (int) \Context::getContext()->shop->id
        );

        return true;
    }

    /**
     * Top clients par points (pour affichage BO).
     */
    public function getTopCustomers(int $limit = 10): array
    {
        $ptable = $this->prefix . self::TABLE_POINTS;

        return $this->db->executeS(
            "SELECT p.id_customer, SUM(p.points) AS total,
                    c.firstname, c.lastname, c.email
             FROM `{$ptable}` p
             JOIN `{$this->prefix}customer` c ON c.id_customer = p.id_customer
             GROUP BY p.id_customer
             ORDER BY total DESC
             LIMIT " . (int) $limit
        ) ?: [];
    }
}
