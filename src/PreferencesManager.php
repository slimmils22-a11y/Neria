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
        // Templates envoi manuel (ManualSendManager::WAVE1_TEMPLATES,
        // groupe "VIP / marketing", même famille que vip_invitation/
        // private_sale ci-dessus) et A/B testing (ABTestManager) —
        // absents jusqu'ici de cette table, isAllowed() les traitait comme
        // "non classés" et autorisait TOUJOURS leur envoi, même à un client
        // ayant explicitement désactivé la catégorie 'behav'.
        'vip'                       => 'behav',
        'private_invitation'        => 'behav',
        'voucher'                   => 'behav',
        'voucher_new'               => 'behav',
        // Round 72b (garde-fou étendu à ManualSendManager::WAVE1_TEMPLATES et
        // ABTestManager::getEligibleTemplates() en entier) : 21 autres
        // templates du même catalogue WAVE1, tout aussi non classés et donc
        // toujours envoyés sans respecter les préférences. Catégorisés selon
        // le regroupement déjà en commentaire dans WAVE1_TEMPLATES lui-même
        // (ManualSendManager.php) : "Artisanat / service" et
        // "Logistique / incidents" → post (même famille que
        // order_shipped_delay/refund_processed, suivis liés à UNE commande
        // précise) ; le reste du groupe "VIP / marketing" → behav (même
        // famille que vip/private_invitation ci-dessus) ; "Divers" réparti
        // selon sa nature réelle.
        'artisan_message'           => 'post',
        'craftsmanship_update'      => 'post',
        'alteration_update'         => 'post',
        'bespoke_ready'             => 'post',
        'repair_completed'          => 'post',
        'repair_request_confirm'    => 'post',
        'care_certificate'          => 'post',
        'certificate_provenance'    => 'post',
        // Round 294 : 'certificate_email' (CertificateManager::send(),
        // certificat PDF d'authenticité joint à une commande) était absent
        // de cette table — isAllowed() le traitait comme "non classé" et
        // l'envoyait TOUJOURS, même à un client ayant désactivé la
        // catégorie 'post' dans son centre de préférences, malgré un
        // appel isAllowed() explicitement présent avant Mail::Send()
        // (garde-fou visible mais inopérant faute d'entrée ici).
        'certificate_email'         => 'post',
        'extended_warranty'         => 'post',
        'white_glove_apology'       => 'post',
        'product_recall'            => 'post',
        'customs_alert'             => 'post',
        'delivery_attempt_failed'   => 'post',
        'packaging_choice'          => 'post',
        'tax_refund_eligible'       => 'post',
        'gift_message_confirm'      => 'post',
        'unboxing_guide'            => 'post',
        'personal_shopper_intro'    => 'behav',
        'concierge_followup'        => 'behav',
        'gift_guarantee'            => 'behav',
        'corporate_order_confirm'   => 'b2b',
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
    /**
     * @param string $email Destinataire sans compte client (id_customer=0 —
     *                       newsletter/newsletter_voucher envoyés à des
     *                       inscrits ps_emailsubscription qui ne sont pas
     *                       forcément des clients PrestaShop). Sans cet
     *                       argument, TOUT destinataire résolu à
     *                       id_customer=0 retombait sur "opt-in par défaut"
     *                       en permanence, quoi que le centre de préférences
     *                       ait enregistré pour son email (saveByCustomer()
     *                       écrit pourtant bien une ligne avec id_customer=0
     *                       + email — jamais relue faute de ce paramètre).
     *                       Non-conformité RGPD/CAN-SPAM démontrable : le
     *                       client voyait "préférences enregistrées" mais
     *                       continuait de recevoir la catégorie décochée.
     */
    public function isAllowed(int $idCustomer, string $template, ?int $idShop = null, string $email = ''): bool
    {
        $cat = self::TEMPLATE_CAT[$template] ?? null;
        if ($cat === null) {
            return true; // template non classé → toujours envoyé
        }

        $shop = $idShop ?? $this->idShop;

        if ($idCustomer <= 0) {
            $email = trim(strtolower($email));
            if ($email === '') {
                return true;
            }
            // Round 215 : $use_cache=false, même famille de bug que les
            // rounds 210-214 — ce résultat détermine directement si
            // Mail::Send() part ou non. Sans lui, un client venant de se
            // désabonner (INSERT/UPDATE brut via Db::execute(), hors cycle
            // ObjectModel) pourrait, sous cache SQL périmé, recevoir quand
            // même l'email — risque RGPD direct et silencieux.
            $row = $this->db->getRow(
                "SELECT `subscribed` FROM `" . _DB_PREFIX_ . self::TABLE . "`
                 WHERE `id_shop`     = {$shop}
                   AND `id_customer` = 0
                   AND `email`       = '" . pSQL($email) . "'
                   AND `category`    = '" . pSQL($cat) . "'",
                false
            );
            return $row === false || (bool) $row['subscribed'];
        }

        // Round 215 : $use_cache=false, même risque qu'au bloc ci-dessus.
        $row = $this->db->getRow(
            "SELECT `subscribed` FROM `" . _DB_PREFIX_ . self::TABLE . "`
             WHERE `id_shop`    = {$shop}
               AND `id_customer`= {$idCustomer}
               AND `category`  = '" . pSQL($cat) . "'",
            false
        );

        // Pas de ligne = opt-in par défaut
        return $row === false || (bool) $row['subscribed'];
    }

    /**
     * Round 153 : version groupée de isAllowed() — appeler isAllowed() une
     * fois par client dans une boucle (SegmentManager::preflightCheck()/
     * sendToSegment()) déclenchait jusqu'à ~1000 requêtes SQL individuelles
     * pour un segment de 500 destinataires (une par appel, deux appels par
     * envoi de campagne). Une seule requête IN(...) suffit : la table
     * neria_preferences ne contient que les clients ayant explicitement
     * modifié leurs préférences (opt-out par défaut absent de la table),
     * donc tout id_customer non présent dans le résultat reste autorisé
     * par défaut — même règle que isAllowed() ligne-par-ligne.
     *
     * @param int[] $idCustomers
     * @return array<int,bool> [id_customer => autorisé]
     */
    public function isAllowedBatch(array $idCustomers, string $template, ?int $idShop = null): array
    {
        $idCustomers = array_values(array_unique(array_filter(array_map('intval', $idCustomers), fn($id) => $id > 0)));

        $cat = self::TEMPLATE_CAT[$template] ?? null;
        if ($cat === null || empty($idCustomers)) {
            // Template non classé → toujours envoyé (même règle que
            // isAllowed()) ; liste vide → rien à résoudre.
            $result = [];
            foreach ($idCustomers as $id) {
                $result[$id] = true;
            }
            return $result;
        }

        $shop = $idShop ?? $this->idShop;
        $idList = implode(',', $idCustomers);

        // Round 215 : $use_cache=false, même risque que isAllowed()
        // ci-dessus (isAllowedBatch() alimente directement les envois
        // groupés de SegmentManager::sendToSegment()).
        $rows = $this->db->executeS(
            "SELECT `id_customer`, `subscribed` FROM `" . _DB_PREFIX_ . self::TABLE . "`
             WHERE `id_shop`     = {$shop}
               AND `id_customer` IN ({$idList})
               AND `category`    = '" . pSQL($cat) . "'",
            true,
            false
        );

        $subscribed = [];
        foreach ((array) $rows as $r) {
            $subscribed[(int) $r['id_customer']] = (bool) $r['subscribed'];
        }

        $result = [];
        foreach ($idCustomers as $id) {
            // Pas de ligne = opt-in par défaut, même règle que isAllowed().
            $result[$id] = $subscribed[$id] ?? true;
        }
        return $result;
    }

    /**
     * Retourne les préférences d'un client (toutes catégories).
     * Valeur par défaut : 1 (souscrit).
     */
    public function getByCustomer(int $idCustomer, string $email = ''): array
    {
        $prefs = array_fill_keys(self::CATEGORIES, 1);

        if ($idCustomer <= 0) {
            $email = trim(strtolower($email));
            if ($email === '') {
                return $prefs;
            }
            $rows = $this->db->executeS(
                "SELECT `category`, `subscribed` FROM `" . _DB_PREFIX_ . self::TABLE . "`
                 WHERE `id_shop`     = {$this->idShop}
                   AND `id_customer` = 0
                   AND `email`       = '" . pSQL($email) . "'"
            );
            foreach ((is_array($rows) ? $rows : []) as $row) {
                $cat = $row['category'];
                if (array_key_exists($cat, $prefs)) {
                    $prefs[$cat] = (int) $row['subscribed'];
                }
            }
            return $prefs;
        }

        // Round 178 : ORDER BY id_preference ASC — défense en profondeur
        // en complément du nettoyage ajouté dans saveByCustomer() : si des
        // lignes dupliquées pour la même catégorie subsistent malgré tout
        // (données legacy antérieures à ce correctif, migration partielle),
        // la boucle ci-dessous écrase $prefs[$cat] à CHAQUE itération —
        // avec un tri ASC (plus ancien → plus récent), c'est donc bien la
        // DERNIÈRE itération (la ligne la plus RÉCENTE) qui l'emporte, de
        // façon déterministe plutôt que selon l'ordre physique (non
        // garanti) renvoyé par MySQL sans tri explicite. Round 303 :
        // commentaire corrigé — il affirmait à tort "DESC", ce qui aurait
        // en réalité fait gagner la ligne la plus ANCIENNE (l'inverse de
        // l'objectif visé) si un futur correctif s'y était fié pour
        // "réparer" un tri jugé à tort incohérent avec ce texte.
        $rows = $this->db->executeS(
            "SELECT `category`, `subscribed` FROM `" . _DB_PREFIX_ . self::TABLE . "`
             WHERE `id_shop`    = {$this->idShop}
               AND `id_customer`= {$idCustomer}
             ORDER BY `id_preference` ASC"
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
        $normalizedEmail = strtolower(trim($email));

        // Round 178 : la clé unique (id_shop, id_customer, email, category)
        // inclut l'email pour distinguer deux CLIENTS INVITÉS différents
        // (id_customer=0, voir le commentaire sur cette contrainte dans
        // sql/install.sql) — mais pour un client IDENTIFIÉ (id_customer>0),
        // id_customer suffit déjà à l'identifier de façon unique. L'email
        // vient du token du lien reçu par mail, donc peut différer d'un
        // envoi à l'autre (changement d'adresse entre deux visites du
        // centre de préférences) : sans ce nettoyage, chaque email
        // différent créait une NOUVELLE ligne au lieu de mettre à jour
        // l'existante, et getByCustomer() (qui n'a pas de tri déterministe)
        // pouvait alors retourner une ancienne préférence obsolète selon
        // l'ordre physique MySQL — y compris un opt-out que le client
        // croyait avoir remplacé.
        if ($idCustomer > 0) {
            $this->db->execute(
                "DELETE FROM `" . _DB_PREFIX_ . self::TABLE . "`
                 WHERE `id_shop`     = {$this->idShop}
                   AND `id_customer` = {$idCustomer}
                   AND `email`       != '" . pSQL($normalizedEmail) . "'"
            );
        }

        foreach (self::CATEGORIES as $cat) {
            $subscribed = isset($prefs[$cat]) ? (int)(bool)$prefs[$cat] : 1;
            $this->db->execute(
                "INSERT INTO `" . _DB_PREFIX_ . self::TABLE . "`
                 (`id_shop`,`id_customer`,`email`,`category`,`subscribed`,`date_upd`)
                 VALUES ({$this->idShop}, {$idCustomer}, '" . pSQL($normalizedEmail) . "',
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
     *
     * Round 269 : signé via NeriaTools::trackingSignKey() (préfère
     * NERIA_ENCRYPTION_KEY, propre au module, jamais affectée par une
     * rotation de _COOKIE_KEY_ côté PrestaShop) au lieu de _COOKIE_KEY_
     * directement — une rotation invalidait sinon silencieusement tout
     * lien de préférences déjà envoyé dans un email, sans recours pour le
     * client.
     */
    public static function tokenForEmail(string $email): string
    {
        return substr(hash_hmac('sha256', strtolower(trim($email)), \NeriaTools::trackingSignKey()), 0, 32);
    }

    /**
     * Génère l'URL du centre de préférences pour un email/client.
     */
    public function getPreferencesUrl(string $email, int $idCustomer, string $lang = 'fr', int $idShop = 0): string
    {
        $token = self::tokenForEmail($email);
        $link  = \Context::getContext()->link;
        // Scopé par $idShop (round 110) : sans ça, getBaseLink() retombait
        // sur Context::getContext() — le process qui ENVOIE l'email (cron,
        // admin BO), pas la boutique du CLIENT destinataire — même piège
        // multi-boutique déjà traité pour {product_url}/{shop_name} ailleurs
        // dans ce module. Un client de la boutique B recevait un lien
        // "Gérer mes préférences" pointant vers le domaine de la boutique A.
        $base  = rtrim($link->getBaseLink($idShop ?: null), '/');
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

    /**
     * Round 232 : $prefs_stats attendu par configure.tpl (bloc "Centre de
     * préférences email") — le template existait déjà avec ses {if
     * $prefs_stats}/{foreach} mais aucune méthode ne calculait jamais cette
     * variable côté PHP, ni ici ni dans neria.php. La section restait donc
     * vide en permanence pour tous les marchands, sans erreur visible
     * (échec silencieux du {if} Smarty sur une variable non définie).
     *
     * Retourne, par catégorie, le nombre de désabonnements (subscribed=0)
     * et le total de préférences explicitement enregistrées (opt-in par
     * défaut : une absence de ligne n'est pas comptée ici, seules les
     * lignes réellement écrites par un client via le centre de
     * préférences le sont).
     */
    public function getStats(): array
    {
        $stats = array_fill_keys(self::CATEGORIES, ['opted_out' => 0, 'total' => 0]);

        $rows = $this->db->executeS(
            "SELECT `category`,
                    COUNT(*) AS total,
                    SUM(`subscribed` = 0) AS opted_out
             FROM `" . _DB_PREFIX_ . self::TABLE . "`
             WHERE `id_shop` = {$this->idShop}
             GROUP BY `category`"
        );

        foreach ((is_array($rows) ? $rows : []) as $row) {
            $cat = $row['category'];
            if (array_key_exists($cat, $stats)) {
                $stats[$cat] = [
                    'opted_out' => (int) $row['opted_out'],
                    'total'     => (int) $row['total'],
                ];
            }
        }

        return $stats;
    }

    /**
     * Round 232 : $prefs_recent attendu par configure.tpl, même défaut que
     * getStats() ci-dessus — jamais implémentée.
     *
     * Retourne les $limit clients ayant le plus récemment modifié leurs
     * préférences (toutes catégories confondues), avec leur nombre actuel
     * de catégories désabonnées.
     */
    public function getRecentChanges(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $rows = $this->db->executeS(
            "SELECT p.id_customer, p.email,
                    MAX(p.date_upd) AS date_upd,
                    SUM(p.subscribed = 0) AS nb_optout,
                    COALESCE(c.firstname, '') AS firstname,
                    COALESCE(c.lastname, '')  AS lastname
             FROM `" . _DB_PREFIX_ . self::TABLE . "` p
             LEFT JOIN `" . _DB_PREFIX_ . "customer` c
                    ON c.id_customer = p.id_customer AND p.id_customer > 0
             WHERE p.id_shop = {$this->idShop}
             GROUP BY p.id_customer, p.email
             ORDER BY MAX(p.date_upd) DESC
             LIMIT {$limit}"
        );

        return is_array($rows) ? $rows : [];
    }
}
