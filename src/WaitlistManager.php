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

    public function register(int $idCustomer, int $idProduct, int $idShop): bool
    {
        if ($this->isRegistered($idCustomer, $idProduct, $idShop)) return true;
        $t   = $this->prefix . self::TABLE;
        $now = pSQL(date('Y-m-d H:i:s'));
        // La clé unique porte sur (id_customer, id_product, id_shop) — un client
        // multi-boutique doit pouvoir s'inscrire séparément sur chaque boutique
        // où le même produit est en rupture, sans que l'inscription d'une
        // boutique écrase celle d'une autre.
        return $this->db->execute(
            "INSERT INTO `{$t}` (id_customer, id_product, id_shop, registered_at, notified_at, claim_started_at)
             VALUES ({$idCustomer}, {$idProduct}, {$idShop}, '{$now}', NULL, NULL)
             ON DUPLICATE KEY UPDATE registered_at = '{$now}', notified_at = NULL, claim_started_at = NULL"
        );
    }

    public function unregister(int $idCustomer, int $idProduct, int $idShop): bool
    {
        return $this->db->delete(self::TABLE,
            'id_customer = ' . $idCustomer . ' AND id_product = ' . $idProduct . ' AND id_shop = ' . $idShop
        );
    }

    public function isRegistered(int $idCustomer, int $idProduct, int $idShop): bool
    {
        return (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$this->prefix}" . self::TABLE . "`
             WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND id_shop = {$idShop}
               AND notified_at IS NULL"
        ) > 0;
    }

    // ── Notification lors du retour en stock ─────────────────────

    public function notifyProduct(int $idProduct, int $idShop): int
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
        // id_product_attribute=null (et non 0) : neria_waitlist est une liste
        // d'attente au niveau produit, pas par déclinaison. Passer 0 ne lisait
        // que le stock de la combinaison "sans attribut" et retournait 0 pour
        // tout produit géré par déclinaisons même quand une déclinaison précise
        // était de retour en stock — plus aucune notification ne partait jamais.
        // null fait sommer le stock disponible sur toutes les déclinaisons.
        $availableQty = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, null, $idShop);
        // availableQty <= 0 : rien de réellement disponible (stock à 0 au moment de
        // l'appel, race condition avec la mise à jour, ou déclinaison sans stock géré) —
        // ne rien envoyer plutôt que de traiter toute la file sans plafond. Ce hook est
        // déclenché en synchrone dans la requête HTTP admin (actionUpdateQuantity) :
        // sans ce garde-fou, une file de plusieurs milliers d'inscrits pouvait dépasser
        // le timeout HTTP d'un hébergeur mutualisé.
        if ($availableQty <= 0) {
            return 0;
        }
        $rows = array_slice($rows, 0, $availableQty);

        $sent = 0;
        foreach ($rows as $row) {
            $idCustomer = (int) $row['id_customer'];
            // Langue du client (déjà récupérée par la jointure ci-dessus) —
            // repli sur la langue par défaut de la boutique si absente/corrompue.
            $idLang     = (int) $row['id_lang'] ?: (int) \Configuration::get('PS_LANG_DEFAULT');

            $product = new \Product($idProduct, false, $idLang);
            if (!\Validate::isLoadedObject($product)) continue;

            $cover    = \Product::getCover($idProduct);
            $imageUrl = '';
            if ($cover) {
                $imageUrl = \Context::getContext()->link->getImageLink(
                    $product->link_rewrite,
                    (int) $cover['id_image'],
                    \ImageType::getFormattedName('home')
                );
            }

            $daysWaited = max(1, (int) $row['days_waited']);
            $vars = [
                '{firstname}'          => $row['firstname'],
                '{days_waited_plural}' => $daysWaited > 1 ? 's' : '',
                '{product_name}'       => $product->name,
                '{product_url}'        => \Context::getContext()->link->getProductLink($product),
                '{product_image}'      => $imageUrl,
                '{product_price}'      => \NeriaTools::displayPrice((float) $product->price, new \Currency((int) \Context::getContext()->currency->id), $idLang),
                '{days_waited}'        => $daysWaited,
                '{reservation_hours}'  => (int) \Configuration::getGlobalValue('NERIA_WAITLIST_RESERVATION_HOURS') ?: self::RESERVATION_HOURS,
                '{shop_name}'          => \Configuration::get('PS_SHOP_NAME'),
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
            $claimed = $this->db->execute(
                "UPDATE `{$this->prefix}" . self::TABLE . "`
                 SET claim_started_at = NOW()
                 WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND id_shop = {$idShop}
                   AND notified_at IS NULL"
            ) && $this->db->Affected_Rows() > 0;

            if (!$claimed) {
                continue; // un autre processus a déjà pris/prend cette notification
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
                         WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND id_shop = {$idShop}"
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
                         WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND id_shop = {$idShop}"
                    );
                }
            } catch (\Throwable $e) {
                // Envoi en échec : on libère la réclamation pour permettre un nouvel essai.
                $this->db->execute(
                    "UPDATE `{$this->prefix}" . self::TABLE . "`
                     SET claim_started_at = NULL
                     WHERE id_customer = {$idCustomer} AND id_product = {$idProduct} AND id_shop = {$idShop}"
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
}
