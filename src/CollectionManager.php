<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * CollectionManager — Suggestion de complétion de collection
 *
 * Détecte les clients qui ont acheté N-1 pièces d'une collection de N
 * et envoie un email "votre collection est presque complète".
 */

if (!defined('_PS_VERSION_')) exit;

class CollectionManager
{
    private \Db    $db;
    private string $prefix;
    private        $module;

    public function __construct($module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->prefix = _DB_PREFIX_;
    }

    // ── CRUD ─────────────────────────────────────────────────────────────

    public function getAll(): array
    {
        $rows = $this->db->executeS(
            "SELECT * FROM `{$this->prefix}neria_collection` ORDER BY `name` ASC"
        );
        return is_array($rows) ? $rows : [];
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->getRow(
            "SELECT * FROM `{$this->prefix}neria_collection` WHERE `id_neria_collection` = " . $id
        );
        return $row ?: null;
    }

    public function create(string $name, array $productIds, bool $active = true): bool
    {
        return $this->db->insert('neria_collection', [
            'name'        => pSQL($name),
            'product_ids' => pSQL(json_encode(array_values(array_unique(array_map('intval', $productIds))))),
            'active'      => (int) $active,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, string $name, array $productIds, bool $active): bool
    {
        return $this->db->update('neria_collection', [
            'name'        => pSQL($name),
            'product_ids' => pSQL(json_encode(array_values(array_unique(array_map('intval', $productIds))))),
            'active'      => (int) $active,
        ], '`id_neria_collection` = ' . $id);
    }

    public function delete(int $id): bool
    {
        $this->db->delete('neria_collection_sent', '`id_neria_collection` = ' . $id);
        return $this->db->delete('neria_collection', '`id_neria_collection` = ' . $id);
    }

    // ── CRON : détection + envoi ──────────────────────────────────────────

    public function runDailyCheck(): int
    {
        if (!\Configuration::getGlobalValue('NERIA_COLLECTION_COMPLETION_ENABLED')) return 0;

        $sent        = 0;
        $collections = $this->getAll();

        foreach ($collections as $col) {
            if (!$col['active']) continue;

            $productIds = json_decode($col['product_ids'], true);
            if (!is_array($productIds) || count($productIds) < 2) continue;

            $total = count($productIds);
            $sent += $this->processCollection((int) $col['id_neria_collection'], $col['name'], $productIds, $total);
        }

        return $sent;
    }

    private function processCollection(int $colId, string $colName, array $productIds, int $total): int
    {
        $sent    = 0;
        $inList  = implode(',', $productIds);

        // Clients ayant acheté au moins $total-1 produits de la collection (parmi des commandes payées)
        $rows = $this->db->executeS("
            SELECT o.id_customer, MIN(o.id_shop) AS id_shop,
                   GROUP_CONCAT(DISTINCT od.product_id ORDER BY od.product_id) AS bought_ids
            FROM `{$this->prefix}order_detail` od
            INNER JOIN `{$this->prefix}orders` o ON o.id_order = od.id_order AND o.valid = 1
            WHERE od.product_id IN ({$inList})
            GROUP BY o.id_customer
            HAVING COUNT(DISTINCT od.product_id) = " . ($total - 1) . "
        ");

        if (!is_array($rows)) return 0;

        foreach ($rows as $row) {
            $idCustomer = (int) $row['id_customer'];
            $boughtIds  = array_map('intval', explode(',', $row['bought_ids']));
            $missing    = array_values(array_diff($productIds, $boughtIds));

            if (empty($missing)) continue;
            $missingId = $missing[0];

            // Dédup : ne pas renvoyer pour la même collection + client
            if ($this->alreadySent($colId, $idCustomer)) continue;

            // Récupérer les infos client + langue
            $customer = new \Customer($idCustomer);
            if (!\Validate::isLoadedObject($customer)) continue;

            $idLang = $this->resolveLang($customer);
            $idShop = (int) ($row['id_shop'] ?: \Context::getContext()->shop->id);

            // Récupérer le produit manquant
            $product = new \Product($missingId, false, $idLang);
            if (!\Validate::isLoadedObject($product)) continue;

            $productName  = $product->name;
            $productLink  = \Context::getContext()->link->getProductLink($product);
            $productImage = $this->getProductImageUrl($missingId, $idLang);
            $productPrice = (float) $product->price;

            $toName = trim($customer->firstname . ' ' . $customer->lastname) ?: null;

            $vars = [
                '{firstname}'              => $customer->firstname,
                '{collection_name}'        => $colName,
                '{missing_product}'        => $productName,
                '{missing_product_url}'    => $productLink,
                '{missing_image_url}'      => $productImage,
                '{missing_price}'          => \NeriaTools::displayPrice($productPrice, \Currency::getDefaultCurrency()),
                '{bought_count}'           => (string) count($boughtIds),
                '{total_count}'            => (string) $total,
                '{shop_name}'              => \Configuration::get('PS_SHOP_NAME'),
            ];

            try {
                $mailed = \Mail::Send(
                    $idLang,
                    'collection_completion',
                    '',
                    $vars,
                    $customer->email,
                    $toName,
                    null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/',
                    false,
                    $idShop
                );

                if ($mailed) {
                    $this->markSent($colId, $idCustomer);
                    $sent++;

                    if (class_exists('WatchdogManager')) {
                        (new \WatchdogManager($this->module))->info(
                            \WatchdogManager::i18nMsg('watchdog.collection_item_sent', [
                                'collection' => $colName,
                                'email'      => $customer->email,
                                'product'    => $missingId,
                            ]),
                            'collection_completion', 'CollectionManager'
                        );
                    }
                }
            } catch (\Throwable $e) {
                if (class_exists('WatchdogManager')) {
                    (new \WatchdogManager($this->module))->error(
                        \WatchdogManager::i18nMsg('watchdog.collection_item_error', ['error' => $e->getMessage()]),
                        'collection_completion', 'CollectionManager'
                    );
                }
            }
        }

        return $sent;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function alreadySent(int $colId, int $idCustomer): bool
    {
        $r = $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_collection_sent`
             WHERE `id_neria_collection` = {$colId} AND `id_customer` = {$idCustomer}"
        );
        return (int) $r > 0;
    }

    private function markSent(int $colId, int $idCustomer): void
    {
        $this->db->insert('neria_collection_sent', [
            'id_neria_collection' => $colId,
            'id_customer'         => $idCustomer,
            'sent_at'             => date('Y-m-d H:i:s'),
        ]);
    }

    private function resolveLang(\Customer $customer): int
    {
        if (!empty($customer->id_lang)) {
            return (int) $customer->id_lang;
        }
        $ctx = \Context::getContext();
        return (int) ($ctx->language->id ?? \Configuration::get('PS_LANG_DEFAULT'));
    }

    private function getProductImageUrl(int $idProduct, int $idLang): string
    {
        $cover = \Product::getCover($idProduct);
        if (!$cover) return '';
        return \Context::getContext()->link->getImageLink('', (int) $cover['id_image'], \ImageType::getFormattedName('home'));
    }

    // ── Statistiques ──────────────────────────────────────────────────────

    public function getStats(): array
    {
        $total = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_collection`"
        );
        $active = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_collection` WHERE active = 1"
        );
        $sent = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_collection_sent`"
        );
        $sentLast30 = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_collection_sent`
             WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        return compact('total', 'active', 'sent', 'sentLast30');
    }
}
