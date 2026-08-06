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
    private const MAX_BATCH_PER_RUN = 500;

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

    /**
     * Variante de getAll() qui résout les IDs produits stockés en JSON vers
     * leur nom/référence/image — évite d'afficher une liste brute de nombres
     * dans le back-office (peu lisible pour un marchand).
     */
    public function getAllWithProductDetails(int $idLang): array
    {
        $rows = $this->getAll();
        foreach ($rows as &$row) {
            $ids = json_decode($row['product_ids'], true);
            $row['product_details'] = is_array($ids) ? self::resolveProducts($ids, $idLang) : [];
        }
        unset($row);
        return $rows;
    }

    /**
     * Recherche de produits par nom ou référence, pour le sélecteur avec
     * auto-complétion du formulaire d'ajout de collection (AJAX).
     */
    public static function searchProducts(string $query, int $idLang, int $idShop, int $limit = 20): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $db     = \Db::getInstance();
        $prefix = _DB_PREFIX_;
        // Échapper les métacaractères LIKE (% et _) pour éviter des résultats
        // bruités : pSQL() n'échappe pas la sémantique LIKE.
        $like   = pSQL(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query));

        $rows = $db->executeS(
            "SELECT p.id_product, pl.name, p.reference
             FROM `{$prefix}product` p
             INNER JOIN `{$prefix}product_lang` pl
                     ON pl.id_product = p.id_product AND pl.id_lang = " . (int) $idLang . " AND pl.id_shop = " . (int) $idShop . "
             WHERE (pl.name LIKE '%{$like}%' OR p.reference LIKE '%{$like}%')
               AND p.active = 1
             ORDER BY pl.name ASC
             LIMIT " . (int) $limit
        );

        if (!is_array($rows)) {
            return [];
        }

        $results = [];
        foreach ($rows as $r) {
            $idProduct = (int) $r['id_product'];
            $results[] = [
                'id'        => $idProduct,
                'name'      => $r['name'],
                'reference' => $r['reference'],
                'image'     => self::getProductThumbUrl($idProduct),
            ];
        }
        return $results;
    }

    /**
     * Résout une liste d'IDs produits vers name/reference/image, en
     * préservant l'ordre d'origine. Les produits supprimés depuis sont
     * omis silencieusement (pas d'exception qui casserait l'affichage).
     */
    private static function resolveProducts(array $ids, int $idLang): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $prefix = _DB_PREFIX_;
        $inList = implode(',', $ids);
        $rows = \Db::getInstance()->executeS(
            "SELECT p.id_product, pl.name, p.reference
             FROM `{$prefix}product` p
             INNER JOIN `{$prefix}product_lang` pl
                     ON pl.id_product = p.id_product AND pl.id_lang = " . (int) $idLang . "
             WHERE p.id_product IN ({$inList})"
        );
        $byId = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $byId[(int) $r['id_product']] = $r;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue; // produit supprimé depuis la création de la collection
            }
            $out[] = [
                'id'        => $id,
                'name'      => $byId[$id]['name'],
                'reference' => $byId[$id]['reference'],
                'image'     => self::getProductThumbUrl($id),
            ];
        }
        return $out;
    }

    private static function getProductThumbUrl(int $idProduct): string
    {
        $cover = \Product::getCover($idProduct);
        if (!$cover) {
            return '';
        }
        return \Context::getContext()->link->getImageLink('', (int) $cover['id_image'], \ImageType::getFormattedName('small_default'));
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

        // group_concat_max_len par défaut MySQL = 1024 octets — largement
        // suffisant pour une petite collection, mais une collection de 150+
        // produits peut faire dépasser GROUP_CONCAT(od.product_id) cette
        // limite : la chaîne est alors tronquée SILENCIEUSEMENT (pas
        // d'erreur SQL), et bought_ids ci-dessous ne contient plus qu'une
        // liste partielle. array_diff() calcule alors un "produit manquant"
        // potentiellement faux (un produit déjà acheté par le client, ou
        // omet le vrai produit manquant) — élargi à 1 Mo, largement au-delà
        // de toute collection réaliste.
        $this->db->execute('SET SESSION group_concat_max_len = 1000000');

        // Clients ayant acheté au moins $total-1 produits de la collection dans une même boutique
        // (parmi des commandes payées) — groupé par boutique pour ne pas mélanger les catalogues
        // multi-boutiques, et borné pour éviter un timeout sur un gros volume.
        $rows = $this->db->executeS("
            SELECT o.id_customer, o.id_shop,
                   GROUP_CONCAT(DISTINCT od.product_id ORDER BY od.product_id) AS bought_ids
            FROM `{$this->prefix}order_detail` od
            INNER JOIN `{$this->prefix}orders` o ON o.id_order = od.id_order AND o.valid = 1
            WHERE od.product_id IN ({$inList})
            GROUP BY o.id_customer, o.id_shop
            HAVING COUNT(DISTINCT od.product_id) = " . ($total - 1) . "
            LIMIT " . self::MAX_BATCH_PER_RUN . "
        ");

        if (!is_array($rows)) return 0;

        foreach ($rows as $row) {
            $idCustomer = (int) $row['id_customer'];
            $boughtIds  = array_map('intval', explode(',', $row['bought_ids']));
            $missing    = array_values(array_diff($productIds, $boughtIds));

            if (empty($missing)) continue;
            $missingId = $missing[0];

            $idShop = (int) ($row['id_shop'] ?: \Context::getContext()->shop->id);

            // Réservation atomique AVANT l'envoi (et non plus une simple
            // lecture suivie d'un INSERT après coup) : deux déclenchements
            // quasi simultanés du cron (fallback + serveur, ou double clic
            // sur « Forcer l'exécution ») passaient tous deux le test
            // alreadySent() avant que l'un ou l'autre n'ait eu le temps
            // d'insérer sa ligne — l'email pouvait alors partir deux fois
            // même si la clé UNIQUE empêchait bien la double ligne en base.
            // INSERT IGNORE sur (id_neria_collection, id_customer, id_shop)
            // agit comme un verrou compare-and-swap : un seul processus le
            // remporte. id_shop fait partie de la clé (upgrade 1.0.38) :
            // la boucle ci-dessus groupe déjà les achats par (customer,
            // shop) pour ne pas mélanger les catalogues multi-boutiques,
            // mais sans id_shop dans la clé, un même client complétant
            // RÉELLEMENT la même collection sur deux boutiques distinctes
            // voyait la 2e complétion bloquée à tort par la réservation de
            // la 1re — email jamais envoyé pour la 2e boutique, sans erreur.
            if (!$this->claimSend($colId, $idCustomer, $idShop)) continue;

            // Récupérer les infos client + langue
            $customer = new \Customer($idCustomer);
            if (!\Validate::isLoadedObject($customer)) {
                $this->releaseSendClaim($colId, $idCustomer, $idShop);
                continue;
            }

            $idLang = $this->resolveLang($customer);

            // Aucun filtre de préférence n'était appliqué ici — un client
            // ayant désactivé la catégorie 'post' (post-achat) recevait
            // quand même cette suggestion, en contradiction avec son choix.
            // Même garde-fou que BehavioralCronManager/SegmentManager/
            // CalendarManager/SeasonalCampaignManager/LookCompletionManager.
            //
            // Libère la réservation sur chaque sortie anticipée ci-dessous :
            // la réservation n'a de sens que pour empêcher un double ENVOI,
            // pas pour bloquer définitivement un client qui n'a temporairement
            // pas reçu l'email (préférences, produit désactivé, rupture de
            // stock) — sans releaseSendClaim() ici, la clé UNIQUE empêchait
            // tout INSERT IGNORE ultérieur même une fois la condition levée
            // (produit réapprovisionné, préférences réactivées), excluant le
            // client à vie de cette notification.
            if (class_exists('PreferencesManager')
                && !(new \PreferencesManager($this->module))->isAllowed($idCustomer, 'collection_completion', $idShop)
            ) {
                $this->releaseSendClaim($colId, $idCustomer, $idShop);
                continue;
            }

            // Récupérer le produit manquant — actif uniquement. Contrairement
            // à getCategories()/searchProducts() qui filtrent déjà p.active=1,
            // ce chargement individuel n'excluait pas les produits désactivés
            // (fin de série retirée du catalogue sans mise à jour de la règle
            // de collection) : le cron continuait d'envoyer "il ne vous manque
            // que X" avec un lien produit indisponible.
            $product = new \Product($missingId, false, $idLang);
            if (!\Validate::isLoadedObject($product) || !$product->active) {
                $this->releaseSendClaim($colId, $idCustomer, $idShop);
                continue;
            }

            // Ignore un produit actif mais en rupture (et sans commande en
            // backorder possible) — sans ça, l'email « il ne vous manque
            // que X » invite le client à acheter un produit qu'il ne peut
            // pas réellement commander.
            if (!\StockAvailable::getQuantityAvailableByProduct($missingId, null, $idShop)
                && !\Product::isAvailableWhenOutOfStock($product->out_of_stock)
            ) {
                $this->releaseSendClaim($colId, $idCustomer, $idShop);
                continue;
            }

            // Lien généré dans le contexte de LA BOUTIQUE du client (id_shop
            // de la commande), pas celui du contexte d'exécution courant du
            // cron — sur une install multi-boutiques avec domaines
            // distincts, un client de la boutique 2 recevait sinon un lien
            // pointant vers le domaine/catalogue de la boutique 1 (celui
            // chargé au moment où le cron a démarré).
            $productLink  = \Context::getContext()->link->getProductLink($product, null, null, null, $idLang, $idShop);
            $productName  = $product->name;
            $productImage = $this->getProductImageUrl($missingId, $idLang, $idShop);
            $productPrice = (float) $product->price;

            $toName = trim($customer->firstname . ' ' . $customer->lastname) ?: null;

            $vars = [
                '{firstname}'              => $customer->firstname,
                '{collection_name}'        => $colName,
                '{missing_product}'        => $productName,
                '{missing_product_url}'    => $productLink,
                '{missing_image_url}'      => $productImage,
                // Devise par défaut de LA BOUTIQUE du client ($idShop), pas
                // celle du contexte global d'exécution du cron — sur une
                // install multi-devises/multi-boutiques, Currency::
                // getDefaultCurrency() ignorait toujours la devise réelle
                // de la boutique où le client a acheté.
                '{missing_price}'          => \NeriaTools::displayPrice(
                    $productPrice,
                    new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop)),
                    $idLang
                ),
                '{bought_count}'           => (string) count($boughtIds),
                '{total_count}'            => (string) $total,
                '{shop_name}'              => \Configuration::get('PS_SHOP_NAME'),
                // Scope le Mode Silence par collection (cf. CooldownManager)
                // — sans lui, une notification légitime pour une DEUXIÈME
                // collection complétée dans la fenêtre de cooldown était
                // bloquée à tort comme doublon de la première.
                '{cooldown_scope}'         => 'collection:' . $colId,
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
                } else {
                    // Envoi échoué (retourné false, sans exception) : libère
                    // la réservation pour permettre une nouvelle tentative au
                    // prochain passage du cron, plutôt que de perdre
                    // silencieusement ce client pour toujours.
                    $this->releaseSendClaim($colId, $idCustomer, $idShop);
                }
            } catch (\Throwable $e) {
                $this->releaseSendClaim($colId, $idCustomer, $idShop);
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

    /**
     * Réservation atomique compare-and-swap : true si CE process a bien
     * remporté la réservation (l'email peut/doit partir), false si un autre
     * process l'a déjà (ou l'envoi a déjà réussi lors d'un passage
     * précédent) — voir le commentaire dans processCollection().
     */
    private function claimSend(int $colId, int $idCustomer, int $idShop): bool
    {
        $this->db->execute(
            "INSERT IGNORE INTO `{$this->prefix}neria_collection_sent`
                (`id_neria_collection`, `id_customer`, `id_shop`, `sent_at`)
             VALUES ({$colId}, {$idCustomer}, {$idShop}, '" . date('Y-m-d H:i:s') . "')"
        );
        return $this->db->Affected_Rows() > 0;
    }

    private function releaseSendClaim(int $colId, int $idCustomer, int $idShop): void
    {
        $this->db->delete('neria_collection_sent',
            '`id_neria_collection` = ' . $colId . ' AND `id_customer` = ' . $idCustomer . ' AND `id_shop` = ' . $idShop
        );
    }

    private function resolveLang(\Customer $customer): int
    {
        if (!empty($customer->id_lang)) {
            return (int) $customer->id_lang;
        }
        $ctx = \Context::getContext();
        return (int) ($ctx->language->id ?? \Configuration::get('PS_LANG_DEFAULT'));
    }

    private function getProductImageUrl(int $idProduct, int $idLang, int $idShop): string
    {
        $cover = \Product::getCover($idProduct);
        if (!$cover) return '';

        // getImageLink() ne prend pas id_shop en paramètre — le domaine
        // utilisé dépend du Context::shop courant. Même motif déjà établi
        // dans BehavioralCronManager/MonthlyReportManager : bascule
        // temporaire du contexte le temps de générer CE lien, restauration
        // immédiate après. Sans ça, l'image pointait vers le domaine de la
        // boutique du contexte d'exécution du cron, pas celle du client.
        $context = \Context::getContext();
        $originalShop = $context->shop;
        $context->shop = new \Shop($idShop);
        try {
            return $context->link->getImageLink('', (int) $cover['id_image'], \ImageType::getFormattedName('home'));
        } finally {
            $context->shop = $originalShop;
        }
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
