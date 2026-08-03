<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — PreferencesManager
 * Centre de préférences email : opt-in/out par catégorie d'email.
 * Opt-in par défaut — aucune ligne en DB = client reçoit tout.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PreferencesManager
{
    const TABLE = 'neria_preferences';

    const CATEGORIES = ['cart', 'post', 'loyalty', 'behav', 'season', 'b2b', 'newsletter'];

    /** Mapping template → catégorie (miroir de StatsManager::$CHART_CATEGORIES) */
    const TEMPLATE_CAT = [
        'abandoned_cart_1'          => 'cart',
        'abandoned_cart_2'          => 'cart',
        'abandoned_cart_3'          => 'cart',
        'checkout_abandonment'      => 'cart',
        'ghost_cart'                => 'cart',
        'post_purchase_care'        => 'post',
        'post_purchase_review'      => 'post',
        'complete_your_look'        => 'post',
        'collection_completion'     => 'post',
        'product_lifespan_reminder' => 'post',
        'order_shipped_delay'       => 'post',
        'order_on_hold'             => 'post',
        'order_partial_shipped'     => 'post',
        'refund_processed'          => 'post',
        'return_received'           => 'post',
        'refund_reconciliation_1'   => 'post',
        'refund_reconciliation_2'   => 'post',
        'refund_reconciliation_3'   => 'post',
        'waitlist_available'        => 'post',
        'wishlist_reminder'         => 'post',
        'back_in_stock'             => 'post',
        'loyalty_tier_upgrade'      => 'loyalty',
        'loyalty_recap'             => 'loyalty',
        'loyalty_reward_expiry'     => 'loyalty',
        'milestone_order'           => 'loyalty',
        'referral_invitation'       => 'loyalty',
        'birthday'                  => 'behav',
        'relationship_anniversary'  => 'behav',
        'win_back'                  => 'behav',
        'reorder_reminder'          => 'behav',
        'vip_invitation'            => 'behav',
        'private_sale'              => 'behav',
        'first_anniversary'         => 'behav',
        'christmas'                 => 'season',
        'valentine'                 => 'season',
        'halloween'                 => 'season',
        'eid'                       => 'season',
        'ramadan'                   => 'season',
        'diwali'                    => 'season',
        'lunar_new_year'            => 'season',
        'nowruz'                    => 'season',
        'black_friday'              => 'season',
        'new_year'                  => 'season',
        'hanukkah'                  => 'season',
        'fathers_day'               => 'season',
        'mothers_day'               => 'season',
        'grandparents_day'          => 'season',
        'end_of_year_gift'          => 'season',
        'early_access'              => 'season',
        'exclusive_preview'         => 'season',
        'quote_expiry_48h'          => 'b2b',
        'quote_expiry_day'          => 'b2b',
        'quote_extension_offer'     => 'b2b',
        'newsletter'                => 'newsletter',
        'newsletter_voucher'        => 'newsletter',
        'gift_ideas'                => 'newsletter',
    ];

    private \Db    $db;
    private int    $idShop;
    private object $module;

    public function __construct(object $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    /**
     * Retourne true si l'envoi est autorisé pour ce client/template.
     * Opt-in par défaut : aucune ligne = autorisé.
     *
     * @param int|null $idShop Boutique du DESTINATAIRE — par défaut la
     *                         boutique du contexte courant. Un appelant qui
     *                         traite des clients de plusieurs boutiques dans
     *                         un même contexte figé (ex: cron itérant sur
     *                         $shops) DOIT passer explicitement l'id_shop du
     *                         client traité, sinon la requête cherche ses
     *                         préférences sous la mauvaise boutique, ne
     *                         trouve aucune ligne, et retombe sur "opt-in par
     *                         défaut" — contournement RGPD silencieux.
     */
    public function isAllowed(int $idCustomer, string $template, ?int $idShop = null): bool
    {
        if ($idCustomer <= 0) {
            return true;
        }
        $cat = self::TEMPLATE_CAT[$template] ?? null;
        if ($cat === null) {
            return true; // template non classé → toujours envoyé
        }

        $shop = $idShop ?? $this->idShop;
        $row = $this->db->getRow(
            "SELECT `subscribed` FROM `" . _DB_PREFIX_ . self::TABLE . "`
             WHERE `id_shop`    = {$shop}
               AND `id_customer`= {$idCustomer}
               AND `category`  = '" . pSQL($cat) . "'"
        );

        // Pas de ligne = opt-in par défaut
        return $row === false || (bool) $row['subscribed'];
    }

    /**
     * Retourne les préférences d'un client (toutes catégories).
     * Valeur par défaut : 1 (souscrit).
     */
    public function getByCustomer(int $idCustomer): array
    {
        $prefs = array_fill_keys(self::CATEGORIES, 1);
        if ($idCustomer <= 0) {
            return $prefs;
        }

        $rows = $this->db->executeS(
            "SELECT `category`, `subscribed` FROM `" . _DB_PREFIX_ . self::TABLE . "`
             WHERE `id_shop`    = {$this->idShop}
               AND `id_customer`= {$idCustomer}"
        );

        foreach ((is_array($rows) ? $rows : []) as $row) {
            $cat = $row['category'];
            if (array_key_exists($cat, $prefs)) {
                $prefs[$cat] = (int) $row['subscribed'];
            }
        }

        return $prefs;
    }

    /**
     * Sauvegarde les préférences d'un client.
     * $prefs = ['cart' => 1, 'post' => 0, ...]
     */
    public function saveByCustomer(int $idCustomer, string $email, array $prefs): void
    {
        foreach (self::CATEGORIES as $cat) {
            $subscribed = isset($prefs[$cat]) ? (int)(bool)$prefs[$cat] : 1;
            $this->db->execute(
                "INSERT INTO `" . _DB_PREFIX_ . self::TABLE . "`
                 (`id_shop`,`id_customer`,`email`,`category`,`subscribed`,`date_upd`)
                 VALUES ({$this->idShop}, {$idCustomer}, '" . pSQL(strtolower($email)) . "',
                         '" . pSQL($cat) . "', {$subscribed}, NOW())
                 ON DUPLICATE KEY UPDATE
                   `subscribed` = VALUES(`subscribed`),
                   `email`      = VALUES(`email`),
                   `date_upd`   = NOW()"
            );
        }

        if (class_exists('WatchdogManager')) {
            try {
                (new WatchdogManager($this->module))->info(
                    WatchdogManager::i18nMsg('watchdog.preferences_updated'),
                    '',
                    'Preferences',
                    ['id_customer' => $idCustomer, 'email' => $email, 'prefs' => $prefs]
                );
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * Génère le token HMAC pour un email (même logique que unsubscribe).
     */
    public static function tokenForEmail(string $email): string
    {
        return substr(hash_hmac('sha256', strtolower(trim($email)), _COOKIE_KEY_), 0, 32);
    }

    /**
     * Génère l'URL du centre de préférences pour un email/client.
     */
    public function getPreferencesUrl(string $email, int $idCustomer, string $lang = 'fr'): string
    {
        $token = self::tokenForEmail($email);
        $link  = \Context::getContext()->link;
        $base  = rtrim($link->getBaseLink(), '/');
        return $base . '/module/neria/preferences'
            . '?email=' . urlencode($email)
            . '&token=' . urlencode($token)
            . '&lang='  . urlencode($lang)
            . '&cid='   . (int) $idCustomer;
    }

    /**
     * Retourne un résumé des préférences d'un client pour la fiche BO.
     */
    public function getSummaryForCustomer(int $idCustomer): array
    {
        return $this->getByCustomer($idCustomer);
    }
}
