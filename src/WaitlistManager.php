<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * WaitlistManager — Liste d'attente produits
 *
 * Quand un produit est en rupture, le client s'inscrit.
 * Quand le stock remonte, Neria envoie un email unique avec réservation temporelle.
 */

if (!defined('_PS_VERSION_')) exit;

class WaitlistManager
{
    const TABLE      = 'neria_waitlist';
    const RESERVATION_HOURS = 4; // valeur par défaut si non configuré

    private \Db    $db;
    private string $prefix;
    private        $module;

    public function __construct($module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->prefix = _DB_PREFIX_;
    }

    // ── Inscription / désinscription ─────────────────────────────

    // Round 167 : $idProductAttribute optionnel (0 = toute déclinaison
    // confondue, comportement historique) — sans lui, un client inscrit en
    // attendant qu'une taille/couleur précise revienne en stock était
    // notifié dès que N'IMPORTE QUELLE combinaison du produit repassait
    // au-dessus de 0 (notifyProduct() ne sommait que le stock du PRODUIT),
    // même si la déclinaison réellement attendue restait à 0 — "votre
    // produit est de retour" pour un article que le client ne pouvait en
    // réalité pas acheter dans la taille/couleur voulue. Infrastructure
    // backend uniquement ce round : le paramètre reste 0 par défaut tant
    // qu'aucun appelant (front, BO) ne propose encore de sélection de
    // déclinaison — n'introduit aucun changement de comportement pour les
    // appels existants.
    public function register(int $idCustomer, int $idProduct, int $idShop, int $idProductAttribute = 0): bool
    {
        if ($this->isRegistered($idCustomer, $idProduct, $idShop, $idProductAttribute)) return true;
        $t   = $this->prefix . self::TABLE;
        $now = pSQL(date('Y-m-d H:i:s'));
        // La clé unique porte sur (id_customer, id_product, id_product_attribute,
        // id_shop) — un client multi-boutique doit pouvoir s'inscrire
        // séparément sur chaque boutique où le même produit est en rupture,
        // sans que l'inscription d'une boutique écrase celle d'une autre ;
        // de même pour 2 déclinaisons distinctes du même produit.
        return $this->db->execute(
            "INSERT INTO `{$t}` (id_customer, id_product, id_product_attribute, id_shop, registered_at, notified_at, claim_started_at)
             VALUES ({$idCustomer}, {$idProduct}, {$idProductAttribute}, {$idShop}, '{$now}', NULL, NULL)
             ON DUPLICATE KEY UPDATE registered_at = '{$now}', notified_at = NULL, claim_started_at = NULL"
        );
    }

    public function unregister(int $idCustomer, int $idProduct, int $idShop, int $idProductAttribute = 0): bool
    {
        return $this->db->delete(self::TABLE,
            'id_customer = ' . $idCustomer . ' AND id_product = ' . $idProduct
            . ' AND id_product_attribute = ' . $idProductAttribute . ' AND id_shop = ' . $idShop
        );
    }

    public function isRegistered(int $idCustomer, int $idProduct, int $idShop, int $idProductAttribute = 0): bool
    {
        return (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}" . self::TABLE . "`
             WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
               AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}
               AND notified_at IS NULL"
        ) > 0;
    }

    // ── Notification lors du retour en stock ─────────────────────

    public function notifyProduct(int $idProduct, int $idShop): int
    {
        // Round 167 : le plafond availableQty ci-dessous protège contre
        // l'envoi à toute la file, mais PAS contre deux appels concurrents
        // à notifyProduct() pour le MÊME produit (double hook
        // actionUpdateQuantity, ou hook + appel manuel BO quasi simultanés)
        // — chacun relit indépendamment le même stock disponible et notifie
        // jusqu'à availableQty inscrits DIFFÉRENTS, promettant en tout
        // jusqu'à 2× la quantité réellement disponible. GET_LOCK sérialise
        // les appels par produit+boutique ; timeout 0 (fail-fast) car un
        // 2e appel concurrent doit simplement attendre le prochain
        // déclenchement plutôt que bloquer la requête HTTP admin en cours
        // (ce hook tourne en synchrone dans actionUpdateQuantity).
        $lockName = 'neria_waitlist_notify_' . $idProduct . '_' . $idShop;
        if ((int) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 0)") !== 1) {
            return 0;
        }

        try {
            return $this->notifyProductLocked($idProduct, $idShop);
        } finally {
            $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        }
    }

    private function notifyProductLocked(int $idProduct, int $idShop): int
    {
        $rows = $this->db->executeS(
            "SELECT w.*, c.firstname, c.lastname, c.email, c.id_lang, w.id_shop,
                    DATEDIFF(NOW(), w.registered_at) AS days_waited
             FROM `{$this->prefix}" . self::TABLE . "` w
             INNER JOIN `{$this->prefix}customer` c ON c.id_customer = w.id_customer
             WHERE w.id_product = {$idProduct}
               AND w.id_shop = {$idShop}
               AND w.notified_at IS NULL
             ORDER BY w.registered_at ASC"
        );
        if (!is_array($rows) || empty($rows)) return 0;

        // Ne notifie jamais plus d'inscrits que la quantité réellement
        // disponible — sans cette limite, un réapprovisionnement de 2 unités
        // envoyait la même promesse "de retour en stock" à tous les inscrits
        // (parfois des dizaines), alors qu'une seule personne pourrait
        // réellement acheter. Premier inscrit, premier notifié (déjà trié
        // par registered_at ASC) ; les autres restent en attente pour le
        // prochain réapprovisionnement.
        // SUM direct sur TOUTES les lignes stock_available de ce produit
        // (aucun filtre id_product_attribute) — neria_waitlist est une liste
        // d'attente au niveau produit, pas par déclinaison. L'ancien code
        // passait id_product_attribute=0, qui ne lit que la combinaison
        // "sans attribut" (quasi toujours à 0 pour un produit à
        // déclinaisons). Un correctif précédent avait remplacé 0 par null en
        // pensant que StockAvailable::getQuantityAvailableByProduct(...,
        // null, ...) sommait alors toutes les déclinaisons — FAUX dans ce
        // cœur PrestaShop : null y est explicitement converti en 0
        // ("if ($id_product_attribute === null) { $id_product_attribute = 0; }",
        // classes/stock/StockAvailable.php), donc le bug d'origine
        // persistait à l'identique malgré ce correctif. Un SUM(quantity) SQL
        // direct (même technique déjà utilisée dans UpsellManager pour ce
        // même problème) est la seule façon fiable d'agréger tout le stock
        // d'un produit à déclinaisons.
        // Round 167 : en mode "stock partagé" entre boutiques d'un même
        // groupe (Shop::getGroup()->share_stock), PrestaShop stocke la
        // quantité réelle sur UNE SEULE ligne stock_available avec
        // id_shop=0/id_shop_group=X (cf. StockAvailable::addSqlShopRestriction()
        // dans le cœur) — jamais sur une ligne id_shop=$idShop. Le filtre
        // `id_shop = $idShop` ci-dessus renvoyait donc systématiquement 0
        // dans ce mode, empêchant TOUT inscrit d'être jamais notifié malgré
        // du stock réellement disponible. Même logique de bascule que le
        // cœur PS : ligne id_shop=0/id_shop_group=X si partagé, sinon
        // id_shop=$idShop/id_shop_group=0 comme avant.
        $shopGroup = new \Shop($idShop);
        $shareStock = (bool) $shopGroup->getGroup()->share_stock;
        if ($shareStock) {
            $stockWhere = " AND id_shop = 0 AND id_shop_group = " . (int) $shopGroup->id_shop_group;
        } else {
            $stockWhere = " AND id_shop = " . (int) $idShop . " AND id_shop_group = 0";
        }
        $availableQty = (int) $this->db->getValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM `" . _DB_PREFIX_ . "stock_available`
             WHERE id_product = " . (int) $idProduct . $stockWhere
        );
        // availableQty <= 0 : rien de réellement disponible (stock à 0 au moment de
        // l'appel, race condition avec la mise à jour, ou déclinaison sans stock géré) —
        // ne rien envoyer plutôt que de traiter toute la file sans plafond. Ce hook est
        // déclenché en synchrone dans la requête HTTP admin (actionUpdateQuantity) :
        // sans ce garde-fou, une file de plusieurs milliers d'inscrits pouvait dépasser
        // le timeout HTTP d'un hébergeur mutualisé.
        if ($availableQty <= 0) {
            return 0;
        }

        // Round 167 : une ligne inscrite pour une déclinaison PRÉCISE
        // (id_product_attribute != 0) ne doit être notifiée que si CETTE
        // déclinaison a réellement du stock — sinon un client attendant une
        // taille précise était notifié dès qu'une AUTRE taille du même
        // produit revenait en stock. Les inscriptions "toute déclinaison"
        // (id_product_attribute = 0, comportement historique) restent
        // filtrées uniquement sur le stock total du produit, déjà vérifié
        // ci-dessus.
        $rows = array_values(array_filter($rows, function (array $r) use ($idProduct, $stockWhere): bool {
            $attr = (int) $r['id_product_attribute'];
            if ($attr === 0) {
                return true;
            }
            $qty = (int) $this->db->getValue(
                "SELECT COALESCE(SUM(quantity), 0) FROM `" . _DB_PREFIX_ . "stock_available`
                 WHERE id_product = " . (int) $idProduct . " AND id_product_attribute = " . $attr . $stockWhere
            );
            return $qty > 0;
        }));

        $rows = array_slice($rows, 0, $availableQty);

        $sent = 0;
        foreach ($rows as $row) {
            $idCustomer = (int) $row['id_customer'];
            // Langue du client (déjà récupérée par la jointure ci-dessus) —
            // repli sur la langue par défaut de la boutique si absente/corrompue.
            $idLang     = (int) $row['id_lang'] ?: (int) \Configuration::get('PS_LANG_DEFAULT');

            // Round 138 : Shop::setContext() englobe désormais AUSSI le
            // constructeur Product et Product::getCover() — pas seulement
            // getImageLink()/getProductLink() ci-dessous. Le commentaire
            // précédent prétendait appliquer "le même correctif que
            // CollectionManager/LookCompletionManager", mais ne faisait en
            // réalité que réassigner Context->shop (version PARTIELLE,
            // insuffisante) — Product::getCover() et le constructeur
            // Product résolvent leurs données via le contexte boutique
            // STATIQUE (Shop::$context_id_shop), jamais mis à jour par une
            // simple réassignation de Context->shop (seul Shop::setContext()
            // le fait réellement). Round 137 avait déjà révélé que
            // CollectionManager, cité ici comme référence, n'avait lui-même
            // jamais reçu la version complète — même défaut ici.
            // hookActionUpdateQuantity() (neria.php) appelle notifyProduct()
            // en boucle sur TOUTES les boutiques du groupe pour un stock
            // partagé, mais le contexte statique reste fixé à la boutique du
            // BO admin qui a déclenché la mise à jour pendant toute la
            // boucle : sans ce switch complet, un client de la Boutique B
            // recevait le nom/prix/image/lien produit de la Boutique A.
            $originalShopId = \Shop::getContextShopID(true);
            \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);
            $context      = \Context::getContext();
            $originalShop = $context->shop;
            $context->shop = new \Shop($idShop);
            try {
                // Round 184 : !$product->active ajouté — contrairement à
                // LookCompletionManager::buildProductBlocks() (déjà
                // protégé), rien n'empêchait d'envoyer un email "de retour
                // en stock" pour un produit désactivé/retiré du catalogue
                // entre l'inscription sur liste d'attente et le réassort
                // (ex. stock résiduel non nettoyé avant la désactivation),
                // pointant vers une page produit indisponible.
                $product = new \Product($idProduct, false, $idLang, $idShop);
                if (!\Validate::isLoadedObject($product) || !$product->active) continue;

                $cover    = \Product::getCover($idProduct);
                $imageUrl = '';

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
                \Shop::setContext(\Shop::CONTEXT_SHOP, $originalShopId);
            }

            $daysWaited = max(1, (int) $row['days_waited']);
            $vars = [
                '{firstname}'          => $row['firstname'],
                '{days_waited_plural}' => $daysWaited > 1 ? 's' : '',
                '{product_name}'       => $product->name,
                '{product_url}'        => $productUrl,
                '{product_image}'      => $imageUrl,
                // Currency::PS_CURRENCY_DEFAULT scopé par $idShop — même
                // piège multi-boutique déjà corrigé (round 103) pour
                // {product_url}/{product_image} ci-dessus dans ce même bloc,
                // et (round 106) pour {shop_name} juste en-dessous. Sans ce
                // scope, {product_price} retombait sur la devise du contexte
                // d'exécution courant (BO admin qui a déclenché la mise à
                // jour de stock), pas celle de la boutique du client réel.
                // Round 184 : safeProductPrice() (Product::getPriceStatic())
                // remplace $product->price brut — même pattern que
                // UpsellManager::safeProductPrice()/LookCompletionManager.
                // $product->price est le champ ObjectModel catalogue (HT,
                // sans specific_price/promo) : un produit en promo au
                // moment du retour en stock affichait son prix plein tarif
                // dans l'email "de retour en stock", différent du prix
                // réel affiché sur la fiche produit au clic.
                '{product_price}'      => \NeriaTools::displayPrice($this->safeProductPrice($idProduct, $idShop), new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop)), $idLang),
                '{days_waited}'        => $daysWaited,
                '{reservation_hours}'  => (int) \Configuration::getGlobalValue('NERIA_WAITLIST_RESERVATION_HOURS') ?: self::RESERVATION_HOURS,
                // Configuration::get(..., $idShop) : round 106, même piège
                // multi-boutique déjà corrigé (round 103) pour
                // {product_url}/{product_image} ci-dessus dans ce même bloc.
                '{shop_name}'          => \Configuration::get('PS_SHOP_NAME', null, null, $idShop),
                // Scope le Mode Silence par produit (cf. CooldownManager) —
                // sans lui, une notification "de retour en stock" légitime
                // pour un DEUXIÈME produit dans la fenêtre de cooldown était
                // bloquée à tort comme doublon de la première.
                '{cooldown_scope}'     => 'product:' . $idProduct,
            ];

            // Réclamation atomique AVANT l'envoi : deux appels concurrents à
            // notifyProduct() (hook actionUpdateQuantity + auto-réparation
            // HealthCheckManager, ou deux mises à jour de stock rapprochées)
            // pouvaient tous deux SELECT la même ligne non notifiée avant que
            // l'un ou l'autre n'exécute l'UPDATE — le même client recevait
            // alors l'email "de retour en stock" deux fois. L'UPDATE
            // conditionné sur notified_at IS NULL agit comme un verrou
            // compare-and-swap : un seul processus peut le remporter.
            //
            // claim_started_at (distincte de notified_at) pose la réclamation ;
            // notified_at n'est posé qu'après confirmation réelle de l'envoi.
            // Si le process meurt entre les deux, HealthCheckManager peut
            // détecter sans ambiguïté un claim resté sans notified_at au-delà
            // d'1h et le libérer — impossible à faire en toute sécurité avec
            // notified_at seul, qui est aussi l'état de succès permanent.
            // AND claim_started_at IS NULL (ou expiré depuis plus d'1h)
            // manquait ici : la condition ne testait que notified_at IS NULL,
            // qui ne se pose qu'APRÈS l'envoi réussi. Pendant toute la
            // fenêtre entre ce premier claim et Mail::Send() ci-dessous,
            // notified_at restait NULL — un second appel concurrent (le
            // scénario exact que ce verrou est censé empêcher, décrit dans
            // le commentaire ci-dessus) matchait la même ligne et obtenait
            // lui aussi Affected_Rows() > 0, envoyant le même email deux
            // fois. Le délai d'1h permet de récupérer un claim orphelin
            // (process mort avant notified_at) sans bloquer le client à vie.
            // Round 187 : id_product_attribute ajouté à CETTE requête et aux
            // 3 suivantes de ce bloc (re-vérification, notified_at, libération
            // de réclamation) — absent jusqu'ici alors que le SELECT initial
            // (plus haut) scope déjà correctement par déclinaison précise
            // (round 167, cf. commentaire ci-dessus sur $rows). Un client
            // inscrit sur DEUX déclinaisons différentes du même produit
            // (ex. taille S et taille L) a deux lignes distinctes en base.
            // Sans ce filtre, traiter la ligne de la taille S (réassortie)
            // matchait AUSSI la ligne taille L (jamais réassortie, filtrée
            // hors de $rows plus haut) — la marquant à tort notified_at alors
            // qu'aucun email n'a jamais été envoyé pour cette déclinaison :
            // le client perd silencieusement sa notification de retour en
            // stock pour la taille L.
            $idProductAttribute = (int) $row['id_product_attribute'];
            $claimed = $this->db->execute(
                "UPDATE `{$this->prefix}" . self::TABLE . "`
                 SET claim_started_at = NOW()
                 WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                   AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}
                   AND notified_at IS NULL
                   AND (claim_started_at IS NULL OR claim_started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))"
            ) && $this->db->Affected_Rows() > 0;

            if (!$claimed) {
                continue; // un autre processus a déjà pris/prend cette notification
            }

            // Round 167 : unregister() supprimait la ligne sans condition
            // sur claim_started_at — un client se désinscrivant exactement
            // dans la fenêtre entre le claim ci-dessus et Mail::Send()
            // ci-dessous recevait quand même l'email "de retour en stock"
            // qu'il venait de refuser (l'UPDATE final notified_at trouvait
            // simplement 0 ligne, sans erreur). Cette re-vérification juste
            // avant l'envoi referme la majeure partie de la fenêtre — elle
            // ne peut pas annuler un envoi déjà en cours (latence SMTP de
            // Mail::Send() elle-même reste un residu de fenêtre inévitable
            // dans tout système de notification "au moins une fois").
            $stillRegistered = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$this->prefix}" . self::TABLE . "`
                 WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                   AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}"
            ) > 0;
            if (!$stillRegistered) {
                continue; // désinscrit entre le claim et l'envoi
            }

            // Round 194 : Mail::Send() du cœur PrestaShop retourne TOUJOURS
            // true quand le hook actionEmailSendBefore annule l'envoi
            // (bounce/blacklist/préférences/cooldown) — même piège déjà
            // corrigé pour CollectionManager (round 180)/LookCompletionManager
            // (round 190)/QueueManager/OrderTriggersManager (round 178/192)
            // mais jamais étendu ici. Sans ces garde-fous, notified_at était
            // marqué (bloc if ($mailed) plus bas) même sur un envoi bloqué
            // par le hook — le client était exclu À VIE de cette notification
            // "de retour en stock" pour ce produit, même après la levée du
            // blocage (bounce/blacklist levés, préférence réactivée...).
            if (class_exists('BounceManager') && \BounceManager::isBounced($row['email'])) {
                $this->db->execute(
                    "UPDATE `{$this->prefix}" . self::TABLE . "`
                     SET claim_started_at = NULL
                     WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                       AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}"
                );
                continue;
            }
            if (class_exists('BlacklistManager')) {
                $langIso = class_exists('TranslationEngine')
                    ? (new \TranslationEngine($this->module))->langFromId($idLang)
                    : (string) (\Language::getIsoById($idLang) ?: '');
                if ((new \BlacklistManager($idShop))->isBlacklisted('waitlist_available', $langIso)) {
                    $this->db->execute(
                        "UPDATE `{$this->prefix}" . self::TABLE . "`
                         SET claim_started_at = NULL
                         WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                           AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}"
                    );
                    continue;
                }
            }
            if (class_exists('PreferencesManager')
                && !(new \PreferencesManager($this->module))->isAllowed($idCustomer, 'waitlist_available', $idShop, $row['email'])
            ) {
                $this->db->execute(
                    "UPDATE `{$this->prefix}" . self::TABLE . "`
                     SET claim_started_at = NULL
                     WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                       AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}"
                );
                continue;
            }
            if (class_exists('ConfigManager') && class_exists('CooldownManager')
                && (new \ConfigManager($this->module))->isCooldownEnabled()
            ) {
                $cdMinutes = (new \ConfigManager($this->module))->getCooldownMinutes();
                if ((new \CooldownManager())->isDuplicate($row['email'], 'waitlist_available', $cdMinutes, $idShop, 0, 'product:' . $idProduct)) {
                    $this->db->execute(
                        "UPDATE `{$this->prefix}" . self::TABLE . "`
                         SET claim_started_at = NULL
                         WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                           AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}"
                    );
                    continue;
                }
            }

            try {
                $mailed = \Mail::Send(
                    $idLang,
                    'waitlist_available',
                    '',
                    $vars,
                    $row['email'],
                    trim($row['firstname'] . ' ' . $row['lastname']) ?: null,
                    null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/',
                    false,
                    $idShop
                );

                if ($mailed) {
                    $this->db->execute(
                        "UPDATE `{$this->prefix}" . self::TABLE . "`
                         SET notified_at = NOW()
                         WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                           AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}"
                    );
                    $sent++;

                    if (class_exists('WatchdogManager')) {
                        (new \WatchdogManager($this->module))->info(
                            sprintf('Waitlist → %s (produit #%d, %d j d\'attente)', $row['email'], $idProduct, $daysWaited),
                            'waitlist_available', 'WaitlistManager'
                        );
                    }
                } else {
                    // Envoi échoué : on libère la réclamation pour permettre un nouvel essai.
                    $this->db->execute(
                        "UPDATE `{$this->prefix}" . self::TABLE . "`
                         SET claim_started_at = NULL
                         WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                           AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}"
                    );
                }
            } catch (\Throwable $e) {
                // Envoi en échec : on libère la réclamation pour permettre un nouvel essai.
                $this->db->execute(
                    "UPDATE `{$this->prefix}" . self::TABLE . "`
                     SET claim_started_at = NULL
                     WHERE id_customer = {$idCustomer} AND id_product = {$idProduct}
                       AND id_product_attribute = {$idProductAttribute} AND id_shop = {$idShop}"
                );
                if (class_exists('WatchdogManager')) {
                    (new \WatchdogManager($this->module))->error(
                        'Waitlist notify error : ' . $e->getMessage(),
                        'waitlist_available', 'WaitlistManager'
                    );
                }
            }
        }

        return $sent;
    }

    /**
     * Round 184 : prix réel (taxe + specific_price/promo appliqués), même
     * logique que UpsellManager::safeProductPrice()/LookCompletionManager::
     * safeProductPrice() — Product::getPriceStatic() peut nécessiter un
     * panier en contexte pour résoudre certaines règles de taxe ; ce fichier
     * tourne typiquement depuis un cron/hook admin sans panier actif, d'où
     * le panier temporaire ci-dessous.
     */
    private function safeProductPrice(int $idProduct, int $idShop): float
    {
        $ctx     = \Context::getContext();
        $hadCart = \Validate::isLoadedObject($ctx->cart);

        if (!$hadCart) {
            $tmp = new \Cart();
            // Round 198 : PS_CURRENCY_DEFAULT scopé par $idShop (la VRAIE
            // boutique du client) — absent jusqu'ici, le panier temporaire
            // utilisait $ctx->currency->id (devise AMBIANTE du process :
            // reliquat d'une boutique précédente dans une boucle
            // multi-boutiques, ou devise de session de l'employé BO qui a
            // déclenché le cron). NeriaTools::displayPrice() (appelant,
            // ci-dessus) ne fait QUE formater le montant retourné ici avec
            // le symbole de la devise de $idShop — jamais de conversion. Un
            // écart entre les deux faisait afficher un montant numérique
            // dans la mauvaise devise avec le symbole de la bonne (ex.
            // "110,00 €" pour un produit à 100€), un écart réel avec le
            // prix qui sera facturé au client.
            $tmp->id_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop) ?: (int) ($ctx->currency->id ?? \Configuration::get('PS_CURRENCY_DEFAULT'));
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

    // ── Stats BO ─────────────────────────────────────────────────

    /**
     * @param int|null $idShop Boutique à filtrer — par défaut la boutique du
     *                         contexte courant. Auparavant sans filtre du
     *                         tout : sur une install multi-boutiques, les
     *                         compteurs BO ("Liste d'attente") incluaient les
     *                         inscriptions de TOUTES les boutiques du module.
     */
    public function getStats(?int $idShop = null): array
    {
        $idShop = $idShop ?? (int) \Context::getContext()->shop->id;
        $t = $this->prefix . self::TABLE;
        return [
            'subscribers' => (int) $this->db->getValue("SELECT COUNT(*) FROM `{$t}` WHERE notified_at IS NULL AND id_shop = {$idShop}"),
            'products'    => (int) $this->db->getValue("SELECT COUNT(DISTINCT id_product) FROM `{$t}` WHERE notified_at IS NULL AND id_shop = {$idShop}"),
            'notified'    => (int) $this->db->getValue("SELECT COUNT(*) FROM `{$t}` WHERE notified_at IS NOT NULL AND id_shop = {$idShop}"),
            'notified30'  => (int) $this->db->getValue("SELECT COUNT(*) FROM `{$t}` WHERE notified_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND id_shop = {$idShop}"),
        ];
    }

    /**
     * @param int|null $idShop Boutique à filtrer — par défaut la boutique du
     *                         contexte courant. Même correctif que getStats().
     */
    public function getTopProducts(int $limit = 10, ?int $idShop = null): array
    {
        $idShop = $idShop ?? (int) \Context::getContext()->shop->id;
        $rows = $this->db->executeS(
            "SELECT w.id_product, COUNT(*) AS nb,
                    pl.name AS product_name,
                    MAX(DATEDIFF(NOW(), w.registered_at)) AS max_wait_days
             FROM `{$this->prefix}" . self::TABLE . "` w
             LEFT JOIN `{$this->prefix}product_lang` pl
                ON pl.id_product = w.id_product
               AND pl.id_lang = " . (int) \Configuration::get('PS_LANG_DEFAULT') . "
             WHERE w.notified_at IS NULL AND w.id_shop = {$idShop}
             GROUP BY w.id_product, pl.name
             ORDER BY nb DESC
             LIMIT " . (int) $limit
        );
        return is_array($rows) ? $rows : [];
    }

    /**
     * Round 167 : purge les inscriptions jamais satisfaites au-delà de
     * $olderThanDays — sans cette purge, une inscription pour un produit
     * jamais réapprovisionné restait indéfiniment en base (notified_at IS
     * NULL), grossissant neria_waitlist sans limite et faussant à terme
     * getStats()/getTopProducts(). N'affecte que les inscriptions non
     * satisfaites (notified_at IS NULL) — un historique de notification
     * déjà envoyée est conservé (utile pour les statistiques).
     */
    // Round 179 (audit transversal de fin de série) : $idShop optionnel
    // ajouté (défaut null = toutes boutiques, comportement historique
    // inchangé pour l'appel cron existant dans neria.php) — cohérent avec
    // getStats()/getTopProducts() ci-dessus, qui suivent déjà ce même
    // pattern "?int $idShop = null" dans cette classe. Auparavant cette
    // purge était la SEULE méthode de la classe sans aucun moyen de scoper
    // par boutique (register()/isRegistered() reçoivent $idShop en
    // paramètre obligatoire) — un appel scopé à une boutique aurait
    // silencieusement purgé les inscriptions en attente de TOUTES les
    // boutiques, avec des seuils métier potentiellement différents par
    // boutique.
    public function purgeStaleEntries(int $olderThanDays = 365, ?int $idShop = null): int
    {
        $shopFilter = $idShop !== null ? ' AND id_shop = ' . (int) $idShop : '';
        $this->db->execute(
            "DELETE FROM `{$this->prefix}" . self::TABLE . "`
             WHERE notified_at IS NULL
               AND registered_at < DATE_SUB(NOW(), INTERVAL " . (int) $olderThanDays . " DAY)"
               . $shopFilter
        );
        return (int) $this->db->Affected_Rows();
    }
}
