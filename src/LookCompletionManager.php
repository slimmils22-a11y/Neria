<?php
/**
 * LookCompletionManager — Programme "Complétez votre look"
 *
 * 48h après la livraison, envoie un email suggérant 2-3 produits complémentaires
 * selon les règles d'association catégorie → produits définies par le marchand.
 */

if (!defined('_PS_VERSION_')) exit;

class LookCompletionManager
{
    private \Db    $db;
    private string $prefix;
    private        $module;

    /** Fenêtre de détection : entre 48h et 72h après livraison */
    const DELAY_MIN_HOURS = 48;
    const DELAY_MAX_HOURS = 72;

    public function __construct($module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->prefix = _DB_PREFIX_;
    }

    // ── CRUD règles ──────────────────────────────────────────────────────

    public function getAllRules(): array
    {
        $rows = $this->db->executeS(
            "SELECT r.*, cl.name AS category_name
             FROM `{$this->prefix}neria_look_rule` r
             LEFT JOIN `{$this->prefix}category_lang` cl
                ON cl.id_category = r.id_category
               AND cl.id_lang = " . (int) \Configuration::get('PS_LANG_DEFAULT') . "
             ORDER BY cl.name ASC"
        );
        return is_array($rows) ? $rows : [];
    }

    public function getRuleById(int $id): ?array
    {
        $row = $this->db->getRow(
            "SELECT * FROM `{$this->prefix}neria_look_rule` WHERE `id_neria_look_rule` = " . $id
        );
        return $row ?: null;
    }

    public function createRule(int $idCategory, array $productIds, bool $active = true): bool
    {
        return $this->db->insert('neria_look_rule', [
            'id_category' => $idCategory,
            'product_ids' => pSQL(json_encode(array_values(array_unique(array_map('intval', $productIds))))),
            'active'      => (int) $active,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateRule(int $id, int $idCategory, array $productIds, bool $active): bool
    {
        return $this->db->update('neria_look_rule', [
            'id_category' => $idCategory,
            'product_ids' => pSQL(json_encode(array_values(array_unique(array_map('intval', $productIds))))),
            'active'      => (int) $active,
        ], '`id_neria_look_rule` = ' . $id);
    }

    public function deleteRule(int $id): bool
    {
        return $this->db->delete('neria_look_rule', '`id_neria_look_rule` = ' . $id);
    }

    // ── CRON : détection + envoi ─────────────────────────────────────────

    public function runDailyCheck(): int
    {
        if (!\Configuration::getGlobalValue('NERIA_LOOK_COMPLETION_ENABLED')) return 0;

        $sent = 0;

        // Commandes passées au statut "Livré" entre 48h et 72h
        $deliveredStateId = (int) \Configuration::get('PS_OS_DELIVERED');
        if (!$deliveredStateId) $deliveredStateId = 5;

        $orders = $this->db->executeS("
            SELECT DISTINCT oh.id_order, o.id_customer, o.id_lang, o.id_shop
            FROM `{$this->prefix}order_history` oh
            INNER JOIN `{$this->prefix}orders` o ON o.id_order = oh.id_order AND o.valid = 1
            WHERE oh.id_order_state = {$deliveredStateId}
              AND oh.date_add >= DATE_SUB(NOW(), INTERVAL " . self::DELAY_MAX_HOURS . " HOUR)
              AND oh.date_add <  DATE_SUB(NOW(), INTERVAL " . self::DELAY_MIN_HOURS . " HOUR)
        ");

        if (!is_array($orders)) return 0;

        foreach ($orders as $order) {
            $idOrder    = (int) $order['id_order'];
            $idCustomer = (int) $order['id_customer'];
            $idLang     = (int) $order['id_lang'] ?: (int) \Configuration::get('PS_LANG_DEFAULT');
            $idShop     = (int) $order['id_shop'];

            // Dédup : un seul email par commande
            if ($this->alreadySent($idOrder)) continue;

            // Catégories des produits de cette commande
            $categoryIds = $this->getOrderCategoryIds($idOrder);
            if (empty($categoryIds)) continue;

            // Trouver la première règle active qui correspond à une catégorie de la commande
            $rule = $this->findMatchingRule($categoryIds);
            if (!$rule) continue;

            $productIds = json_decode($rule['product_ids'], true);
            if (!is_array($productIds) || empty($productIds)) continue;

            // Récupérer les infos des produits suggérés (max 3)
            $products = $this->buildProductBlocks(array_slice($productIds, 0, 3), $idLang);
            if (empty($products)) continue;

            $customer = new \Customer($idCustomer);
            if (!\Validate::isLoadedObject($customer)) continue;

            $vars = $this->buildVars($customer, $products, $rule['category_name'] ?? '');

            try {
                $mailed = \Mail::Send(
                    $idLang,
                    'complete_your_look',
                    '',
                    $vars,
                    $customer->email,
                    trim($customer->firstname . ' ' . $customer->lastname) ?: null,
                    null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/',
                    false,
                    $idShop
                );

                if ($mailed) {
                    $this->markSent($idOrder, $idCustomer);
                    $sent++;

                    if (class_exists('WatchdogManager')) {
                        (new \WatchdogManager($this->module))->info(
                            sprintf('Look completion → %s (commande #%d)', $customer->email, $idOrder),
                            'complete_your_look', 'LookCompletion'
                        );
                    }
                }
            } catch (\Throwable $e) {
                if (class_exists('WatchdogManager')) {
                    (new \WatchdogManager($this->module))->error(
                        'Erreur look completion : ' . $e->getMessage(),
                        'complete_your_look', 'LookCompletion'
                    );
                }
            }
        }

        return $sent;
    }

    // ── Helpers privés ───────────────────────────────────────────────────

    private function getOrderCategoryIds(int $idOrder): array
    {
        $rows = $this->db->executeS("
            SELECT DISTINCT p.id_category_default
            FROM `{$this->prefix}order_detail` od
            INNER JOIN `{$this->prefix}product` p ON p.id_product = od.product_id
            WHERE od.id_order = {$idOrder}
        ");
        if (!is_array($rows)) return [];
        return array_column($rows, 'id_category_default');
    }

    private function findMatchingRule(array $categoryIds): ?array
    {
        $inList = implode(',', array_map('intval', $categoryIds));
        $row = $this->db->getRow("
            SELECT r.*, cl.name AS category_name
            FROM `{$this->prefix}neria_look_rule` r
            LEFT JOIN `{$this->prefix}category_lang` cl
               ON cl.id_category = r.id_category
              AND cl.id_lang = " . (int) \Configuration::get('PS_LANG_DEFAULT') . "
            WHERE r.active = 1 AND r.id_category IN ({$inList})
            ORDER BY r.id_neria_look_rule ASC
        ");
        return $row ?: null;
    }

    private function buildProductBlocks(array $productIds, int $idLang): array
    {
        $blocks = [];
        foreach ($productIds as $pid) {
            $product = new \Product((int) $pid, false, $idLang);
            if (!\Validate::isLoadedObject($product)) continue;

            $cover = \Product::getCover((int) $pid);
            $imageUrl = '';
            if ($cover) {
                $imageUrl = \Context::getContext()->link->getImageLink(
                    $product->link_rewrite,
                    (int) $cover['id_image'],
                    \ImageType::getFormattedName('home')
                );
            }

            $blocks[] = [
                'name'  => $product->name,
                'url'   => \Context::getContext()->link->getProductLink($product),
                'image' => $imageUrl,
                'price' => number_format((float) $product->price, 2, ',', ' '),
            ];
        }
        return $blocks;
    }

    private function buildVars(\Customer $customer, array $products, string $categoryName): array
    {
        $vars = [
            '{firstname}'      => $customer->firstname,
            '{category_name}'  => $categoryName,
            '{shop_name}'      => \Configuration::get('PS_SHOP_NAME'),
        ];

        // Produit 1 (toujours présent)
        $vars['{product1_name}']  = $products[0]['name']  ?? '';
        $vars['{product1_url}']   = $products[0]['url']   ?? '';
        $vars['{product1_image}'] = $products[0]['image'] ?? '';
        $vars['{product1_price}'] = $products[0]['price'] ?? '';

        // Produit 2 (optionnel)
        $vars['{product2_name}']  = $products[1]['name']  ?? '';
        $vars['{product2_url}']   = $products[1]['url']   ?? '';
        $vars['{product2_image}'] = $products[1]['image'] ?? '';
        $vars['{product2_price}'] = $products[1]['price'] ?? '';

        // Produit 3 (optionnel)
        $vars['{product3_name}']  = $products[2]['name']  ?? '';
        $vars['{product3_url}']   = $products[2]['url']   ?? '';
        $vars['{product3_image}'] = $products[2]['image'] ?? '';
        $vars['{product3_price}'] = $products[2]['price'] ?? '';

        $vars['{has_product2}'] = !empty($products[1]) ? '1' : '0';
        $vars['{has_product3}'] = !empty($products[2]) ? '1' : '0';

        return $vars;
    }

    private function alreadySent(int $idOrder): bool
    {
        return (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_look_sent` WHERE `id_order` = {$idOrder}"
        ) > 0;
    }

    private function markSent(int $idOrder, int $idCustomer): void
    {
        $this->db->insert('neria_look_sent', [
            'id_order'    => $idOrder,
            'id_customer' => $idCustomer,
            'sent_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    // ── Statistiques ─────────────────────────────────────────────────────

    public function getStats(): array
    {
        $rules     = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$this->prefix}neria_look_rule`");
        $active    = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$this->prefix}neria_look_rule` WHERE active = 1");
        $sent      = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$this->prefix}neria_look_sent`");
        $sent30    = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_look_sent` WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        return compact('rules', 'active', 'sent', 'sent30');
    }

    // ── Liste des catégories PS pour le BO ───────────────────────────────

    public function getCategories(): array
    {
        $rows = $this->db->executeS(
            "SELECT c.id_category, cl.name
             FROM `{$this->prefix}category` c
             INNER JOIN `{$this->prefix}category_lang` cl
                ON cl.id_category = c.id_category
               AND cl.id_lang = " . (int) \Configuration::get('PS_LANG_DEFAULT') . "
             WHERE c.active = 1 AND c.id_category > 2
             ORDER BY cl.name ASC"
        );
        return is_array($rows) ? $rows : [];
    }
}
