<?php
/**
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

        $count  = (int) \Order::getCustomerNbOrders($idCustomer);
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
                    array_merge($common, ['{order_count}' => (string) $count]),
                    $customer->email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        "milestone_order palier {$count} envoyé à {$customer->email}",
                        'milestone_order', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        "milestone_order palier {$count} : échec silencieux pour {$customer->email} — Mail::Send() a retourné false. Vérifiez la configuration SMTP.",
                        'milestone_order', 'OrderTriggers'
                    );
                }
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    "milestone_order palier {$count} : erreur pour {$customer->email} — {$e->getMessage()}",
                    'milestone_order', 'OrderTriggers'
                );
            }
        }

        // loyalty_tier_upgrade
        if (isset(self::LOYALTY_TIERS[$count])) {
            try {
                $tierName   = self::LOYALTY_TIERS[$count];
                $historyUrl = \Context::getContext()->link->getPageLink('history', true);

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
                        "loyalty_tier_upgrade tier {$tierName} envoyé à {$customer->email}",
                        'loyalty_tier_upgrade', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        "loyalty_tier_upgrade tier {$tierName} : échec silencieux pour {$customer->email} — Mail::Send() a retourné false. Vérifiez la configuration SMTP.",
                        'loyalty_tier_upgrade', 'OrderTriggers'
                    );
                }
            } catch (\Throwable $e) {
                $this->watchdog()->error(
                    "loyalty_tier_upgrade tier {$tierName} : erreur pour {$customer->email} — {$e->getMessage()}",
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
                    array_merge($common, ['{shipped_items}' => $this->buildItemsSummary($order)]),
                    $email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        "order_partial_shipped commande #{$order->reference} envoyé à {$email}",
                        'order_partial_shipped', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        "order_partial_shipped commande #{$order->reference} : échec silencieux pour {$email} — Mail::Send() a retourné false. Vérifiez la configuration SMTP.",
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
                        "order_on_hold « {$statusName} » commande #{$order->reference} envoyé à {$email}",
                        'order_on_hold', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        "order_on_hold « {$statusName} » : échec silencieux pour {$email} — Mail::Send() a retourné false. Vérifiez la configuration SMTP.",
                        'order_on_hold', 'OrderTriggers'
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                "handleStatusChange commande #{$idOrder} : erreur inattendue — {$e->getMessage()}. Vérifiez les logs serveur.",
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
                    "refund_processed {$formatted} commande #{$order->reference} envoyé à {$customer->email}",
                    'refund_processed', 'OrderTriggers'
                );
            } else {
                $this->watchdog()->warning(
                    "refund_processed {$formatted} commande #{$order->reference} : échec silencieux pour {$customer->email} — Mail::Send() a retourné false. Vérifiez la configuration SMTP.",
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
                "refund_processed commande #{$order->reference} : erreur — {$e->getMessage()}",
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
                    "return_received retour #{$orderReturn->id} commande #{$order->reference} envoyé à {$customer->email}",
                    'return_received', 'OrderTriggers'
                );
            } else {
                $this->watchdog()->warning(
                    "return_received retour #{$orderReturn->id} : échec silencieux pour {$customer->email} — Mail::Send() a retourné false. Vérifiez la configuration SMTP.",
                    'return_received', 'OrderTriggers'
                );
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                "return_received retour #{$orderReturn->id} : erreur — {$e->getMessage()}",
                'return_received', 'OrderTriggers'
            );
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function buildItemsSummary(\Order $order): string
    {
        try {
            $products = $order->getProducts();
            if (!is_array($products) || empty($products)) {
                return '';
            }
            $lines = array_map(
                fn($p) => '× ' . (int) $p['product_quantity'] . ' ' . $p['product_name'],
                $products
            );
            return implode("\n", $lines);
        } catch (\Throwable $e) {
            return '';
        }
    }

}
