<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — OrderTriggersManager
 *
 * Emails déclenchés par des événements de commande PrestaShop.
 * Chaque méthode publique correspond à un hook PS et envoie le template
 * Neria approprié via Mail::Send → hook actionEmailSendBefore → EmailRenderer.
 *
 * Templates gérés :
 *   milestone_order       — hookActionObjectOrderAddAfter (Xème commande)
 *   loyalty_tier_upgrade  — hookActionObjectOrderAddAfter (franchissement de palier fidélité)
 *   order_on_hold         — hookActionOrderStatusPostUpdate (statut custom bloquant)
 *   order_partial_shipped — hookActionOrderStatusPostUpdate (expédition partielle)
 *   refund_processed      — hookActionOrderSlipAdd (avoir/remboursement)
 *   return_received       — hookActionObjectOrderReturnAddAfter (retour marchandise)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderTriggersManager
{
    // Paliers de commandes pour le template milestone_order
    const MILESTONES = [5, 10, 25, 50, 100];

    // Ordinal localisé de chaque palier, pour {milestone_count} — utilisé
    // en tant qu'ADJECTIF juste avant "commande/order/..." dans le texte
    // traduit (ex. fr "votre {milestone_count} commande", ja "{milestone_count}
    // のご注文"). Table figée plutôt qu'un algorithme d'ordinaux générique :
    // MILESTONES est un ensemble fixe et restreint (5 valeurs), et plusieurs
    // langues (ar/ja/ko/zh/tw) ont des règles d'ordinaux trop spécifiques
    // (accord grammatical, compteurs dédiés) pour être fiables à calculer
    // dynamiquement — chaque valeur ci-dessous a été vérifiée manuellement
    // contre la phrase exacte de milestone_intro dans translations.json.
    const MILESTONE_ORDINALS = [
        'fr' => [5 => '5e', 10 => '10e', 25 => '25e', 50 => '50e', 100 => '100e'],
        'en' => [5 => '5th', 10 => '10th', 25 => '25th', 50 => '50th', 100 => '100th'],
        'gb' => [5 => '5th', 10 => '10th', 25 => '25th', 50 => '50th', 100 => '100th'],
        'de' => [5 => '5.', 10 => '10.', 25 => '25.', 50 => '50.', 100 => '100.'],
        'it' => [5 => '5°', 10 => '10°', 25 => '25°', 50 => '50°', 100 => '100°'],
        'es' => [5 => '5º', 10 => '10º', 25 => '25º', 50 => '50º', 100 => '100º'],
        'pt' => [5 => '5º', 10 => '10º', 25 => '25º', 50 => '50º', 100 => '100º'],
        'br' => [5 => '5º', 10 => '10º', 25 => '25º', 50 => '50º', 100 => '100º'],
        'ar' => [5 => 'الخامس', 10 => 'العاشر', 25 => 'الخامس والعشرون', 50 => 'الخمسون', 100 => 'المئة'],
        'ja' => [5 => '5回目', 10 => '10回目', 25 => '25回目', 50 => '50回目', 100 => '100回目'],
        'ko' => [5 => '5번째', 10 => '10번째', 25 => '25번째', 50 => '50번째', 100 => '100번째'],
        'zh' => [5 => '第5次', 10 => '第10次', 25 => '第25次', 50 => '第50次', 100 => '第100次'],
        'tw' => [5 => '第5次', 10 => '第10次', 25 => '第25次', 50 => '第50次', 100 => '第100次'],
        'ru' => [5 => '5-й', 10 => '10-й', 25 => '25-й', 50 => '50-й', 100 => '100-й'],
        'tr' => [5 => '5.', 10 => '10.', 25 => '25.', 50 => '50.', 100 => '100.'],
        'sv' => [5 => '5:e', 10 => '10:e', 25 => '25:e', 50 => '50:e', 100 => '100:e'],
        'no' => [5 => '5.', 10 => '10.', 25 => '25.', 50 => '50.', 100 => '100.'],
        'da' => [5 => '5.', 10 => '10.', 25 => '25.', 50 => '50.', 100 => '100.'],
        'nl' => [5 => '5e', 10 => '10e', 25 => '25e', 50 => '50e', 100 => '100e'],
    ];

    // Paliers fidélité : nb commandes → nom du tier
    const LOYALTY_TIERS = [
        3  => 'Bronze',
        10 => 'Silver',
        25 => 'Gold',
        50 => 'Platinum',
    ];

    // IDs des statuts PS standards (1–13) — on n'envoie order_on_hold /
    // order_partial_shipped que pour des statuts custom créés par le marchand
    const STANDARD_STATUS_IDS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13];

    private \Neria $module;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(\Neria $module)
    {
        $this->module = $module;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    /**
     * Résout {milestone_count} : ordinal localisé (cf. MILESTONE_ORDINALS)
     * si le palier et la langue sont couverts, repli sur le nombre brut
     * sinon (jamais de valeur vide envoyée dans un email réel).
     */
    private function formatMilestoneOrdinal(int $count, int $idLang): string
    {
        $iso = \Language::getIsoById($idLang) ?: 'fr';
        return self::MILESTONE_ORDINALS[$iso][$count] ?? (string) $count;
    }

    // ============================================================
    // MILESTONE_ORDER — Palier commandes atteint
    // Déclenché par : hookActionObjectOrderAddAfter
    // ============================================================

    public function handleNewOrder(\Order $order): void
    {
        $idCustomer = (int) $order->id_customer;
        if ($idCustomer <= 0) {
            return;
        }

        $customer = new \Customer($idCustomer);
        if (!\Validate::isLoadedObject($customer)) {
            return;
        }

        // \Order::getCustomerNbOrders() compte TOUTES les commandes (y compris
        // en attente de paiement, refusées ou annulées) — on ne veut compter que
        // les commandes valides pour les paliers milestone/fidélité, sinon un
        // client peut décrocher un palier (et sa récompense) sur des commandes
        // jamais réellement honorées.
        $count  = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `id_customer` = ' . $idCustomer . ' AND `valid` = 1'
        );
        $idLang = (int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT');
        $idShop = (int) $order->id_shop;
        $toName = trim($customer->firstname . ' ' . $customer->lastname) ?: null;
        $common = [
            '{firstname}' => $customer->firstname,
            '{lastname}'  => $customer->lastname,
            '{shop_name}' => \Configuration::get('PS_SHOP_NAME'),
        ];

        // milestone_order
        if (in_array($count, self::MILESTONES, true)) {
            try {
                $result = \Mail::Send(
                    $idLang, 'milestone_order', '',
                    array_merge($common, [
                        '{milestone_count}' => $this->formatMilestoneOrdinal($count, $idLang),
                        '{order_count}'     => (string) $count,
                    ]),
                    $customer->email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.milestone_sent', ['count' => $count, 'email' => $customer->email]),
                        'milestone_order', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'milestone_order', 'email' => $customer->email]),
                        'milestone_order', 'OrderTriggers'
                    );
                }
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.milestone_error', ['count' => $count, 'email' => $customer->email, 'error' => $e->getMessage()]),
                    'milestone_order', 'OrderTriggers'
                );
            }
        }

        // loyalty_tier_upgrade
        if (isset(self::LOYALTY_TIERS[$count])) {
            try {
                $tierName   = self::LOYALTY_TIERS[$count];
                $historyUrl = \Context::getContext()->link->getPageLink('history', true, $idLang);

                $result = \Mail::Send(
                    $idLang, 'loyalty_tier_upgrade', '',
                    array_merge($common, [
                        '{new_tier_name}' => $tierName,
                        '{history_url}'   => $historyUrl,
                    ]),
                    $customer->email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.loyalty_tier_sent', ['tier' => $tierName, 'email' => $customer->email]),
                        'loyalty_tier_upgrade', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'loyalty_tier_upgrade', 'email' => $customer->email]),
                        'loyalty_tier_upgrade', 'OrderTriggers'
                    );
                }
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.loyalty_tier_error', ['tier' => $tierName, 'email' => $customer->email, 'error' => $e->getMessage()]),
                    'loyalty_tier_upgrade', 'OrderTriggers'
                );
            }
        }
    }

    // ============================================================
    // ORDER_ON_HOLD + ORDER_PARTIAL_SHIPPED
    // Déclenché par : hookActionOrderStatusPostUpdate
    //
    // Ne se déclenche QUE pour des statuts custom (ID > 13).
    // Le marchand crée ses propres statuts avec les flags appropriés :
    //   order_on_hold         → send_email=1, paid=0, shipped=0, delivery=0
    //   order_partial_shipped → shipped=1, delivery=0
    // ============================================================

    public function handleStatusChange(
        \OrderState $newStatus,
        \OrderState $oldStatus,
        int $idOrder
    ): void {
        try {
            // Ignorer tous les statuts standards PrestaShop
            if (in_array((int) $newStatus->id, self::STANDARD_STATUS_IDS, true)) {
                return;
            }

            $order = new \Order($idOrder);
            if (!\Validate::isLoadedObject($order)) {
                return;
            }

            $customer = new \Customer((int) $order->id_customer);
            if (!\Validate::isLoadedObject($customer)) {
                return;
            }

            $idLang = (int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT');
            $email  = $customer->email;
            $toName = trim($customer->firstname . ' ' . $customer->lastname) ?: null;
            $idShop = (int) $order->id_shop;
            $common = [
                '{firstname}'  => $customer->firstname,
                '{lastname}'   => $customer->lastname,
                '{order_name}' => $order->reference,
                '{shop_name}'  => \Configuration::get('PS_SHOP_NAME'),
            ];

            // order_partial_shipped : expédition partielle
            if ($newStatus->shipped && !$newStatus->delivery && !$oldStatus->shipped) {
                $result = \Mail::Send(
                    $idLang, 'order_partial_shipped', '',
                    array_merge($common, $this->buildShippedItemsVars($order)),
                    $email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.partial_shipped_sent', ['order' => $order->reference, 'email' => $email]),
                        'order_partial_shipped', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'order_partial_shipped', 'email' => $email]),
                        'order_partial_shipped', 'OrderTriggers'
                    );
                }
                return;
            }

            // order_on_hold : statut bloquant custom
            if (
                $newStatus->send_email
                && !$newStatus->paid
                && !$newStatus->shipped
                && !$newStatus->delivery
            ) {
                $statusName = is_array($newStatus->name)
                    ? ($newStatus->name[$idLang] ?? reset($newStatus->name))
                    : (string) $newStatus->name;

                $result = \Mail::Send(
                    $idLang, 'order_on_hold', '',
                    array_merge($common, ['{hold_reason}' => $statusName]),
                    $email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.order_on_hold_sent', ['status' => $statusName, 'order' => $order->reference, 'email' => $email]),
                        'order_on_hold', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'order_on_hold', 'email' => $email]),
                        'order_on_hold', 'OrderTriggers'
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.status_change_error', ['order' => $idOrder, 'error' => $e->getMessage()]),
                '', 'OrderTriggers'
            );
        }
    }

    // ============================================================
    // REFUND_PROCESSED — Avoir / remboursement créé
    // Déclenché par : hookActionOrderSlipAdd
    // ============================================================

    public function handleRefund(\Order $order, array $productList): void
    {
        try {
            $customer = new \Customer((int) $order->id_customer);
            if (!\Validate::isLoadedObject($customer)) {
                return;
            }

            $idLang = (int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT');

            // Montant total remboursé depuis la liste des produits
            $amount = 0.0;
            foreach ($productList as $p) {
                $amount += (float) ($p['unit_price'] ?? 0) * (int) ($p['quantity'] ?? 0);
            }
            $currency = new \Currency((int) $order->id_currency);
            $formatted = \Tools::displayPrice($amount, $currency);

            $result = \Mail::Send(
                $idLang,
                'refund_processed',
                '',
                [
                    '{firstname}'     => $customer->firstname,
                    '{lastname}'      => $customer->lastname,
                    '{order_name}'    => $order->reference,
                    '{refund_amount}' => $formatted,
                    '{shop_name}'     => \Configuration::get('PS_SHOP_NAME'),
                ],
                $customer->email,
                trim($customer->firstname . ' ' . $customer->lastname) ?: null,
                null, null, null, null,
                _PS_MODULE_DIR_ . 'neria/mails/',
                false,
                (int) $order->id_shop
            );

            if ($result) {
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.refund_sent', ['amount' => $formatted, 'order' => $order->reference, 'email' => $customer->email]),
                    'refund_processed', 'OrderTriggers'
                );
            } else {
                $this->watchdog()->warning(
                    \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'refund_processed', 'email' => $customer->email]),
                    'refund_processed', 'OrderTriggers'
                );
            }

            // ── Planifier la séquence de réconciliation (J+1/J+3/J+7) ──
            // Une seule séquence par commande (UNIQUE KEY uniq_order).
            // INSERT IGNORE évite les doublons si l'admin crée plusieurs avoirs.
            if (\Configuration::getGlobalValue('NERIA_REFUND_RECONCILIATION_ENABLED')) {
                $db = \Db::getInstance();
                $db->execute(
                    'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'neria_reconciliation`
                     (id_order, id_customer, id_shop, send_1_date, send_2_date, send_3_date, date_add)
                     VALUES (
                         ' . (int) $order->id . ',
                         ' . (int) $customer->id . ',
                         ' . (int) $order->id_shop . ',
                         DATE_ADD(CURDATE(), INTERVAL 1 DAY),
                         DATE_ADD(CURDATE(), INTERVAL 3 DAY),
                         DATE_ADD(CURDATE(), INTERVAL 7 DAY),
                         NOW()
                     )'
                );
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.refund_error', ['order' => $order->reference, 'error' => $e->getMessage()]),
                'refund_processed', 'OrderTriggers'
            );
        }
    }

    // ============================================================
    // RETURN_RECEIVED — Retour marchandise enregistré
    // Déclenché par : hookActionObjectOrderReturnAddAfter
    // ============================================================

    public function handleReturn(\OrderReturn $orderReturn): void
    {
        try {
            $order = new \Order((int) $orderReturn->id_order);
            if (!\Validate::isLoadedObject($order)) {
                return;
            }

            $customer = new \Customer((int) $order->id_customer);
            if (!\Validate::isLoadedObject($customer)) {
                return;
            }

            $idLang = (int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT');

            // Résumé des produits retournés
            $rows = \Db::getInstance()->executeS(
                'SELECT od.product_name, ord.product_quantity
                 FROM `' . _DB_PREFIX_ . 'order_return_detail` ord
                 INNER JOIN `' . _DB_PREFIX_ . 'order_detail` od
                     ON od.id_order_detail = ord.id_order_detail
                 WHERE ord.id_order_return = ' . (int) $orderReturn->id
            );
            $summary = '';
            if (is_array($rows) && !empty($rows)) {
                $lines = array_map(
                    fn($r) => '× ' . (int) $r['product_quantity'] . ' ' . $r['product_name'],
                    $rows
                );
                $summary = implode("\n", $lines);
            }

            $result = \Mail::Send(
                $idLang,
                'return_received',
                '',
                [
                    '{firstname}'     => $customer->firstname,
                    '{lastname}'      => $customer->lastname,
                    '{order_name}'    => $order->reference,
                    '{meta_products}' => $summary,
                    '{shop_name}'     => \Configuration::get('PS_SHOP_NAME'),
                ],
                $customer->email,
                trim($customer->firstname . ' ' . $customer->lastname) ?: null,
                null, null, null, null,
                _PS_MODULE_DIR_ . 'neria/mails/',
                false,
                (int) $order->id_shop
            );

            if ($result) {
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.return_sent', ['return' => $orderReturn->id, 'order' => $order->reference, 'email' => $customer->email]),
                    'return_received', 'OrderTriggers'
                );
            } else {
                $this->watchdog()->warning(
                    \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'return_received', 'email' => $customer->email]),
                    'return_received', 'OrderTriggers'
                );
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.return_error', ['return' => $orderReturn->id, 'error' => $e->getMessage()]),
                'return_received', 'OrderTriggers'
            );
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Construit {shipped_items} (HTML, <br> entre les lignes) et
     * {shipped_items_txt} (texte brut, \n) pour order_partial_shipped — la
     * seule variable initialement câblée ({shipped_items}) avait deux
     * défauts : jamais de variante _txt (le .txt affichait le placeholder
     * brut) et un formatage à base de "\n" seul, invisible dans un email
     * HTML sans <br>. Ajoute aussi le transporteur/numéro de suivi réels
     * (ps_order_carrier) — absents de la première version qui ne listait
     * que les produits, sans aucune information d'expédition.
     *
     * @return array{'{shipped_items}': string, '{shipped_items_txt}': string}
     */
    private function buildShippedItemsVars(\Order $order): array
    {
        try {
            $products = $order->getProducts();
            $productLines = is_array($products)
                ? array_map(
                    fn($p) => '× ' . (int) $p['product_quantity'] . ' ' . $p['product_name'],
                    $products
                )
                : [];

            $carriers = \Db::getInstance()->executeS(
                'SELECT oc.tracking_number, c.name AS carrier_name
                 FROM `' . _DB_PREFIX_ . 'order_carrier` oc
                 LEFT JOIN `' . _DB_PREFIX_ . 'carrier` c ON c.id_carrier = oc.id_carrier
                 WHERE oc.id_order = ' . (int) $order->id . '
                 ORDER BY oc.date_add ASC'
            );

            $carrierLines = [];
            if (is_array($carriers)) {
                $total = count($carriers);
                foreach ($carriers as $i => $row) {
                    $label = ($total > 1)
                        ? sprintf('Colis %d/%d', $i + 1, $total)
                        : 'Colis';
                    $carrierName = trim((string) ($row['carrier_name'] ?? '')) ?: '—';
                    $tracking    = trim((string) ($row['tracking_number'] ?? ''));
                    $carrierLines[] = $tracking !== ''
                        ? sprintf('%s — %s %s', $label, $carrierName, $tracking)
                        : sprintf('%s — %s', $label, $carrierName);
                }
            }

            $allLines = array_merge($productLines, $carrierLines);
            if (empty($allLines)) {
                return ['{shipped_items}' => '', '{shipped_items_txt}' => ''];
            }

            return [
                '{shipped_items}'     => '<p>' . implode('</p><p>', array_map('htmlspecialchars', $allLines)) . '</p>',
                '{shipped_items_txt}' => implode("\n", $allLines),
            ];
        } catch (\Throwable $e) {
            return ['{shipped_items}' => '', '{shipped_items_txt}' => ''];
        }
    }

}
