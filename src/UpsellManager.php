<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — UpsellManager
 *
 * Sélectionne automatiquement un produit complémentaire à suggérer
 * dans les emails post-achat. Algorithme en 3 niveaux :
 *
 *   1. Accessoires PS définis par le marchand (ps_accessory)
 *   2. Co-achat : produits souvent commandés en même temps
 *   3. Bestseller de la même catégorie
 *
 * Génère également le bloc HTML/TXT prêt à injecter dans le template.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UpsellManager
{
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
    // POINT D'ENTRÉE PUBLIC
    // ============================================================

    /**
     * Retourne les données du produit à suggérer, ou null si rien trouvé.
     *
     * @param int $idOrder    Commande déclenchante
     * @param int $idLang     Langue de l'email
     * @return array|null  ['name', 'price_formatted', 'image_url', 'product_url',
     *                      'category', 'reason']
     */
    public function getUpsellProduct(int $idOrder, int $idLang, ?int $idShop = null): ?array
    {
        if ($idLang <= 0) {
            $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        }

        $orderProducts = $this->getOrderProductIds($idOrder);
        if (empty($orderProducts)) {
            return null;
        }

        $idCustomer    = $this->getOrderCustomerId($idOrder);
        $alreadyBought = $idCustomer > 0 ? $this->getCustomerProductIds($idCustomer) : [];
        $excluded      = array_values(array_unique(array_merge($orderProducts, $alreadyBought)));

        // enrich() peut renvoyer null si le produit trouvé n'a pas d'image
        // (pas de bloc visuel possible) — dans ce cas, il ne faut PAS
        // abandonner toute la suggestion : le tier suivant peut très bien
        // avoir un produit avec une image valide. Le "return" immédiat
        // d'origine court-circuitait les Tiers 2/3 dès qu'un simple produit
        // Tier 1 sans photo était trouvé.

        // Tier 1 — accessoires définis dans le back-office produit
        $row = $this->findByAccessories($orderProducts, $excluded, $idLang, $idShop);
        if ($row) {
            // Round 184 : $idCustomer propagé — enrich()/safeProductPrice()
            // ignoraient jusqu'ici le client réel, résolvant le prix avec le
            // groupe tarifaire "visiteur" par défaut du contexte cron. Un
            // client B2B à tarif négocié (specific_price restreinte à son
            // id_group) voyait un prix upsell différent (plus élevé) de
            // celui qu'il paierait réellement au checkout.
            $result = $this->enrich($row, $idLang, 'L\'accessoire parfait', $idShop, $idCustomer);
            if ($result) {
                return $result;
            }
        }

        // Tier 2 — co-achat (collaborative filtering léger)
        $row = $this->findByCoPurchase($orderProducts, $excluded, $idLang, $idShop);
        if ($row) {
            $result = $this->enrich($row, $idLang, 'Souvent acheté ensemble', $idShop, $idCustomer);
            if ($result) {
                return $result;
            }
        }

        // Tier 3 — meilleur vendeur même catégorie
        $row = $this->findByCategoryBestseller($orderProducts, $excluded, $idLang, $idShop);
        if ($row) {
            $result = $this->enrich($row, $idLang, 'Notre suggestion pour vous', $idShop, $idCustomer);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Raccourci pour les campagnes saisonnières "idées cadeaux" :
     * cherche la dernière commande valide du client et retourne le bloc HTML upsell.
     * Retourne une chaîne vide si aucun produit ne peut être suggéré.
     *
     * $idShop obligatoire : sans ce filtre, un client partagé entre
     * boutiques (compte client mutualisé) reçoit une suggestion basée sur
     * sa dernière commande TOUTES boutiques confondues — produit pouvant
     * être absent du catalogue de la boutique qui envoie la campagne
     * (image/lien cassés), ou simple fuite d'information entre boutiques.
     * Même raisonnement que le scope id_shop déjà appliqué partout ailleurs
     * dans BehavioralCronManager.
     */
    public function renderUpsellBlock(int $idCustomer, int $idLang, int $idShop): string
    {
        $upsell = $this->findUpsellForCustomer($idCustomer, $idLang, $idShop);
        if (!$upsell) {
            return '';
        }

        $config = new \ConfigManager($this->module);
        return $this->buildHtmlBlock($upsell, $config);
    }

    /**
     * Équivalent TXT de renderUpsellBlock() — même produit suggéré, à
     * injecter à la place de {upsell_block_txt} dans la version texte.
     */
    public function renderUpsellBlockTxt(int $idCustomer, int $idLang, int $idShop): string
    {
        $upsell = $this->findUpsellForCustomer($idCustomer, $idLang, $idShop);
        if (!$upsell) {
            return '';
        }

        return $this->buildTxtBlock($upsell);
    }

    private function findUpsellForCustomer(int $idCustomer, int $idLang, int $idShop): ?array
    {
        $row = $this->db->getRow(
            "SELECT id_order FROM `{$this->prefix}orders`
             WHERE id_customer = " . (int) $idCustomer . "
               AND id_shop = " . (int) $idShop . "
               AND valid = 1
             ORDER BY date_add DESC"
        );
        if (!$row) {
            return null;
        }

        return $this->getUpsellProduct((int) $row['id_order'], $idLang, $idShop);
    }

    // ============================================================
    // GÉNÉRATION DU BLOC HTML
    // ============================================================

    /**
     * Génère le bloc HTML complet (inline-styles, email-safe) à injecter
     * à la place du placeholder {upsell_block} dans le template compilé.
     * Retourne une chaîne vide si $upsell est null.
     */
    public function buildHtmlBlock(?array $upsell, ConfigManager $config): string
    {
        if ($upsell === null) {
            return '';
        }

        $design  = $config->getDesignConfig();
        $accent  = htmlspecialchars($design['color_accent']    ?? '#b38b59', ENT_QUOTES);
        $text    = htmlspecialchars($design['color_text']      ?? '#2b2520', ENT_QUOTES);
        $muted   = '#8c857e';
        $border  = '#e8e0d8';

        $name     = htmlspecialchars($upsell['name']          ?? '', ENT_QUOTES);
        $price    = htmlspecialchars($upsell['price_formatted'] ?? '', ENT_QUOTES);
        $image    = htmlspecialchars($upsell['image_url']     ?? '', ENT_QUOTES);
        $url      = htmlspecialchars($upsell['product_url']   ?? '#', ENT_QUOTES);
        $category = htmlspecialchars($upsell['category']      ?? '', ENT_QUOTES);
        $reason   = htmlspecialchars($upsell['reason']        ?? '', ENT_QUOTES);

        return '
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin:32px 0 0 0; border-top:1px solid ' . $border . ';">
  <tr>
    <td style="padding-top:28px;">

      <!-- Étiquette raison -->
      <p style="margin:0 0 16px 0; font-size:11px; font-weight:700; letter-spacing:.1em;
                text-transform:uppercase; color:' . $muted . ';">' . $reason . '</p>

      <!-- Produit : image + infos -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <!-- Image -->
          <td width="110" valign="top" style="padding-right:20px;">
            <a href="' . $url . '" style="text-decoration:none; display:block;">
              <img src="' . $image . '" width="110" alt="' . $name . '"
                   style="display:block; border-radius:3px; width:110px;" />
            </a>
          </td>

          <!-- Texte -->
          <td valign="middle">
            ' . ($category !== '' ? '<p style="margin:0 0 5px 0; font-size:10px; font-weight:700;
                  letter-spacing:.1em; text-transform:uppercase; color:' . $muted . ';">'
                  . $category . '</p>' : '') . '
            <p style="margin:0 0 8px 0; font-size:16px; font-weight:600;
                      color:' . $text . '; line-height:1.3;">' . $name . '</p>
            <p style="margin:0 0 18px 0; font-size:15px; font-weight:700;
                      color:' . $accent . ';">' . $price . '</p>
            <a href="' . $url . '"
               style="display:inline-block; padding:12px 28px; background:#2b2520;
                      color:#ffffff; text-decoration:none; font-size:11px; font-weight:700;
                      letter-spacing:.08em; text-transform:uppercase; border-radius:2px;">
              Découvrir
            </a>
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>';
    }

    /**
     * Génère la section TXT à injecter à la place de {upsell_block_txt}.
     */
    public function buildTxtBlock(?array $upsell): string
    {
        if ($upsell === null) {
            return '';
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '---';
        $lines[] = $upsell['reason'] ?? '';
        if (!empty($upsell['category'])) {
            $lines[] = $upsell['category'];
        }
        $lines[] = $upsell['name'] ?? '';
        $lines[] = $upsell['price_formatted'] ?? '';
        $lines[] = $upsell['product_url'] ?? '';
        $lines[] = '---';

        return implode("\n", $lines);
    }

    // ============================================================
    // ALGORITHME — 3 niveaux
    // ============================================================

    private function findByAccessories(array $productIds, array $excluded, int $idLang, ?int $idShop = null): ?array
    {
        $inProducts = $this->intList($productIds);
        $notIn      = $this->notInClause('p.id_product', $excluded);
        // Round 145 : filtre id_shop ajouté au SUM de stock — voir le
        // commentaire détaillé au-dessus de la clause SUM ci-dessous.
        $stockShop = $idShop !== null ? ' AND sa.id_shop = ' . (int) $idShop : '';

        // GROUP BY + ORDER BY déterministe : un produit peut avoir plusieurs
        // catégories (JOIN category_product) — sans ces clauses, le résultat
        // dépend de l'ordre physique des lignes en base et peut changer d'un
        // appel à l'autre pour un même client/commande sans raison fonctionnelle.
        return $this->db->getRow(
            "SELECT p.id_product, MIN(pl.name) AS name, MIN(cp.id_category) AS id_category
             FROM `{$this->prefix}accessory` a
             JOIN `{$this->prefix}product` p
                  ON a.id_product_2 = p.id_product AND p.active = 1
             JOIN `{$this->prefix}product_lang` pl
                  ON p.id_product = pl.id_product AND pl.id_lang = " . (int) $idLang . "
             JOIN `{$this->prefix}category_product` cp ON p.id_product = cp.id_product
             WHERE a.id_product_1 IN ({$inProducts})
               {$notIn}
               -- Somme le stock sur toutes les déclinaisons (pas seulement
               -- id_product_attribute=0) : un produit géré par déclinaisons
               -- n'a quasiment jamais de stock sur la ligne 'sans attribut',
               -- ce qui excluait systématiquement tout produit à déclinaisons
               -- des suggestions, même largement disponible. Voir correctif
               -- identique dans WaitlistManager::notifyProduct(). Round 145 :
               -- filtre id_shop ajouté — sans lui, un produit épuisé sur la
               -- boutique qui envoie l'email mais en stock sur une AUTRE
               -- boutique de la même install passait quand même le filtre
               -- (SUM global toutes boutiques confondues).
               AND (SELECT SUM(sa.quantity) FROM `{$this->prefix}stock_available` sa
                    WHERE sa.id_product = p.id_product{$stockShop}) > 0
             GROUP BY p.id_product
             ORDER BY p.id_product ASC"
        ) ?: null;
    }

    private function findByCoPurchase(array $productIds, array $excluded, int $idLang, ?int $idShop = null): ?array
    {
        $inProducts = $this->intList($productIds);
        $notIn      = $this->notInClause('od2.product_id', $excluded);
        $stockShop  = $idShop !== null ? ' AND sa.id_shop = ' . (int) $idShop : '';
        // Round 178 : le stock est bien scopé par boutique (ci-dessous),
        // mais le classement par popularité (COUNT(*) AS freq, fréquence de
        // co-achat) agrégeait order_detail de TOUTES les boutiques — un
        // produit populaire uniquement chez une boutique B2B pouvait ainsi
        // être poussé comme « souvent acheté ensemble » à un client d'une
        // toute autre boutique B2C, alors que le stock lui est correctement
        // scopé. order_detail a sa propre colonne id_shop (pas besoin de
        // JOIN orders).
        $freqShop = $idShop !== null ? ' AND od2.id_shop = ' . (int) $idShop : '';

        return $this->db->getRow(
            "SELECT od2.product_id AS id_product, MIN(pl.name) AS name,
                    MIN(cp.id_category) AS id_category, COUNT(*) AS freq
             FROM `{$this->prefix}order_detail` od1
             JOIN `{$this->prefix}order_detail` od2
                  ON od1.id_order = od2.id_order AND od2.product_id != od1.product_id
             JOIN `{$this->prefix}product` p
                  ON od2.product_id = p.id_product AND p.active = 1
             JOIN `{$this->prefix}product_lang` pl
                  ON p.id_product = pl.id_product AND pl.id_lang = " . (int) $idLang . "
             JOIN `{$this->prefix}category_product` cp ON p.id_product = cp.id_product
             WHERE od1.product_id IN ({$inProducts})
               {$notIn}
               {$freqShop}
               -- Somme le stock sur toutes les déclinaisons, scopé id_shop —
               -- voir correctif identique dans findByAccessories() ci-dessus.
               AND (SELECT SUM(sa.quantity) FROM `{$this->prefix}stock_available` sa
                    WHERE sa.id_product = p.id_product{$stockShop}) > 0
             GROUP BY od2.product_id
             ORDER BY freq DESC"
        ) ?: null;
    }

    private function findByCategoryBestseller(array $productIds, array $excluded, int $idLang, ?int $idShop = null): ?array
    {
        // Catégories des produits commandés
        $inProducts = $this->intList($productIds);
        $catRows    = $this->db->executeS(
            "SELECT DISTINCT id_category
             FROM `{$this->prefix}category_product`
             WHERE id_product IN ({$inProducts})"
        ) ?: [];

        $categories = array_column($catRows, 'id_category');
        if (empty($categories)) {
            return null;
        }

        $inCats = $this->intList($categories);
        $notIn  = $this->notInClause('p.id_product', $excluded);
        $stockShop = $idShop !== null ? ' AND sa.id_shop = ' . (int) $idShop : '';
        // Round 178 : voir commentaire de findByCoPurchase() ci-dessus — le
        // sous-COUNT(*) de ventes (ORDER BY plus bas) agrégeait aussi
        // order_detail de toutes les boutiques sans filtre id_shop.
        $salesShop = $idShop !== null ? ' AND od.id_shop = ' . (int) $idShop : '';

        // Round 167 : GROUP BY manquant, contrairement à findByAccessories()/
        // findByCoPurchase() — un produit appartenant à plusieurs des
        // catégories candidates (cp.id_category IN ({$inCats})) apparaissait
        // en plusieurs lignes distinctes (une par catégorie), rendant
        // l'ORDER BY non déterministe entre elles à égalité de ventes :
        // getRow() pouvait renvoyer tantôt la ligne catégorie A tantôt B
        // pour le même appel logique — l'étiquette catégorie affichée dans
        // l'email variait sans raison fonctionnelle pour un même produit.
        return $this->db->getRow(
            "SELECT p.id_product, MIN(pl.name) AS name, MIN(cp.id_category) AS id_category
             FROM `{$this->prefix}category_product` cp
             JOIN `{$this->prefix}product` p
                  ON cp.id_product = p.id_product AND p.active = 1
             JOIN `{$this->prefix}product_lang` pl
                  ON p.id_product = pl.id_product AND pl.id_lang = " . (int) $idLang . "
             WHERE cp.id_category IN ({$inCats})
               {$notIn}
               -- Somme le stock sur toutes les déclinaisons, scopé id_shop —
               -- voir correctif identique dans findByAccessories() plus haut.
               AND (SELECT SUM(sa.quantity) FROM `{$this->prefix}stock_available` sa
                    WHERE sa.id_product = p.id_product{$stockShop}) > 0
             GROUP BY p.id_product
             ORDER BY (
                 SELECT COUNT(*) FROM `{$this->prefix}order_detail` od
                 WHERE od.product_id = p.id_product{$salesShop}
             ) DESC, p.id_product ASC"
        ) ?: null;
    }

    // ============================================================
    // ENRICHISSEMENT — prix, image, URL
    // ============================================================

    /**
     * Devise résolue par $idShop, pas $this->context->currency (devise du
     * contexte d'EXÉCUTION courant, pas celle de la boutique du client) —
     * même correctif déjà appliqué dans
     * CollectionManager::processCollection() pour {missing_price}. En cron,
     * le contexte reste sur la 1re boutique traitée : un client d'une autre
     * boutique avec une devise différente recevait sinon un prix affiché
     * dans la mauvaise devise. Extraite pour être testable sans dépendre du
     * reste de enrich() (image produit, etc.).
     */
    private function resolveDisplayCurrency(?int $idShop): \Currency
    {
        if ($idShop !== null) {
            return new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop));
        }
        // $this->context->currency peut être null hors contexte front/BO
        // (cron, CLI, ou reflexion de test) — repli sur la devise par
        // défaut globale plutôt qu'un TypeError.
        return $this->context->currency instanceof \Currency
            ? $this->context->currency
            : new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
    }

    private function enrich(array $row, int $idLang, string $reason, ?int $idShop = null, int $idCustomer = 0): ?array
    {
        $idProduct = (int) $row['id_product'];

        $imageUrl = $this->getProductImageUrl($idProduct, $idShop);
        if (!$imageUrl) {
            return null; // Pas d'image → pas de bloc visuel
        }

        $categoryName = '';
        if (!empty($row['id_category'])) {
            $categoryName = (string) $this->db->getValue(
                "SELECT name FROM `{$this->prefix}category_lang`
                 WHERE id_category = " . (int) $row['id_category'] . "
                   AND id_lang = " . (int) $idLang
            );
        }

        $price = $this->safeProductPrice($idProduct, $idLang, $idCustomer, $idShop);
        // Formatage localisé (séparateur décimal + position du symbole selon
        // la langue) — auparavant une virgule française codée en dur,
        // affichée dans le bloc upsell de CHAQUE confirmation de commande,
        // y compris pour les 18 langues non-FR.
        $priceFormatted = \NeriaTools::displayPrice($price, $this->resolveDisplayCurrency($idShop), $idLang);

        // $idShop en 6e argument : même correctif que CollectionManager pour
        // {missing_product_url} — sans lui, un client d'une autre boutique
        // recevait un lien pointant vers le domaine/catalogue de la
        // boutique du contexte d'exécution courant (cron), potentiellement
        // cassé (404) ou menant au mauvais magasin.
        $productUrl = $this->context->link->getProductLink(
            $idProduct, null, null, null, $idLang, $idShop
        );

        return [
            'id_product'      => $idProduct,
            'name'            => $row['name']  ?? '',
            'price_formatted' => $priceFormatted,
            'image_url'       => $imageUrl,
            'product_url'     => $productUrl,
            'category'        => $categoryName,
            'reason'          => $reason,
        ];
    }

    /**
     * Prix TTC d'un produit, calculable même hors contexte employé/cart
     * (cron, CLI). Sans cart ni employé, Product::getPriceStatic() fait un
     * die() (Product.php) ; on fournit donc un cart transitoire le temps du
     * calcul, puis on restaure le contexte.
     */
    private function safeProductPrice(int $idProduct, int $idLang, int $idCustomer = 0, ?int $idShop = null): float
    {
        $ctx     = $this->context;
        $hadCart = \Validate::isLoadedObject($ctx->cart);

        if (!$hadCart) {
            $tmp = new \Cart();
            // Round 198 : PS_CURRENCY_DEFAULT scopé par $idShop quand connu
            // — même correctif que WaitlistManager/LookCompletionManager::
            // safeProductPrice(). resolveDisplayCurrency() (appelant, cf.
            // commentaire round 184 ci-dessus) résout déjà correctement la
            // devise d'AFFICHAGE par $idShop, mais le MONTANT lui-même
            // continuait d'être calculé dans la devise AMBIANTE du process
            // ($ctx->currency) — la correction de l'affichage ne suffisait
            // pas si la valeur sous-jacente restait dans la mauvaise devise.
            $tmp->id_currency = $idShop !== null
                ? ((int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop) ?: (int) ($ctx->currency->id ?? \Configuration::get('PS_CURRENCY_DEFAULT')))
                : (int) ($ctx->currency->id ?? \Configuration::get('PS_CURRENCY_DEFAULT'));
            $tmp->id_lang     = $idLang;
            $ctx->cart        = $tmp;
        }

        try {
            // Round 184 : $idCustomer transmis à getPriceStatic() — sans
            // lui, la méthode retombe sur Group::getCurrent()->id (groupe
            // "visiteur" par défaut du contexte cron), ignorant tout groupe
            // tarifaire réel du client (ex. tarif B2B négocié via
            // specific_price restreinte à son id_group).
            return (float) \Product::getPriceStatic($idProduct, true, null, 2, null, false, true, 1, false, $idCustomer > 0 ? $idCustomer : null);
        } finally {
            if (!$hadCart) {
                $ctx->cart = null;
            }
        }
    }

    private function getProductImageUrl(int $idProduct, ?int $idShop = null): ?string
    {
        $cover = \Image::getCover($idProduct);
        if (empty($cover['id_image'])) {
            return null;
        }

        $idImage = (int) $cover['id_image'];
        $type    = \ImageType::getFormattedName('home');
        // Dossier éclaté (ex : "1/8/") ; vide si l'ancien stockage à plat est actif.
        $folder  = \Configuration::get('PS_LEGACY_IMAGES')
            ? ''
            : \Image::getImgFolderStatic($idImage);

        // URL directe (non conviviale) : résout toujours en HTTP 200, sans
        // dépendre de la réécriture .htaccess des images du marchand —
        // indispensable pour l'affichage dans les emails (Gmail, Outlook…).
        // $idShop transmis à getBaseLink() quand disponible : sans lui,
        // getBaseLink(null, ...) résout le domaine de LA BOUTIQUE DU
        // CONTEXTE d'exécution (souvent celle où le cron a démarré), pas
        // celle du client réel — sur une install multi-boutiques à domaines
        // distincts, l'image de l'email upsell pointait vers le mauvais
        // domaine.
        $ssl  = (bool) \Configuration::get('PS_SSL_ENABLED');
        $base = $this->context->link->getBaseLink($idShop, $ssl);

        return $base . 'img/p/' . $folder . $idImage . '-' . $type . '.jpg';
    }

    // ============================================================
    // ENREGISTREMENT — suggestion, clic, conversion
    // ============================================================

    /**
     * Enregistre la suggestion dans ps_neria_upsell.
     * Retourne l'id_upsell généré, ou 0 en cas d'échec.
     */
    /**
     * @param int $idShop Boutique d'origine de la suggestion — cf.
     *                     checkConversions() qui filtre désormais dessus pour
     *                     éviter qu'un achat du même produit sur une AUTRE
     *                     boutique (client partagé multi-boutiques) ne
     *                     s'attribue à tort à une suggestion envoyée ici.
     */
    public function recordSuggestion(int $idCustomer, int $idOrderSource, array $upsell, int $idShop): int
    {
        $tierMap = [
            'L\'accessoire parfait'       => 'accessory',
            'Souvent acheté ensemble'     => 'co_purchase',
            'Notre suggestion pour vous'  => 'bestseller',
        ];
        $tier = $tierMap[$upsell['reason']] ?? 'bestseller';

        $this->db->execute(
            "INSERT INTO `{$this->prefix}neria_upsell`
                (id_customer, id_shop, id_order_source, id_product_upsell, product_name, tier, reason, sent_at)
             VALUES (
                " . (int) $idCustomer . ",
                " . (int) $idShop . ",
                " . (int) $idOrderSource . ",
                " . (int) $upsell['id_product'] . ",
                '" . pSQL($upsell['name']) . "',
                '" . pSQL($tier) . "',
                '" . pSQL($upsell['reason']) . "',
                NOW()
             )"
        );

        $idUpsell = (int) $this->db->Insert_ID();
        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.upsell_suggestion_created', [
                'upsell'   => $idUpsell,
                'customer' => $idCustomer,
                'order'    => $idOrderSource,
                'product'  => $upsell['name'],
                'tier'     => $tier,
            ]),
            'upsell', 'Upsell'
        );
        return $idUpsell;
    }

    /**
     * Enregistre un clic sur le lien upsell (depuis track.php).
     *
     * Round 187 : $idCustomer (déduit du token de tracking déjà validé par
     * l'appelant) ajouté à la clause WHERE. id_upsell seul (clé auto-
     * incrémentée séquentielle) ne prouvait rien sur l'identité de
     * l'appelant : n'importe quel destinataire d'un email légitime (donc
     * en possession d'UN token valide, quel qu'il soit) pouvait forger
     * track.php?e=click&t=<son propre token>&url=...?neria_ur=N en faisant
     * varier N pour marquer clicked_at sur les lignes upsell d'AUTRES
     * clients — faussant l'attribution de revenu upsell store-wide
     * (checkConversions() attribue ensuite tout achat du client CIBLÉ dans
     * les 7 jours à la suggestion falsifiée) et bloquant définitivement le
     * vrai clic futur de ce client (garde clicked_at IS NULL ci-dessous).
     */
    public function recordClick(int $idUpsell, int $idCustomer): void
    {
        if ($idUpsell <= 0 || $idCustomer <= 0) {
            return;
        }
        $this->db->execute(
            "UPDATE `{$this->prefix}neria_upsell`
             SET clicked_at = NOW()
             WHERE id_upsell = " . (int) $idUpsell . "
               AND id_customer = " . (int) $idCustomer . "
               AND clicked_at IS NULL"
        );
    }

    /**
     * Vérifie les conversions upsell (cron quotidien).
     * Fenêtre d'attribution : 7 jours après le clic.
     * Retourne le nombre de conversions enregistrées.
     */
    public function checkConversions(): int
    {
        $table  = $this->prefix . 'neria_upsell';
        $rows   = $this->db->executeS(
            "SELECT u.id_upsell, u.id_customer, u.id_shop, u.id_product_upsell, u.clicked_at
             FROM `{$table}` u
             WHERE u.clicked_at IS NOT NULL
               AND u.id_order_converted IS NULL
               -- Marge de sécurité (14j, alors que la fenêtre d'attribution
               -- réelle vérifiée plus bas est de 7j) : si le cron quotidien
               -- rate une exécution (échec, maintenance), un clic proche de
               -- la limite des 7 jours ne doit pas sortir de cette
               -- présélection avant que sa propre fenêtre de conversion soit
               -- réellement épuisée, sous peine de perdre silencieusement la
               -- conversion et le revenu attribué.
               AND u.clicked_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)"
        ) ?: [];

        // Round 153 : un getRow() par ligne (jusqu'à plusieurs centaines de
        // requêtes SQL individuelles selon le trafic upsell des 14 derniers
        // jours) remplacé par UNE requête groupée pré-chargeant tous les
        // candidats (id_customer/id_shop/produit) potentiellement
        // pertinents pour CE lot de $rows, avant la boucle — le matching
        // par fenêtre de 7 jours (relative à clicked_at, différente par
        // ligne) reste fait en PHP, ligne par ligne, pour préserver
        // exactement la même logique de correspondance qu'avant.
        $custIds = array_values(array_unique(array_map(fn($r) => (int) $r['id_customer'], $rows)));
        $prodIds = array_values(array_unique(array_map(fn($r) => (int) $r['id_product_upsell'], $rows)));
        $candidatesByKey = [];
        if ($custIds && $prodIds) {
            $candidateRows = $this->db->executeS(
                "SELECT o.id_order, o.id_customer, o.id_shop, o.date_add,
                        od.product_id,
                        SUM(od.unit_price_tax_incl * od.product_quantity) AS revenue
                 FROM `{$this->prefix}orders` o
                 JOIN `{$this->prefix}order_detail` od
                      ON od.id_order = o.id_order
                      AND od.product_id IN (" . implode(',', $prodIds) . ")
                 WHERE o.id_customer IN (" . implode(',', $custIds) . ")
                   AND o.valid = 1
                 GROUP BY o.id_order, od.product_id
                 ORDER BY o.date_add ASC"
            ) ?: [];
            foreach ($candidateRows as $cr) {
                $key = $cr['id_customer'] . '|' . $cr['id_shop'] . '|' . $cr['product_id'];
                $candidatesByKey[$key][] = $cr;
            }
        }

        $count = 0;
        foreach ($rows as $row) {
            // SUM() + GROUP BY (dans la requête groupée ci-dessus) : le
            // produit upsell peut apparaître sur plusieurs lignes
            // order_detail de la même commande (attributs différents,
            // quantités multiples) — prendre une seule ligne sous-évaluait
            // le revenu réellement attribué à l'upsell.
            //
            // Filtre id_shop : sur une install multi-boutiques, un client
            // partagé entre boutiques qui rachète le même produit sur une
            // AUTRE boutique dans la fenêtre de 7 jours ne doit pas faire
            // attribuer cette conversion/ce revenu à la suggestion envoyée
            // par CETTE boutique.
            $key = $row['id_customer'] . '|' . $row['id_shop'] . '|' . $row['id_product_upsell'];
            $match = null;
            foreach ($candidatesByKey[$key] ?? [] as $cr) {
                if ($cr['date_add'] > $row['clicked_at']
                    && $cr['date_add'] <= date('Y-m-d H:i:s', strtotime($row['clicked_at'] . ' +7 days'))
                ) {
                    $match = $cr; // ORDER BY date_add ASC déjà appliqué → 1er match = le plus ancien
                    break;
                }
            }

            if ($match) {
                // Si le même produit a été suggéré plusieurs fois au même
                // client (plusieurs lignes neria_upsell non converties), la
                // requête ci-dessus retrouve la MÊME commande pour chacune —
                // sans ce garde-fou, un seul achat réel faisait attribuer le
                // même revenu à chaque suggestion, doublant le total dans
                // getStats()/ROI.
                // Round 212 : $use_cache=false, même famille de bug que les
                // rounds 210-211 (cache SQL gonflant artificiellement le
                // ROI upsell affiché sur un retry/double worker cron).
                $alreadyClaimed = (int) $this->db->getValue(
                    "SELECT COUNT(*) FROM `{$table}`
                     WHERE id_order_converted = " . (int) $match['id_order'] . "
                       AND id_product_upsell  = " . (int) $row['id_product_upsell'] . "
                       AND id_customer        = " . (int) $row['id_customer'],
                    false
                );
                if ($alreadyClaimed > 0) {
                    continue;
                }

                $this->db->execute(
                    "UPDATE `{$table}`
                     SET id_order_converted = " . (int) $match['id_order'] . ",
                         converted_at       = NOW(),
                         conversion_amount  = " . (float) $match['revenue'] . "
                     WHERE id_upsell = " . (int) $row['id_upsell']
                );
                $count++;
            }
        }

        if ($count > 0) {
            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.upsell_conversions_detected', ['n' => $count]),
                'upsell_conversion', 'Upsell'
            );
        }

        return $count;
    }

    // ============================================================
    // STATISTIQUES BO
    // ============================================================

    /**
     * KPIs agrégés pour la période donnée.
     */
    public function getStats(int $days = 90, ?int $idShop = null): array
    {
        $idShop   = $idShop ?? (int) $this->context->shop->id;
        $table    = $this->prefix . 'neria_upsell';
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        // AND id_shop = ... : neria_upsell est scopé par boutique partout
        // ailleurs dans ce fichier (recordSuggestion, checkConversions,
        // findUpsellForCustomer) — sans ce filtre ici, les KPIs affichés au
        // BO d'une boutique mélangeaient silencieusement les suggestions/
        // clics/conversions de TOUTES les boutiques de l'installation.
        $row = $this->db->getRow(
            "SELECT
                COUNT(*)                                           AS total_sent,
                SUM(clicked_at IS NOT NULL)                       AS total_clicked,
                SUM(id_order_converted IS NOT NULL)               AS total_converted,
                SUM(conversion_amount)                            AS total_revenue,
                SUM(tier = 'accessory')                           AS cnt_accessory,
                SUM(tier = 'co_purchase')                         AS cnt_co_purchase,
                SUM(tier = 'bestseller')                          AS cnt_bestseller
             FROM `{$table}`
             WHERE sent_at >= '{$dateFrom}' AND id_shop = " . (int) $idShop
        ) ?: [];

        $sent       = (int) ($row['total_sent']      ?? 0);
        $clicked    = (int) ($row['total_clicked']   ?? 0);
        $converted  = (int) ($row['total_converted'] ?? 0);
        $revenue    = (float)($row['total_revenue']  ?? 0);

        return [
            'total_sent'       => $sent,
            'total_clicked'    => $clicked,
            'total_converted'  => $converted,
            'total_revenue'    => $revenue,
            'ctr'              => $sent > 0 ? round($clicked   / $sent    * 100, 1) : 0,
            'conv_rate'        => $sent > 0 ? round($converted / $sent    * 100, 1) : 0,
            'avg_order'        => $converted > 0 ? round($revenue / $converted, 2) : 0,
            'cnt_accessory'    => (int) ($row['cnt_accessory']  ?? 0),
            'cnt_co_purchase'  => (int) ($row['cnt_co_purchase'] ?? 0),
            'cnt_bestseller'   => (int) ($row['cnt_bestseller'] ?? 0),
        ];
    }

    /**
     * Journal des N dernières suggestions avec données client.
     */
    public function getLog(int $idLang, int $limit = 50, ?int $idShop = null): array
    {
        $idShop = $idShop ?? (int) $this->context->shop->id;
        $table  = $this->prefix . 'neria_upsell';
        $idLang = $idLang > 0 ? $idLang : (int) \Configuration::get('PS_LANG_DEFAULT');

        // AND u.id_shop = ... : même correctif que getStats() — sans lui, le
        // journal BO montrait des suggestions envoyées à des clients d'une
        // AUTRE boutique.
        $rows = $this->db->executeS(
            "SELECT
                u.id_upsell, u.id_customer, u.id_order_source, u.id_product_upsell,
                u.product_name, u.tier, u.reason,
                u.sent_at, u.clicked_at, u.id_order_converted,
                u.converted_at, u.conversion_amount,
                c.firstname, c.lastname, c.email,
                o.reference AS order_ref,
                img.id_image
             FROM `{$table}` u
             LEFT JOIN `{$this->prefix}customer` c ON c.id_customer = u.id_customer
             LEFT JOIN `{$this->prefix}orders` o ON o.id_order = u.id_order_source
             LEFT JOIN `{$this->prefix}image` img
                  ON img.id_product = u.id_product_upsell AND img.cover = 1
             WHERE u.id_shop = {$idShop}
             ORDER BY u.sent_at DESC
             LIMIT " . (int) $limit
        ) ?: [];

        // Ajoute l'URL miniature pour chaque ligne — Context::getContext()->shop
        // basculé temporairement sur $idShop (même pattern que
        // CollectionManager/LookCompletionManager/WaitlistManager, round
        // 103) : getImageLink() n'a PAS de paramètre $idShop (contrairement
        // à getProductLink()) et résout systématiquement le domaine/thème
        // via le contexte global courant (Tools::getMediaServer()) — sans ce
        // switch, thumb_url pointait vers le domaine/thème de la boutique du
        // CONTEXTE D'EXÉCUTION courant, alors que product_url (juste
        // en-dessous, déjà scopé par $idShop en 6e argument) pointait
        // correctement vers la bonne boutique : incohérence visible quand
        // getLog() est appelée avec un $idShop différent du contexte actif
        // (ex. admin multi-boutique consultant le journal d'une AUTRE
        // boutique que celle active).
        $context      = \Context::getContext();
        $originalShop = $context->shop;
        $context->shop = new \Shop($idShop);
        try {
            foreach ($rows as &$row) {
                $idProduct = (int) $row['id_product_upsell'];
                $idImage   = (int) ($row['id_image'] ?? 0);
                $row['thumb_url'] = '';
                if ($idImage > 0) {
                    $imgType = \ImageType::getFormattedName('small');
                    $row['thumb_url'] = $context->link->getImageLink(
                        'product', $idImage, $imgType
                    );
                }
                $row['product_url'] = $context->link->getProductLink(
                    $idProduct, null, null, null, $idLang, $idShop
                );
            }
            unset($row);
        } finally {
            $context->shop = $originalShop;
        }

        return $rows;
    }

    // ============================================================
    // HELPERS DB
    // ============================================================

    private function getOrderProductIds(int $idOrder): array
    {
        $rows = $this->db->executeS(
            "SELECT DISTINCT product_id FROM `{$this->prefix}order_detail`
             WHERE id_order = " . (int) $idOrder
        ) ?: [];
        return array_map('intval', array_column($rows, 'product_id'));
    }

    private function getOrderCustomerId(int $idOrder): int
    {
        return (int) $this->db->getValue(
            "SELECT id_customer FROM `{$this->prefix}orders`
             WHERE id_order = " . (int) $idOrder
        );
    }

    private function getCustomerProductIds(int $idCustomer): array
    {
        $rows = $this->db->executeS(
            "SELECT DISTINCT od.product_id
             FROM `{$this->prefix}order_detail` od
             JOIN `{$this->prefix}orders` o ON od.id_order = o.id_order
             WHERE o.id_customer = " . (int) $idCustomer
        ) ?: [];
        return array_map('intval', array_column($rows, 'product_id'));
    }

    private function intList(array $ids): string
    {
        return implode(',', array_map('intval', $ids)) ?: '0';
    }

    private function notInClause(string $col, array $ids): string
    {
        if (empty($ids)) {
            return '';
        }
        return 'AND ' . $col . ' NOT IN (' . $this->intList($ids) . ')';
    }
}
