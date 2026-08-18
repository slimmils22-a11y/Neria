<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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

    /**
     * Fenêtre de détection : à partir de 48h après livraison.
     * Bornée haute large (30 jours) pour rattraper un cron manqué/retardé —
     * la dédup par commande (alreadySent) empêche tout double envoi.
     */
    const DELAY_MIN_HOURS = 48;
    const DELAY_MAX_HOURS = 24 * 30;

    /** Nombre max de commandes traitées par exécution du cron */
    const MAX_BATCH_PER_RUN = 500;

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
            ORDER BY oh.date_add ASC
            LIMIT " . self::MAX_BATCH_PER_RUN . "
        ");

        if (!is_array($orders)) return 0;

        foreach ($orders as $order) {
            $idOrder    = (int) $order['id_order'];
            $idCustomer = (int) $order['id_customer'];
            $idLang     = (int) $order['id_lang'] ?: (int) \Configuration::get('PS_LANG_DEFAULT');
            $idShop     = (int) $order['id_shop'];

            // Réservation atomique AVANT l'envoi (voir le commentaire
            // équivalent dans CollectionManager::processCollection()) : deux
            // déclenchements quasi simultanés du cron pouvaient auparavant
            // tous deux passer le test alreadySent() et envoyer l'email en
            // double, même si la clé UNIQUE (uq_order) empêchait bien la
            // double ligne en base.
            if (!$this->claimSend($idOrder, $idCustomer)) continue;

            // Round 157 : tout le traitement de cette commande (jusqu'au
            // Mail::Send() plus bas) est désormais protégé par un seul
            // try/catch englobant, pas seulement l'appel Mail::Send() —
            // même correctif que CollectionManager::processCollection().
            // Avant ce correctif, une exception levée par exemple par
            // buildProductBlocks() (new \Product() sur une donnée produit
            // corrompue) ou new \Customer() AVANT le bloc mail fuyait la
            // réservation (claimSend() jamais libérée — commande exclue à
            // vie de cette notification, clé UNIQUE uq_order bloquant tout
            // INSERT IGNORE futur) ET remontait hors de runDailyCheck(),
            // interrompant le traitement de TOUTES les commandes suivantes
            // du batch pour le reste du cron de ce jour.
            try {
            // Aucun filtre de préférence n'était appliqué ici — un client
            // ayant désactivé la catégorie 'post' (post-achat) recevait
            // quand même cette suggestion, en contradiction avec son choix.
            // Même garde-fou que BehavioralCronManager/SegmentManager/
            // CalendarManager/SeasonalCampaignManager.
            //
            // Libère la réservation sur chaque sortie anticipée ci-dessous —
            // voir le commentaire équivalent dans
            // CollectionManager::processCollection() : sans releaseSendClaim(),
            // la clé UNIQUE (uq_order) bloquait à vie tout nouvel essai pour
            // cette commande même une fois la condition levée (préférences
            // réactivées, règle ajoutée après coup par le marchand...).
            if (class_exists('PreferencesManager')
                && !(new \PreferencesManager($this->module))->isAllowed($idCustomer, 'complete_your_look', $idShop)
            ) {
                $this->releaseSendClaim($idOrder, $idCustomer);
                continue;
            }

            // Catégories des produits de cette commande
            $categoryIds = $this->getOrderCategoryIds($idOrder);
            if (empty($categoryIds)) {
                $this->releaseSendClaim($idOrder, $idCustomer);
                continue;
            }

            // Trouver la première règle active qui correspond à une catégorie de la commande
            $rule = $this->findMatchingRule($categoryIds);
            if (!$rule) {
                $this->releaseSendClaim($idOrder, $idCustomer);
                continue;
            }

            $productIds = json_decode($rule['product_ids'], true);
            if (!is_array($productIds) || empty($productIds)) {
                $this->releaseSendClaim($idOrder, $idCustomer);
                continue;
            }

            // Exclut tout produit déjà acheté par ce client (commande
            // courante ou précédentes) — sans ce filtre, une règle statique
            // définie par le marchand peut re-suggérer un article que le
            // client possède déjà.
            $alreadyBought = $this->getCustomerPurchasedProductIds($idCustomer);
            $productIds    = array_values(array_diff($productIds, $alreadyBought));
            if (empty($productIds)) {
                $this->releaseSendClaim($idOrder, $idCustomer);
                continue;
            }

            // Récupérer les infos des produits suggérés (max 3)
            $products = $this->buildProductBlocks(array_slice($productIds, 0, 3), $idLang, $idShop);
            if (empty($products)) {
                $this->releaseSendClaim($idOrder, $idCustomer);
                continue;
            }

            $customer = new \Customer($idCustomer);
            if (!\Validate::isLoadedObject($customer)) {
                $this->releaseSendClaim($idOrder, $idCustomer);
                continue;
            }

            $vars = $this->buildVars($customer, $products, $rule['category_name'] ?? '', $idShop);
            // {id_order} scope le Mode Silence sur CETTE commande (cf.
            // CooldownManager::isDuplicate) — sans lui, deux suggestions
            // "complétez votre look" légitimes pour deux commandes
            // différentes du même client dans la fenêtre de cooldown se
            // bloquaient mutuellement à tort.
            $vars['{id_order}'] = $idOrder;

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
                    $sent++;

                    if (class_exists('WatchdogManager')) {
                        (new \WatchdogManager($this->module))->info(
                            \WatchdogManager::i18nMsg('watchdog.look_completion_item_sent', [
                                'email' => $customer->email,
                                'order' => $idOrder,
                            ]),
                            'complete_your_look', 'LookCompletion'
                        );
                    }
                } else {
                    // Envoi échoué sans exception : libère la réservation
                    // pour permettre une nouvelle tentative au prochain
                    // passage du cron.
                    $this->releaseSendClaim($idOrder, $idCustomer);
                }
            } catch (\Throwable $e) {
                $this->releaseSendClaim($idOrder, $idCustomer);
                if (class_exists('WatchdogManager')) {
                    (new \WatchdogManager($this->module))->error(
                        \WatchdogManager::i18nMsg('watchdog.look_completion_item_error', ['error' => $e->getMessage()]),
                        'complete_your_look', 'LookCompletion'
                    );
                }
            }
            } catch (\Throwable $e) {
                // Round 157 : exception hors du bloc Mail::Send() (ci-dessus,
                // déjà protégé) — ex. buildProductBlocks()/new \Customer()
                // sur une donnée corrompue. Libère la réservation et
                // continue le batch au lieu de laisser fuiter la réservation
                // et interrompre le traitement des commandes suivantes.
                $this->releaseSendClaim($idOrder, $idCustomer);
                if (class_exists('WatchdogManager')) {
                    (new \WatchdogManager($this->module))->error(
                        \WatchdogManager::i18nMsg('watchdog.look_completion_item_error', ['error' => $e->getMessage()]),
                        'complete_your_look', 'LookCompletion'
                    );
                }
            }
        }

        return $sent;
    }

    // ── Helpers privés ───────────────────────────────────────────────────

    /**
     * Catégories des produits de la commande, triées par valeur totale
     * décroissante (quantité × prix) — la catégorie du produit ayant le plus
     * de poids dans la commande passe en premier, pour que findMatchingRule()
     * priorise une règle pertinente plutôt qu'un ordre de création arbitraire.
     */
    private function getOrderCategoryIds(int $idOrder): array
    {
        // p.id_category_default IS NOT NULL exclut les produits orphelins
        // (donnée corrompue) — sans ce filtre, un NULL castait en 0 dans
        // findMatchingRule() (IN (0, ...)), polluant la liste FIELD() et
        // pouvant décaler l'ordre de priorité des règles. id_category_default
        // != 0 exclut le même cas de figure quand la donnée corrompue est un
        // 0 explicite plutôt qu'un NULL (produit mal configuré côté
        // catalogue) — round 132.
        $rows = $this->db->executeS("
            SELECT p.id_category_default,
                   SUM(od.unit_price_tax_incl * od.product_quantity) AS category_value
            FROM `{$this->prefix}order_detail` od
            INNER JOIN `{$this->prefix}product` p ON p.id_product = od.product_id
            WHERE od.id_order = {$idOrder}
              AND p.id_category_default IS NOT NULL
              AND p.id_category_default != 0
            GROUP BY p.id_category_default
            ORDER BY category_value DESC
        ");
        if (!is_array($rows)) return [];
        return array_column($rows, 'id_category_default');
    }

    /**
     * $categoryIds doit être trié par pertinence décroissante (cf.
     * getOrderCategoryIds ci-dessus). Priorité : catégorie la plus pertinente
     * d'abord (FIELD()), puis règle la plus ancienne en cas d'égalité au sein
     * d'une même catégorie.
     */
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
            ORDER BY FIELD(r.id_category, {$inList}) ASC, r.id_neria_look_rule ASC
        ");
        return $row ?: null;
    }

    /**
     * Tous les id_product déjà achetés par ce client (commandes valides,
     * toutes boutiques confondues — un article acheté sur une autre
     * boutique du groupe reste un article déjà possédé par le client).
     */
    private function getCustomerPurchasedProductIds(int $idCustomer): array
    {
        $rows = $this->db->executeS("
            SELECT DISTINCT od.product_id
            FROM `{$this->prefix}order_detail` od
            INNER JOIN `{$this->prefix}orders` o ON o.id_order = od.id_order AND o.valid = 1
            WHERE o.id_customer = {$idCustomer}
        ");
        return is_array($rows) ? array_map('intval', array_column($rows, 'product_id')) : [];
    }

    private function buildProductBlocks(array $productIds, int $idLang, int $idShop): array
    {
        // Devise par défaut de LA BOUTIQUE du client, pas celle du contexte
        // global — même correctif que CollectionManager::processCollection().
        $currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));

        // Le contexte statique Shop::$context_id_shop — consulté en interne
        // par Product::getCover(), Product::isAvailableWhenOutOfStock() et
        // le constructeur Product lui-même pour résoudre l'association
        // product_shop — n'est PAS mis à jour par une simple réassignation
        // de Context->shop : seul Shop::setContext() le fait réellement
        // (même piège que CooldownManager/DomainReputationManager, round
        // 129). Sans ce commutateur, un produit actif sur la boutique du
        // contexte d'exécution du cron mais désactivé/non associé sur la
        // boutique du client passait à tort le test $product->active, et
        // getCover() pouvait résoudre l'image via la mauvaise association
        // de boutique.
        $originalShopId = \Shop::getContextShopID(true);
        \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
        try {
            $blocks = [];
            foreach ($productIds as $pid) {
                $pid = (int) $pid;
                // Actif uniquement — un produit désactivé/retiré du
                // catalogue ne doit plus être suggéré dans l'email
                // "Complétez votre look" avec un lien mort.
                $product = new \Product($pid, false, $idLang, $idShop);
                if (!\Validate::isLoadedObject($product) || !$product->active) continue;

                // Round 184 : SUM(quantity) SQL direct remplace
                // StockAvailable::getQuantityAvailableByProduct($pid, null, ...)
                // — même piège déjà corrigé (round 167) dans
                // WaitlistManager::notifyProduct() mais jamais répliqué ici :
                // le cœur PrestaShop convertit explicitement
                // id_product_attribute=null en 0
                // (StockAvailable::getQuantityAvailableByProduct()), donc
                // seule la ligne "sans déclinaison" était lue — quasi
                // toujours à 0 pour un produit géré par combinaisons. Un
                // produit parfaitement disponible (stock réparti sur ses
                // combinaisons) était silencieusement écarté des
                // suggestions "Complétez votre look". Bascule stock
                // partagé/non partagé identique à WaitlistManager (round
                // 167) : en mode "stock partagé" entre boutiques d'un
                // groupe, la quantité réelle est sur UNE SEULE ligne
                // id_shop=0/id_shop_group=X, jamais sur id_shop=$idShop.
                $lcShopGroup  = new \Shop($idShop);
                $lcShareStock = (bool) $lcShopGroup->getGroup()->share_stock;
                $lcStockWhere = $lcShareStock
                    ? ' AND id_shop = 0 AND id_shop_group = ' . (int) $lcShopGroup->id_shop_group
                    : ' AND id_shop = ' . $idShop . ' AND id_shop_group = 0';
                $availableQty = (int) $this->db->getValue(
                    'SELECT COALESCE(SUM(quantity), 0) FROM `' . $this->prefix . 'stock_available`
                     WHERE id_product = ' . $pid . $lcStockWhere
                );
                if ($availableQty <= 0 && !\Product::isAvailableWhenOutOfStock($product->out_of_stock)) {
                    continue;
                }

                $cover = \Product::getCover($pid);
                $imageUrl = '';

                // Lien/image générés dans le contexte de LA BOUTIQUE du
                // client (id_shop de la commande), pas celui du contexte
                // d'exécution courant du cron.
                $context = \Context::getContext();
                $originalShop = $context->shop;
                $context->shop = new \Shop($idShop);
                try {
                    if ($cover) {
                        $imageUrl = $context->link->getImageLink(
                            $product->link_rewrite,
                            (int) $cover['id_image'],
                            \ImageType::getFormattedName('home')
                        );
                    }
                    $productUrl = $context->link->getProductLink($product, null, null, null, $idLang, $idShop);
                } finally {
                    $context->shop = $originalShop;
                }

                // Round 184 : Product::getPriceStatic() remplace $product->price
                // brut — même pattern que UpsellManager::safeProductPrice().
                // $product->price est le champ ObjectModel catalogue (HT,
                // sans specific_price/promo, sans groupe tarifaire) : un
                // produit en promo affichait son prix plein tarif dans
                // l'email, différent de celui réellement affiché sur la
                // fiche produit au clic.
                $realPrice = $this->safeProductPrice($pid);
                $blocks[] = [
                    'name'  => $product->name,
                    'url'   => $productUrl,
                    'image' => $imageUrl,
                    'price' => \NeriaTools::displayPrice($realPrice, $currency, $idLang),
                ];
            }
        } finally {
            \Shop::setContext(\Shop::CONTEXT_SHOP, $originalShopId);
        }
        return $blocks;
    }

    /**
     * Round 184 : prix réel (taxe + specific_price/promo appliqués), même
     * logique que UpsellManager::safeProductPrice() — Product::getPriceStatic()
     * peut nécessiter un panier en contexte pour résoudre certaines règles
     * de taxe ; ce fichier tourne typiquement depuis un cron sans panier
     * actif, d'où le panier temporaire ci-dessous.
     */
    private function safeProductPrice(int $idProduct): float
    {
        $ctx     = \Context::getContext();
        $hadCart = \Validate::isLoadedObject($ctx->cart);

        if (!$hadCart) {
            $tmp = new \Cart();
            $tmp->id_currency = (int) ($ctx->currency->id ?? \Configuration::get('PS_CURRENCY_DEFAULT'));
            $tmp->id_lang     = (int) ($ctx->language->id ?? \Configuration::get('PS_LANG_DEFAULT'));
            $ctx->cart        = $tmp;
        }

        try {
            return (float) \Product::getPriceStatic($idProduct, true, null, 2);
        } finally {
            if (!$hadCart) {
                $ctx->cart = null;
            }
        }
    }

    private function buildVars(\Customer $customer, array $products, string $categoryName, int $idShop): array
    {
        $vars = [
            '{firstname}'      => $customer->firstname,
            '{category_name}'  => $categoryName,
            // Configuration::get(..., $idShop) : round 106, même piège déjà
            // corrigé (round 103) pour {product_url}/{product_image} dans
            // buildProductBlocks() de ce même fichier — $idShop est déjà
            // connu à l'appel (id_shop de la commande) mais n'était pas
            // propagé jusqu'à ce {shop_name}.
            '{shop_name}'      => \Configuration::get('PS_SHOP_NAME', null, null, $idShop),
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

    /**
     * Réservation atomique compare-and-swap — voir le commentaire dans
     * l'appelant. true si CE process a remporté la réservation.
     */
    private function claimSend(int $idOrder, int $idCustomer): bool
    {
        $this->db->execute(
            "INSERT IGNORE INTO `{$this->prefix}neria_look_sent`
                (`id_order`, `id_customer`, `sent_at`)
             VALUES ({$idOrder}, {$idCustomer}, '" . date('Y-m-d H:i:s') . "')"
        );
        return $this->db->Affected_Rows() > 0;
    }

    private function releaseSendClaim(int $idOrder, int $idCustomer): void
    {
        $this->db->delete('neria_look_sent',
            '`id_order` = ' . $idOrder . ' AND `id_customer` = ' . $idCustomer
        );
    }

    // ── Statistiques ─────────────────────────────────────────────────────

    public function getStats(): array
    {
        // Round 127 : sent/sent30 scopés par boutique — même pattern que
        // CollectionManager::getStats() (round 119), UpsellManager::getStats()
        // et WaitlistManager::getStats(). neria_look_sent n'a pas de colonne
        // id_shop propre (voir sql/install.sql, table 31), mais chaque ligne
        // référence un id_order réel via lequel la boutique se retrouve —
        // JOIN sur orders.id_shop plutôt qu'une migration de schéma. Sans ce
        // scope, le BO d'une boutique affichait dans son KPI "Complétez
        // votre look" les envois de TOUTES les boutiques de l'installation,
        // juste à côté du KPI "Complétion de collection" (même écran, cf.
        // neria.php) correctement scopé depuis le round 119 — deux chiffres
        // manifestement incohérents sur le même tableau de bord.
        $idShop = (int) \Context::getContext()->shop->id;

        $rules  = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$this->prefix}neria_look_rule`");
        $active = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$this->prefix}neria_look_rule` WHERE active = 1");
        $sent   = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_look_sent` ls
             INNER JOIN `{$this->prefix}orders` o ON o.id_order = ls.id_order
             WHERE o.id_shop = {$idShop}"
        );
        $sent30 = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}neria_look_sent` ls
             INNER JOIN `{$this->prefix}orders` o ON o.id_order = ls.id_order
             WHERE o.id_shop = {$idShop} AND ls.sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        return compact('rules', 'active', 'sent', 'sent30');
    }

    // ── Liste des catégories PS pour le BO ───────────────────────────────

    public function getCategories(): array
    {
        // Round 174 : aucun filtre id_shop — en multi-boutique, le BO
        // proposait pour créer une règle de complétion de look des
        // catégories n'appartenant pas forcément à la boutique courante
        // (chaque boutique a son propre arbre de catégories via
        // category_shop). Jointure ajoutée, cohérente avec le scoping déjà
        // appliqué ailleurs dans ce fichier (getStats()).
        $idShop = (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            "SELECT c.id_category, cl.name
             FROM `{$this->prefix}category` c
             INNER JOIN `{$this->prefix}category_lang` cl
                ON cl.id_category = c.id_category
               AND cl.id_lang = " . (int) \Configuration::get('PS_LANG_DEFAULT') . "
             INNER JOIN `{$this->prefix}category_shop` cs
                ON cs.id_category = c.id_category
               AND cs.id_shop = {$idShop}
             WHERE c.active = 1 AND c.id_category > 2
             ORDER BY cl.name ASC"
        );
        return is_array($rows) ? $rows : [];
    }
}
