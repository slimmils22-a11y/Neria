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

        // Boutique réelle de CET événement de tracking (le clic/l'ouverture
        // arrive sur le domaine de la boutique concernée) — nécessaire pour
        // le cumul par boutique quand NERIA_LOYALTY_CROSS_SHOP_ENABLED est
        // désactivé par le marchand.
        $idShop = (int) \Context::getContext()->shop->id;

        $inserted = $this->db->execute(
            "INSERT IGNORE INTO `{$this->prefix}" . self::TABLE_POINTS . "`
                (id_customer, id_stat, event_type, points, id_shop, date_add)
             VALUES (
                " . (int) $idCustomer . ",
                " . (int) $idStat . ",
                '" . pSQL($eventType) . "',
                " . (int) $points . ",
                " . $idShop . ",
                NOW()
             )"
        );

        if ($inserted && $this->db->Affected_Rows() > 0) {
            $this->checkAndReward($idCustomer, $idShop);
        }
    }

    /**
     * Vérifie si un palier est atteint et envoie le bon de récompense.
     * Chaque palier n'est récompensé qu'une seule fois par client.
     */
    public function checkAndReward(int $idCustomer, ?int $idShop = null): void
    {
        $idShop = $idShop ?? (int) \Context::getContext()->shop->id;
        // Cumul transversal (défaut, comportement historique) : le total ET
        // la vérification "déjà récompensé" ignorent la boutique réelle, au
        // profit d'une valeur sentinelle 0 — nécessaire pour que la clé
        // UNIQUE (id_customer, tier_key, id_shop) de neria_loyalty_rewards
        // bloque bien un 2e bon quelle que soit la boutique d'origine :
        // sans sentinelle fixe, deux boutiques traitant le même client au
        // même moment réserveraient chacune SA propre ligne (id_shop
        // différent), créant deux CartRule pour un seul palier franchi.
        // Mode séparé (réglage marchand) : chaque boutique utilise sa vraie
        // id_shop, avec son propre total et son propre palier —
        // cf. ConfigManager::isLoyaltyCrossShopEnabled().
        $crossShop = (new \ConfigManager($this->module))->isLoyaltyCrossShopEnabled();
        $reservationShopId = $crossShop ? 0 : $idShop;
        $total = $this->getCustomerPoints($idCustomer, $crossShop ? null : $idShop);
        $tiers  = $this->getTiers();

        foreach ($tiers as $tier) {
            if ($total < $tier['points']) {
                continue;
            }

            // Déjà récompensé pour ce palier ? (sentinelle 0 en mode cumul
            // transversal, vraie boutique en mode séparé — voir ci-dessus)
            $alreadySent = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$this->prefix}" . self::TABLE_REWARDS . "`
                 WHERE id_customer = " . (int) $idCustomer . "
                   AND tier_key = '" . pSQL($tier['key']) . "'
                   AND id_shop = " . $reservationShopId
            );
            if ($alreadySent > 0) {
                continue;
            }

            // Génère le bon et envoie l'email
            try {
                $code = $this->generateVoucher($idCustomer, $tier, $reservationShopId, $total);
                if ($code === '') {
                    // Réservation perdue face à une requête concurrente —
                    // comportement attendu (anti-doublon), pas une erreur.
                    continue;
                }
                if ($tier['is_percent']) {
                    $amount = $tier['amount'] . '%';
                } else {
                    // Formatage localisé (séparateur + devise réelle) — auparavant
                    // une virgule française et un repli "€" codés en dur, faux
                    // pour toute langue non-FR ou boutique en devise non-euro.
                    $idLangCustomer = (int) $this->db->getValue(
                        'SELECT id_lang FROM `' . $this->prefix . 'customer`
                         WHERE id_customer = ' . $idCustomer
                    ) ?: (int) \Configuration::get('PS_LANG_DEFAULT');
                    $amount = \NeriaTools::displayPrice((float) $tier['amount'], $this->context->currency, $idLangCustomer);
                }

                $emailSent = $this->sendRewardEmail($idCustomer, $tier, $code, $amount, $total);
                if ($emailSent) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.loyalty_tier_reached', [
                            'tier' => $tier['name'], 'customer' => $idCustomer, 'points' => $total, 'amount' => $amount, 'code' => $code,
                        ]),
                        'loyalty_tier_upgrade',
                        'Loyalty'
                    );
                } else {
                    // Le bon est déjà créé et le palier marqué "traité" (anti-doublon,
                    // irréversible) — si l'email échoue ici, le client a un bon en
                    // base mais ne le sait jamais sans ce log.
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.loyalty_reward_email_failed', [
                            'tier' => $tier['name'], 'customer' => $idCustomer, 'code' => $code,
                        ]),
                        'loyalty_tier_upgrade',
                        'Loyalty'
                    );
                }
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

    /**
     * Retrait des points fidélité gagnés par la conversion d'une commande
     * remboursée — trouvé en réel le 2026-08-01 : un client pouvait
     * atteindre un palier et recevoir un vrai bon de réduction, puis se
     * faire rembourser intégralement la commande sans jamais perdre ni les
     * points ni le bon déjà émis. Appelée depuis
     * OrderTriggersManager::handleRefund().
     *
     * Portée volontairement limitée : supprime les points liés à CETTE
     * commande, puis révoque uniquement les bons de palier déjà émis mais
     * PAS ENCORE UTILISÉS si le nouveau total repasse sous leur seuil — un
     * bon déjà appliqué sur une autre commande n'est jamais annulé
     * rétroactivement (la remise a déjà été accordée, impossible à défaire
     * proprement sans re-facturer le client).
     */
    public function clawbackForOrder(int $idOrder, int $idCustomer, int $idShop): void
    {
        if ($idOrder <= 0 || $idCustomer <= 0) {
            return;
        }

        $statTable = $this->prefix . 'neria_stat';
        $statIds = $this->db->executeS(
            "SELECT id_stat FROM `{$statTable}`
             WHERE id_order = {$idOrder} AND event_type = 'conversion'"
        );
        if (empty($statIds)) {
            return;
        }
        $statIdList = implode(',', array_map('intval', array_column($statIds, 'id_stat')));

        $removed = $this->db->execute(
            "DELETE FROM `{$this->prefix}" . self::TABLE_POINTS . "`
             WHERE id_customer = {$idCustomer} AND id_stat IN ({$statIdList})"
        );
        if (!$removed || $this->db->Affected_Rows() === 0) {
            return;
        }

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.loyalty_points_clawed_back', [
                'customer' => $idCustomer, 'order' => $idOrder,
            ]),
            'refund_processed', 'Loyalty'
        );

        $this->revokeUnusedRewardsBelowThreshold($idCustomer, $idShop);
    }

    /**
     * Révoque (désactive, sans supprimer l'historique) tout bon de palier
     * déjà émis mais jamais utilisé si le total de points actuel du client
     * est repassé sous le seuil qui l'avait déclenché.
     */
    private function revokeUnusedRewardsBelowThreshold(int $idCustomer, int $idShop): void
    {
        $crossShop = (new \ConfigManager($this->module))->isLoyaltyCrossShopEnabled();
        $reservationShopId = $crossShop ? 0 : $idShop;
        $total = $this->getCustomerPoints($idCustomer, $crossShop ? null : $idShop);

        $rewards = $this->db->executeS(
            "SELECT id_reward, tier_key, points_at_reward, id_cart_rule
             FROM `{$this->prefix}" . self::TABLE_REWARDS . "`
             WHERE id_customer = {$idCustomer} AND id_shop = {$reservationShopId}
               AND points_at_reward > {$total}"
        );

        foreach ((array) $rewards as $reward) {
            $idCartRule = (int) $reward['id_cart_rule'];
            if ($idCartRule <= 0) {
                continue;
            }
            $cartRule = new \CartRule($idCartRule);
            if (!\Validate::isLoadedObject($cartRule)) {
                continue;
            }
            // Un bon déjà consommé (quantité restante épuisée) ne doit
            // jamais être touché — la remise a déjà été accordée.
            if ((int) $cartRule->quantity <= 0) {
                continue;
            }
            $cartRule->active = 0;
            $cartRule->update();

            $this->db->delete(self::TABLE_REWARDS, 'id_reward = ' . (int) $reward['id_reward']);

            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.loyalty_reward_revoked', [
                    'customer' => $idCustomer, 'tier' => $reward['tier_key'],
                ]),
                'refund_processed', 'Loyalty'
            );
        }
    }

    // ============================================================
    // GÉNÉRATION DU BON PS (CartRule)
    // ============================================================

    private function generateVoucher(int $idCustomer, array $tier, int $reservationShopId, int $pointsAtReward): string
    {
        // Réservation atomique du palier AVANT de créer un vrai bon de
        // réduction : la contrainte UNIQUE (id_customer, tier_key, id_shop)
        // sur neria_loyalty_rewards est le véritable garde-fou anti-course.
        // Sans cette réservation en premier, deux requêtes quasi simultanées
        // (ex : ouverture + clic sur deux appareils au même moment) pourraient
        // chacune passer le contrôle "déjà récompensé ?" fait plus haut dans
        // checkAndReward() et créer chacune un CartRule réel et valide pour
        // le même palier, avant que la contrainte d'unicité n'intervienne.
        // $reservationShopId est la sentinelle 0 en mode cumul transversal,
        // ou la vraie boutique en mode séparé (voir checkAndReward()).
        $reserved = $this->db->execute(
            "INSERT IGNORE INTO `{$this->prefix}" . self::TABLE_REWARDS . "`
                (id_customer, tier_key, tier_name, points_at_reward, id_cart_rule,
                 voucher_code, voucher_amount, is_percent, id_shop, sent_at)
             VALUES (
                " . (int) $idCustomer . ",
                '" . pSQL($tier['key'])  . "',
                '" . pSQL($tier['name']) . "',
                " . (int) $pointsAtReward . ",
                0, '', " . (float) $tier['amount'] . ", " . (int) $tier['is_percent'] . ", " . $reservationShopId . ", NOW()
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
            // Plafond de sécurité en dernier rempart (déjà appliqué à la saisie
            // dans neria.php) — une valeur non plafonnée déjà en base avant ce
            // correctif ne doit pas produire un CartRule à 500% de réduction.
            $cartRule->reduction_percent = min(100.0, (float) $tier['amount']);
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

    private function sendRewardEmail(int $idCustomer, array $tier, string $code, string $amount, int $points): bool
    {
        $customer = new \Customer($idCustomer);
        if (!\Validate::isLoadedObject($customer)) {
            return false;
        }

        $idLang = (int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT');

        return (bool) \Mail::Send(
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
            // is_array() seul ne suffit pas : un JSON valide mais mal formé
            // (ex : {"custom":true}, ou une entrée de config corrompue/d'un
            // autre usage) passerait ce contrôle tout en donnant des éléments
            // qui ne sont pas des tableaux de palier — plantant ensuite en
            // cascade tout ce qui itère dessus (getCustomerTier, checkAndReward,
            // sendMonthlyRecaps…) avec un TypeError au lieu d'un repli propre.
            if (json_last_error() === JSON_ERROR_NONE && is_array($tiers) && $this->looksLikeTiers($tiers)) {
                return $this->sortTiersByPoints($tiers);
            }
        }
        return self::DEFAULT_TIERS;
    }

    private function looksLikeTiers(array $tiers): bool
    {
        if (empty($tiers)) {
            return false;
        }
        foreach ($tiers as $tier) {
            if (!is_array($tier) || !isset($tier['key'], $tier['name'], $tier['points'])) {
                return false;
            }
        }
        return true;
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

    /**
     * @param int|null $idShop Si fourni, ne compte que les points gagnés
     *                         dans CETTE boutique — sinon (défaut, comportement
     *                         historique) cumule sur toutes les boutiques.
     *                         Cf. ConfigManager::isLoyaltyCrossShopEnabled().
     */
    public function getCustomerPoints(int $idCustomer, ?int $idShop = null): int
    {
        $shopFilter = $idShop !== null ? (' AND id_shop = ' . (int) $idShop) : '';
        return (int) $this->db->getValue(
            "SELECT COALESCE(SUM(points), 0)
             FROM `{$this->prefix}" . self::TABLE_POINTS . "`
             WHERE id_customer = " . (int) $idCustomer . $shopFilter
        );
    }

    public function getCustomerTier(int $idCustomer, ?int $idShop = null): ?array
    {
        $total  = $this->getCustomerPoints($idCustomer, $idShop);
        $tiers  = $this->getTiers();
        $current = null;

        foreach ($tiers as $tier) {
            if ($total >= $tier['points']) {
                $current = $tier;
            }
        }

        return $current;
    }

    public function getNextTier(int $idCustomer, ?int $idShop = null): ?array
    {
        $total = $this->getCustomerPoints($idCustomer, $idShop);
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
        $thisMonth = date('Y-m');

        // Mode cumul transversal (défaut) : comportement historique inchangé —
        // un seul passage, un throttle global, un total tous magasins confondus.
        $crossShop = (new \ConfigManager($this->module))->isLoyaltyCrossShopEnabled();
        if ($crossShop) {
            $lastSent = (string) \Configuration::get(self::CONFIG_RECAP_LAST_SENT);
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

        // Mode séparé (réglage marchand) : sans cette boucle, la boutique qui
        // envoie son récap en premier écrirait un throttle global (clé sans
        // suffixe id_shop) qui bloquerait silencieusement le récap de TOUTES
        // les autres boutiques ce mois-ci — et le total de points utilisé
        // serait quand même cumulé toutes boutiques confondues, contredisant
        // le réglage. Chaque boutique a donc son propre throttle et son
        // propre périmètre client/points — même schéma que checkAndReward().
        $sent = 0;
        $shops = \Shop::getShops(true, null, true) ?: [(int) \Context::getContext()->shop->id];
        foreach ($shops as $idShop) {
            $idShop = (int) $idShop;
            $lastSentKey = self::CONFIG_RECAP_LAST_SENT . '_' . $idShop;
            $lastSent = (string) \Configuration::get($lastSentKey);
            if ($lastSent === $thisMonth) {
                continue; // Déjà envoyé ce mois-ci pour cette boutique
            }

            $customers = $this->db->executeS(
                "SELECT DISTINCT id_customer
                 FROM `{$this->prefix}" . self::TABLE_POINTS . "`
                 WHERE id_customer > 0 AND id_shop = " . $idShop
            ) ?: [];

            foreach ($customers as $row) {
                try {
                    if ($this->sendRecapToCustomer((int) $row['id_customer'], $idShop)) {
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        \WatchdogManager::i18nMsg('watchdog.loyalty_recap_error', ['customer' => (int) $row['id_customer'], 'error' => $e->getMessage()]),
                        'loyalty_recap', 'Loyalty'
                    );
                }
            }

            \Configuration::updateValue($lastSentKey, $thisMonth);
        }

        return $sent;
    }

    private function sendRecapToCustomer(int $idCustomer, ?int $idShop = null): bool
    {
        $customer = new \Customer($idCustomer);
        if (!\Validate::isLoadedObject($customer) || !$customer->active) {
            return false;
        }

        // Points gagnés dans les 30 derniers jours
        $shopFilter = $idShop !== null ? (' AND id_shop = ' . (int) $idShop) : '';
        $pointsMonth = (int) $this->db->getValue(
            "SELECT COALESCE(SUM(points), 0)
             FROM `{$this->prefix}" . self::TABLE_POINTS . "`
             WHERE id_customer = " . (int) $idCustomer . "
               AND date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . $shopFilter
        );

        // Pas de points ce mois → pas d'email (évite le spam pour inactifs)
        if ($pointsMonth === 0) {
            return false;
        }

        $total    = $this->getCustomerPoints($idCustomer, $idShop);
        $nextTier = $this->getNextTier($idCustomer, $idShop);
        $currTier = $this->getCustomerTier($idCustomer, $idShop);

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
