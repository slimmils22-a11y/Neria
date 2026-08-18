<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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

    // Ordinal localisé de chaque palier, pour {milestone_count} — utilisé
    // en tant qu'ADJECTIF juste avant "commande/order/..." dans le texte
    // traduit (ex. fr "votre {milestone_count} commande", ja "{milestone_count}
    // のご注文"). Table figée plutôt qu'un algorithme d'ordinaux générique :
    // MILESTONES est un ensemble fixe et restreint (5 valeurs), et plusieurs
    // langues (ar/ja/ko/zh/tw) ont des règles d'ordinaux trop spécifiques
    // (accord grammatical, compteurs dédiés) pour être fiables à calculer
    // dynamiquement — chaque valeur ci-dessous a été vérifiée manuellement
    // contre la phrase exacte de milestone_intro dans translations.json.
    const MILESTONE_ORDINALS = [
        'fr' => [5 => '5e', 10 => '10e', 25 => '25e', 50 => '50e', 100 => '100e'],
        'en' => [5 => '5th', 10 => '10th', 25 => '25th', 50 => '50th', 100 => '100th'],
        'gb' => [5 => '5th', 10 => '10th', 25 => '25th', 50 => '50th', 100 => '100th'],
        'de' => [5 => '5.', 10 => '10.', 25 => '25.', 50 => '50.', 100 => '100.'],
        'it' => [5 => '5°', 10 => '10°', 25 => '25°', 50 => '50°', 100 => '100°'],
        'es' => [5 => '5º', 10 => '10º', 25 => '25º', 50 => '50º', 100 => '100º'],
        'pt' => [5 => '5º', 10 => '10º', 25 => '25º', 50 => '50º', 100 => '100º'],
        'br' => [5 => '5º', 10 => '10º', 25 => '25º', 50 => '50º', 100 => '100º'],
        'ar' => [5 => 'الخامس', 10 => 'العاشر', 25 => 'الخامس والعشرون', 50 => 'الخمسون', 100 => 'المئة'],
        'ja' => [5 => '5回目', 10 => '10回目', 25 => '25回目', 50 => '50回目', 100 => '100回目'],
        'ko' => [5 => '5번째', 10 => '10번째', 25 => '25번째', 50 => '50번째', 100 => '100번째'],
        'zh' => [5 => '第5次', 10 => '第10次', 25 => '第25次', 50 => '第50次', 100 => '第100次'],
        'tw' => [5 => '第5次', 10 => '第10次', 25 => '第25次', 50 => '第50次', 100 => '第100次'],
        'ru' => [5 => '5-й', 10 => '10-й', 25 => '25-й', 50 => '50-й', 100 => '100-й'],
        'tr' => [5 => '5.', 10 => '10.', 25 => '25.', 50 => '50.', 100 => '100.'],
        'sv' => [5 => '5:e', 10 => '10:e', 25 => '25:e', 50 => '50:e', 100 => '100:e'],
        'no' => [5 => '5.', 10 => '10.', 25 => '25.', 50 => '50.', 100 => '100.'],
        'da' => [5 => '5.', 10 => '10.', 25 => '25.', 50 => '50.', 100 => '100.'],
        'nl' => [5 => '5e', 10 => '10e', 25 => '25e', 50 => '50e', 100 => '100e'],
    ];

    // IDs des statuts PS standards (1–13) — on n'envoie order_on_hold /
    // order_partial_shipped que pour des statuts custom créés par le marchand
    const STANDARD_STATUS_IDS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13];

    private \Neria $module;
    private \Db $db;
    private string $prefix;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(\Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->prefix = _DB_PREFIX_;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    /**
     * Round 178 : Mail::Send() du cœur PrestaShop retourne TOUJOURS true
     * quand le hook actionEmailSendBefore annule l'envoi (bounce/blacklist/
     * préférences/cooldown — voir classes/Mail.php, "if (!$keepGoing) {
     * return true; }"). Toutes les méthodes de ce fichier traitaient donc
     * un envoi silencieusement bloqué par le hook comme un succès :
     * checkMilestone() ne libérait jamais sa réservation anti-doublon
     * (claimMilestone()) que sur un ÉCHEC détecté, donc un palier
     * légitimement atteint mais bloqué restait réclamé à vie, sans email ni
     * bon, sans aucun retry possible (pas de cron pour ce template) ; les
     * autres méthodes journalisaient simplement un faux succès. Revérifie
     * explicitement les mêmes garde-fous que ManualSendManager::send(),
     * AVANT l'appel à Mail::Send() — retourne une raison de blocage (pour
     * log/libération de réservation) ou null si l'envoi peut avoir lieu.
     */
    private function explicitSendBlockReason(string $template, string $email, int $idCustomer, int $idShop, int $idLang): ?string
    {
        if (class_exists('BounceManager') && \BounceManager::isBounced($email)) {
            return 'bounce';
        }

        if (class_exists('BlacklistManager')) {
            $langIso = class_exists('TranslationEngine')
                ? (new \TranslationEngine($this->module))->langFromId($idLang)
                : (string) (\Language::getIsoById($idLang) ?: '');
            if ((new \BlacklistManager($idShop))->isBlacklisted($template, $langIso)) {
                return 'blacklist';
            }
        }

        if (class_exists('PreferencesManager')
            && !(new \PreferencesManager($this->module))->isAllowed($idCustomer, $template, $idShop, $email)
        ) {
            return 'preferences';
        }

        if (class_exists('ConfigManager') && class_exists('CooldownManager')
            && (new \ConfigManager($this->module))->isCooldownEnabled()
        ) {
            $cdMinutes = (new \ConfigManager($this->module))->getCooldownMinutes();
            if ((new \CooldownManager())->isDuplicate($email, $template, $cdMinutes, $idShop)) {
                return 'cooldown';
            }
        }

        return null;
    }

    /**
     * Résout {milestone_count} : ordinal localisé (cf. MILESTONE_ORDINALS)
     * si le palier et la langue sont couverts, repli sur le nombre brut
     * sinon (jamais de valeur vide envoyée dans un email réel).
     */
    private function formatMilestoneOrdinal(int $count, int $idLang): string
    {
        $iso = \Language::getIsoById($idLang) ?: 'fr';
        return self::MILESTONE_ORDINALS[$iso][$count] ?? (string) $count;
    }

    /**
     * Génère un vrai bon de réduction PrestaShop (CartRule) pour un palier de
     * commandes atteint, uniquement si le marchand a activé
     * ConfigManager::isMilestoneVoucherEnabled() (désactivé par défaut).
     * Anti-doublon atomique via ps_neria_milestone_voucher (UNIQUE
     * id_customer+milestone+id_shop), même principe que
     * BehavioralCronManager::generateBirthdayVoucher(). id_shop toujours
     * la vraie boutique (jamais de sentinelle, contrairement aux points de
     * fidélité) : handleNewOrder() compte déjà les commandes du palier
     * UNIQUEMENT pour la boutique de LA commande — "palier 5" en boutique A
     * et "palier 5" en boutique B sont deux jalons distincts par nature.
     *
     * @return string Le code du bon, ou '' si déjà réservé par une requête concurrente.
     */
    /**
     * Réservation atomique (id_customer, milestone, id_shop) — sert à la
     * fois de verrou anti-doublon pour l'EMAIL milestone_order lui-même
     * (voir checkMilestone()) et, si le bon est activé, de réservation pour
     * generateMilestoneVoucher(). Avant ce correctif, seule la génération du
     * bon était dédupliquée : quand le toggle bon de réduction était
     * désactivé, ou quand une commande repassait de non-valide à valide
     * puis retrouvait le même palier (ex. annulation suivie d'un
     * rétablissement de statut), rien n'empêchait un second envoi de
     * l'email de félicitations pour le même palier.
     */
    private function claimMilestone(int $idCustomer, int $milestone, int $idShop): bool
    {
        $reserved = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->prefix . 'neria_milestone_voucher`
                (id_customer, milestone, id_cart_rule, voucher_code, id_shop, created_at)
             VALUES (' . (int) $idCustomer . ', ' . (int) $milestone . ', 0, \'\', ' . (int) $idShop . ', NOW())'
        );

        return (bool) $reserved && (int) $this->db->Affected_Rows() > 0;
    }

    /**
     * Libère la réservation posée par claimMilestone() — appelée depuis
     * checkMilestone() en cas d'échec de l'envoi (silent fail ou exception),
     * pour permettre un futur re-déclenchement de ce palier plutôt que
     * perdre définitivement le client (aucun cron de retry n'existe pour
     * milestone_order, contrairement aux relances comportementales).
     * `id_cart_rule = 0` dans le WHERE est la même protection que celle déjà
     * utilisée dans generateMilestoneVoucher() ci-dessus : si un vrai bon a
     * entre-temps été créé et associé à cette réservation, le DELETE ne
     * matche rien et la réservation/le bon restent intacts — on ne veut
     * jamais recréer un second CartRule au prochain passage.
     */
    private function releaseMilestoneClaim(int $idCustomer, int $milestone, int $idShop): void
    {
        $this->db->execute(
            'DELETE FROM `' . $this->prefix . 'neria_milestone_voucher`
             WHERE id_customer = ' . (int) $idCustomer . ' AND milestone = ' . (int) $milestone . '
               AND id_shop = ' . (int) $idShop . ' AND id_cart_rule = 0'
        );
    }

    private function generateMilestoneVoucher(int $idCustomer, int $milestone, \ConfigManager $config, int $idShop): string
    {
        $amount    = $config->getMilestoneVoucherAmount();
        $isPercent = $config->isMilestoneVoucherPercent();
        // Round 181 : re-clamp au plafond de sécurité au moment de la
        // génération réelle du bon — même correctif que
        // BehavioralCronManager::generateBirthdayVoucher().
        if (!$isPercent) {
            $amount = min($amount, $config->getVoucherFixedCap());
        }
        $code      = 'NERIA-MLST-' . strtoupper(\Tools::passwdGen(6));

        $cartRule = new \CartRule();
        $langs = \Language::getLanguages(false);
        $names = [];
        foreach ($langs as $l) {
            $names[(int) $l['id_lang']] = $code;
        }
        $cartRule->name                    = $names;
        $cartRule->code                    = $code;
        $cartRule->id_customer             = $idCustomer;
        $cartRule->quantity                = 1;
        $cartRule->quantity_per_user       = 1;
        $cartRule->active                  = 1;
        $cartRule->date_from               = date('Y-m-d H:i:s');
        $cartRule->date_to                 = date('Y-m-d H:i:s', strtotime('+' . $config->getVoucherValidity() . ' days'));
        $cartRule->minimum_amount          = 0;
        $cartRule->minimum_amount_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        $cartRule->highlight               = false;
        $cartRule->free_shipping           = false;

        // Restreint le bon à LA BOUTIQUE réelle du client — sans ça,
        // PrestaShop rend un CartRule utilisable sur TOUTES les boutiques de
        // l'installation par défaut. La réservation anti-doublon
        // (neria_milestone_voucher) ne contrôle que l'unicité de l'émission,
        // pas la validité d'usage du bon sur une autre boutique
        // (catalogue/devise différents).
        if ($idShop > 0 && \Shop::isFeatureActive()) {
            $cartRule->shop_restriction = 1;
            $cartRule->id_shop_list     = [$idShop];
        }

        if ($isPercent) {
            $cartRule->reduction_percent = $amount;
            $cartRule->reduction_amount  = 0;
        } else {
            $cartRule->reduction_amount   = $amount;
            $cartRule->reduction_percent  = 0;
            $cartRule->reduction_tax      = 1;
            $cartRule->reduction_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        }

        if (!$cartRule->add()) {
            // Round 133 : ne PLUS libérer la réservation ici. checkMilestone()
            // capture cette exception (bon désactivé silencieusement pour ce
            // palier) mais envoie ensuite quand même l'email milestone_order
            // sans bon — si la réservation était libérée à cet instant, elle
            // n'existait déjà plus au moment où Mail::Send() réussissait,
            // laissant le palier totalement non-réservé malgré l'email déjà
            // parti. Un second déclenchement (hook dupliqué, retraitement de
            // statut) pouvait alors renvoyer l'email ET, cette fois, créer un
            // vrai bon — double bon pour le même jalon. La réservation ne
            // doit être libérée QUE si l'envoi de l'email lui-même échoue,
            // ce que checkMilestone() gère déjà via releaseMilestoneClaim().
            throw new \RuntimeException('CartRule::add() failed for customer ' . $idCustomer . ' milestone ' . $milestone);
        }

        $this->db->execute(
            'UPDATE `' . $this->prefix . 'neria_milestone_voucher`
             SET id_cart_rule = ' . (int) $cartRule->id . ', voucher_code = \'' . pSQL($code) . '\'
             WHERE id_customer = ' . (int) $idCustomer . ' AND milestone = ' . (int) $milestone . '
               AND id_shop = ' . (int) $idShop
        );

        return $code;
    }

    /**
     * Bloc HTML du bon de réduction palier, injecté à la place du
     * placeholder {milestone_voucher_block} — vide si aucun bon (toggle
     * désactivé ou échec de génération), auquel cas le template reste un
     * simple email de reconnaissance sans bloc bon de réduction.
     */
    private function buildMilestoneVoucherHtmlBlock(string $voucherCode, string $iso): string
    {
        if ($voucherCode === '') {
            return '';
        }

        $engine = new \TranslationEngine($this->module);
        $label  = htmlspecialchars(
            $engine->get('milestone_order', 'milestone_voucher_value', $iso),
            ENT_QUOTES
        );
        $accent = htmlspecialchars(
            (new \ConfigManager($this->module))->getDesignConfig()['color_accent'] ?? '#b38b59',
            ENT_QUOTES
        );
        $code = htmlspecialchars($voucherCode, ENT_QUOTES);

        return '<div style="text-align:center;margin:28px 0;padding:24px;border:2px solid ' . $accent . ';background:#fefefe;">'
            . '<p style="font-size:20px;font-weight:700;color:' . $accent . ';margin:0;letter-spacing:0.06em;">' . $code . '</p>'
            . '<p style="margin:12px 0 0 0;">' . $label . '</p>'
            . '</div>';
    }

    /**
     * Équivalent texte, injecté à la place de {milestone_voucher_block_txt}.
     */
    private function buildMilestoneVoucherTxtBlock(string $voucherCode, string $iso): string
    {
        if ($voucherCode === '') {
            return '';
        }

        $engine = new \TranslationEngine($this->module);
        $label  = $engine->get('milestone_order', 'milestone_voucher_value', $iso);

        return "\n" . $label . ' : ' . $voucherCode . "\n";
    }

    // ============================================================
    // MILESTONE_ORDER — Palier commandes atteint
    // Déclenché par : hookActionObjectOrderAddAfter
    // ============================================================

    public function handleNewOrder(\Order $order): void
    {
        $this->checkMilestone($order);
    }

    /**
     * Vérifie si le nombre de commandes VALIDES du client vient d'atteindre
     * un palier milestone, et envoie l'email/le bon associé si oui.
     *
     * Appelée à deux moments :
     *  - handleNewOrder() (hookActionObjectOrderAddAfter) : couvre le cas où
     *    la commande est valide dès sa création (paiement immédiat type CB).
     *  - handleStatusChange() (hookActionOrderStatusPostUpdate), UNIQUEMENT
     *    quand la commande bascule de non-valide à valide : couvre le cas,
     *    largement majoritaire pour virement/chèque/COD, où Order::valid
     *    (= OrderState::logable) passe à 1 après coup, plusieurs jours après
     *    la création. Sans ce second appel, ce palier n'était quasiment
     *    jamais atteint pour ces moyens de paiement — même défaut de fond
     *    que abandoned_cart_1/checkout_abandonment corrigé plus tôt ce soir.
     */
    private function checkMilestone(\Order $order): void
    {
        // Point de vérification dispersé #4 : évite de générer un bon de
        // réduction (CartRule réel, coût métier) pour un email qui de toute
        // façon ne partira pas — le verrou effectif reste
        // hookActionEmailSendBefore (universel), celui-ci économise le
        // travail en amont. Vérification locale uniquement.
        if (class_exists('LicenseManager') && !(new \LicenseManager($this->module))->isEmailSendingAllowed()) {
            return;
        }

        $idCustomer = (int) $order->id_customer;
        if ($idCustomer <= 0) {
            return;
        }

        $customer = new \Customer($idCustomer);
        if (!\Validate::isLoadedObject($customer)) {
            return;
        }

        // \Order::getCustomerNbOrders() compte TOUTES les commandes (y compris
        // en attente de paiement, refusées ou annulées) — on ne veut compter que
        // les commandes valides pour les paliers milestone/fidélité, sinon un
        // client peut décrocher un palier (et sa récompense) sur des commandes
        // jamais réellement honorées.
        $idShop = (int) $order->id_shop;
        $count  = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `id_customer` = ' . $idCustomer . ' AND `id_shop` = ' . $idShop . ' AND `valid` = 1'
        );
        $idLang = (int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT');
        $toName = trim($customer->firstname . ' ' . $customer->lastname) ?: null;
        $common = [
            '{firstname}' => $customer->firstname,
            '{lastname}'  => $customer->lastname,
            // Configuration::get(..., $idShop) : round 106, {shop_name}
            // ignorait la boutique réelle de la commande alors que $idShop
            // ci-dessus (id_shop de LA COMMANDE) scope déjà claimMilestone()
            // et le comptage des commandes valides juste au-dessus.
            '{shop_name}' => \Configuration::get('PS_SHOP_NAME', null, null, $idShop),
        ];

        // milestone_order
        if (in_array($count, self::MILESTONES, true)) {
            // Round 178 : vérifié AVANT la réservation — même raison que le
            // check LicenseManager tout en haut de cette méthode (économiser
            // un bon de réduction réel pour un envoi qui de toute façon ne
            // partira pas), mais surtout pour ne jamais réclamer le palier
            // (claimMilestone()) pour un email qui serait de toute façon
            // bloqué par le hook : sans ça, Mail::Send() renverrait true
            // malgré le blocage, et la réservation ne serait JAMAIS libérée
            // (seul un $result false la libère plus bas) — le palier
            // resterait réclamé à vie, sans email ni bon, sans retry possible.
            $blockReason = $this->explicitSendBlockReason('milestone_order', $customer->email, $idCustomer, $idShop, $idLang);
            if ($blockReason !== null) {
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'milestone_order', 'email' => $customer->email]),
                    'milestone_order', 'OrderTriggers'
                );
                return;
            }

            // Réservation anti-doublon de l'EMAIL lui-même, indépendante du
            // toggle bon de réduction — voir claimMilestone().
            if (!$this->claimMilestone($idCustomer, $count, $idShop)) {
                return;
            }
            try {
                $config      = new \ConfigManager($this->module);
                $voucherCode = '';
                if ($config->isMilestoneVoucherEnabled()) {
                    try {
                        $voucherCode = $this->generateMilestoneVoucher($idCustomer, $count, $config, $idShop);
                    } catch (\Throwable $e) {
                        $this->watchdog()->error(
                            \WatchdogManager::i18nMsg('watchdog.milestone_voucher_error', ['count' => $count, 'email' => $customer->email, 'error' => $e->getMessage()]),
                            'milestone_order', 'OrderTriggers'
                        );
                    }
                }

                $iso = \Language::getIsoById($idLang) ?: 'fr';
                $voucherBlockHtml = $this->buildMilestoneVoucherHtmlBlock($voucherCode, $iso);
                $voucherBlockTxt  = $this->buildMilestoneVoucherTxtBlock($voucherCode, $iso);

                $result = \Mail::Send(
                    $idLang, 'milestone_order', '',
                    array_merge($common, [
                        // {id_order} : scope le Mode Silence par COMMANDE
                        // (voir hookActionEmailSendBefore/CooldownManager),
                        // même correctif déjà appliqué à order_partial_shipped/
                        // order_on_hold/refund_processed/return_received
                        // (round 63) mais jamais étendu à milestone_order.
                        // Sans elle, un client atteignant légitimement deux
                        // paliers différents dans la même fenêtre de cooldown
                        // (import en masse, corrections de statut groupées)
                        // voyait le second email milestone_order bloqué à
                        // tort comme "doublon" par la seule clé
                        // (template, client, fenêtre) — alors qu'un vrai bon
                        // de réduction avait déjà été généré et attribué.
                        '{id_order}'               => (int) $order->id,
                        '{milestone_count}'        => $this->formatMilestoneOrdinal($count, $idLang),
                        '{order_count}'            => (string) $count,
                        '{voucher_code}'           => $voucherCode,
                        '{milestone_voucher_block}'     => $voucherBlockHtml,
                        '{milestone_voucher_block_txt}' => $voucherBlockTxt,
                    ]),
                    $customer->email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.milestone_sent', ['count' => $count, 'email' => $customer->email]),
                        'milestone_order', 'OrderTriggers'
                    );
                } else {
                    // Libère la réservation (si aucun bon réel n'a été créé)
                    // pour permettre un futur re-déclenchement de ce palier —
                    // sans cela, un échec d'envoi ponctuel (SMTP indisponible)
                    // privait ce client de milestone_order à vie, sans aucun
                    // mécanisme de retry pour ce template.
                    $this->releaseMilestoneClaim($idCustomer, $count, $idShop);
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'milestone_order', 'email' => $customer->email]),
                        'milestone_order', 'OrderTriggers'
                    );
                }
            } catch (\Throwable $e) {
                $this->releaseMilestoneClaim($idCustomer, $count, $idShop);
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.milestone_error', ['count' => $count, 'email' => $customer->email, 'error' => $e->getMessage()]),
                    'milestone_order', 'OrderTriggers'
                );
            }
        }

        // Note : le déclenchement "loyalty_tier_upgrade" par nombre de commandes
        // (paliers 3/10/25/50) a été retiré le 2026-07-13 — doublon incomplet
        // du programme de fidélité réel (LoyaltyManager, par points d'engagement,
        // avec vrai bon de réduction généré) : celui-ci envoyait le même template
        // sans jamais fournir {voucher_code}/{total_points}, ni générer de bon.
        // Voir [[project_loyalty_tier_duplicate_fix]] en mémoire.
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
            // La commande vient de basculer de non-valide à valide (ex.
            // virement/chèque/COD confirmé) : c'est le seul moment où ce
            // type de commande peut faire franchir un palier milestone,
            // puisqu'elle ne comptait pas encore lors de handleNewOrder().
            // Ce contrôle doit avoir lieu AVANT le filtre "statuts standards"
            // ci-dessous : la confirmation de paiement (ex. id 2 "Paiement
            // accepté") est elle-même un statut STANDARD PrestaShop, donc le
            // early-return suivant l'aurait rendue silencieusement
            // inatteignable pour tous les moyens de paiement asynchrones.
            if (!$oldStatus->logable && $newStatus->logable) {
                $orderForMilestone = new \Order($idOrder);
                if (\Validate::isLoadedObject($orderForMilestone)) {
                    $this->checkMilestone($orderForMilestone);

                    // Recrédite les points si cette commande avait été
                    // clawback-ée (annulation, remboursement) et redevient
                    // valide — ex. litige résolu en faveur du marchand,
                    // ré-expédition après une annulation. Sans effet si
                    // aucun clawback n'avait eu lieu (idempotent).
                    if (class_exists('LoyaltyManager') && \Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')) {
                        try {
                            (new \LoyaltyManager($this->module))->restoreForOrder(
                                $idOrder, (int) $orderForMilestone->id_customer, (int) $orderForMilestone->id_shop
                            );
                        } catch (\Throwable $e) {
                            $this->watchdog()->error(
                                \WatchdogManager::i18nMsg('watchdog.loyalty_clawback_error', ['order' => $orderForMilestone->reference, 'error' => $e->getMessage()]),
                                'order_restored', 'OrderTriggers'
                            );
                        }
                    }
                }
            }

            // Annulation d'une commande auparavant valide (logable), SANS
            // création d'avoir — le seul clawback existant (handleRefund())
            // n'est déclenché que par actionOrderSlipAdd (création d'un
            // avoir/CartRule), jamais par un simple changement de statut.
            // Un marchand qui annule une commande payée directement via le
            // statut BO (flux PrestaShop tout à fait normal, sans passer par
            // un avoir) laissait le client garder définitivement les points
            // de fidélité gagnés sur une commande pourtant annulée. Placé
            // AVANT le filtre "statuts standards" ci-dessous, comme le check
            // milestone, puisque "Annulée" est elle-même un statut standard.
            if ($oldStatus->logable && !$newStatus->logable
                && class_exists('LoyaltyManager') && \Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')
            ) {
                $orderForCancel = new \Order($idOrder);
                if (\Validate::isLoadedObject($orderForCancel)) {
                    try {
                        (new \LoyaltyManager($this->module))->clawbackForOrder(
                            $idOrder, (int) $orderForCancel->id_customer, (int) $orderForCancel->id_shop
                        );
                    } catch (\Throwable $e) {
                        $this->watchdog()->error(
                            \WatchdogManager::i18nMsg('watchdog.loyalty_clawback_error', ['order' => $orderForCancel->reference, 'error' => $e->getMessage()]),
                            'order_canceled', 'OrderTriggers'
                        );
                    }
                }
            }

            // Ignorer tous les statuts standards PrestaShop pour les
            // déclencheurs order_on_hold / order_partial_shipped ci-dessous
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
            // {cooldown_scope} scope le Mode Silence sur CETTE commande (cf.
            // CooldownManager, neria.php hookActionEmailSendBefore) — sans
            // lui, order_partial_shipped/order_on_hold pour une commande B
            // pouvait être bloqué à tort par le cooldown posé pour une
            // commande A totalement différente du même client, dans la même
            // fenêtre. Même correctif que LookCompletionManager.
            $common = [
                '{firstname}'      => $customer->firstname,
                '{lastname}'       => $customer->lastname,
                '{order_name}'     => $order->reference,
                // Configuration::get(..., $idShop) : round 106, même piège
                // que le Mail::Send() plus bas déjà scopé par $idShop.
                '{shop_name}'      => \Configuration::get('PS_SHOP_NAME', null, null, $idShop),
                '{id_order}'       => (int) $order->id,
                '{cooldown_scope}' => 'order:' . (int) $order->id,
            ];

            // order_partial_shipped : expédition partielle
            if ($newStatus->shipped && !$newStatus->delivery && !$oldStatus->shipped) {
                // Round 178 : voir explicitSendBlockReason() — Mail::Send()
                // renvoie true même si le hook bloque l'envoi, journalisant
                // à tort un succès.
                if ($this->explicitSendBlockReason('order_partial_shipped', $email, (int) $order->id_customer, $idShop, $idLang) !== null) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'order_partial_shipped', 'email' => $email]),
                        'order_partial_shipped', 'OrderTriggers'
                    );
                    return;
                }
                $result = \Mail::Send(
                    $idLang, 'order_partial_shipped', '',
                    array_merge($common, $this->buildShippedItemsVars($order)),
                    $email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.partial_shipped_sent', ['order' => $order->reference, 'email' => $email]),
                        'order_partial_shipped', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'order_partial_shipped', 'email' => $email]),
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

                // Round 178 : voir explicitSendBlockReason() ci-dessus.
                if ($this->explicitSendBlockReason('order_on_hold', $email, (int) $order->id_customer, $idShop, $idLang) !== null) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'order_on_hold', 'email' => $email]),
                        'order_on_hold', 'OrderTriggers'
                    );
                    return;
                }
                $result = \Mail::Send(
                    $idLang, 'order_on_hold', '',
                    array_merge($common, ['{hold_reason}' => $statusName]),
                    $email, $toName, null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/', false, $idShop
                );
                if ($result) {
                    $this->watchdog()->info(
                        \WatchdogManager::i18nMsg('watchdog.order_on_hold_sent', ['status' => $statusName, 'order' => $order->reference, 'email' => $email]),
                        'order_on_hold', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'order_on_hold', 'email' => $email]),
                        'order_on_hold', 'OrderTriggers'
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.status_change_error', ['order' => $idOrder, 'error' => $e->getMessage()]),
                '', 'OrderTriggers'
            );
        }
    }

    // ============================================================
    // REFUND_PROCESSED — Avoir / remboursement créé
    // Déclenché par : hookActionOrderSlipAdd
    // ============================================================

    public function handleRefund(\Order $order, array $productList, int $idOrderSlip = 0): void
    {
        try {
            // Verrou par avoir (pas seulement par commande) : rien n'empêchait
            // auparavant un double déclenchement du hook actionOrderSlipAdd
            // (rejeu, module tiers, double dispatch PrestaShop) de renvoyer
            // deux fois l'email refund_processed pour LE MÊME avoir. Sans
            // id_order_slip disponible (hook appelé sans orderSlip dans
            // $params, cas rare), on ne peut pas déduper — on continue alors
            // sans verrou plutôt que de bloquer un remboursement légitime.
            if ($idOrderSlip > 0) {
                $lockName = 'neria_refund_slip_' . $idOrderSlip;
                if ((int) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 0)") !== 1) {
                    return;
                }
            }

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
            $formatted = \NeriaTools::displayPrice($amount, $currency, $idLang);

            // Round 178 : voir explicitSendBlockReason() ci-dessus — vérifié
            // à part (pas en early return) car le retrait des points de
            // fidélité plus bas doit avoir lieu QUE l'email parte ou non
            // (le remboursement est réel indépendamment du blocage email).
            if ($this->explicitSendBlockReason('refund_processed', $customer->email, (int) $order->id_customer, (int) $order->id_shop, $idLang) !== null) {
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'refund_processed', 'email' => $customer->email]),
                    'refund_processed', 'OrderTriggers'
                );
            } else {
                $result = \Mail::Send(
                    $idLang,
                    'refund_processed',
                    '',
                    [
                        '{firstname}'      => $customer->firstname,
                        '{lastname}'       => $customer->lastname,
                        '{order_name}'     => $order->reference,
                        '{refund_amount}'  => $formatted,
                        // Configuration::get(..., $order->id_shop) : round 106,
                        // même piège que le Mail::Send() plus bas déjà scopé par
                        // (int) $order->id_shop.
                        '{shop_name}'      => \Configuration::get('PS_SHOP_NAME', null, null, (int) $order->id_shop),
                        // Scope le Mode Silence sur CETTE commande — sans lui, un
                        // client remboursé sur deux commandes distinctes dans la
                        // même fenêtre de cooldown ne recevait qu'un seul des
                        // deux emails refund_processed, le second étant bloqué à
                        // tort comme doublon.
                        '{id_order}'       => (int) $order->id,
                        '{cooldown_scope}' => 'order:' . (int) $order->id,
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
                        \WatchdogManager::i18nMsg('watchdog.refund_sent', ['amount' => $formatted, 'order' => $order->reference, 'email' => $customer->email]),
                        'refund_processed', 'OrderTriggers'
                    );
                } else {
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'refund_processed', 'email' => $customer->email]),
                        'refund_processed', 'OrderTriggers'
                    );
                }
            }

            // ── Retrait des points/bons fidélité gagnés par cette commande ──
            // Les points de fidélité sont attribués à taux FIXE par commande
            // (LoyaltyManager::POINTS_CONVERSION = 10 points, pas un taux par
            // euro dépensé) — clawbackForOrder() retire donc TOUJOURS la
            // totalité des points de la commande, sans notion de proportion.
            // Auparavant appelé pour TOUT avoir, même un remboursement
            // partiel mineur (ex. 20€ sur une commande de 200€ pour un
            // article défectueux) : le client perdait 100% de ses points
            // pour 10% de remboursement. On ne clawback désormais que si
            // l'avoir couvre la quasi-totalité de la commande (>= 90% du
            // montant payé) — un remboursement réellement partiel ne
            // déclenche plus le retrait, cohérent avec le fait qu'un achat
            // réel a bien eu lieu sur la part non remboursée.
            // Cumul de TOUS les avoirs de la commande (pas seulement celui-ci) :
            // un marchand qui rembourse en 2 avoirs successifs (50% + 50%)
            // doit bien déclencher le clawback une fois le cumul proche du
            // total — un calcul basé sur le seul avoir courant manquerait ce
            // cas (chaque avoir individuellement < 90%, jamais de clawback).
            $orderTotal   = (float) $order->total_paid_tax_incl;
            $totalRefunded = (float) $this->db->getValue(
                'SELECT SUM(total_products_tax_incl + total_shipping_tax_incl)
                 FROM `' . _DB_PREFIX_ . 'order_slip`
                 WHERE id_order = ' . (int) $order->id
            );
            $refundRatio  = $orderTotal > 0 ? ($totalRefunded / $orderTotal) : 1.0;
            if ($refundRatio >= 0.9 && class_exists('LoyaltyManager') && \Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')) {
                try {
                    (new \LoyaltyManager($this->module))->clawbackForOrder(
                        (int) $order->id, (int) $customer->id, (int) $order->id_shop
                    );
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        \WatchdogManager::i18nMsg('watchdog.loyalty_clawback_error', ['order' => $order->reference, 'error' => $e->getMessage()]),
                        'refund_processed', 'OrderTriggers'
                    );
                }
            }

            // ── Ajustement du revenu attribué (ps_neria_stat) ───────────
            // Round 185 : jusqu'ici, aucun code du module ne touchait
            // ps_neria_stat après un remboursement — le revenu attribué à
            // la conversion (StatsManager::recordConversion(), au moment du
            // paiement) restait compté indéfiniment dans getRevenueStats()/
            // MonthlyReportManager/dashboards ROI même après remboursement
            // total, surestimant durablement le ROI par template/campagne.
            // $totalRefunded (cumul de TOUS les avoirs de la commande) est
            // déjà calculé ci-dessus pour le clawback fidélité — réutilisé
            // ici pour ramener le revenu de la ligne 'conversion' de cette
            // commande au montant réellement conservé par le marchand.
            if (class_exists('StatsManager')) {
                try {
                    (new \StatsManager($this->module))->adjustConversionRevenueForOrder(
                        (int) $order->id, max(0.0, $orderTotal - $totalRefunded)
                    );
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        \WatchdogManager::i18nMsg('watchdog.revenue_adjustment_error', ['order' => $order->reference, 'error' => $e->getMessage()]),
                        'refund_processed', 'OrderTriggers'
                    );
                }
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
                \WatchdogManager::i18nMsg('watchdog.refund_error', ['order' => $order->reference, 'error' => $e->getMessage()]),
                'refund_processed', 'OrderTriggers'
            );
        } finally {
            if ($idOrderSlip > 0) {
                $this->db->execute("SELECT RELEASE_LOCK('" . pSQL('neria_refund_slip_' . $idOrderSlip) . "')");
            }
        }
    }

    // ============================================================
    // RETURN_RECEIVED — Retour marchandise enregistré
    // Déclenché par : hookActionObjectOrderReturnAddAfter
    // ============================================================

    public function handleReturn(\OrderReturn $orderReturn): void
    {
        // Verrou par retour (même raison que handleRefund() ci-dessus, qui
        // verrouille par avoir) : rien n'empêchait auparavant un double
        // déclenchement du hook actionObjectOrderReturnAddAfter (rejeu,
        // module tiers, double dispatch PrestaShop) de renvoyer deux fois
        // l'email return_received pour LE MÊME retour. Le scope Mode
        // Silence ({id_order}/{cooldown_scope} ci-dessous) atténue le risque
        // mais reste un contrôle non-atomique (lecture puis écriture) —
        // insuffisant contre deux appels quasi simultanés.
        $idOrderReturn = (int) $orderReturn->id;
        $lockName = 'neria_return_' . $idOrderReturn;
        if ($idOrderReturn > 0 && (int) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 0)") !== 1) {
            return;
        }

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
            $summary    = '';
            $summaryTxt = '';
            if (is_array($rows) && !empty($rows)) {
                $lines = array_map(
                    fn($r) => '× ' . (int) $r['product_quantity'] . ' ' . $r['product_name'],
                    $rows
                );
                $summary    = implode("\n", $lines);
                // {meta_products_txt} — bug trouvé le 2026-07-14 (contrôle
                // Watchdog txt_placeholder_coverage) : jamais fourni, {summary}
                // et sa version txt étant en réalité identiques (texte brut
                // déjà), on réutilise les mêmes lignes.
                $summaryTxt = $summary;
            }

            // Round 178 : voir explicitSendBlockReason() plus haut.
            if ($this->explicitSendBlockReason('return_received', $customer->email, (int) $order->id_customer, (int) $order->id_shop, $idLang) !== null) {
                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'return_received', 'email' => $customer->email]),
                    'return_received', 'OrderTriggers'
                );
                return;
            }

            $result = \Mail::Send(
                $idLang,
                'return_received',
                '',
                [
                    '{firstname}'         => $customer->firstname,
                    '{lastname}'          => $customer->lastname,
                    '{order_name}'        => $order->reference,
                    '{meta_products}'     => $summary,
                    '{meta_products_txt}' => $summaryTxt,
                    // Configuration::get(..., $order->id_shop) : round 106,
                    // même piège que le Mail::Send() plus bas déjà scopé par
                    // (int) $order->id_shop.
                    '{shop_name}'         => \Configuration::get('PS_SHOP_NAME', null, null, (int) $order->id_shop),
                    // Scope le Mode Silence sur CETTE commande — même
                    // correctif que refund_processed/order_on_hold/
                    // order_partial_shipped ci-dessus : sans lui, un retour
                    // sur une commande B pouvait être bloqué à tort par le
                    // cooldown posé pour un retour sur une commande A
                    // différente du même client, dans la même fenêtre.
                    '{id_order}'          => (int) $order->id,
                    '{cooldown_scope}'    => 'order:' . (int) $order->id,
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
                    \WatchdogManager::i18nMsg('watchdog.return_sent', ['return' => $orderReturn->id, 'order' => $order->reference, 'email' => $customer->email]),
                    'return_received', 'OrderTriggers'
                );
            } else {
                $this->watchdog()->warning(
                    \WatchdogManager::i18nMsg('watchdog.send_silent_fail', ['template' => 'return_received', 'email' => $customer->email]),
                    'return_received', 'OrderTriggers'
                );
            }
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.return_error', ['return' => $orderReturn->id, 'error' => $e->getMessage()]),
                'return_received', 'OrderTriggers'
            );
        } finally {
            if ($idOrderReturn > 0) {
                $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
            }
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Construit {shipped_items} (HTML, <br> entre les lignes) et
     * {shipped_items_txt} (texte brut, \n) pour order_partial_shipped — la
     * seule variable initialement câblée ({shipped_items}) avait deux
     * défauts : jamais de variante _txt (le .txt affichait le placeholder
     * brut) et un formatage à base de "\n" seul, invisible dans un email
     * HTML sans <br>. Ajoute aussi le transporteur/numéro de suivi réels
     * (ps_order_carrier) — absents de la première version qui ne listait
     * que les produits, sans aucune information d'expédition.
     *
     * @return array{'{shipped_items}': string, '{shipped_items_txt}': string}
     */
    private function buildShippedItemsVars(\Order $order): array
    {
        try {
            $products = $order->getProducts();
            $productLines = is_array($products)
                ? array_map(
                    fn($p) => '× ' . (int) $p['product_quantity'] . ' ' . $p['product_name'],
                    $products
                )
                : [];

            $carriers = \Db::getInstance()->executeS(
                'SELECT oc.tracking_number, c.name AS carrier_name
                 FROM `' . _DB_PREFIX_ . 'order_carrier` oc
                 LEFT JOIN `' . _DB_PREFIX_ . 'carrier` c ON c.id_carrier = oc.id_carrier
                 WHERE oc.id_order = ' . (int) $order->id . '
                 ORDER BY oc.date_add ASC'
            );

            $carrierLines = [];
            if (is_array($carriers)) {
                $total = count($carriers);
                foreach ($carriers as $i => $row) {
                    $label = ($total > 1)
                        ? sprintf('Colis %d/%d', $i + 1, $total)
                        : 'Colis';
                    $carrierName = trim((string) ($row['carrier_name'] ?? '')) ?: '—';
                    $tracking    = trim((string) ($row['tracking_number'] ?? ''));
                    $carrierLines[] = $tracking !== ''
                        ? sprintf('%s — %s %s', $label, $carrierName, $tracking)
                        : sprintf('%s — %s', $label, $carrierName);
                }
            }

            $allLines = array_merge($productLines, $carrierLines);
            if (empty($allLines)) {
                return ['{shipped_items}' => '', '{shipped_items_txt}' => ''];
            }

            return [
                '{shipped_items}'     => '<p>' . implode('</p><p>', array_map('htmlspecialchars', $allLines)) . '</p>',
                '{shipped_items_txt}' => implode("\n", $allLines),
            ];
        } catch (\Throwable $e) {
            return ['{shipped_items}' => '', '{shipped_items_txt}' => ''];
        }
    }

}
