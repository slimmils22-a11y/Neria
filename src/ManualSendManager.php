<?php
/**
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
        $labels = NeriaTools::getTemplateLabels();
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
        $placeholders = $this->extractPlaceholders($template);
        if (empty($placeholders)) {
            return [];
        }

        $auto       = array_flip(self::AUTO_VARS);
        $customKeys = array_flip($this->getCustomVarKeys());
        $editable   = [];

        foreach ($placeholders as $key) {
            // Ramène les variantes _html / _txt à la clé de base
            $base = preg_replace('/_(html|txt)$/', '', $key);

            if (isset($auto[$base]) || isset($customKeys[$base])) {
                continue;
            }
            $editable[$base] = true;
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
            return ['ok' => false, 'message' => $this->module->l('Template non autorisé à l\'envoi manuel.')];
        }

        $email = trim($email);
        if ($email === '' || !\Validate::isEmail($email)) {
            return ['ok' => false, 'message' => $this->module->l('Adresse email du destinataire invalide.')];
        }

        // Sujet vide : laissé tel quel. EmailRenderer le remplira avec le titre
        // du template traduit dans la langue détectée du client.
        $subject = trim($subject);

        $customer = $this->findCustomer($email);
        $idLang   = $customer ? (int) $customer['id_lang'] : (int) \Configuration::get('PS_LANG_DEFAULT');
        $idShop   = (int) \Context::getContext()->shop->id;

        // Contexte de base
        $vars = [
            '{firstname}'   => $customer['firstname'] ?? '',
            '{lastname}'    => $customer['lastname'] ?? '',
            '{email}'       => $email,
            '{shop_name}'   => (string) \Configuration::get('PS_SHOP_NAME'),
            '{shop_url}'    => \Tools::getShopDomainSsl(true, true),
            '{history_url}' => \Context::getContext()->link->getPageLink('history', true),
        ];

        // Commande optionnelle (contexte + détection langue via {id_order})
        if ($orderRef !== '') {
            $order = $this->findOrder($orderRef);
            if ($order) {
                $vars['{order_name}'] = $order['reference'];
                $vars['{id_order}']   = (int) $order['id_order'];
            }
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
                'Envoi manuel : ' . $template . ' → ' . $email,
                $template,
                'ManualSendManager'
            );
            return [
                'ok'      => true,
                'message' => sprintf($this->module->l('Email « %1$s » envoyé à %2$s.'), $template, $email),
            ];
        }

        $this->watchdog()->error(
            'Échec envoi manuel : ' . $template . ' → ' . $email,
            $template,
            'ManualSendManager'
        );
        return [
            'ok'      => false,
            'message' => $this->module->l('Échec de l\'envoi. Vérifiez la configuration email de PrestaShop.'),
        ];
    }

    /**
     * Trouve un client par email (non supprimé).
     *
     * @param string $email
     * @return array|null [id_customer, id_lang, firstname, lastname]
     */
    private function findCustomer(string $email): ?array
    {
        $row = $this->db->getRow(
            'SELECT `id_customer`, `id_lang`, `firstname`, `lastname`
             FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `email` = \'' . pSQL($email) . '\'
               AND `deleted` = 0
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
             ORDER BY `id_order` DESC'
        );

        return (is_array($row) && !empty($row['id_order'])) ? $row : null;
    }
}
