<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — BehavioralCronManager
 *
 * Emails comportementaux déclenchés une fois par jour.
 * Chaque méthode privée correspond à un template coquille de la Vague 2.
 * La déduplication passe par ps_neria_behavioral_sent (UNIQUE sur customer+template+ref_id).
 *
 * Templates gérés :
 *   birthday            — anniversaire client (J-0)
 *   first_anniversary            — 1 an après la 1ère commande
 *   relationship_anniversary    — chaque année à la date du 1er achat (2 ans, 3 ans…)
 *   reorder_reminder    — 30 j après la dernière commande
 *   win_back            — 90 j sans commande
 *   abandoned_cart_1    — panier abandonné 1 h
 *   abandoned_cart_2    — panier abandonné 24 h
 *   abandoned_cart_3    — panier abandonné 72 h
 *   checkout_abandonment— paiement abandonné (transporteur + adresses sélectionnés, 1h)
 *   post_purchase_care  — 7 j après livraison
 *   post_purchase_review— 14 j après livraison
 *   order_shipped_delay — expédié depuis 7 j sans livraison
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BehavioralCronManager
{
    // ── Délais (jours / heures) ───────────────────────────────────
    const DELAY_REORDER_DAYS          = 30;
    const DELAY_WIN_BACK_DAYS         = 90;
    const DELAY_POST_CARE_DAYS        = 7;
    const DELAY_POST_REVIEW_DAYS      = 14;
    const DELAY_SHIPPED_DELAY_DAYS    = 7;
    const DELAY_CART_1_HOURS          = 1;
    const DELAY_CART_2_HOURS          = 24;
    const DELAY_CART_3_HOURS          = 72;
    const DELAY_CHECKOUT_HOURS        = 1;

    // Statut PS « Livré » (ID 5 par défaut)
    const STATUS_DELIVERED = 5;
    // Statut PS « Expédié » (shipped=1)
    const STATUS_SHIPPED   = 4;

    // Plafond de lignes traitées par méthode et par passage du cron. Chaque
    // ligne déclenche un Mail::Send() synchrone (~2s mesuré en réel via
    // QueueManager::processQueue() : 50 emails = 111s). Sans plafond, aucune
    // des ~15 requêtes SELECT de ce fichier n'a de LIMIT : sur une base avec
    // plusieurs milliers de clients éligibles le même jour (ex. campagne
    // d'anniversaires un jour de forte natalité, franchise multi-boutiques),
    // le cron peut tourner des heures dans une seule requête HTTP/CLI et
    // dépasser max_execution_time. Les clients non traités ce jour restent
    // éligibles (NOT EXISTS sur neria_behavioral_sent) et seront repris au
    // prochain passage du cron — rien n'est perdu, juste étalé.
    const MAX_BATCH_PER_RUN = 500;

    private \Neria $module;
    private \Db $db;
    private string $prefix;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(\Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->prefix = _DB_PREFIX_;
    }

    private function historyUrl(int $idLang = 0): string
    {
        $ctx = \Context::getContext();
        return ($ctx && $ctx->link) ? $ctx->link->getPageLink('history', true, $idLang > 0 ? $idLang : null) : '';
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    /**
     * Point d'entrée principal — appelé une fois par jour.
     */
    public function run(): void
    {
        \Configuration::updateValue(\HealthCheckManager::CRON_LAST_BEHAVIORAL, date('Y-m-d H:i:s'));
        $this->watchdog()->info(\WatchdogManager::i18nMsg('watchdog.behavioral_cron_start'), '', 'BehavioralCron');

        // Vider la file d'attente des emails programmés (fenêtres d'achat individuelles)
        if (\Configuration::getGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED') && class_exists('QueueManager')) {
            try {
                $queued = (new \QueueManager($this->module))->processQueue();
                if ($queued > 0) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.queue_processed', ['n' => $queued]),
                        '',
                        'BehavioralCron'
                    );
                }
            } catch (\Throwable $e) {
                $this->watchdog()->error(\WatchdogManager::i18nMsg('watchdog.queue_process_error', ['error' => $e->getMessage()]), '', 'BehavioralCron');
            }
        }

        // Chaque tâche est isolée : l'échec de l'une (ex: sendBirthdays())
        // ne doit jamais empêcher les 19 autres de s'exécuter le même jour.
        //
        // Toutes les méthodes ci-dessous filtrent désormais leurs requêtes par
        // Context::getContext()->shop->id (isolation multi-boutique). Or run()
        // n'est appelé qu'UNE fois par jour, par le premier visiteur front qui
        // déclenche le hook — dans le contexte de LA boutique qu'il visite. Sans
        // la boucle ci-dessous, les autres boutiques d'une install multi-shop
        // ne recevraient donc plus JAMAIS ces emails comportementaux. On boucle
        // ici sur chaque boutique active en basculant temporairement le contexte
        // PrestaShop, pour que chaque boutique soit traitée exactement une fois.
        // Point de vérification dispersé #3 : les tâches ci-dessous génèrent
        // presque exclusivement des envois d'emails comportementaux — inutile
        // de faire tout ce travail de calcul (requêtes, dédup) si le verrou
        // de licence bloquera de toute façon l'email au moment de l'envoi
        // (déjà garanti universellement par hookActionEmailSendBefore).
        // Vérification locale uniquement, aucun appel réseau ici. Les tâches
        // purement calculatoires (recalculatePropensityScores, segmentation,
        // churn) ne sont PAS concernées : elles ne bloquent aucun email.
        $emailSendingAllowed = !class_exists('LicenseManager')
            || (new \LicenseManager($this->module))->isEmailSendingAllowed();

        $originalShop = \Context::getContext()->shop;
        $shops = \Shop::getShops(true, null, true) ?: [(int) $originalShop->id];

        if ($emailSendingAllowed) {
            foreach ($shops as $idShop) {
                \Context::getContext()->shop = new \Shop((int) $idShop);

                $this->runStep('sendBirthdays',                 fn () => $this->sendBirthdays());
                $this->runStep('sendFirstAnniversaries',         fn () => $this->sendFirstAnniversaries());
                $this->runStep('sendRelationshipAnniversaries',  fn () => $this->sendRelationshipAnniversaries());
                $this->runStep('sendReorderReminders',           fn () => $this->sendReorderReminders());
                $this->runStep('sendWinBacks',                   fn () => $this->sendWinBacks());
                $this->runStep('sendRewardExpiryAlerts',         fn () => $this->sendRewardExpiryAlerts());
                $this->runStep('sendWishlistReminders',          fn () => $this->sendWishlistReminders());
                $this->runStep('sendAbandonedCarts(1)',          fn () => $this->sendAbandonedCarts('abandoned_cart_1', self::DELAY_CART_1_HOURS));
                $this->runStep('sendAbandonedCarts(2)',          fn () => $this->sendAbandonedCarts('abandoned_cart_2', self::DELAY_CART_2_HOURS));
                $this->runStep('sendAbandonedCarts(3)',          fn () => $this->sendAbandonedCarts('abandoned_cart_3', self::DELAY_CART_3_HOURS));
                $this->runStep('sendCheckoutAbandonment',        fn () => $this->sendCheckoutAbandonment());
                $this->runStep('sendQuoteExpiryReminders',       fn () => $this->sendQuoteExpiryReminders());
                $this->runStep('sendRefundReconciliations',      fn () => $this->sendRefundReconciliations());
                $this->runStep('sendLifespanReminders',          fn () => $this->sendLifespanReminders());
                $this->runStep('sendPostPurchase(care)',         fn () => $this->sendPostPurchase('post_purchase_care',   self::DELAY_POST_CARE_DAYS));
                $this->runStep('sendPostPurchase(review)',       fn () => $this->sendPostPurchase('post_purchase_review', self::DELAY_POST_REVIEW_DAYS));
                $this->runStep('sendShippedDelayAlerts',         fn () => $this->sendShippedDelayAlerts());
                $this->runStep('sendGhostCarts',                 fn () => $this->sendGhostCarts());
            }
        }

        \Context::getContext()->shop = $originalShop;

        // Tâches globales (non scopées par boutique, dédup propre via
        // id_order — déjà rattaché à une seule boutique) : une seule fois.
        // recalculatePropensityScores() n'envoie aucun email — jamais gaté.
        $this->runStep('recalculatePropensityScores',    fn () => $this->recalculatePropensityScores());
        if ($emailSendingAllowed) {
            $this->runStep('sendCollectionCompletions',      fn () => $this->sendCollectionCompletions());
            $this->runStep('sendLookCompletions',            fn () => $this->sendLookCompletions());
        }

        // ── Segmentation comportementale (recalcul quotidien) ─────────
        if (class_exists('SegmentManager')) {
            try {
                (new \SegmentManager($this->module))->recomputeAll();
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.segment_recompute_failed', ['error' => $e->getMessage()]),
                    '', 'BehavioralCron'
                );
            }
        }

        // ── Score de risque de désabonnement (recalcul quotidien) ─────
        if (class_exists('ChurnScoreManager')) {
            try {
                (new \ChurnScoreManager($this->module))->recomputeAll();
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.churn_recompute_failed', ['error' => $e->getMessage()]),
                    '', 'BehavioralCron'
                );
            }
        }

        $this->watchdog()->cronHeartbeat('behavioral', 'ok');
        $this->watchdog()->info(\WatchdogManager::i18nMsg('watchdog.behavioral_cron_done'), '', 'BehavioralCron');
    }

    /**
     * Exécute une tâche isolée : une exception y est journalisée puis
     * absorbée, sans jamais interrompre les tâches suivantes du run().
     */
    private function runStep(string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.step_failed', ['label' => $label, 'error' => $e->getMessage()]),
                '', 'BehavioralCron'
            );
        }
    }

    // ============================================================
    // BIRTHDAY — anniversaire client
    // Ref_id = année courante (une seule fois par an)
    // ============================================================

    private function sendBirthdays(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_BIRTHDAY_ENABLED')) {
            return;
        }
        $year   = (int) date('Y');
        $idShop = (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            'SELECT c.id_customer, c.email, c.firstname, c.lastname, c.id_lang, c.id_shop
             FROM `' . $this->prefix . 'customer` c
             WHERE c.active = 1 AND c.deleted = 0 AND c.id_shop = ' . $idShop . '
               AND c.birthday IS NOT NULL AND c.birthday != \'0000-00-00\'
               AND DAY(c.birthday) = DAY(NOW()) AND MONTH(c.birthday) = MONTH(NOW())
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = c.id_customer AND bs.template = \'birthday\'
                     AND bs.ref_id = ' . $year . ' AND bs.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        $config = new \ConfigManager($this->module);

        foreach ((array) $rows as $r) {
            $idCustomer = (int) $r['id_customer'];
            try {
                $code = $this->generateBirthdayVoucher($idCustomer, $config, $idShop);
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.birthday_voucher_error', [
                        'customer' => $idCustomer, 'error' => $e->getMessage(),
                    ]),
                    'birthday',
                    'BehavioralCron'
                );
                continue;
            }

            if ($code === '') {
                // Réservation perdue face à une requête concurrente (rare,
                // cron exécuté deux fois le même jour) — comportement
                // attendu, pas une erreur : on ne renvoie pas ce client.
                continue;
            }

            $this->send(
                'birthday',
                $r,
                [
                    '{voucher_code}' => $code,
                    '{shop_url}'     => \Tools::getShopDomainSsl(true),
                ],
                $year
            );
        }
    }

    /**
     * Génère un vrai bon de réduction PrestaShop (CartRule) pour l'anniversaire
     * d'un client, selon le montant/type configuré par le marchand
     * (ConfigManager::getBirthdayVoucherAmount() / isBirthdayVoucherPercent()).
     * Réservation atomique anti-doublon via ps_neria_birthday_voucher (UNIQUE
     * id_customer+year), sur le même principe que LoyaltyManager::generateVoucher().
     *
     * @return string Le code du bon, ou '' si déjà réservé par une requête concurrente.
     */
    private function generateBirthdayVoucher(int $idCustomer, \ConfigManager $config, int $idShop): string
    {
        $year = (int) date('Y');

        $reserved = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->prefix . 'neria_birthday_voucher`
                (id_customer, year, id_cart_rule, voucher_code, id_shop, created_at)
             VALUES (' . (int) $idCustomer . ', ' . $year . ', 0, \'\', ' . $idShop . ', NOW())'
        );

        if (!$reserved || (int) $this->db->Affected_Rows() === 0) {
            return '';
        }

        $amount    = $config->getBirthdayVoucherAmount();
        $isPercent = $config->isBirthdayVoucherPercent();
        $code      = 'NERIA-BDAY-' . strtoupper(\Tools::passwdGen(6));

        $cartRule = new \CartRule();
        $cartRule->name                    = ['1' => $code];
        $langs = \Language::getLanguages(false);
        $names = [];
        foreach ($langs as $l) {
            $names[(int) $l['id_lang']] = $code;
        }
        $cartRule->name                    = $names;
        $cartRule->code                    = $code;
        $cartRule->id_customer             = $idCustomer;
        $cartRule->quantity                = 1;
        $cartRule->quantity_per_user       = 1;
        $cartRule->active                  = 1;
        $cartRule->date_from               = date('Y-m-d H:i:s');
        $cartRule->date_to                 = date('Y-m-d H:i:s', strtotime('+' . $config->getVoucherValidity() . ' days'));
        $cartRule->minimum_amount          = 0;
        $cartRule->minimum_amount_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $cartRule->highlight               = false;
        $cartRule->free_shipping           = false;

        if ($isPercent) {
            $cartRule->reduction_percent = $amount;
            $cartRule->reduction_amount  = 0;
        } else {
            $cartRule->reduction_amount   = $amount;
            $cartRule->reduction_percent  = 0;
            $cartRule->reduction_tax      = 1;
            $cartRule->reduction_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        }

        if (!$cartRule->add()) {
            // Libère la réservation pour permettre une nouvelle tentative
            // au prochain passage du cron (sinon ce client resterait sans
            // bon d'anniversaire à vie pour cette année).
            $this->db->execute(
                'DELETE FROM `' . $this->prefix . 'neria_birthday_voucher`
                 WHERE id_customer = ' . (int) $idCustomer . ' AND year = ' . $year . ' AND id_cart_rule = 0'
            );
            throw new \RuntimeException('CartRule::add() failed for customer ' . $idCustomer);
        }

        $this->db->execute(
            'UPDATE `' . $this->prefix . 'neria_birthday_voucher`
             SET id_cart_rule = ' . (int) $cartRule->id . ', voucher_code = \'' . pSQL($code) . '\'
             WHERE id_customer = ' . (int) $idCustomer . ' AND year = ' . $year
        );

        return $code;
    }

    // ============================================================
    // FIRST ANNIVERSARY — 1 an après la 1ère commande
    // Ref_id = id_order de la 1ère commande
    // ============================================================

    private function sendFirstAnniversaries(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_FIRST_ANNIVERSARY_ENABLED')) {
            return;
        }
        $idShop = (int) \Context::getContext()->shop->id;
        // Filtre sur o.id_shop (pas c.id_shop) : un client partagé entre
        // boutiques peut avoir sa 1ère commande sur une AUTRE boutique que
        // celle où il a été créé — c'est bien "1ère commande DE CETTE
        // boutique" qui doit déclencher l'email à l'image de CETTE boutique.
        $rows = $this->db->executeS(
            'SELECT c.id_customer, c.email, c.firstname, c.lastname, c.id_lang, c.id_shop,
                    MIN(o.id_order) AS id_first_order
             FROM `' . $this->prefix . 'customer` c
             JOIN `' . $this->prefix . 'orders` o ON o.id_customer = c.id_customer AND o.valid = 1 AND o.id_shop = ' . $idShop . '
             WHERE c.active = 1 AND c.deleted = 0
             GROUP BY c.id_customer
             HAVING DATE(MIN(o.date_add)) = DATE(DATE_SUB(NOW(), INTERVAL 1 YEAR))
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = c.id_customer AND bs.template = \'first_anniversary\'
                     AND bs.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            try {
                $this->send('first_anniversary', $r, [], (int) $r['id_first_order']);
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.behavioral_send_error', [
                        'template' => 'first_anniversary',
                        'customer' => $r['id_customer'] ?? '?',
                        'error'    => $e->getMessage(),
                    ]),
                    'first_anniversary',
                    'BehavioralCron'
                );
            }
        }
    }

    // ============================================================
    // REORDER REMINDER — 30 j après la dernière commande
    // Ref_id = id_order de la dernière commande
    // ============================================================

    private function sendReorderReminders(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_REORDER_ENABLED')) {
            return;
        }
        $days   = self::DELAY_REORDER_DAYS;
        $idShop = (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            'SELECT c.id_customer, c.email, c.firstname, c.lastname, c.id_lang, c.id_shop,
                    o.id_order, od.product_name
             FROM `' . $this->prefix . 'customer` c
             JOIN `' . $this->prefix . 'orders` o
                  ON o.id_customer = c.id_customer AND o.valid = 1 AND o.id_shop = ' . $idShop . '
             JOIN `' . $this->prefix . 'order_detail` od ON od.id_order = o.id_order
             WHERE c.active = 1 AND c.deleted = 0
               AND DATE(o.date_add) = DATE(DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY))
               AND o.id_order = (
                   SELECT MAX(o2.id_order) FROM `' . $this->prefix . 'orders` o2
                   WHERE o2.id_customer = c.id_customer AND o2.valid = 1 AND o2.id_shop = ' . $idShop . '
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = c.id_customer AND bs.template = \'reorder_reminder\'
                     AND bs.ref_id = o.id_order AND bs.id_shop = ' . $idShop . '
               )
             GROUP BY c.id_customer
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            try {
                $this->send(
                    'reorder_reminder',
                    $r,
                    [
                        '{product_name}' => $r['product_name'],
                        '{shop_url}'     => \Tools::getShopDomainSsl(true),
                    ],
                    (int) $r['id_order']
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.behavioral_send_error', [
                        'template' => 'reorder_reminder',
                        'customer' => $r['id_customer'] ?? '?',
                        'error'    => $e->getMessage(),
                    ]),
                    'reorder_reminder',
                    'BehavioralCron'
                );
            }
        }
    }

    // ============================================================
    // WIN BACK — 90 j sans commande
    // Ref_id = année courante (une campagne par an)
    // ============================================================

    private function sendWinBacks(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_WIN_BACK_ENABLED')) {
            return;
        }
        $days   = self::DELAY_WIN_BACK_DAYS;
        $year   = (int) date('Y');
        $idShop = (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            'SELECT c.id_customer, c.email, c.firstname, c.lastname, c.id_lang, c.id_shop
             FROM `' . $this->prefix . 'customer` c
             WHERE c.active = 1 AND c.deleted = 0 AND c.id_shop = ' . $idShop . '
               AND (
                   SELECT MAX(o.date_add) FROM `' . $this->prefix . 'orders` o
                   WHERE o.id_customer = c.id_customer AND o.valid = 1 AND o.id_shop = ' . $idShop . '
               ) <= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = c.id_customer AND bs.template = \'win_back\'
                     AND bs.ref_id = ' . $year . ' AND bs.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            try {
                $this->send(
                    'win_back',
                    $r,
                    ['{shop_url}' => \Tools::getShopDomainSsl(true)],
                    $year
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.behavioral_send_error', [
                        'template' => 'win_back',
                        'customer' => $r['id_customer'] ?? '?',
                        'error'    => $e->getMessage(),
                    ]),
                    'win_back',
                    'BehavioralCron'
                );
            }
        }
    }

    // ============================================================
    // LOYALTY_REWARD_EXPIRY — bon de réduction expirant dans 7 j
    // Source : ps_cart_rule assigné à un client (id_customer > 0)
    // Ref_id = id_cart_rule (une seule alerte par bon)
    // ============================================================

    private function sendRewardExpiryAlerts(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_REWARD_EXPIRY_ENABLED')) {
            return;
        }
        // ps_cart_rule n'a pas de colonne id_shop native — on scope via le
        // client (c.id_shop), seul rattachement disponible.
        $idShop = (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            'SELECT cr.id_cart_rule, cr.id_customer, cr.date_to,
                    c.email, c.firstname, c.lastname, c.id_lang, c.id_shop
             FROM `' . $this->prefix . 'cart_rule` cr
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = cr.id_customer
             WHERE cr.active = 1 AND cr.id_customer > 0 AND c.id_shop = ' . $idShop . '
               AND DATE(cr.date_to) = DATE(DATE_ADD(NOW(), INTERVAL 7 DAY))
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = cr.id_customer
                     AND bs.template = \'loyalty_reward_expiry\'
                     AND bs.ref_id = cr.id_cart_rule AND bs.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            try {
                $expiryDate = \NeriaTools::formatDate($r['date_to'], \Language::getIsoById((int) $r['id_lang']) ?: 'fr');
                $historyUrl = $this->historyUrl((int) $r['id_lang']);

                $this->send(
                    'loyalty_reward_expiry',
                    $r,
                    [
                        '{reward_expiry_date}' => $expiryDate,
                        '{history_url}'        => $historyUrl,
                    ],
                    (int) $r['id_cart_rule']
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.behavioral_send_error', [
                        'template' => 'loyalty_reward_expiry',
                        'customer' => $r['id_customer'] ?? '?',
                        'error'    => $e->getMessage(),
                    ]),
                    'loyalty_reward_expiry',
                    'BehavioralCron'
                );
            }
        }
    }

    // ============================================================
    // WISHLIST_REMINDER — article en wishlist non acheté
    // Nécessite le module blockwishlist (tables ps_wishlist*)
    // Ref_id = YEAR*100+MONTH → une alerte par client par mois
    // ============================================================

    private function sendWishlistReminders(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_WISHLIST_ENABLED')) {
            return;
        }
        // Passer silencieusement si le module blockwishlist n'est pas installé
        $tableExists = $this->db->executeS(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = \'' . pSQL(_DB_PREFIX_ . 'wishlist_product') . '\' LIMIT 1'
        );
        if (empty($tableExists)) {
            return;
        }

        $refId  = (int) date('Y') * 100 + (int) date('n');
        $idShop = (int) \Context::getContext()->shop->id;

        $rows = $this->db->executeS(
            'SELECT w.id_customer, w.id_shop,
                    c.email, c.firstname, c.lastname, c.id_lang,
                    pl.name AS product_name
             FROM `' . $this->prefix . 'wishlist` w
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = w.id_customer
             JOIN (
                 SELECT wp.id_wishlist, MIN(wp.id_wishlist_product) AS min_id
                 FROM `' . $this->prefix . 'wishlist_product` wp
                 GROUP BY wp.id_wishlist
             ) first_item ON first_item.id_wishlist = w.id_wishlist
             JOIN `' . $this->prefix . 'wishlist_product` wp
                  ON wp.id_wishlist_product = first_item.min_id
             JOIN `' . $this->prefix . 'product_lang` pl
                  ON pl.id_product = wp.id_product AND pl.id_lang = c.id_lang
             WHERE c.active = 1 AND c.deleted = 0 AND w.id_shop = ' . $idShop . '
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = w.id_customer
                     AND bs.template = \'wishlist_reminder\'
                     AND bs.ref_id = ' . $refId . ' AND bs.id_shop = ' . $idShop . '
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM `' . $this->prefix . 'orders` o
                   JOIN `' . $this->prefix . 'order_detail` od ON od.id_order = o.id_order
                   JOIN `' . $this->prefix . 'wishlist_product` wp2
                        ON wp2.id_wishlist_product = first_item.min_id
                   WHERE o.id_customer = w.id_customer
                     AND od.product_id = wp2.id_product
                     AND o.valid = 1
               )
             GROUP BY w.id_customer
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            try {
                $this->send(
                    'wishlist_reminder',
                    $r,
                    [
                        '{product_name}' => $r['product_name'],
                        '{shop_url}'     => \Tools::getShopDomainSsl(true),
                    ],
                    $refId
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.behavioral_send_error', [
                        'template' => 'wishlist_reminder',
                        'customer' => $r['id_customer'] ?? '?',
                        'error'    => $e->getMessage(),
                    ]),
                    'wishlist_reminder',
                    'BehavioralCron'
                );
            }
        }
    }

    // ============================================================
    // ABANDONED CART — 3 séquences (1h / 24h / 72h)
    // Ref_id = id_cart
    // ============================================================

    private function sendAbandonedCarts(string $template, int $hours): void
    {
        if (!\Configuration::getGlobalValue('NERIA_ABANDONED_CART_ENABLED')) {
            return;
        }
        // run() n'est déclenché qu'une fois par jour (garde-fou
        // CRON_LAST_BEHAVIORAL dans neria.php). Pour les templates à délai
        // court (1h), une fenêtre de seulement 1h manquait quasiment tous
        // les paniers : élargie à 24h. Les délais plus longs (24h/72h)
        // gardent une fenêtre étroite d'1h, car l'élargir créerait un
        // chevauchement avec la fenêtre du palier suivant (ex: cart_1
        // [1h,25h] chevaucherait cart_2 [24h,48h] et enverrait les deux
        // emails pour le même panier). La dédup via neria_behavioral_sent
        // empêche seulement le renvoi du MÊME template, pas ce chevauchement.
        $minAgo = ($hours <= 1) ? 24 : $hours + 1;
        $idShop = (int) \Context::getContext()->shop->id;
        $rows   = $this->db->executeS(
            'SELECT ca.id_cart, ca.id_customer, ca.id_shop,
                    c.email, c.firstname, c.lastname, c.id_lang
             FROM `' . $this->prefix . 'cart` ca
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = ca.id_customer
             WHERE ca.id_customer > 0 AND c.active = 1 AND c.deleted = 0 AND ca.id_shop = ' . $idShop . '
               AND ca.date_upd BETWEEN DATE_SUB(NOW(), INTERVAL ' . $minAgo . ' HOUR)
                                   AND DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'orders` o WHERE o.id_cart = ca.id_cart
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = ca.id_customer AND bs.template = \'' . pSQL($template) . '\'
                     AND bs.ref_id = ca.id_cart AND bs.id_shop = ' . $idShop . '
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = ca.id_customer AND bs.template = \'checkout_abandonment\'
                     AND bs.ref_id = ca.id_cart AND bs.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            try {
                $idCart   = (int) $r['id_cart'];
                $cartUrl  = \Tools::getShopDomainSsl(true) . 'index.php?controller=order';
                $products = $this->buildCartProducts($idCart);

                $this->send(
                    $template,
                    $r,
                    [
                        '{cart_url}'     => $cartUrl,
                        '{products}'     => $products,
                        '{products_txt}' => $this->buildCartProductsTxt($idCart),
                    ],
                    $idCart
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.behavioral_send_error', [
                        'template' => $template,
                        'customer' => $r['id_customer'] ?? '?',
                        'error'    => $e->getMessage(),
                    ]),
                    $template,
                    'BehavioralCron'
                );
            }
        }
    }

    // ============================================================
    // CHECKOUT ABANDONMENT — 1h (transporteur + 2 adresses sélectionnés)
    // Ref_id = id_cart
    // ============================================================

    public function getCheckoutAbandonmentStats(): array
    {
        // neria_behavioral_sent n'a pas de colonne id_shop, mais la table
        // orders jointe en a une : sans filtre, une commande récupérée sur
        // une autre boutique (install multi-boutiques) se retrouvait comptée
        // dans les stats de la boutique courante.
        $idShop = (int) \Context::getContext()->shop->id;
        $row  = $this->db->getRow(
            'SELECT
                COUNT(bs.id)                    AS emails_sent,
                COUNT(DISTINCT o.id_order)      AS orders_recovered,
                COALESCE(SUM(o.total_paid_tax_incl), 0) AS revenue_recovered
             FROM `' . $this->prefix . 'neria_behavioral_sent` bs
             LEFT JOIN `' . $this->prefix . 'orders` o
                ON o.id_cart = bs.ref_id AND o.date_add > bs.sent_at AND o.id_shop = ' . $idShop . '
             WHERE bs.template = \'checkout_abandonment\''
        );

        $sent      = (int)   ($row['emails_sent']       ?? 0);
        $recovered = (int)   ($row['orders_recovered']  ?? 0);
        $revenue   = (float) ($row['revenue_recovered'] ?? 0.0);

        return [
            'emails_sent'       => $sent,
            'orders_recovered'  => $recovered,
            'revenue_recovered' => round($revenue, 2),
            'conversion_rate'   => $sent > 0 ? round($recovered / $sent * 100, 1) : 0.0,
        ];
    }

    private function sendCheckoutAbandonment(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_CHECKOUT_ABANDONMENT_ENABLED')) {
            return;
        }

        $hours  = self::DELAY_CHECKOUT_HOURS;
        $idShop = (int) \Context::getContext()->shop->id;
        $rows  = $this->db->executeS(
            'SELECT ca.id_cart, ca.id_customer, ca.id_shop,
                    c.email, c.firstname, c.lastname, c.id_lang
             FROM `' . $this->prefix . 'cart` ca
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = ca.id_customer
             WHERE ca.id_customer > 0 AND c.active = 1 AND c.deleted = 0 AND ca.id_shop = ' . $idShop . '
               AND ca.id_carrier > 0
               AND ca.id_address_delivery > 0
               AND ca.id_address_invoice > 0
               AND (SELECT COUNT(*) FROM `' . $this->prefix . 'cart_product` cp
                    WHERE cp.id_cart = ca.id_cart) > 0
               AND ca.date_upd BETWEEN DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                   AND DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'orders` o WHERE o.id_cart = ca.id_cart
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = ca.id_customer AND bs.template = \'checkout_abandonment\'
                     AND bs.ref_id = ca.id_cart AND bs.id_shop = ' . $idShop . '
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = ca.id_customer
                     AND bs.template IN (\'abandoned_cart_1\',\'abandoned_cart_2\',\'abandoned_cart_3\')
                     AND bs.ref_id = ca.id_cart AND bs.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            try {
                $idCart  = (int) $r['id_cart'];
                $cartUrl = \Tools::getShopDomainSsl(true) . 'index.php?controller=order';

                $this->send(
                    'checkout_abandonment',
                    $r,
                    [
                        '{cart_url}' => $cartUrl,
                        '{products}' => $this->buildCartProducts($idCart),
                    ],
                    $idCart
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.behavioral_send_error', [
                        'template' => 'checkout_abandonment',
                        'customer' => $r['id_customer'] ?? '?',
                        'error'    => $e->getMessage(),
                    ]),
                    'checkout_abandonment',
                    'BehavioralCron'
                );
            }
        }
    }

    // ============================================================
    // POST PURCHASE — care (J+7) et review (J+14)
    // Ref_id = id_order
    // ============================================================

    private function sendPostPurchase(string $template, int $days): void
    {
        if (!\Configuration::getGlobalValue('NERIA_POST_PURCHASE_ENABLED')) {
            return;
        }
        $idShop = (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            'SELECT o.id_order, o.id_customer, o.id_shop,
                    c.email, c.firstname, c.lastname, c.id_lang
             FROM `' . $this->prefix . 'orders` o
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = o.id_customer
             WHERE c.active = 1 AND c.deleted = 0 AND o.valid = 1 AND o.id_shop = ' . $idShop . '
               AND EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'order_history` oh
                   WHERE oh.id_order = o.id_order
                     AND oh.id_order_state = ' . self::STATUS_DELIVERED . '
                     AND DATE(oh.date_add) = DATE(DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY))
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = o.id_customer AND bs.template = \'' . pSQL($template) . '\'
                     AND bs.ref_id = o.id_order AND bs.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        // Toggle BO respecté : l'upsell n'est instancié que s'il est activé.
        // L'email d'avis (post_purchase_review) part dans tous les cas ;
        // seul le bloc produit est conditionné par NERIA_UPSELL_ENABLED.
        $upsellMgr = ($template === 'post_purchase_review'
                      && (bool) \Configuration::getGlobalValue('NERIA_UPSELL_ENABLED'))
            ? new \UpsellManager($this->module)
            : null;

        foreach ((array) $rows as $r) {
            $idOrder = (int) $r['id_order'];
            $idLang  = (int) ($r['id_lang'] ?: \Configuration::get('PS_LANG_DEFAULT'));

            $extraVars = ['{review_url}' => \Tools::getShopDomainSsl(true)];

            // Placeholders upsell toujours nettoyés pour l'email d'avis
            // (vides si désactivé OU si aucun produit pertinent n'est trouvé).
            if ($template === 'post_purchase_review') {
                $extraVars['{upsell_block}']     = '';
                $extraVars['{upsell_block_txt}'] = '';
            }

            if ($upsellMgr !== null) {
                try {
                    $upsell = $upsellMgr->getUpsellProduct($idOrder, $idLang);

                    if ($upsell !== null) {
                        $idUpsell = $upsellMgr->recordSuggestion(
                            (int) $r['id_customer'], $idOrder, $upsell
                        );
                        if ($idUpsell > 0) {
                            $sep = (strpos($upsell['product_url'], '?') !== false) ? '&' : '?';
                            $upsell['product_url'] .= $sep . 'neria_ur=' . $idUpsell;
                            $this->watchdog()->info(
                                \WatchdogManager::i18nMsg('watchdog.upsell_sent', [
                                    'name'   => $upsell['name'],
                                    'reason' => $upsell['reason'],
                                    'email'  => $r['email'] ?? '?',
                                    'order'  => $idOrder,
                                ]),
                                'post_purchase_review',
                                'Upsell'
                            );
                        }
                    } else {
                        $this->watchdog()->info(
                            \WatchdogManager::i18nMsg('watchdog.upsell_no_product', [
                                'order' => $idOrder,
                                'email' => $r['email'] ?? '?',
                            ]),
                            'post_purchase_review',
                            'Upsell'
                        );
                    }

                    $config = new \ConfigManager($this->module);
                    $extraVars['{upsell_block}']     = $upsellMgr->buildHtmlBlock($upsell, $config);
                    $extraVars['{upsell_block_txt}'] = $upsellMgr->buildTxtBlock($upsell);
                } catch (\Throwable $e) {
                    $extraVars['{upsell_block}']     = '';
                    $extraVars['{upsell_block_txt}'] = '';
                    $this->watchdog()->error(
                        \WatchdogManager::i18nMsg('watchdog.upsell_error', [
                            'order' => $idOrder,
                            'error' => $e->getMessage(),
                        ]),
                        'post_purchase_review',
                        'Upsell'
                    );
                }
            }

            $this->send($template, $r, $extraVars, $idOrder);
        }
    }

    // ============================================================
    // ORDER SHIPPED DELAY — expédié depuis 7 j sans livraison
    // Ref_id = id_order
    // ============================================================

    private function sendShippedDelayAlerts(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_SHIPPED_DELAY_ENABLED')) {
            return;
        }
        $days   = self::DELAY_SHIPPED_DELAY_DAYS;
        $idShop = (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            'SELECT o.id_order, o.reference, o.id_customer, o.id_shop,
                    c.email, c.firstname, c.lastname, c.id_lang
             FROM `' . $this->prefix . 'orders` o
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = o.id_customer
             WHERE c.active = 1 AND c.deleted = 0 AND o.valid = 1 AND o.id_shop = ' . $idShop . '
               AND EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'order_history` oh
                   JOIN `' . $this->prefix . 'order_state` os ON os.id_order_state = oh.id_order_state
                   WHERE oh.id_order = o.id_order AND os.shipped = 1
                     AND oh.date_add <= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'order_history` oh
                   JOIN `' . $this->prefix . 'order_state` os ON os.id_order_state = oh.id_order_state
                   WHERE oh.id_order = o.id_order
                     AND (os.delivery = 1 OR oh.id_order_state = ' . (int) \Configuration::get('PS_OS_CANCELED') . ')
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = o.id_customer AND bs.template = \'order_shipped_delay\'
                     AND bs.ref_id = o.id_order AND bs.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            try {
                $newDate = \NeriaTools::formatDate('+7 days', \Language::getIsoById((int) $r['id_lang']) ?: 'fr');
                $this->send(
                    'order_shipped_delay',
                    $r,
                    [
                        '{order_name}'        => $r['reference'],
                        '{new_shipping_date}' => $newDate,
                    ],
                    (int) $r['id_order']
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.behavioral_send_error', [
                        'template' => 'order_shipped_delay',
                        'customer' => $r['id_customer'] ?? '?',
                        'error'    => $e->getMessage(),
                    ]),
                    'order_shipped_delay',
                    'BehavioralCron'
                );
            }
        }
    }

    // ============================================================
    // QUOTE EXPIRY REMINDERS — devis B2B (J-2, Jour J, prolongation)
    // Ref_id = id_quote (table neria_quote)
    // ============================================================

    public function getQuoteStats(): array
    {
        $idShop = (int) \Context::getContext()->shop->id;
        $row = $this->db->getRow(
            'SELECT
                COUNT(*)                                          AS total_quotes,
                SUM(status = \'won\')                            AS quotes_won,
                SUM(status = \'active\')                         AS quotes_active,
                SUM(status IN (\'expired\',\'lost\'))            AS quotes_lost,
                COALESCE(SUM(CASE WHEN status = \'won\' THEN quote_total ELSE 0 END), 0) AS revenue_won
             FROM `' . $this->prefix . 'neria_quote`
             WHERE id_shop = ' . $idShop
        );

        $total   = (int)   ($row['total_quotes']  ?? 0);
        $won     = (int)   ($row['quotes_won']     ?? 0);
        $active  = (int)   ($row['quotes_active']  ?? 0);
        $lost    = (int)   ($row['quotes_lost']    ?? 0);
        $revenue = (float) ($row['revenue_won']    ?? 0.0);

        return [
            'total_quotes'  => $total,
            'quotes_won'    => $won,
            'quotes_active' => $active,
            'quotes_lost'   => $lost,
            'revenue_won'   => round($revenue, 2),
            'win_rate'      => $total > 0 ? round($won / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Publique (contrairement aux autres send*()) : appelée directement par
     * HealthCheckManager::checkQuoteRemindersStuck() pour forcer l'envoi des
     * relances en retard sans attendre le prochain passage du cron complet.
     */
    public function sendQuoteExpiryReminders(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_QUOTE_REMINDERS_ENABLED')) {
            return;
        }

        $idShop = (int) \Context::getContext()->shop->id;

        // ── 1. Rappel 48h avant expiration ───────────────────────
        $rows48h = $this->db->executeS(
            'SELECT q.id_quote, q.id_customer, q.id_shop, q.quote_ref, q.quote_total,
                    q.id_currency, q.expiry_date,
                    c.email, c.firstname, c.lastname, c.id_lang
             FROM `' . $this->prefix . 'neria_quote` q
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = q.id_customer
             WHERE q.status = \'active\' AND q.sent_48h = 0 AND q.id_shop = ' . $idShop . '
               AND DATE(q.expiry_date) = DATE(DATE_ADD(NOW(), INTERVAL 2 DAY))
               AND c.active = 1 AND c.deleted = 0
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );
        foreach ((array) $rows48h as $r) {
            // Try/catch par ligne : sans ça, une exception sur UN devis (ex.
            // deadlock MySQL — ce cron tourne en même temps que
            // SegmentManager/ChurnScoreManager sur les mêmes tables) faisait
            // remonter l'exception hors de sendQuoteExpiryReminders() entière,
            // empêchant silencieusement les sections 2 et 3 ci-dessous de
            // s'exécuter CE jour-là pour TOUS les clients, sans alerte dédiée.
            try {
                $this->sendQuoteEmail('quote_expiry_48h', $r);
                $this->db->execute(
                    'UPDATE `' . $this->prefix . 'neria_quote`
                     SET sent_48h = 1, date_upd = NOW() WHERE id_quote = ' . (int) $r['id_quote']
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.quote_reminder_error', [
                        'quote' => $r['quote_ref'] ?? ('#' . ($r['id_quote'] ?? '?')),
                        'error' => $e->getMessage(),
                    ]),
                    'quote_expiry_48h',
                    'BehavioralCron'
                );
            }
        }

        // ── 2. Rappel Jour J ──────────────────────────────────────
        $rowsDay = $this->db->executeS(
            'SELECT q.id_quote, q.id_customer, q.id_shop, q.quote_ref, q.quote_total,
                    q.id_currency, q.expiry_date,
                    c.email, c.firstname, c.lastname, c.id_lang
             FROM `' . $this->prefix . 'neria_quote` q
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = q.id_customer
             WHERE q.status = \'active\' AND q.sent_day = 0 AND q.id_shop = ' . $idShop . '
               AND DATE(q.expiry_date) = CURDATE()
               AND c.active = 1 AND c.deleted = 0
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );
        foreach ((array) $rowsDay as $r) {
            try {
                $this->sendQuoteEmail('quote_expiry_day', $r);
                $this->db->execute(
                    'UPDATE `' . $this->prefix . 'neria_quote`
                     SET sent_day = 1, date_upd = NOW() WHERE id_quote = ' . (int) $r['id_quote']
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.quote_reminder_error', [
                        'quote' => $r['quote_ref'] ?? ('#' . ($r['id_quote'] ?? '?')),
                        'error' => $e->getMessage(),
                    ]),
                    'quote_expiry_day',
                    'BehavioralCron'
                );
            }
        }

        // ── 3. Offre de prolongation (J+1 ou après) ──────────────
        $rowsExt = $this->db->executeS(
            'SELECT q.id_quote, q.id_customer, q.id_shop, q.quote_ref, q.quote_total,
                    q.id_currency, q.expiry_date,
                    c.email, c.firstname, c.lastname, c.id_lang
             FROM `' . $this->prefix . 'neria_quote` q
             JOIN `' . $this->prefix . 'customer` c ON c.id_customer = q.id_customer
             WHERE q.status = \'active\' AND q.sent_extension = 0 AND q.id_shop = ' . $idShop . '
               AND DATE(q.expiry_date) < CURDATE()
               AND c.active = 1 AND c.deleted = 0
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );
        foreach ((array) $rowsExt as $r) {
            try {
                $this->sendQuoteEmail('quote_extension_offer', $r, true);
                $this->db->execute(
                    'UPDATE `' . $this->prefix . 'neria_quote`
                     SET sent_extension = 1, status = \'expired\', date_upd = NOW()
                     WHERE id_quote = ' . (int) $r['id_quote']
                );
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.quote_reminder_error', [
                        'quote' => $r['quote_ref'] ?? ('#' . ($r['id_quote'] ?? '?')),
                        'error' => $e->getMessage(),
                    ]),
                    'quote_extension_offer',
                    'BehavioralCron'
                );
            }
        }
    }

    private function sendQuoteEmail(string $template, array $r, bool $withExtension = false): void
    {
        $idCurrency = (int) ($r['id_currency'] ?: \Configuration::get('PS_CURRENCY_DEFAULT'));
        $currency   = new \Currency($idCurrency);
        $idLang     = (int) ($r['id_lang'] ?? 0);
        $langIso    = \Language::getIsoById($idLang) ?: 'fr';
        $total      = \NeriaTools::displayPrice((float) $r['quote_total'], $currency, $idLang ?: null);
        $expiry     = \NeriaTools::formatDate($r['expiry_date'], $langIso);
        $newExpiry  = $withExtension
            ? \NeriaTools::formatDate($r['expiry_date'] . ' +7 days', $langIso)
            : '';

        $this->send(
            $template,
            $r,
            [
                '{quote_ref}'       => $r['quote_ref'],
                '{quote_total}'     => $total,
                '{expiry_date}'     => $expiry,
                '{new_expiry_date}' => $newExpiry,
                '{quote_url}'       => \Tools::getShopDomainSsl(true),
            ],
            (int) $r['id_quote']
        );
    }

    // ============================================================
    // RECONCILIATION POST-REMBOURSEMENT — J+1 / J+3 / J+7
    // Déclenchée par actionOrderSlipAdd via OrderTriggersManager.
    // Le cron envoie chaque step quand sa date est atteinte.
    // Annulation si le client a repassé commande depuis le remboursement.
    // ============================================================

    private function sendRefundReconciliations(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_REFUND_RECONCILIATION_ENABLED')) {
            return;
        }

        $table  = $this->prefix . 'neria_reconciliation';
        $idShop = (int) \Context::getContext()->shop->id;
        $rows   = $this->db->executeS(
            "SELECT r.*, c.email, c.firstname, c.lastname, c.id_lang, c.id_shop AS c_shop
             FROM `{$table}` r
             JOIN `{$this->prefix}customer` c ON c.id_customer = r.id_customer
             WHERE r.status = 'active' AND r.id_shop = {$idShop}
               AND (
                   (r.sent_1 = 0 AND r.send_1_date <= CURDATE()) OR
                   (r.sent_1 = 1 AND r.sent_2 = 0 AND r.send_2_date <= CURDATE()) OR
                   (r.sent_1 = 1 AND r.sent_2 = 1 AND r.sent_3 = 0 AND r.send_3_date <= CURDATE())
               )
               AND c.active = 1 AND c.deleted = 0
             LIMIT " . self::MAX_BATCH_PER_RUN
        );

        foreach ((array) $rows as $r) {
            $idReconciliation = (int) $r['id_reconciliation'];
            $idCustomer       = (int) $r['id_customer'];
            $idOrder          = (int) $r['id_order'];

            try {
                // Annuler si le client a passé une nouvelle commande depuis le remboursement
                $hasReordered = (int) $this->db->getValue(
                    "SELECT COUNT(*) FROM `{$this->prefix}orders`
                     WHERE id_customer = {$idCustomer}
                       AND valid = 1
                       AND id_shop = {$idShop}
                       AND id_order > {$idOrder}"
                );
                if ($hasReordered > 0) {
                    $this->db->execute(
                        "UPDATE `{$table}` SET status = 'cancelled' WHERE id_reconciliation = {$idReconciliation}"
                    );
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.reconciliation_cancelled', ['id' => $idReconciliation, 'customer' => $idCustomer]),
                        'refund_reconciliation', 'BehavioralCron'
                    );
                    continue;
                }

                $customer = [
                    'id_customer' => $idCustomer,
                    'email'       => $r['email'],
                    'firstname'   => $r['firstname'],
                    'lastname'    => $r['lastname'],
                    'id_lang'     => $r['id_lang'],
                    'id_shop'     => $r['id_shop'],
                ];

                if (!$r['sent_1']) {
                    $this->send('refund_reconciliation_1', $customer, ['{order_name}' => ''], $idOrder);
                    $this->db->execute(
                        "UPDATE `{$table}` SET sent_1 = 1 WHERE id_reconciliation = {$idReconciliation}"
                    );
                } elseif (!$r['sent_2']) {
                    $this->send('refund_reconciliation_2', $customer, ['{order_name}' => ''], $idOrder);
                    $this->db->execute(
                        "UPDATE `{$table}` SET sent_2 = 1 WHERE id_reconciliation = {$idReconciliation}"
                    );
                } elseif (!$r['sent_3']) {
                    $this->send('refund_reconciliation_3', $customer, ['{order_name}' => ''], $idOrder);
                    $this->db->execute(
                        "UPDATE `{$table}` SET sent_3 = 1 WHERE id_reconciliation = {$idReconciliation}"
                    );
                }
            } catch (\Throwable $e) {
                // Try/catch par ligne — sans ça, une exception sur UNE relance
                // (ex. deadlock MySQL) empêchait toutes les lignes suivantes du
                // lot d'être traitées ce jour-là, sans alerte dédiée.
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.reconciliation_error', [
                        'id'    => $idReconciliation,
                        'error' => $e->getMessage(),
                    ]),
                    'refund_reconciliation',
                    'BehavioralCron'
                );
            }
        }
    }

    private function recalculatePropensityScores(): void
    {
        if (!class_exists('PropensityScoreManager') || !\Configuration::getGlobalValue('NERIA_PROPENSITY_ENABLED')) {
            return;
        }
        try {
            (new \PropensityScoreManager($this->module))->recalculateAll();
        } catch (\Throwable $e) {
            $this->watchdog()->error(\WatchdogManager::i18nMsg('watchdog.propensity_recalc_error', ['error' => $e->getMessage()]), '', 'BehavioralCron');
        }
    }

    private function sendLifespanReminders(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_LIFESPAN_ENABLED')) {
            return;
        }

        // Le nom produit récupéré ici n'est qu'un filet de secours (voir plus bas,
        // le nom réel est re-résolu dans la langue du client) : on utilise la
        // langue par défaut de la boutique plutôt qu'un id_lang=1 codé en dur,
        // pour ne pas exclure des produits via l'INNER JOIN si la langue 1
        // n'est pas installée/active sur cette boutique.
        $defaultLang = (int) \Configuration::get('PS_LANG_DEFAULT') ?: 1;

        $table    = $this->prefix . 'neria_product_lifespan';
        $products = $this->db->executeS(
            "SELECT pl.id_product, pl.id_shop, pl.lifespan_days, pl.alert_days,
                    p.reference, pl2.name AS product_name
             FROM `{$table}` pl
             JOIN `{$this->prefix}product` p ON p.id_product = pl.id_product
             LEFT JOIN `{$this->prefix}product_lang` pl2
                  ON pl2.id_product = pl.id_product AND pl2.id_lang = {$defaultLang} AND pl2.id_shop = pl.id_shop"
        ) ?: [];

        if (empty($products)) {
            return;
        }

        // MAX_BATCH_PER_RUN était déjà appliqué à la requête clients de CHAQUE
        // produit individuellement, mais rien ne plafonnait le total sur
        // l'ensemble des produits configurés — une boutique avec beaucoup de
        // produits à durée de vie pouvait générer plusieurs fois ce volume de
        // requêtes en une seule exécution du cron. Compteur global en plus.
        $totalSentThisRun = 0;

        foreach ($products as $product) {
            if ($totalSentThisRun >= self::MAX_BATCH_PER_RUN) {
                break;
            }
            $idProduct   = (int) $product['id_product'];
            $lifespanDays = (int) $product['lifespan_days'];
            $alertDays   = (int) $product['alert_days'];
            $targetDay   = $lifespanDays - $alertDays;

            if ($targetDay <= 0) {
                // alert_days >= lifespan_days (mauvaise config) : DATE_SUB deviendrait
                // une addition et chercherait des achats dans le futur — on ignore.
                continue;
            }

            // Chercher les clients ayant acheté ce produit il y a exactement $targetDay jours
            $customers = $this->db->executeS(
                "SELECT DISTINCT c.id_customer, c.email, c.firstname, c.lastname,
                        c.id_lang, o.id_shop, o.id_order,
                        MAX(o.date_add) AS purchase_date
                 FROM `{$this->prefix}orders` o
                 JOIN `{$this->prefix}order_detail` od ON od.id_order = o.id_order
                 JOIN `{$this->prefix}customer` c ON c.id_customer = o.id_customer
                 WHERE od.product_id = {$idProduct}
                   AND o.valid = 1
                   AND o.id_shop = {$product['id_shop']}
                   AND c.active = 1 AND c.deleted = 0
                   AND DATE(o.date_add) = DATE_SUB(CURDATE(), INTERVAL {$targetDay} DAY)
                 GROUP BY c.id_customer, o.id_shop, o.id_order
                 LIMIT " . self::MAX_BATCH_PER_RUN
            ) ?: [];

            foreach ($customers as $customer) {
                if ($totalSentThisRun >= self::MAX_BATCH_PER_RUN) {
                    break;
                }
                try {
                    // Déduplication via neria_behavioral_sent
                    $alreadySent = (int) $this->db->getValue(
                        "SELECT COUNT(*) FROM `{$this->prefix}neria_behavioral_sent`
                         WHERE id_customer = " . (int) $customer['id_customer'] . "
                           AND template = 'product_lifespan_reminder'
                           AND ref_id = {$idProduct}
                           AND id_shop = " . (int) $customer['id_shop']
                    );
                    if ($alreadySent > 0) {
                        continue;
                    }

                    // Annuler si le client a déjà racheté ce produit après son achat initial
                    $hasReordered = (int) $this->db->getValue(
                        "SELECT COUNT(*) FROM `{$this->prefix}orders` o
                         JOIN `{$this->prefix}order_detail` od ON od.id_order = o.id_order
                         WHERE o.id_customer = " . (int) $customer['id_customer'] . "
                           AND od.product_id = {$idProduct}
                           AND o.valid = 1
                           AND o.id_shop = " . (int) $customer['id_shop'] . "
                           AND o.id_order > " . (int) $customer['id_order']
                    );
                    if ($hasReordered > 0) {
                        continue;
                    }

                    $idLang      = (int) $customer['id_lang'] ?: (int) \Configuration::get('PS_LANG_DEFAULT');
                    $productName = $this->db->getValue(
                        "SELECT name FROM `{$this->prefix}product_lang`
                         WHERE id_product = {$idProduct} AND id_lang = {$idLang} LIMIT 1"
                    ) ?: $product['product_name'];

                    $productUrl = \Context::getContext()->link->getProductLink(
                        $idProduct, null, null, null, $idLang, (int) $customer['id_shop']
                    );

                    $this->send('product_lifespan_reminder', $customer, [
                        '{product_name}'   => $productName,
                        '{product_url}'    => $productUrl,
                        '{estimated_days}' => (string) $lifespanDays,
                    ], $idProduct);
                    $totalSentThisRun++;
                } catch (\Throwable $e) {
                    // Try/catch par client — sans ça, une exception sur UN
                    // client empêchait les clients suivants (et les produits
                    // suivants de la boucle englobante) d'être traités.
                    $this->watchdog()->error(
                        \WatchdogManager::i18nMsg('watchdog.lifespan_reminder_error', [
                            'product' => $idProduct,
                            'error'   => $e->getMessage(),
                        ]),
                        'product_lifespan_reminder',
                        'BehavioralCron'
                    );
                }
            }
        }
    }

    // ============================================================
    // RELATIONSHIP ANNIVERSARY — stats BO
    // ============================================================

    public function getRelationshipAnniversaryStats(): array
    {
        // Emails envoyés
        $sent = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->prefix . 'neria_behavioral_sent`
             WHERE template = \'relationship_anniversary\''
        );

        // Commandes passées dans les 48h suivant l'envoi (attribution last-click)
        // neria_behavioral_sent n'a pas de colonne id_shop, mais orders en a une :
        // sans filtre, une commande d'une autre boutique (install multi-boutiques)
        // se retrouvait attribuée aux stats de la boutique courante.
        $idShop = (int) \Context::getContext()->shop->id;
        $row = $this->db->getRow(
            'SELECT COUNT(DISTINCT o.id_order) AS orders_attributed,
                    COALESCE(SUM(o.total_paid_tax_incl), 0) AS revenue_attributed
             FROM `' . $this->prefix . 'neria_behavioral_sent` bs
             JOIN `' . $this->prefix . 'orders` o
                  ON o.id_customer = bs.id_customer
                  AND o.valid = 1
                  AND o.id_shop = ' . $idShop . '
                  AND o.date_add BETWEEN bs.sent_at AND DATE_ADD(bs.sent_at, INTERVAL 48 HOUR)
             WHERE bs.template = \'relationship_anniversary\''
        );

        $orders  = (int)   ($row['orders_attributed']  ?? 0);
        $revenue = (float) ($row['revenue_attributed'] ?? 0.0);

        return [
            'emails_sent'        => $sent,
            'orders_attributed'  => $orders,
            'revenue_attributed' => round($revenue, 2),
            'avg_order_value'    => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
        ];
    }

    // ============================================================
    // RELATIONSHIP ANNIVERSARY — chaque année à la date du 1er achat
    // Dédup : un envoi par client par année (ref_id = année courante)
    // ============================================================

    private function sendRelationshipAnniversaries(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED')) {
            return;
        }

        // Clients dont la date du 1er achat tombe aujourd'hui (mois+jour)
        // et qui ont passé commande il y a au moins 1 an.
        $idShop = (int) \Context::getContext()->shop->id;
        // Année calculée côté PHP (et non YEAR(NOW()) côté MySQL) pour rester
        // cohérente avec l'insertion plus bas dans send() qui utilise
        // (int) date('Y') — sans ça, un décalage de fuseau horaire entre PHP
        // et la session MySQL pouvait faire diverger les deux valeurs autour
        // de minuit le 31/12, cassant la déduplication et permettant un
        // second envoi du même email d'anniversaire de relation.
        $currentYear = (int) date('Y');
        $rows = $this->db->executeS(
            'SELECT c.id_customer, c.email, c.firstname, c.lastname, c.id_lang, c.id_shop,
                    MIN(o.date_add) AS first_order_date,
                    MIN(o.id_order) AS id_first_order,
                    TIMESTAMPDIFF(YEAR, MIN(o.date_add), NOW()) AS years
             FROM `' . $this->prefix . 'customer` c
             JOIN `' . $this->prefix . 'orders` o ON o.id_customer = c.id_customer AND o.valid = 1 AND o.id_shop = ' . $idShop . '
             WHERE c.active = 1 AND c.deleted = 0
             GROUP BY c.id_customer
             HAVING DATE_FORMAT(MIN(o.date_add), \'%m-%d\') = DATE_FORMAT(NOW(), \'%m-%d\')
               AND TIMESTAMPDIFF(YEAR, MIN(o.date_add), NOW()) >= 1
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = c.id_customer
                     AND bs.template = \'relationship_anniversary\'
                     AND bs.ref_id = ' . $currentYear . '
                     AND bs.id_shop = ' . $idShop . '
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs2
                   WHERE bs2.id_customer = c.id_customer
                     AND bs2.template = \'first_anniversary\'
                     AND YEAR(bs2.sent_at) = ' . $currentYear . '
                     AND bs2.id_shop = ' . $idShop . '
               )
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        $rows = (array) $rows;

        if (empty($rows)) {
            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.anniversary_none_eligible'),
                'relationship_anniversary',
                'BehavioralCron'
            );
            return;
        }

        $sent   = 0;
        $errors = 0;

        foreach ($rows as $r) {
            $years      = (int) $r['years'];
            $yearsLabel = $this->yearsLabel($years, (int) $r['id_lang']);

            try {
                $this->send(
                    'relationship_anniversary',
                    $r,
                    ['{years_label}' => $yearsLabel],
                    (int) date('Y')
                );
                $sent++;
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.anniversary_sent', [
                        'email'     => $r['email'] ?? '?',
                        'firstname' => $r['firstname'] ?? '',
                        'years'     => $yearsLabel,
                    ]),
                    'relationship_anniversary',
                    'BehavioralCron'
                );
            } catch (\Throwable $e) {
                $errors++;
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.anniversary_error', [
                        'email' => $r['email'] ?? '?',
                        'error' => $e->getMessage(),
                    ]),
                    'relationship_anniversary',
                    'BehavioralCron'
                );
            }
        }

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.anniversary_summary', ['sent' => $sent, 'errors' => $errors]),
            'relationship_anniversary',
            'BehavioralCron'
        );
    }

    // ============================================================
    // GHOST CART — même produit ajouté 3+ fois sans achat
    // Ref_id = id_product (un email unique par produit par client)
    // ============================================================

    private function sendGhostCarts(): void
    {
        if (!\Configuration::getGlobalValue('NERIA_GHOST_CART_ENABLED')) {
            return;
        }

        $idShop = (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            'SELECT cp.id_product, ca.id_customer, ca.id_shop,
                    c.email, c.firstname, c.lastname, c.id_lang,
                    COUNT(DISTINCT ca.id_cart) AS times_added
             FROM `' . $this->prefix . 'cart_product` cp
             JOIN `' . $this->prefix . 'cart` ca
                  ON ca.id_cart = cp.id_cart AND ca.id_customer > 0 AND ca.id_shop = ' . $idShop . '
             JOIN `' . $this->prefix . 'customer` c
                  ON c.id_customer = ca.id_customer AND c.active = 1 AND c.deleted = 0
             WHERE ca.date_upd >= DATE_SUB(NOW(), INTERVAL 60 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'orders` o
                   JOIN `' . $this->prefix . 'order_detail` od ON od.id_order = o.id_order
                   WHERE o.id_customer = ca.id_customer
                     AND od.product_id = cp.id_product
                     AND o.valid = 1
               )
               AND NOT EXISTS (
                   SELECT 1 FROM `' . $this->prefix . 'neria_behavioral_sent` bs
                   WHERE bs.id_customer = ca.id_customer
                     AND bs.template = \'ghost_cart\'
                     AND bs.ref_id = cp.id_product
                     AND bs.id_shop = ' . $idShop . '
               )
             GROUP BY cp.id_product, ca.id_customer, ca.id_shop,
                      c.email, c.firstname, c.lastname, c.id_lang
             HAVING COUNT(DISTINCT ca.id_cart) >= 3
             LIMIT ' . self::MAX_BATCH_PER_RUN
        );

        if (empty($rows)) {
            return;
        }

        foreach ((array) $rows as $r) {
            $idProduct  = (int) $r['id_product'];
            $idLang     = (int) ($r['id_lang'] ?: \Configuration::get('PS_LANG_DEFAULT'));
            $idCustomer = (int) $r['id_customer'];

            $product = new \Product($idProduct, false, $idLang);
            if (!\Validate::isLoadedObject($product)) {
                continue;
            }

            // URL produit
            $productUrl = \Context::getContext()->link->getProductLink(
                $product, null, null, null, $idLang, (int) $r['id_shop']
            );

            // Image principale
            $cover    = \Product::getCover($idProduct);
            $imageUrl = '';
            if ($cover) {
                $imageUrl = \Context::getContext()->link->getImageLink(
                    $product->link_rewrite,
                    (int) $cover['id_image'],
                    \ImageType::getFormattedName('home')
                );
            }

            $this->send(
                'ghost_cart',
                $r,
                [
                    '{product_name}'  => $product->name,
                    '{product_url}'   => $productUrl,
                    '{product_image}' => $imageUrl,
                    '{product_price}' => \NeriaTools::displayPrice((float) $product->price, \Currency::getDefaultCurrency(), $idLang),
                    '{times_added}'   => (int) $r['times_added'],
                ],
                $idProduct
            );
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Envoie un email comportemental et enregistre l'envoi.
     *
     * @param string $template  Nom du template Neria
     * @param array  $customer  Ligne DB avec email, firstname, lastname, id_lang, id_shop
     * @param array  $extraVars Variables spécifiques au template
     * @param int    $refId     Identifiant de déduplication (id_order, id_cart, année…)
     */
    private function send(
        string $template,
        array $customer,
        array $extraVars,
        int $refId = 0
    ): void {
        $email = $customer['email'] ?? '?';

        try {
            $idLang = (int) $customer['id_lang'] ?: (int) \Configuration::get('PS_LANG_DEFAULT');
            $idShop = (int) ($customer['id_shop'] ?? \Context::getContext()->shop->id);
            $toName = trim($customer['firstname'] . ' ' . $customer['lastname']) ?: null;
            $idCust = (int) ($customer['id_customer'] ?? 0);

            // Vérifier les préférences email du client avant envoi
            if ($idCust > 0 && class_exists('PreferencesManager')) {
                $pm = new PreferencesManager($this->module);
                if (!$pm->isAllowed($idCust, $template)) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.send_cancelled_pref', ['id' => $idCust, 'template' => $template]),
                        $template,
                        'BehavioralCron'
                    );
                    return;
                }
            }

            // Vérifier que le template SOURCE Neria existe avant d'appeler Mail::Send.
            $coreFile = _PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/' . $template . '.html';
            if (!file_exists($coreFile)) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.template_missing', ['template' => $template]),
                    $template,
                    'BehavioralCron'
                );
                return;
            }

            // ── Fenêtre d'achat individuelle ─────────────────────────────
            // Si la feature est activée et que ce client a un pattern d'achat détecté,
            // on place l'email en queue plutôt que de l'envoyer immédiatement.
            if (
                \Configuration::getGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED')
                && class_exists('PurchaseWindowManager')
                && class_exists('QueueManager')
            ) {
                $preferredHour = (new \PurchaseWindowManager())->getPreferredHour((int) $customer['id_customer'], $idShop);
                if ($preferredHour !== null) {
                    (new \QueueManager($this->module))->enqueue($template, $customer, $extraVars, $refId, $preferredHour);
                    // Inscrire en dedup immédiatement : le cron ne repassera pas dessus demain.
                    $this->db->execute(
                        'INSERT IGNORE INTO `' . $this->prefix . 'neria_behavioral_sent`
                         (id_customer, template, ref_id, id_shop, sent_at)
                         VALUES (' . (int) $customer['id_customer'] . ', \'' . pSQL($template) . '\', '
                        . (int) $refId . ', ' . $idShop . ', NOW())'
                    );
                    return;
                }
            }
            // ─────────────────────────────────────────────────────────────

            $vars = array_merge(
                [
                    '{firstname}'   => $customer['firstname'],
                    '{lastname}'    => $customer['lastname'],
                    '{shop_name}'   => \Configuration::get('PS_SHOP_NAME'),
                    '{history_url}' => $this->historyUrl($idLang),
                ],
                $extraVars
            );

            $sent = \Mail::Send(
                $idLang,
                $template,
                '',
                $vars,
                $email,
                $toName,
                null, null, null, null,
                _PS_MODULE_DIR_ . 'neria/mails/',
                false,
                $idShop
            );

            if ($sent) {
                $this->db->execute(
                    'INSERT IGNORE INTO `' . $this->prefix . 'neria_behavioral_sent`
                     (id_customer, template, ref_id, id_shop, sent_at)
                     VALUES (' . (int) $customer['id_customer'] . ', \'' . pSQL($template) . '\', '
                    . (int) $refId . ', ' . $idShop . ', NOW())'
                );
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.send_ok', ['template' => $template, 'email' => $email, 'ref' => $refId]),
                    $template,
                    'BehavioralCron'
                );
            } else {
                $this->watchdog()->warning(
                    \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => $template, 'email' => $email]),
                    $template,
                    'BehavioralCron'
                );
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.send_exception', ['template' => $template, 'email' => $email, 'error' => $e->getMessage()]),
                $template,
                'BehavioralCron'
            );
        }
    }

    /**
     * Construit le résumé HTML des produits d'un panier pour {products}.
     */
    private function buildCartProducts(int $idCart): string
    {
        try {
            $rows = $this->db->executeS(
                'SELECT p.reference, pl.name, cp.quantity
                 FROM `' . $this->prefix . 'cart_product` cp
                 JOIN `' . $this->prefix . 'product` p ON p.id_product = cp.id_product
                 JOIN `' . $this->prefix . 'product_lang` pl
                      ON pl.id_product = cp.id_product
                     AND pl.id_lang = (SELECT id_lang FROM `' . $this->prefix . 'cart`
                                       WHERE id_cart = ' . $idCart . ' LIMIT 1)
                 WHERE cp.id_cart = ' . $idCart
            );
            if (!is_array($rows) || empty($rows)) {
                return '';
            }
            $lines = array_map(
                fn($r) => '<li>× ' . (int) $r['quantity'] . ' ' . htmlspecialchars($r['name']) . '</li>',
                $rows
            );
            return '<ul style="margin:0;padding:0 0 0 18px;">' . implode('', $lines) . '</ul>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Équivalent texte de buildCartProducts(), pour {products_txt} (fallback
     * TXT des templates abandoned_cart_1/2/3) — bug trouvé le 2026-07-13 via
     * un rapport de test externe : jamais généré jusqu'ici, seul {products}
     * (HTML) existait.
     */
    private function buildCartProductsTxt(int $idCart): string
    {
        try {
            $rows = $this->db->executeS(
                'SELECT p.reference, pl.name, cp.quantity
                 FROM `' . $this->prefix . 'cart_product` cp
                 JOIN `' . $this->prefix . 'product` p ON p.id_product = cp.id_product
                 JOIN `' . $this->prefix . 'product_lang` pl
                      ON pl.id_product = cp.id_product
                     AND pl.id_lang = (SELECT id_lang FROM `' . $this->prefix . 'cart`
                                       WHERE id_cart = ' . $idCart . ' LIMIT 1)
                 WHERE cp.id_cart = ' . $idCart
            );
            if (!is_array($rows) || empty($rows)) {
                return '';
            }
            $lines = array_map(
                fn($r) => '- ' . (int) $r['quantity'] . ' x ' . $r['name'],
                $rows
            );
            return implode("\n", $lines);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function yearsLabel(int $years, int $idLang): string
    {
        $iso = \Language::getIsoById($idLang) ?: 'en';
        $words = [
            'fr' => ['un an',       'deux ans',   'trois ans',    'quatre ans',   'cinq ans'],
            'en' => ['one year',    'two years',  'three years',  'four years',   'five years'],
            'de' => ['einem Jahr',  'zwei Jahren','drei Jahren',  'vier Jahren',  'fünf Jahren'],
            'it' => ['un anno',     'due anni',   'tre anni',     'quattro anni', 'cinque anni'],
            'es' => ['un año',      'dos años',   'tres años',    'cuatro años',  'cinco años'],
            'pt' => ['um ano',      'dois anos',  'três anos',    'quatro anos',  'cinco anos'],
            'br' => ['um ano',      'dois anos',  'três anos',    'quatro anos',  'cinco anos'],
            'gb' => ['one year',    'two years',  'three years',  'four years',   'five years'],
            'nl' => ['één jaar',    'twee jaar',  'drie jaar',    'vier jaar',    'vijf jaar'],
            'ru' => ['один год',    'два года',   'три года',     'четыре года',  'пять лет'],
            'tr' => ['bir yıl',     'iki yıl',    'üç yıl',       'dört yıl',     'beş yıl'],
            'sv' => ['ett år',      'två år',     'tre år',       'fyra år',      'fem år'],
            'da' => ['ét år',       'to år',      'tre år',       'fire år',      'fem år'],
            'no' => ['ett år',      'to år',      'tre år',       'fire år',      'fem år'],
            'ar' => ['سنة واحدة',  'سنتين',      'ثلاث سنوات',  'أربع سنوات',  'خمس سنوات'],
            'ja' => ['1年',         '2年',        '3年',          '4年',          '5年'],
            'ko' => ['1년',         '2년',        '3년',          '4년',          '5년'],
            'zh' => ['一年',        '两年',       '三年',         '四年',         '五年'],
            'tw' => ['一年',        '兩年',       '三年',         '四年',         '五年'],
        ];

        if (isset($words[$iso]) && $years >= 1 && $years <= 5) {
            return $words[$iso][$years - 1];
        }

        $suffixes = [
            'fr' => ' ans', 'es' => ' años', 'pt' => ' anos', 'br' => ' anos',
            'it' => ' anni', 'de' => ' Jahre', 'nl' => ' jaar', 'ru' => ' лет',
            'tr' => ' yıl', 'sv' => ' år', 'da' => ' år', 'no' => ' år',
            'ar' => ' سنوات',
        ];
        $suffix = $suffixes[$iso] ?? ' years';
        return $years . $suffix;
    }

    // ── Complétez votre look ─────────────────────────────────────────────

    private function sendLookCompletions(): void
    {
        if (!class_exists('LookCompletionManager')) return;
        try {
            $sent = (new \LookCompletionManager($this->module))->runDailyCheck();
            if ($sent > 0) {
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.look_completion_sent', ['n' => $sent]),
                    '', 'BehavioralCron'
                );
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.look_completion_error', ['error' => $e->getMessage()]),
                '', 'BehavioralCron'
            );
        }
    }

    // ── Complétion de collection ──────────────────────────────────────────

    private function sendCollectionCompletions(): void
    {
        if (!class_exists('CollectionManager')) return;
        try {
            $sent = (new \CollectionManager($this->module))->runDailyCheck();
            if ($sent > 0) {
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.collection_completion_sent', ['n' => $sent]),
                    '', 'BehavioralCron'
                );
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.collection_completion_error', ['error' => $e->getMessage()]),
                '', 'BehavioralCron'
            );
        }
    }

}
