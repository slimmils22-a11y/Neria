<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — ManualSendManager
 *
 * Gère l'envoi manuel, depuis le back-office, des templates « à la demande »
 * (vague 1) : le marchand choisit un template + un client (+ une commande),
 * remplit les champs de contenu spécifiques au template, et envoie.
 *
 * L'envoi passe par Mail::Send → hook actionEmailSendBefore → EmailRenderer,
 * donc bénéficie automatiquement du design, de la traduction et de la
 * détection de langue Neria.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ManualSendManager
{
    /**
     * Templates envoyables manuellement (vague 1).
     * Le libellé lisible vient de NeriaTools::getTemplateLabels().
     */
    const WAVE1_TEMPLATES = [
        // Artisanat / service
        'artisan_message', 'craftsmanship_update', 'alteration_update', 'bespoke_ready',
        'repair_completed', 'repair_request_confirm', 'care_certificate',
        'certificate_provenance', 'extended_warranty',
        // Comportemental (crons)
        'first_anniversary', 'relationship_anniversary',
        'checkout_abandonment',
        'quote_expiry_48h', 'quote_expiry_day', 'quote_extension_offer',
        // Logistique / incidents
        'white_glove_apology', 'product_recall', 'customs_alert',
        'delivery_attempt_failed', 'packaging_choice',
        // VIP / marketing
        'vip', 'early_access', 'exclusive_preview', 'private_sale', 'private_invitation',
        'personal_shopper_intro', 'concierge_followup', 'end_of_year_gift', 'gift_guarantee',
        // Divers
        'corporate_order_confirm', 'tax_refund_eligible', 'gift_message_confirm', 'unboxing_guide',
    ];

    /**
     * Variables injectées AUTOMATIQUEMENT (contexte client/commande, design,
     * variables marchand). À NE PAS demander au marchand : la découverte des
     * champs éditables les soustrait des placeholders du template.
     */
    const AUTO_VARS = [
        // Client / boutique
        'firstname', 'lastname', 'email', 'shop_name', 'shop_url', 'shop_logo',
        // Commande
        'order_name', 'id_order', 'date', 'payment',
        // Liens
        'history_url', 'guest_tracking_url', 'tracking_url', 'order_url', 'order_link',
        'followup', 'link', 'url', 'contact_url', 'review_url', 'product_url', 'product_link',
        // Transport / adresses
        'carrier', 'carrier_name',
        'delivery_block_html', 'invoice_block_html', 'delivery_block_txt', 'invoice_block_txt',
        // Totaux
        'total_paid', 'total_products', 'total_discounts', 'total_shipping',
        'total_wrapping', 'total_tax_paid', 'total',
        // Tableaux produits
        'products', 'products_txt', 'discounts', 'discounts_txt', 'items', 'items_txt',
        'meta_products', 'meta_products_txt', 'nbProducts',
        // Divers contexte
        'subject', 'check_name', 'check_address_html', 'check_address_txt',
    ];

    /**
     * Variables sans libellé dédié dans leur template (paragraphe autonome) :
     * on les rattache à la meilleure clé de traduction existante, plutôt que
     * de retomber sur la clé brute (anglaise). Reste du pur reuse de
     * traductions (aucune fabrication).
     */
    const LABEL_KEY_OVERRIDE = [
        'white_glove_apology' => ['apology_reason' => 'apology_details_title'],
    ];

    /**
     * Libellés des rares champs sans clé de traduction dédiée dans leur
     * template (paragraphes autonomes ou titre partagé). Mots simples,
     * fournis dans les 19 langues. La clé est le nom de variable.
     */
    const FIELD_LABEL_I18N = [
        'invitation_location' => [
            'fr' => 'Lieu', 'en' => 'Location', 'gb' => 'Location', 'de' => 'Ort', 'it' => 'Luogo',
            'es' => 'Lugar', 'pt' => 'Local', 'br' => 'Local', 'ar' => 'المكان',
            'ja' => '会場', 'ko' => '장소', 'zh' => '地点', 'tw' => '地點',
            'ru' => 'Место', 'tr' => 'Yer', 'sv' => 'Plats', 'no' => 'Sted',
            'da' => 'Sted', 'nl' => 'Locatie',
        ],
        'invitation_dates' => [
            'fr' => 'Dates', 'en' => 'Dates', 'gb' => 'Dates', 'de' => 'Termine', 'it' => 'Date',
            'es' => 'Fechas', 'pt' => 'Datas', 'br' => 'Datas', 'ar' => 'التواريخ',
            'ja' => '日時', 'ko' => '일정', 'zh' => '日期', 'tw' => '日期',
            'ru' => 'Даты', 'tr' => 'Tarihler', 'sv' => 'Datum', 'no' => 'Datoer',
            'da' => 'Datoer', 'nl' => 'Data',
        ],
        'voucher_usage' => [
            'fr' => "Conditions d'utilisation", 'en' => 'Terms of use', 'gb' => 'Terms of use',
            'de' => 'Nutzungsbedingungen', 'it' => "Condizioni d'uso",
            'es' => 'Condiciones de uso', 'pt' => 'Condições de uso',
            'br' => 'Condições de uso', 'ar' => 'شروط الاستخدام',
            'ja' => '利用条件', 'ko' => '이용 조건', 'zh' => '使用条款',
            'tw' => '使用條款', 'ru' => 'Условия использования',
            'tr' => 'Kullanım koşulları', 'sv' => 'Användningsvillkor',
            'no' => 'Vilkår', 'da' => 'Betingelser', 'nl' => 'Gebruiksvoorwaarden',
        ],
        // ── Champs URL éditables ─────────────────────────────────────
        // Label explicite : indique au marchand qu'il faut coller une URL.
        'sale_url' => [
            'fr' => 'URL de la vente privée', 'en' => 'Private sale URL', 'gb' => 'Private sale URL',
            'de' => 'URL des Privatverkaufs', 'it' => 'URL della vendita privata',
            'es' => 'URL de la venta privada', 'pt' => 'URL da venda privada',
            'br' => 'URL da venda privada', 'ar' => 'رابط البيع الخاص',
            'ja' => 'プライベートセールURL', 'ko' => '프라이빗 세일 URL',
            'zh' => '私密特卖链接', 'tw' => '私密特賣連結',
            'ru' => 'Ссылка на закрытую распродажу', 'tr' => 'Özel satış URL\'si',
            'sv' => 'URL för privatförsäljning', 'no' => 'URL til privat salg',
            'da' => 'URL til privatsalg', 'nl' => 'URL van de private sale',
        ],
        'rsvp_url' => [
            'fr' => 'URL de confirmation (RSVP)', 'en' => 'RSVP confirmation URL', 'gb' => 'RSVP confirmation URL',
            'de' => 'Bestätigungs-URL (RSVP)', 'it' => 'URL di conferma (RSVP)',
            'es' => 'URL de confirmación (RSVP)', 'pt' => 'URL de confirmação (RSVP)',
            'br' => 'URL de confirmação (RSVP)', 'ar' => 'رابط التأكيد (RSVP)',
            'ja' => '出欠確認URL (RSVP)', 'ko' => 'RSVP 확인 URL',
            'zh' => '确认链接 (RSVP)', 'tw' => '確認連結 (RSVP)',
            'ru' => 'Ссылка для подтверждения (RSVP)', 'tr' => 'Onay URL\'si (RSVP)',
            'sv' => 'Bekräftelse-URL (RSVP)', 'no' => 'Bekreftelses-URL (RSVP)',
            'da' => 'Bekræftelses-URL (RSVP)', 'nl' => 'Bevestigings-URL (RSVP)',
        ],
        'review_url' => [
            'fr' => 'URL de la page avis', 'en' => 'Review page URL', 'gb' => 'Review page URL',
            'de' => 'URL der Bewertungsseite', 'it' => 'URL della pagina recensioni',
            'es' => 'URL de la página de reseñas', 'pt' => 'URL da página de avaliações',
            'br' => 'URL da página de avaliações', 'ar' => 'رابط صفحة التقييمات',
            'ja' => 'レビューページURL', 'ko' => '리뷰 페이지 URL',
            'zh' => '评价页面链接', 'tw' => '評價頁面連結',
            'ru' => 'Ссылка на страницу отзывов', 'tr' => 'Yorum sayfası URL\'si',
            'sv' => 'URL till recensionssida', 'no' => 'URL til anmeldelsesside',
            'da' => 'URL til anmeldelsesside', 'nl' => 'URL van de beoordelingspagina',
        ],
        'contact_url' => [
            'fr' => 'URL du formulaire de contact', 'en' => 'Contact form URL', 'gb' => 'Contact form URL',
            'de' => 'Kontaktformular-URL', 'it' => 'URL del modulo di contatto',
            'es' => 'URL del formulario de contacto', 'pt' => 'URL do formulário de contato',
            'br' => 'URL do formulário de contato', 'ar' => 'رابط نموذج الاتصال',
            'ja' => 'お問い合わせフォームURL', 'ko' => '연락처 양식 URL',
            'zh' => '联系表单链接', 'tw' => '聯絡表單連結',
            'ru' => 'Ссылка на форму обратной связи', 'tr' => 'İletişim formu URL\'si',
            'sv' => 'URL till kontaktformulär', 'no' => 'URL til kontaktskjema',
            'da' => 'URL til kontaktformular', 'nl' => 'URL van het contactformulier',
        ],
        'product_url' => [
            'fr' => 'URL du produit', 'en' => 'Product URL', 'gb' => 'Product URL',
            'de' => 'Produkt-URL', 'it' => 'URL del prodotto',
            'es' => 'URL del producto', 'pt' => 'URL do produto',
            'br' => 'URL do produto', 'ar' => 'رابط المنتج',
            'ja' => '商品URL', 'ko' => '제품 URL',
            'zh' => '商品链接', 'tw' => '商品連結',
            'ru' => 'Ссылка на товар', 'tr' => 'Ürün URL\'si',
            'sv' => 'Produkt-URL', 'no' => 'Produkt-URL',
            'da' => 'Produkt-URL', 'nl' => 'Product-URL',
        ],
        'cart_url' => [
            'fr' => 'URL du panier abandonné', 'en' => 'Abandoned cart URL', 'gb' => 'Abandoned cart URL',
            'de' => 'URL des abgebrochenen Warenkorbs', 'it' => 'URL del carrello abbandonato',
            'es' => 'URL del carrito abandonado', 'pt' => 'URL do carrinho abandonado',
            'br' => 'URL do carrinho abandonado', 'ar' => 'رابط سلة التسوق المتروكة',
            'ja' => '放棄カートURL', 'ko' => '포기된 장바구니 URL',
            'zh' => '弃购购物车链接', 'tw' => '棄購購物車連結',
            'ru' => 'Ссылка на брошенную корзину', 'tr' => 'Terk edilen sepet URL\'si',
            'sv' => 'URL för övergiven varukorg', 'no' => 'URL for forlatt handlekurv',
            'da' => 'URL til forladt indkøbskurv', 'nl' => 'URL van verlaten winkelwagen',
        ],
        'order_url' => [
            'fr' => 'URL de la commande', 'en' => 'Order URL', 'gb' => 'Order URL',
            'de' => 'Bestell-URL', 'it' => 'URL dell\'ordine',
            'es' => 'URL del pedido', 'pt' => 'URL do pedido',
            'br' => 'URL do pedido', 'ar' => 'رابط الطلب',
            'ja' => '注文URL', 'ko' => '주문 URL',
            'zh' => '订单链接', 'tw' => '訂單連結',
            'ru' => 'Ссылка на заказ', 'tr' => 'Sipariş URL\'si',
            'sv' => 'Order-URL', 'no' => 'Ordre-URL',
            'da' => 'Ordre-URL', 'nl' => 'Bestelling-URL',
        ],
        'verif_url' => [
            'fr' => 'URL de vérification email', 'en' => 'Email verification URL', 'gb' => 'Email verification URL',
            'de' => 'E-Mail-Verifizierungs-URL', 'it' => 'URL di verifica email',
            'es' => 'URL de verificación de email', 'pt' => 'URL de verificação de email',
            'br' => 'URL de verificação de email', 'ar' => 'رابط التحقق من البريد',
            'ja' => 'メール確認URL', 'ko' => '이메일 확인 URL',
            'zh' => '邮箱验证链接', 'tw' => '電郵驗證連結',
            'ru' => 'Ссылка для подтверждения email', 'tr' => 'E-posta doğrulama URL\'si',
            'sv' => 'E-postverifierings-URL', 'no' => 'E-postverifiserings-URL',
            'da' => 'E-mailverifikations-URL', 'nl' => 'E-mailverificatie-URL',
        ],
        'return_slip_url' => [
            'fr' => 'URL du bon de retour', 'en' => 'Return slip URL', 'gb' => 'Return slip URL',
            'de' => 'Rückgabeschein-URL', 'it' => 'URL del documento di reso',
            'es' => 'URL del albarán de devolución', 'pt' => 'URL da guia de devolução',
            'br' => 'URL da guia de devolução', 'ar' => 'رابط إيصال الإرجاع',
            'ja' => '返品票URL', 'ko' => '반품 전표 URL',
            'zh' => '退货单链接', 'tw' => '退貨單連結',
            'ru' => 'Ссылка на бланк возврата', 'tr' => 'İade formu URL\'si',
            'sv' => 'Returkvitto-URL', 'no' => 'Returkvittering-URL',
            'da' => 'Returkvitterings-URL', 'nl' => 'Retourbon-URL',
        ],
        'years_label' => [
            'fr' => 'Durée (ex : deux ans)', 'en' => 'Duration (e.g. two years)', 'gb' => 'Duration (e.g. two years)',
            'de' => 'Dauer (z.B. zwei Jahre)', 'it' => 'Durata (es. due anni)',
            'es' => 'Duración (ej. dos años)', 'pt' => 'Duração (ex. dois anos)',
            'br' => 'Duração (ex. dois anos)', 'ar' => 'المدة (مثال: سنتين)',
            'ja' => '期間（例：2年）', 'ko' => '기간 (예: 2년)',
            'zh' => '时长（如：两年）', 'tw' => '時長（如：兩年）',
            'ru' => 'Срок (напр. два года)', 'tr' => 'Süre (örn. iki yıl)',
            'sv' => 'Tid (t.ex. två år)', 'no' => 'Varighet (f.eks. to år)',
            'da' => 'Varighed (f.eks. to år)', 'nl' => 'Duur (bijv. twee jaar)',
        ],
    ];

    /** @var Neria */
    private Neria $module;

    /** @var \Db */
    private \Db $db;

    /** @var WatchdogManager|null */
    private ?WatchdogManager $watchdog = null;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
    }

    private function watchdog(): WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // LISTE DES TEMPLATES
    // ============================================================

    /**
     * Retourne les templates de la vague 1 avec leur libellé lisible,
     * triés par libellé. ['key' => 'Libellé', ...]
     *
     * @return array
     */
    public function getSendableTemplates(): array
    {
        // Libellés traduits dans la langue du back-office (repli FR canonique)
        $labels = class_exists('AdminTranslator')
            ? AdminTranslator::templateLabels()
            : NeriaTools::getTemplateLabels();
        $out    = [];

        foreach (self::WAVE1_TEMPLATES as $key) {
            $out[$key] = $labels[$key] ?? $key;
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Indique si un template est autorisé à l'envoi manuel.
     */
    public function isSendable(string $template): bool
    {
        return in_array($template, self::WAVE1_TEMPLATES, true);
    }

    // ============================================================
    // DÉCOUVERTE DES VARIABLES ÉDITABLES
    // ============================================================

    /**
     * Découvre les variables de CONTENU à remplir par le marchand pour un
     * template : tous les placeholders {xxx} du template (.html + .txt),
     * moins ceux injectés automatiquement (contexte, design, variables
     * marchand). Les variantes {xxx_html}/{xxx_txt} sont ramenées à {xxx}.
     *
     * @param string $template
     * @return string[] Liste de clés (ex: ['product_name', 'apology_reason'])
     */
    public function getEditableVars(string $template): array
    {
        // Champs injectés par le cron, invisibles dans le source HTML/TXT
        // mais nécessaires à l'envoi manuel pour un rendu correct.
        $cronInjected = [
            'relationship_anniversary' => ['years_label'],
            'first_anniversary'        => [],
        ];

        $placeholders = $this->extractPlaceholders($template);
        $auto         = array_flip(self::AUTO_VARS);
        $customKeys   = array_flip($this->getCustomVarKeys());
        $editable     = [];

        foreach ($placeholders as $key) {
            $base = preg_replace('/_(html|txt)$/', '', $key);
            if (isset($auto[$base]) || isset($customKeys[$base])) {
                continue;
            }
            $editable[$base] = true;
        }

        // Ajouter les champs cron-injectés spécifiques à ce template
        foreach ($cronInjected[$template] ?? [] as $extra) {
            $editable[$extra] = true;
        }

        return array_keys($editable);
    }

    /**
     * Extrait tous les placeholders {xxx} des sources .html et .txt d'un
     * template. Ignore {neria_trad ...} (espace) et {$smarty} (dollar).
     *
     * @param string $template
     * @return string[]
     */
    private function extractPlaceholders(string $template): array
    {
        $found = [];

        foreach (['html', 'txt'] as $ext) {
            $path = $this->module->getModulePath(
                'mails/themes/neria_global/core/' . $template . '.' . $ext
            );
            if (!is_file($path)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            if (preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $content, $m)) {
                foreach ($m[1] as $key) {
                    $found[$key] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Clés des variables personnalisées du marchand (injectées auto).
     *
     * @return string[]
     */
    private function getCustomVarKeys(): array
    {
        $table  = _DB_PREFIX_ . 'neria_custom_variable';
        $idShop = (int) \Context::getContext()->shop->id;

        $rows = $this->db->executeS(
            "SELECT `variable_key` FROM `{$table}` WHERE `id_shop` = {$idShop}"
        );

        return is_array($rows) ? array_column($rows, 'variable_key') : [];
    }

    /**
     * Map template => variables éditables, pour toute la vague 1.
     * Utilisé par le back-office pour afficher les bons champs.
     *
     * @return array<string,string[]>
     */
    public function getEditableVarsMap(): array
    {
        $map = [];
        foreach (self::WAVE1_TEMPLATES as $key) {
            $map[$key] = $this->getEditableVars($key);
        }
        return $map;
    }

    /**
     * Map template => champs éditables avec libellé traduit dans la langue du
     * back-office (réutilise les libellés déjà traduits dans les templates).
     * [template => [ ['key'=>'product_name', 'label'=>'Pièce'], ... ]]
     *
     * @param string $adminIso Code langue du back-office (employé)
     * @return array<string,array<int,array{key:string,label:string}>>
     */
    public function getEditableFieldsMap(string $adminIso): array
    {
        $engine = new TranslationEngine($this->module);
        $map    = [];

        foreach (self::WAVE1_TEMPLATES as $tpl) {
            $fields = [];
            foreach ($this->getEditableVars($tpl) as $var) {
                // 1. Libellé fourni directement (champs sans clé de trad dédiée)
                $label = $this->directLabel($var, $adminIso);

                // 2. Sinon, libellé réutilisé depuis le template (traduit dans
                //    la langue du BO) ; on retire un éventuel « : » final.
                if ($label === '') {
                    $labelKey = $this->findVarLabelKey($tpl, $var);
                    if ($labelKey !== '') {
                        $label = trim(strip_tags($engine->get($tpl, $labelKey, $adminIso)));
                        $label = rtrim($label, " :—-");
                    }
                }

                // 3. Repli : nom de variable humanisé
                if ($label === '') {
                    $label = ucfirst(str_replace('_', ' ', $var));
                }

                $fields[] = ['key' => $var, 'label' => $label];
            }
            $map[$tpl] = $fields;
        }

        return $map;
    }

    /**
     * Libellé direct d'une variable depuis FIELD_LABEL_I18N, dans la langue du
     * back-office (replis : code 2 lettres, puis anglais). '' si absente.
     *
     * @param string $var
     * @param string $adminIso
     * @return string
     */
    private function directLabel(string $var, string $adminIso): string
    {
        if (!isset(self::FIELD_LABEL_I18N[$var])) {
            return '';
        }
        $set = self::FIELD_LABEL_I18N[$var];
        $iso = strtolower($adminIso);

        return $set[$iso] ?? $set[substr($iso, 0, 2)] ?? $set['en'] ?? '';
    }

    /**
     * Retrouve la clé de traduction servant de libellé à une variable dans un
     * template : soit un {neria_trad} inline avant la variable sur la même
     * ligne, soit un titre {neria_trad} seul sur la ligne juste au-dessus
     * (en ignorant les lignes vides et les séparateurs ----).
     *
     * @param string $template
     * @param string $var
     * @return string Clé de traduction, ou '' si aucune trouvée
     */
    private function findVarLabelKey(string $template, string $var): string
    {
        $path = $this->module->getModulePath(
            'mails/themes/neria_global/core/' . $template . '.txt'
        );
        if (!is_file($path)) {
            return '';
        }

        // Override explicite (variables sans libellé dédié dans le template)
        if (isset(self::LABEL_KEY_OVERRIDE[$template][$var])) {
            return self::LABEL_KEY_OVERRIDE[$template][$var];
        }

        $lines  = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path));
        $needle = '{' . $var . '}';

        foreach ($lines as $i => $line) {
            $pos = strpos($line, $needle);
            if ($pos === false) {
                continue;
            }

            // 1. Libellé inline (même ligne, avant la variable)
            $before = substr($line, 0, $pos);
            if (preg_match_all('/\{neria_trad\s+key=[\'"]([a-z0-9_]+)[\'"]\s*\}/', $before, $mm)
                && !empty($mm[1])
            ) {
                return end($mm[1]);
            }

            // 2. Titre sur une ligne au-dessus (saute blancs et séparateurs)
            for ($j = $i - 1; $j >= 0; $j--) {
                $prev = trim($lines[$j]);
                if ($prev === '' || preg_match('/^-{3,}$/', $prev)) {
                    continue;
                }
                if (preg_match('/^\{neria_trad\s+key=[\'"]([a-z0-9_]+)[\'"]\s*\}$/', $prev, $tm)) {
                    return $tm[1];
                }
                break; // ligne non-titre → pas de libellé dédié
            }

            return '';
        }

        return '';
    }

    // ============================================================
    // ENVOI
    // ============================================================

    /**
     * Envoie manuellement un template à un client. Passe par Mail::Send →
     * hook Neria (design + traduction + détection de langue).
     *
     * @param string $template    Clé du template (doit être dans la vague 1)
     * @param string $email       Email du destinataire
     * @param string $orderRef    Référence de commande (optionnel) — contexte + détection langue
     * @param string $subject     Sujet de l'email
     * @param array  $contentVars Champs de contenu remplis par le marchand [key => value]
     * @return array{ok:bool, message:string}
     */
    public function send(
        string $template,
        string $email,
        string $orderRef,
        string $subject,
        array $contentVars
    ): array {
        if (!$this->isSendable($template)) {
            return ['ok' => false, 'message' => AdminTranslator::t('msg.send_not_allowed')];
        }

        // Point de vérification dispersé #5 : même verrou que les autres
        // envois, avec un message explicite ici (contrairement au blocage
        // silencieux des crons) puisque c'est un marchand qui vient de
        // cliquer "Envoyer" et doit comprendre pourquoi rien ne part.
        if (class_exists('LicenseManager') && !(new \LicenseManager($this->module))->isEmailSendingAllowed()) {
            return ['ok' => false, 'message' => AdminTranslator::t('msg.send_blocked_license')];
        }

        $email = trim($email);
        if ($email === '' || !\Validate::isEmail($email)) {
            return ['ok' => false, 'message' => AdminTranslator::t('msg.send_invalid_email')];
        }

        // ── Garde-fou bounce ────────────────────────────────────────────
        // Mail::Send() du cœur PrestaShop retourne TOUJOURS true quand un
        // hook actionEmailSendBefore annule l'envoi (voir classes/Mail.php,
        // "if (!$keepGoing) { return true; }") — sans ce contrôle en amont,
        // le marchand voit "email envoyé" alors que rien n'est réellement
        // parti (bloqué silencieusement par BounceManager dans le hook).
        if (class_exists('BounceManager') && \BounceManager::isBounced($email)) {
            return ['ok' => false, 'message' => AdminTranslator::tVars('msg.send_blocked_bounce', ['email' => $email])];
        }

        // ── Garde-fou first_anniversary / relationship_anniversary ────────
        if ($template === 'first_anniversary'
            && \Configuration::getGlobalValue('NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED')
        ) {
            $guard = $this->checkAnniversaryConflict($email, 'relationship_anniversary');
            if ($guard !== null) {
                return ['ok' => false, 'message' => $guard];
            }
        }
        if ($template === 'relationship_anniversary') {
            $guard = $this->checkAnniversaryConflict($email, 'first_anniversary');
            if ($guard !== null) {
                return ['ok' => false, 'message' => $guard];
            }
        }

        // Sujet vide : laissé tel quel. EmailRenderer le remplira avec le titre
        // du template traduit dans la langue détectée du client.
        $subject = trim($subject);

        $customer = $this->findCustomer($email);
        $idLang   = $customer ? (int) $customer['id_lang'] : (int) \Configuration::get('PS_LANG_DEFAULT');
        // id_shop DU CLIENT réel, pas Context::getContext()->shop (contexte
        // BO de l'employé qui déclenche l'envoi) — même correctif que
        // scheduleManual() (\$idShopManual) et CertificateManager (round 74) :
        // un opérateur en contexte "Boutique A" envoyant manuellement à un
        // client de la "Boutique B" utilisait sinon la config
        // SMTP/expéditeur/préférences ET les liens ({shop_url},
        // {history_url}) de la MAUVAISE boutique.
        $idShop   = (int) ($customer['id_shop'] ?? \Context::getContext()->shop->id);

        // ── Garde-fou blacklist ───────────────────────────────────────────
        // Un template blacklisté ne peut plus être rendu par Neria ; comme les
        // templates Wave1 (dont celui-ci) n'ont pas d'équivalent natif PrestaShop
        // vers lequel se replier, Mail::Send() échouera de toute façon (fichier
        // introuvable) — autant le dire clairement au marchand plutôt que de
        // laisser passer un message générique "vérifiez la config SMTP".
        if (class_exists('BlacklistManager')) {
            // Utilise langFromId() (code Neria normalisé), pas Language::getIsoById()
            // brut : sinon les packs PS dont l'iso_code diffère du code Neria
            // (us→en, pt-br→br, zh-tw/zh-hk→tw, zh-cn/cn→zh, nb/nn→no) ne
            // matchent jamais une règle de blacklist enregistrée sous le code
            // Neria normalisé, et le garde-fou blacklist est silencieusement
            // inopérant pour ces langues.
            $langIso = class_exists('TranslationEngine')
                ? (new \TranslationEngine($this->module))->langFromId($idLang)
                : (string) (\Language::getIsoById($idLang) ?: '');
            if ((new \BlacklistManager())->isBlacklisted($template, $langIso)) {
                return ['ok' => false, 'message' => AdminTranslator::tVars('msg.send_blocked_blacklist', ['template' => $template])];
            }
        }

        // ── Garde-fou préférences ────────────────────────────────────────
        // Le hook central actionEmailSendBefore (neria.php) applique déjà
        // isAllowed() à ce Mail::Send() — le client est donc déjà protégé
        // sans ce garde-fou. Mais Mail::Send() retourne TOUJOURS true quand
        // un hook annule l'envoi (comportement documenté du cœur PrestaShop,
        // cf. garde-fou bounce ci-dessus) : sans cette vérification explicite
        // ICI, le marchand voyait "Email envoyé" alors que rien n'était
        // réellement parti — bloqué silencieusement par le hook.
        if (class_exists('PreferencesManager')
            && !(new \PreferencesManager($this->module))->isAllowed($customer ? (int) $customer['id_customer'] : 0, $template, $idShop, $email)
        ) {
            return ['ok' => false, 'message' => AdminTranslator::tVars('msg.send_blocked_preferences', ['email' => $email])];
        }

        // ── Garde-fou variables personnalisées manquantes ──────────────────
        // Bloque l'envoi si ce template utilise une variable personnalisée
        // (Configurer → Variables personnalisées) restée vide — sinon
        // l'email part avec un texte tronqué/vide (ex. "Sous  jours à
        // compter de..."). Un champ fourni directement dans $contentVars
        // pour CET envoi (même clé, normalisée comme ci-dessous) prime sur
        // la variable persistée.
        if (class_exists('ConfigManager')) {
            $overrideKeys = [];
            foreach (array_keys($contentVars) as $k) {
                $normalized = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
                if ($normalized !== '') {
                    $overrideKeys[] = $normalized;
                }
            }
            $missingVars = (new \ConfigManager($this->module))->findMissingCustomVarsForTemplate($template, $overrideKeys);
            if (!empty($missingVars)) {
                return [
                    'ok'      => false,
                    'message' => AdminTranslator::tVars('msg.send_blocked_missing_vars', ['list' => implode(', ', $missingVars)]),
                ];
            }
        }

        // ── Garde-fou contexte commande ─────────────────────────────────────
        // {order_name} et {order_url} sont listés dans AUTO_VARS (donc exclus
        // du formulaire "champs à remplir" par getEditableVars()) mais ne sont
        // en réalité injectés QUE si $orderRef pointe vers une commande
        // valide (ci-dessous). Sans ce garde-fou, un template qui les utilise
        // (alteration_update, gift_guarantee...) partait avec le placeholder
        // brut non résolu dès que le marchand envoyait sans lier de commande
        // — bug réel observé en production (watchdog.residual_vars_stripped
        // sur alteration_update/{order_name} et gift_guarantee/{order_url},
        // 30/07/2026), le marchand n'ayant lui-même aucun champ de secours
        // pour ces clés puisqu'elles sont considérées "automatiques".
        $order = ($orderRef !== '') ? $this->findOrder($orderRef) : null;
        if (!$order) {
            $placeholders   = $this->extractPlaceholders($template);
            $needsOrderVars = array_intersect(['order_name', 'order_url'], $placeholders);
            if (!empty($needsOrderVars)) {
                return [
                    'ok'      => false,
                    'message' => AdminTranslator::tVars('msg.send_blocked_missing_order', ['list' => implode(', ', $needsOrderVars)]),
                ];
            }
        }

        // Contexte de base
        $vars = [
            '{firstname}'   => $customer['firstname'] ?? '',
            '{lastname}'    => $customer['lastname'] ?? '',
            '{email}'       => $email,
            '{shop_name}'   => (string) \Configuration::get('PS_SHOP_NAME'),
            '{shop_url}'    => $this->resolveShopUrl($idShop),
            '{history_url}' => \Context::getContext()->link->getPageLink('history', true, $idLang, null, false, $idShop),
        ];

        // Commande optionnelle (contexte + détection langue via {id_order})
        if ($order) {
            $vars['{order_name}'] = $order['reference'];
            $vars['{id_order}']   = (int) $order['id_order'];
            $vars['{order_url}']  = \Context::getContext()->link->getPageLink(
                'order-detail', true, $idLang, ['id_order' => (int) $order['id_order']]
            );
        }

        // Champs de contenu remplis par le marchand (clés nettoyées)
        foreach ($contentVars as $key => $value) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
            if ($key === '') {
                continue;
            }
            // Le message personnalisé est transformé en bloc HTML/TXT par
            // EmailRenderer (via {custom_message_raw}).
            if ($key === 'custom_message') {
                $vars['{custom_message_raw}'] = (string) $value;
                continue;
            }
            $vars['{' . $key . '}'] = (string) $value;
        }

        $toName = trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? ''));

        // Horodatage juste avant l'appel — sert de repère pour retrouver la
        // vraie cause d'un échec dans le log natif PrestaShop (ps_log), cf.
        // ci-dessous : Mail::Send() y écrit la raison précise (adresse
        // invalide, sujet invalide, erreur SMTP réelle...) via dieOrLog(),
        // mais ne la retourne jamais à l'appelant — juste `false`.
        $sendAttemptedAt = date('Y-m-d H:i:s');

        $sent = \Mail::Send(
            $idLang,
            $template,
            $subject,
            $vars,
            $email,
            $toName !== '' ? $toName : null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'neria/mails/',
            false,
            $idShop
        );

        if ($sent) {
            $this->watchdog()->info(
                WatchdogManager::i18nMsg('watchdog.manual_send_ok', ['template' => $template, 'email' => $email]),
                $template,
                'ManualSendManager'
            );

            // Enregistrer les envois manuels d'anniversaire dans behavioral_sent
            // pour que le garde-fou bidirectionnel fonctionne (cron + manuel), ET
            // pour que le cron ne renvoie pas le même email en double plus tard.
            // ref_id doit utiliser EXACTEMENT la même clé que BehavioralCronManager,
            // sinon la contrainte UNIQUE(customer, template, ref_id) ne matche pas
            // et le cron considère l'anniversaire comme jamais envoyé :
            //  - relationship_anniversary → année en cours (peut se redéclencher chaque année)
            //  - first_anniversary        → id_order de la 1ère commande (une seule fois)
            if (in_array($template, ['first_anniversary', 'relationship_anniversary'], true)
                && $customer
            ) {
                if ($template === 'first_anniversary') {
                    $refId = (int) $this->db->getValue(
                        'SELECT MIN(id_order) FROM `' . _DB_PREFIX_ . 'orders`
                         WHERE id_customer = ' . (int) $customer['id_customer'] . '
                           AND valid = 1'
                    );
                } else {
                    $refId = (int) date('Y');
                }

                if ($refId > 0) {
                    $this->db->execute(
                        'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'neria_behavioral_sent`
                         (id_customer, template, ref_id, id_shop, sent_at)
                         VALUES (' . (int) $customer['id_customer'] . ', \'' . pSQL($template) . '\', '
                        . $refId . ', ' . $idShop . ', NOW())'
                    );
                }
            }

            return [
                'ok'      => true,
                'message' => sprintf(AdminTranslator::t('msg.send_success'), $template, $email),
            ];
        }

        // Retrouve la vraie cause dans le log natif PrestaShop (ps_log) plutôt
        // que d'afficher un message générique "vérifiez la config SMTP" qui
        // masque le vrai problème (adresse invalide, sujet invalide, échec
        // SwiftMailer réel, etc.) — bug trouvé le 2026-07-13 via un rapport
        // de test externe.
        // Filtre sur object_type='SwiftMessage' (PS8, SwiftMailer) OU
        // 'MailerMessage' (PS9, Symfony Mailer — cf. classes/Mail.php core PS9,
        // PrestaShopLogger::addLog('Mailer Error: ...', 3, null, 'MailerMessage')) —
        // même piège de renommage de classe que le bug List-Unsubscribe
        // (2026-07-18) : sans le second type, la vraie cause existe bien en
        // base sur PS9 mais n'était jamais retrouvée, retombant sur le
        // message générique. Filtre aussi sur le message "template manquant" —
        // sans ces filtres, une entrée ps_log écrite par un tout autre module
        // à la même seconde s'affichait comme "la vraie cause", potentiellement
        // sans aucun rapport avec cet échec d'envoi. Un log absent/hors-sujet
        // reste préférable à un log trompeur présenté comme fiable.
        $realReason = (string) $this->db->getValue(
            'SELECT `message` FROM `' . _DB_PREFIX_ . 'log`
             WHERE `date_add` >= \'' . pSQL($sendAttemptedAt) . '\'
             AND (`object_type` IN (\'SwiftMessage\', \'MailerMessage\') OR `message` LIKE \'Error - The following e-mail template%\')
             ORDER BY `id_log` DESC'
        );

        $this->watchdog()->error(
            WatchdogManager::i18nMsg('watchdog.manual_send_failed', [
                'template' => $template,
                'email'    => $email,
                'reason'   => $realReason !== '' ? $realReason : AdminTranslator::t('msg.send_failed_reason_unknown'),
            ]),
            $template,
            'ManualSendManager'
        );
        return [
            'ok'      => false,
            'message' => $realReason !== ''
                ? AdminTranslator::tVars('msg.send_failed_reason', ['reason' => $realReason])
                : AdminTranslator::t('msg.send_failed'),
        ];
    }

    /**
     * Tools::getShopDomainSsl() est lié au CONTEXTE courant, pas à un
     * id_shop passé en paramètre — bascule temporaire vers la vraie
     * boutique du client, le temps de résoudre le domaine, même pattern
     * que CertificateManager::sendCertificateEmail() (round 74). Sans ça,
     * {shop_url} pointait vers le domaine du contexte BO de l'employé au
     * lieu de celui de la boutique réelle du client.
     */
    private function resolveShopUrl(int $idShop): string
    {
        $originalShop = \Context::getContext()->shop;
        if ((int) $originalShop->id !== $idShop) {
            \Context::getContext()->shop = new \Shop($idShop);
        }
        $shopUrl = \Tools::getShopDomainSsl(true, true);
        \Context::getContext()->shop = $originalShop;

        return $shopUrl;
    }

    /**
     * Trouve un client par email (non supprimé).
     *
     * @param string $email
     * @return array|null [id_customer, id_lang, firstname, lastname, id_shop]
     */
    private function findCustomer(string $email): ?array
    {
        $shopRestriction = \Shop::addSqlRestriction(\Shop::SHARE_CUSTOMER);
        $row = $this->db->getRow(
            'SELECT `id_customer`, `id_lang`, `firstname`, `lastname`, `id_shop`
             FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `email` = \'' . pSQL($email) . '\'
               AND `deleted` = 0
               ' . $shopRestriction . '
             ORDER BY `id_customer` DESC'
        );

        return (is_array($row) && !empty($row['id_customer'])) ? $row : null;
    }

    /**
     * Trouve une commande par sa référence.
     *
     * @param string $ref
     * @return array|null [id_order, reference]
     */
    private function findOrder(string $ref): ?array
    {
        $row = $this->db->getRow(
            'SELECT `id_order`, `reference`
             FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `reference` = \'' . pSQL($ref) . '\'
               AND `id_shop` = ' . (int) \Context::getContext()->shop->id . '
             ORDER BY `id_order` DESC'
        );

        return (is_array($row) && !empty($row['id_order'])) ? $row : null;
    }

    /**
     * Bloque l'envoi si le template concurrent ($conflictTemplate) a déjà été
     * envoyé à ce client cette année (auto ou manuel via behavioral_sent).
     * Fonctionne dans les deux sens : first_anniversary ↔ relationship_anniversary.
     */
    private function checkAnniversaryConflict(string $email, string $conflictTemplate): ?string
    {
        $customer = $this->findCustomer($email);
        if (!$customer) {
            return null;
        }

        // relationship_anniversary stocke l'année en cours comme ref_id (peut
        // se déclencher chaque année) — first_anniversary stocke l'id_order
        // de la 1ère commande (ne se déclenche qu'une seule fois dans la vie
        // du client) : filtrer par année pour ce dernier ne matche jamais un
        // id_order réel et rendait ce sens du garde-fou totalement inopérant.
        $refFilter = ($conflictTemplate === 'relationship_anniversary')
            ? ' AND ref_id = ' . (int) date('Y')
            : '';
        $alreadySent = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_behavioral_sent`
             WHERE id_customer = ' . (int) $customer['id_customer'] . '
               AND template = \'' . pSQL($conflictTemplate) . '\'' . $refFilter
        );

        if ($alreadySent > 0) {
            $labels = [
                'first_anniversary'        => AdminTranslator::t('msg.anniversary_label_first_full'),
                'relationship_anniversary' => AdminTranslator::t('msg.anniversary_label_relationship_full'),
            ];
            $conflictLabel = $labels[$conflictTemplate] ?? $conflictTemplate;
            return AdminTranslator::tVars('msg.anniversary_conflict_blocked', ['label' => $conflictLabel]);
        }

        return null;
    }

    /**
     * Vérification AJAX proactive du centre de préférences, pour afficher un
     * bandeau d'avertissement AVANT que le marchand ne clique "Envoyer" — le
     * garde-fou bloquant réel vit dans send()/scheduleManual() (et, en
     * amont, le hook central actionEmailSendBefore), cette méthode ne fait
     * que refléter ce que ce garde-fou décidera pour informer l'opérateur
     * BO, qui ne verrait sinon la vraie cause qu'après coup dans le journal
     * Watchdog (Mail::Send() retourne toujours true même quand le hook
     * annule l'envoi).
     *
     * Retourne ['blocked' => bool, 'message' => string]
     */
    public function getPreferencesGuardStatus(string $email, string $template): array
    {
        if (!class_exists('PreferencesManager') || !isset(\PreferencesManager::TEMPLATE_CAT[$template])) {
            return ['blocked' => false, 'message' => ''];
        }

        $customer  = $this->findCustomer($email);
        $idShop    = (int) \Context::getContext()->shop->id;
        $idCustomer = $customer ? (int) $customer['id_customer'] : 0;

        $allowed = (new \PreferencesManager($this->module))->isAllowed($idCustomer, $template, $idShop, $email);
        if ($allowed) {
            return ['blocked' => false, 'message' => ''];
        }

        return [
            'blocked' => true,
            'message' => AdminTranslator::tVars('msg.send_blocked_preferences', ['email' => $email]),
        ];
    }

    /**
     * Bandeau INFORMATIF (jamais bloquant, contrairement à
     * getPreferencesGuardStatus()) : signale à l'opérateur BO que le Mode
     * Silence (CooldownManager) ne s'applique pas aux destinataires sans
     * compte client — neria_stat n'a pas de colonne email, seulement
     * id_customer (0 = invité), donc CooldownManager::isDuplicate() ne peut
     * structurellement pas retrouver l'historique d'envoi de ce destinataire
     * pour appliquer la fenêtre anti-doublon. Ne modifie ni ne contourne
     * CooldownManager — n'informe que sur une limitation déjà existante,
     * pour que le marchand en ait conscience avant d'envoyer manuellement
     * (risque de recevoir le même template plusieurs fois en peu de temps
     * si plusieurs envois manuels/automatiques se chevauchent).
     */
    public function getCooldownGuestNoticeStatus(string $email): array
    {
        if (!class_exists('ConfigManager') || !(new \ConfigManager($this->module))->isCooldownEnabled()) {
            return ['notice' => false, 'message' => ''];
        }
        if ($this->findCustomer($email) !== null) {
            return ['notice' => false, 'message' => ''];
        }
        return [
            'notice'  => true,
            'message' => AdminTranslator::tVars('msg.cooldown_guest_notice', ['email' => $email]),
        ];
    }

    /**
     * Vérification AJAX du garde-fou pour le front BO (bidirectionnel).
     * $template = template que le marchand veut envoyer (first_anniversary ou relationship_anniversary)
     * Retourne ['blocked' => bool, 'sent' => bool, 'message' => string]
     */
    public function getAnniversaryGuardStatus(string $email, string $template = 'first_anniversary'): array
    {
        $conflictTemplate = ($template === 'first_anniversary') ? 'relationship_anniversary' : 'first_anniversary';
        $featureActive    = (bool) \Configuration::getGlobalValue('NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED');

        // Pour first_anniversary : ne vérifier que si la feature est active
        if ($template === 'first_anniversary' && !$featureActive) {
            return ['blocked' => false, 'sent' => false, 'message' => ''];
        }

        $customer = $this->findCustomer($email);
        if (!$customer) {
            if ($template === 'first_anniversary' && $featureActive) {
                return [
                    'blocked' => false,
                    'sent'    => false,
                    'message' => AdminTranslator::t('msg.anniversary_feature_active_no_customer'),
                ];
            }
            return ['blocked' => false, 'sent' => false, 'message' => ''];
        }

        // Voir commentaire dans checkAnniversaryConflict() : ref_id=année ne
        // s'applique qu'à relationship_anniversary.
        $refFilter = ($conflictTemplate === 'relationship_anniversary')
            ? ' AND ref_id = ' . (int) date('Y')
            : '';
        $conflictSent = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_behavioral_sent`
             WHERE id_customer = ' . (int) $customer['id_customer'] . '
               AND template = \'' . pSQL($conflictTemplate) . '\'' . $refFilter
        );

        $labels = [
            'first_anniversary'        => AdminTranslator::t('msg.anniversary_label_first_short'),
            'relationship_anniversary' => AdminTranslator::t('msg.anniversary_label_relationship_short'),
        ];

        if ($conflictSent > 0) {
            return [
                'blocked' => true,
                'sent'    => true,
                'message' => AdminTranslator::tVars('msg.anniversary_blocked_html', ['label' => $labels[$conflictTemplate]]),
            ];
        }

        if ($template === 'first_anniversary' && $featureActive) {
            return [
                'blocked' => false,
                'sent'    => false,
                'message' => AdminTranslator::t('msg.anniversary_feature_active_pending'),
            ];
        }

        return ['blocked' => false, 'sent' => false, 'message' => ''];
    }

    /**
     * Recherche des clients par email, prénom ou nom (autocomplétion BO).
     * Retourne max 8 résultats avec la dernière commande.
     */
    public function searchCustomers(string $q): array
    {
        $q = trim($q);
        if (strlen($q) < 2) {
            return [];
        }
        // Échappe les métacaractères LIKE (%, _) avant pSQL() — sans ça, un
        // "_" dans la recherche (fréquent, ex. jean_dupont) matche n'importe
        // quel caractère et pollue la liste de destinataires avec des
        // clients non pertinents. Même correctif que checkDuplicate() plus bas.
        $safe = pSQL(addcslashes($q, '%_'));
        // Respecte le mode de partage client PrestaShop (boutique isolée ou
        // partagée au sein d'un groupe) — évite qu'un employé restreint à
        // une boutique retrouve des clients d'une autre boutique isolée,
        // sans casser la recherche si les clients sont partagés.
        $shopRestriction = \Shop::addSqlRestriction(\Shop::SHARE_CUSTOMER, 'c');
        $rows = $this->db->executeS(
            'SELECT c.`id_customer`, c.`email`, c.`firstname`, c.`lastname`,
                    o.`reference` AS last_order_ref,
                    DATE_FORMAT(o.`date_add`, \'%d/%m/%Y\') AS last_order_date
             FROM `' . _DB_PREFIX_ . 'customer` c
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o
                   ON o.`id_customer` = c.`id_customer`
                  AND o.`id_order` = (
                      SELECT MAX(`id_order`) FROM `' . _DB_PREFIX_ . 'orders`
                      WHERE `id_customer` = c.`id_customer`
                  )
             WHERE c.`deleted` = 0
               ' . $shopRestriction . '
               AND (
                   c.`email` LIKE \'%' . $safe . '%\'
                OR c.`firstname` LIKE \'%' . $safe . '%\'
                OR c.`lastname` LIKE \'%' . $safe . '%\'
               )
             ORDER BY c.`id_customer` DESC
             LIMIT 8'
        );
        return is_array($rows) ? $rows : [];
    }

    /**
     * Vérifie si ce template a déjà été envoyé manuellement à ce client (7 derniers jours).
     */
    public function checkDuplicate(string $email, string $template): array
    {
        if ($email === '' || $template === '') {
            return ['blocked' => false, 'message' => ''];
        }
        // Échappe les métacaractères LIKE (%, _) présents dans l'email avant
        // de l'utiliser comme motif — sans ça, un email contenant un "_"
        // (fréquent, ex. jean_dupont@x.com) matche n'importe quel caractère
        // à cette position dans le log, faussant le comptage de doublons.
        // SUM(occurrence_count) et non COUNT(*) : WatchdogManager::record()
        // consolide toute entrée identique (même message) survenue dans la
        // dernière heure en incrémentant occurrence_count sur la ligne
        // existante au lieu d'insérer une nouvelle ligne. Deux envois
        // manuels du même template au même client dans la même heure
        // produisent le même message ('watchdog.manual_send_ok', mêmes
        // vars) et sont donc consolidés en UNE seule ligne — un COUNT(*)
        // renvoyait alors 1 malgré 2 envois réels, ne détectant jamais le
        // doublon.
        $likeSafeEmail = addcslashes($email, '%_');
        $count = (int) $this->db->getValue(
            'SELECT COALESCE(SUM(`occurrence_count`), 0) FROM `' . _DB_PREFIX_ . 'neria_log`
             WHERE `class` = \'ManualSendManager\'
               AND `template` = \'' . pSQL($template) . '\'
               AND `message` LIKE \'%' . pSQL($likeSafeEmail) . '%\'
               AND `date_add` >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
        if ($count > 0) {
            return [
                'blocked' => false,
                'message' => AdminTranslator::tVars('msg.duplicate_warning', ['count' => $count]),
            ];
        }
        return ['blocked' => false, 'message' => ''];
    }

    /**
     * Wrapper public de findCustomer() pour les endpoints AJAX (preview, etc.).
     */
    public function findCustomerPublic(string $email): ?array
    {
        return $this->findCustomer($email);
    }

    /**
     * Planifie un envoi manuel à une date/heure précise via la Queue.
     */
    public function scheduleManual(
        string $template,
        string $email,
        string $orderRef,
        string $subject,
        array  $contentVars,
        string $sendAt
    ): array {
        if (!$this->isSendable($template)) {
            return ['ok' => false, 'message' => AdminTranslator::t('msg.send_not_allowed')];
        }
        $email = trim($email);
        if ($email === '' || !\Validate::isEmail($email)) {
            return ['ok' => false, 'message' => AdminTranslator::t('msg.send_invalid_email')];
        }
        if (class_exists('BounceManager') && \BounceManager::isBounced($email)) {
            return ['ok' => false, 'message' => AdminTranslator::tVars('msg.send_blocked_bounce', ['email' => $email])];
        }
        if (!class_exists('QueueManager')) {
            return ['ok' => false, 'message' => 'QueueManager non disponible.'];
        }

        // Mêmes garde-fous que send() (blacklist, conflit anniversaire) —
        // sans eux, scheduleManual() était une porte de contournement
        // complète des protections du module : un template blacklisté ou en
        // conflit avec l'autre palier d'anniversaire pouvait quand même être
        // planifié puis parti via la Queue. La licence n'a PAS besoin d'être
        // revérifiée ici : QueueManager::processQueue() la vérifie déjà au
        // moment de l'envoi réel (les lignes restent en attente, jamais
        // perdues, tant que la licence est bloquée).
        if ($template === 'first_anniversary'
            && \Configuration::getGlobalValue('NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED')
        ) {
            $guard = $this->checkAnniversaryConflict($email, 'relationship_anniversary');
            if ($guard !== null) {
                return ['ok' => false, 'message' => $guard];
            }
        }
        if ($template === 'relationship_anniversary') {
            $guard = $this->checkAnniversaryConflict($email, 'first_anniversary');
            if ($guard !== null) {
                return ['ok' => false, 'message' => $guard];
            }
        }

        // ── Garde-fou contexte commande ───────────────────────────────────
        // Même garde-fou que send() (voir son commentaire détaillé) —
        // {order_name}/{order_url} ne sont résolus QUE si $orderRef pointe
        // vers une commande valide. scheduleManual() ne l'appliquait pas :
        // un envoi PLANIFIÉ (via la file d'attente) sans commande liée
        // partait quand même, des jours plus tard, avec le placeholder brut
        // non résolu pour alteration_update/gift_guarantee — sans que le
        // marchand n'ait la moindre chance de s'en apercevoir au moment de
        // la planification.
        $order = ($orderRef !== '') ? $this->findOrder($orderRef) : null;
        if (!$order) {
            $placeholders   = $this->extractPlaceholders($template);
            $needsOrderVars = array_intersect(['order_name', 'order_url'], $placeholders);
            if (!empty($needsOrderVars)) {
                return [
                    'ok'      => false,
                    'message' => AdminTranslator::tVars('msg.send_blocked_missing_order', ['list' => implode(', ', $needsOrderVars)]),
                ];
            }
        }

        $customer = $this->findCustomer($email) ?? [
            'id_customer' => 0,
            'id_lang'     => (int) \Configuration::get('PS_LANG_DEFAULT'),
            'firstname'   => '',
            'lastname'    => '',
            'email'       => $email,
            'id_shop'     => (int) \Context::getContext()->shop->id,
        ];
        $customer['email'] = $email;
        $idLang = (int) $customer['id_lang'];
        // findCustomer() (client réel) ne retourne pas de colonne id_shop —
        // contrairement au pseudo-client par défaut ci-dessus — donc
        // $customer['id_shop'] serait indéfini pour un vrai client.
        $idShopManual = (int) ($customer['id_shop'] ?? \Context::getContext()->shop->id);

        if (class_exists('BlacklistManager')) {
            $langIso = class_exists('TranslationEngine')
                ? (new \TranslationEngine($this->module))->langFromId($idLang)
                : (string) (\Language::getIsoById($idLang) ?: '');
            if ((new \BlacklistManager())->isBlacklisted($template, $langIso)) {
                return ['ok' => false, 'message' => AdminTranslator::tVars('msg.send_blocked_blacklist', ['template' => $template])];
            }
        }

        // ── Garde-fou préférences ────────────────────────────────────────
        // Le hook central actionEmailSendBefore (neria.php) applique déjà
        // isAllowed() à TOUT Mail::Send(), y compris l'envoi réel déclenché
        // plus tard par QueueManager::processQueue() pour ce planifié — le
        // client est donc déjà protégé sans ce garde-fou. Mais Mail::Send()
        // retourne TOUJOURS true quand un hook annule l'envoi (comportement
        // documenté du cœur PrestaShop, cf. garde-fou bounce ci-dessus) :
        // sans cette vérification explicite ICI, au moment de la
        // PLANIFICATION, le marchand n'a aucun moyen de savoir que son envoi
        // planifié ne partira jamais réellement le jour J.
        if (class_exists('PreferencesManager')
            && !(new \PreferencesManager($this->module))->isAllowed((int) ($customer['id_customer'] ?? 0), $template, $idShopManual, $email)
        ) {
            return ['ok' => false, 'message' => AdminTranslator::tVars('msg.send_blocked_preferences', ['email' => $email])];
        }

        if (class_exists('ConfigManager')) {
            $overrideKeys = [];
            foreach (array_keys($contentVars) as $k) {
                $normalized = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
                if ($normalized !== '') {
                    $overrideKeys[] = $normalized;
                }
            }
            $missingVars = (new \ConfigManager($this->module))->findMissingCustomVarsForTemplate($template, $overrideKeys);
            if (!empty($missingVars)) {
                return [
                    'ok'      => false,
                    'message' => AdminTranslator::tVars('msg.send_blocked_missing_vars', ['list' => implode(', ', $missingVars)]),
                ];
            }
        }

        $vars = [
            '{firstname}'   => $customer['firstname'] ?? '',
            '{lastname}'    => $customer['lastname'] ?? '',
            '{email}'       => $email,
            '{shop_name}'   => (string) \Configuration::get('PS_SHOP_NAME'),
            '{shop_url}'    => $this->resolveShopUrl($idShopManual),
            '{history_url}' => \Context::getContext()->link->getPageLink('history', true, $idLang, null, false, $idShopManual),
        ];

        // Commande optionnelle — même résolution que send() (voir plus haut).
        if ($order) {
            $vars['{order_name}'] = $order['reference'];
            $vars['{id_order}']   = (int) $order['id_order'];
            $vars['{order_url}']  = \Context::getContext()->link->getPageLink(
                'order-detail', true, $idLang, ['id_order' => (int) $order['id_order']]
            );
        }

        foreach ($contentVars as $key => $value) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
            if ($key !== '') {
                $vars['{' . $key . '}'] = (string) $value;
            }
        }

        $queued = (new \QueueManager($this->module))->enqueueAt($template, $customer, $vars, 0, $sendAt);
        if (!$queued) {
            // La contrainte UNIQUE (id_customer, template, ref_id=0, id_shop)
            // empêche un 2e envoi manuel planifié du même template au même
            // client tant que le premier n'a pas été traité (ou l'a déjà
            // été — la ligne reste en base) — sans cette vérification,
            // l'admin voyait "programmé avec succès" pour un envoi qui
            // n'avait en réalité jamais été inséré en file.
            return ['ok' => false, 'message' => AdminTranslator::t('msg.scheduled_duplicate')];
        }

        $this->watchdog()->info(
            WatchdogManager::i18nMsg('watchdog.manual_send_scheduled', [
                'template' => $template,
                'email'    => $email,
                'date'     => $sendAt,
            ]),
            $template,
            'ManualSendManager'
        );

        return [
            'ok'      => true,
            'message' => AdminTranslator::tVars('msg.scheduled_success', ['date' => NeriaTools::formatDate($sendAt, AdminTranslator::currentLang(), true)]),
        ];
    }
}
