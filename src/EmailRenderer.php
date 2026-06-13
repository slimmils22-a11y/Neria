<?php
/**
 * NERIA â€” EmailRenderer
 *
 * Orchestrateur central du rendu des emails Neria.
 * Intercepte chaque email envoyÃ© par PrestaShop via le hook
 * actionEmailSendBefore et :
 *
 * 1. Identifie le template et la langue du destinataire
 * 2. Enregistre la fonction Smarty {neria_trad key='...'}
 * 3. Injecte les variables de design (couleurs, polices, RTL)
 * 4. SÃ©lectionne la variante A/B si un test est actif
 * 5. Injecte le pixel de tracking pour les statistiques
 * 6. Injecte les liens rÃ©seaux sociaux
 * 7. Injecte la signature manuscrite si configurÃ©e
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class EmailRenderer
{
    // ============================================================
    // CONSTANTES
    // ============================================================

    /**
     * Templates qui NE doivent PAS être traités par Neria
     */
    const EXCLUDED_TEMPLATES = [];

    // ============================================================
    // PROPRIÃ‰TÃ‰S
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var TranslationEngine Moteur de traduction */
    private TranslationEngine $engine;

    /** @var ConfigManager Gestionnaire de configuration */
    private ConfigManager $config;

    /** @var \Context Contexte PrestaShop courant */
    private \Context $context;

    /** @var WatchdogManager|null Instance paresseuse du watchdog */
    private ?WatchdogManager $watchdog = null;

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct(Neria $module)
    {
        $this->module  = $module;
        $this->engine  = new TranslationEngine($module);
        $this->config  = new ConfigManager($module);
        $this->context = \Context::getContext();
    }

    // ============================================================
    // POINT D'ENTRÃ‰E PRINCIPAL
    // ============================================================

    /**
     * Traite les paramÃ¨tres d'un email avant son envoi
     * AppelÃ© depuis neria.php â†’ hookActionEmailSendBefore()
     *
     * @param array $params ParamÃ¨tres passÃ©s par PrestaShop :
     *   $params['template']     : nom du template (ex: order_conf)
     *   $params['idLang']       : id langue PrestaShop
     *   $params['templateVars'] : variables Smarty du template
     *   $params['subject']      : sujet de l'email
     *   $params['to']           : adresse email destinataire
     *   $params['toName']       : nom du destinataire
     */
    private function watchdog(): WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    public function processEmailParams(array &$params): void
    {
        // â”€â”€ VÃ©rifie que le module est actif â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if (!$this->config->isActive()) {
            return;
        }

        // â”€â”€ RÃ©cupÃ¨re et valide le template â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $template = $this->resolveTemplate($params['template'] ?? '');

        if (!$template || $this->isExcluded($template)) {
            return;
        }

        // â”€â”€ RÃ©sout la langue â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $lang = $this->resolveEmailLang($params);

        // â”€â”€ Sujet â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Si aucun sujet n'est fourni (ex. envoi manuel), on utilise le titre
        // principal du template (clé greeting_main) traduit dans la langue
        // détectée — réutilise les traductions existantes (18 langues).
        if (trim((string) ($params['subject'] ?? '')) === '') {
            $headline = $this->engine->get($template, 'greeting_main', $lang);
            if ($headline !== '') {
                $params['subject'] = trim(strip_tags($headline));
            }
        }

        // â”€â”€ SÃ©lectionne la variante A/B si nÃ©cessaire â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $variant = $this->resolveABVariant($template, $params);

        // â”€â”€ Enregistre {neria_trad} dans Smarty â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->registerSmartyFunction($template, $lang, $variant);

        // â”€â”€ Injecte les variables de design dans Smarty â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->injectDesignVars($lang, $params['templateVars']);

        // â”€â”€ Injecte les liens rÃ©seaux sociaux â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Reformate le tableau produits PrestaShop
        $this->reformatProductsHtml($params['templateVars']);

        $this->injectSocialVars($params['templateVars']);

        // â”€â”€ Injecte la signature â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->injectSignatureVars($params['templateVars']);

        // Injecte les variables personnalisées du marchand ({return_address}, etc.)
        $this->injectCustomVars($params['templateVars']);

        // Message personnalisé optionnel (envoi manuel) — versions HTML et TXT
        $this->injectCustomMessage($params['templateVars']);

        // Lien du bon de retour (page Retours du compte client)
        if ($template === 'return_slip') {
            $this->injectReturnSlipUrl($params['templateVars']);
        }

        // â”€â”€ GÃ©nÃ¨re les variantes texte des variables HTML (pour le .txt)
        $this->injectTextVariants($params['templateVars']);

        // â”€â”€ Injecte le pixel de tracking â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($this->config->isStatsEnabled()) {
            $this->injectTrackingPixel($template, $lang, $params);
        }

        // Compiler template Neria et changer templatePath.
        // Le contenu est compilé dans la langue DÉTECTÉE ($lang), mais doit
        // être écrit dans le dossier ISO que Mail::send va réellement lire —
        // celui de $idLang (cf. Mail::send → getIsoById((int)$idLang)). Sinon
        // PrestaShop sert un autre fichier que la langue détectée et l'email
        // part dans la mauvaise langue.
        $outIso = \Language::getIsoById((int) ($params['idLang'] ?? 0)) ?: $lang;
        $compiledPath = $this->compileNeriaTemplate($template, $lang, $outIso);
        if ($compiledPath && isset($params['templatePath'])) {
            // PS détecte 'modules/neria/' dans le chemin et cherche dans ce dossier
            $params['templatePath'] = _PS_MODULE_DIR_ . 'neria/mails/';
        }

        // â”€â”€ Log â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->module->log(
            sprintf(
                'EmailRenderer: rendu [%s][%s]%s',
                $template,
                $lang,
                $variant ? ' variante ' . $variant : ''
            ),
            1
        );

        $this->watchdog()->info(
            sprintf('Email rendu avec succès%s', $variant ? ' — variante ' . $variant : ''),
            $template,
            'EmailRenderer'
        );
    }

    // ============================================================
    // SMARTY â€” Enregistrement de {neria_trad}
    // ============================================================

    /**
     * Enregistre la fonction Smarty {neria_trad key='...'} sur
     * l'instance Smarty du contexte PrestaShop.
     *
     * La closure capture $template, $lang et $variant pour que
     * chaque appel {neria_trad} sache quel bloc charger.
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @param string $variant  Variante A/B ('A', 'B' ou '')
     */
    private function registerSmartyFunction(
        string $template,
        string $lang,
        string $variant
    ): void {
        $engine  = $this->engine;
        $module  = $this->module;
        $smarty  = $this->context->smarty;

        // Ã‰vite d'enregistrer deux fois (cas de plusieurs emails
        // envoyÃ©s dans la mÃªme requÃªte)
        try {
            $smarty->unregisterPlugin('function', 'neria_trad');
        } catch (\Throwable $e) {
            // Plugin pas encore enregistrÃ© â€” normal au premier appel
        }

        $smarty->registerPlugin(
            'function',
            'neria_trad',
            function (array $p) use ($engine, $template, $lang, $variant, $module): string {
                if (empty($p['key'])) {
                    return '';
                }

                $key = $p['key'];

                // Variante B : cherche d'abord dans les textes A/B
                if ($variant === 'B') {
                    $abManager = new ABTestManager($module);
                    $abValue   = $abManager->getVariantBValue($template, $lang, $key);
                    if ($abValue !== null) {
                        return $abValue;
                    }
                }

                // Traduction standard
                return $engine->get($template, $key, $lang);
            }
        );
    }

    // ============================================================
    // VARIABLES DE DESIGN
    // ============================================================

    /**
     * Injecte les variables CSS de design dans les templateVars Smarty
     * Disponibles dans les templates sous {$neria_color_accent}, etc.
     *
     * @param string $lang         Code langue (pour la police)
     * @param array  $templateVars Variables Smarty (passÃ© par rÃ©fÃ©rence)
     */
    private function injectDesignVars(string $lang, &$templateVars): void
    {
        if (!is_array($templateVars)) {
            $templateVars = [];
        }
        $design = $this->config->getDesignConfig();

        $templateVars = array_merge($templateVars, [
            // â”€â”€ Couleurs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'neria_color_background' => $design['color_background'],
            'neria_color_container'  => $design['color_container'],
            'neria_color_accent'     => $design['color_accent'],
            'neria_color_text'       => $design['color_text'],

            // â”€â”€ Mode sombre â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'neria_dark_mode'        => $design['dark_mode'] ? 'true' : 'false',

            // â”€â”€ Mise en page â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'neria_container_width'  => $design['container_width'],
            'neria_logo_width'       => $design['logo_width'],
            'neria_logo_url'         => $this->resolveLogoUrl($design['logo_path']),

            // â”€â”€ Typographie â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'neria_font_family'      => $this->config->getFontForLang($lang),

            // â”€â”€ RTL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'neria_dir'              => $this->engine->isRtl($lang) ? 'rtl' : 'ltr',
            'neria_text_align'       => $this->engine->isRtl($lang) ? 'right' : 'left',
            'neria_is_rtl'           => $this->engine->isRtl($lang),

            // â”€â”€ Langue â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            'neria_lang'             => $lang,
        ]);
    }

    /**
     * Injecte les liens rÃ©seaux sociaux dans les templateVars
     * Seuls les liens renseignÃ©s sont injectÃ©s
     * Si vide â†’ variable Ã  chaÃ®ne vide (le template gÃ¨re l'affichage)
     *
     * @param array $templateVars Variables Smarty (passÃ© par rÃ©fÃ©rence)
     */
    private function injectSocialVars(array &$templateVars): void
    {
        $links = $this->config->getSocialLinks();

        $templateVars = array_merge($templateVars, [
            'neria_social_instagram' => $links['instagram'] ?? '',
            'neria_social_pinterest' => $links['pinterest'] ?? '',
            'neria_social_facebook'  => $links['facebook']  ?? '',
            'neria_social_twitter'   => $links['twitter']   ?? '',
            'neria_social_youtube'   => $links['youtube']   ?? '',
            'neria_social_tiktok'    => $links['tiktok']    ?? '',
            'neria_has_social'       => !empty($links),
        ]);
    }

    /**
     * Injecte la signature manuscrite dans les templateVars
     * Si aucune signature n'est configurÃ©e, injecte des chaÃ®nes vides
     *
     * @param array $templateVars Variables Smarty (passÃ© par rÃ©fÃ©rence)
     */
    private function injectSignatureVars(array &$templateVars): void
    {
        $signature = $this->resolveSignature();

        $templateVars = array_merge($templateVars, [
            'neria_signature_url'    => $signature['url'],
            'neria_signature_name'   => $signature['name'],
            'neria_signature_title'  => $signature['title'],
            'neria_has_signature'    => !empty($signature['url']),
        ]);
    }

    /**
     * Injecte les variables personnalisées du marchand dans les templateVars
     * pour qu'elles soient substituées directement dans les emails (.html et .txt).
     *
     * Pour chaque variable {cle}, génère aussi deux variantes :
     *   - {cle_html} : sauts de ligne convertis en <br> (rendu HTML)
     *   - {cle_txt}  : texte brut, entités décodées (rendu .txt)
     *
     * Ne remplace jamais une valeur déjà présente (respecte les fakeVars d'aperçu).
     *
     * @param array $templateVars Variables Smarty (passé par référence)
     */
    private function injectCustomVars(array &$templateVars): void
    {
        if (!is_array($templateVars)) {
            return;
        }

        $vars = $this->config->getCustomVariables();
        if (empty($vars)) {
            return;
        }

        foreach ($vars as $row) {
            $key = isset($row['variable_key']) ? trim((string) $row['variable_key']) : '';
            if ($key === '') {
                continue;
            }

            $value   = (string) ($row['variable_value'] ?? '');
            $rawKey  = '{' . $key . '}';
            $htmlKey = '{' . $key . '_html}';
            $txtKey  = '{' . $key . '_txt}';

            if (empty($templateVars[$rawKey])) {
                $templateVars[$rawKey] = $value;
            }
            if (empty($templateVars[$htmlKey])) {
                $templateVars[$htmlKey] = nl2br($value);
            }
            if (empty($templateVars[$txtKey])) {
                $templateVars[$txtKey] = trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
            }
        }
    }

    /**
     * Construit le message personnalisé optionnel (saisi par le marchand lors
     * d'un envoi manuel) en deux variantes : {custom_message} (bloc HTML) et
     * {custom_message_txt} (texte). Source : {custom_message_raw}.
     *
     * Toujours défini (vide par défaut) pour qu'aucun placeholder littéral ne
     * subsiste dans les emails standards (le slot existe dans layout.html et
     * dans le TXT compilé).
     *
     * @param array $templateVars Variables Smarty (passé par référence)
     */
    private function injectCustomMessage(array &$templateVars): void
    {
        if (!is_array($templateVars)) {
            return;
        }

        $raw = isset($templateVars['{custom_message_raw}'])
            ? trim((string) $templateVars['{custom_message_raw}'])
            : '';

        if ($raw === '') {
            $templateVars['{custom_message}']     = '';
            $templateVars['{custom_message_txt}'] = '';
            return;
        }

        $safe = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
        $templateVars['{custom_message}'] =
            '<div class="neria-info-box" style="margin-top:28px; font-style:italic;">'
            . nl2br($safe)
            . '</div>';

        $templateVars['{custom_message_txt}'] =
            "\n--------------------------------\n" . $raw . "\n";
    }

    /**
     * Injecte le lien du bon de retour ({return_slip_url}) pointant vers la
     * page « Retours marchandise » du compte client (order-follow).
     * Fallback : page historique des commandes.
     *
     * @param array $templateVars Variables Smarty (passé par référence)
     */
    private function injectReturnSlipUrl(array &$templateVars): void
    {
        if (!empty($templateVars['{return_slip_url}'])) {
            return;
        }

        try {
            $url = $this->context->link->getPageLink('order-follow', true);
        } catch (\Throwable $e) {
            $url = $this->context->link->getPageLink('history', true);
        }

        $templateVars['{return_slip_url}'] = $url;
    }

    /**
     * GÃ©nÃ¨re des variantes texte brut des variables HTML, pour que
     * la version .txt des emails n'affiche pas de balises HTML.
     *
     * PrestaShop substitue les mÃªmes templateVars dans le .html et
     * le .txt. Une variable comme {messages} (bloc HTML de conversation)
     * apparaÃ®t donc en balises brutes dans le .txt. On crÃ©e ici une
     * variante {messages_txt} nettoyÃ©e que le template .txt utilise.
     *
     * @param array $templateVars Variables Smarty (passÃ© par rÃ©fÃ©rence)
     */
    private function injectTextVariants(array &$templateVars): void
    {
        if (!is_array($templateVars)) {
            return;
        }

        // Variables HTML connues â†’ variante texte {xxx_txt}
        $htmlKeys = ['{messages}'];

        foreach ($htmlKeys as $key) {
            if (empty($templateVars[$key])) {
                continue;
            }

            $txtKey = preg_replace('/\}$/', '_txt}', $key);

            // Si la variante texte existe dÃ©jÃ , on la respecte
            if (isset($templateVars[$txtKey])) {
                continue;
            }

            $html = (string) $templateVars[$key];
            // Convertit les sauts de bloc en retours Ã  la ligne
            $text = preg_replace('#</p>|<br\s*/?>#i', "\n", $html);
            $text = NeriaTools::sanitizeText($text);
            // Compacte les lignes vides multiples
            $text = preg_replace("/\n{2,}/", "\n", $text);

            $templateVars[$txtKey] = trim($text);
        }
    }

    // ============================================================
    // TRACKING
    // ============================================================

    /**
     * GÃ©nÃ¨re un token de tracking unique et injecte le pixel HTML
     * dans les templateVars. Le pixel est une image 1Ã—1 invisible
     * qui dÃ©clenche un "open" quand l'email est ouvert.
     *
     * @param string $template    Nom du template
     * @param string $lang        Code langue
     * @param array  $params      ParamÃ¨tres email (passÃ© par rÃ©fÃ©rence)
     */
    private function injectTrackingPixel(
        string $template,
        string $lang,
        array &$params
    ): void {
        // GÃ©nÃ¨re un token unique par email
        $token = $this->generateTrackingToken(
            $template,
            $lang,
            $params['to'] ?? ''
        );

        // URL du pixel de tracking (module front controller)
        $trackingUrl = $this->context->link->getModuleLink(
            'neria',
            'track',
            ['t' => $token, 'e' => 'open'],
            true // HTTPS forcÃ©
        );

        // Pixel HTML 1Ã—1 invisible â€” compatible tous clients email
        $pixel = sprintf(
            '<img src="%s" width="1" height="1" '
            . 'style="display:block;width:1px;height:1px;border:0;" '
            . 'alt="" />',
            htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8')
        );

        // Injecte dans templateVars
        $params['templateVars']['neria_tracking_pixel'] = $pixel;
        $params['templateVars']['neria_tracking_token'] = $token;

        // Stocke le token pour que StatsManager puisse enregistrer l'envoi
        $params['neria_token']    = $token;
        $params['neria_template'] = $template;
        $params['neria_lang']     = $lang;
    }

    /**
     * GÃ©nÃ¨re un token SHA-256 unique pour un email
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @param string $to       Email destinataire
     * @return string Token hexadÃ©cimal de 64 caractÃ¨res
     */
    private function generateTrackingToken(
        string $template,
        string $lang,
        string $to
    ): string {
        return hash('sha256', implode('|', [
            $template,
            $lang,
            $to,
            microtime(true),
            random_int(100000, 999999),
        ]));
    }

    // ============================================================
    // A/B TESTING
    // ============================================================

    /**
     * DÃ©termine la variante A/B Ã  utiliser pour un email donnÃ©
     * Retourne 'A', 'B' ou '' si pas de test actif
     *
     * @param string $template Nom du template
     * @param array  $params   ParamÃ¨tres email
     * @return string
     */
    private function resolveABVariant(string $template, array $params): string
    {
        if (!$this->config->isAbtestEnabled()) {
            return '';
        }

        if (!class_exists('ABTestManager')) {
            return '';
        }

        $abManager = new ABTestManager($this->module);
        return $abManager->getVariantForEmail(
            $template,
            (int) ($params['idCustomer'] ?? 0)
        );
    }

    // ============================================================
    // RÉSOLUTION DE LA LANGUE
    // ============================================================

    /**
     * Détermine la langue de l'email.
     *
     * Si la détection automatique (NERIA_AUTO_LANG) est activée, délègue
     * à TranslationEngine::resolveOptimalLang() qui tient compte du choix
     * explicite du client et du pays de livraison. Sinon, conserve le
     * comportement PrestaShop historique (langue du compte).
     *
     * @param array $params Paramètres de l'email
     * @return string Code langue Neria
     */
    private function resolveEmailLang(array $params): string
    {
        $idLang = (int) ($params['idLang'] ?? 0);

        if (!$this->config->isAutoLangEnabled()) {
            return $this->engine->langFromId($idLang);
        }

        $idCustomer = $this->resolveCustomerId($params);
        $location   = $this->getCustomerLocation($idCustomer, $params);

        return $this->engine->resolveOptimalLang(
            $idLang,
            $location['iso'],
            $location['postcode']
        );
    }

    /**
     * Retrouve l'id_customer à partir de l'email destinataire.
     *
     * Le hook actionEmailSendBefore ne fournit pas d'id_customer ; on le
     * déduit de l'adresse email (champ 'to') pour pouvoir remonter au
     * pays de livraison.
     *
     * @param array $params Paramètres de l'email
     * @return int id_customer ou 0 si introuvable
     */
    private function resolveCustomerId(array $params): int
    {
        $to = $params['to'] ?? '';
        if (is_array($to)) {
            $to = reset($to);
        }
        $to = trim((string) $to);

        if ($to === '' || !\Validate::isEmail($to)) {
            return 0;
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT `id_customer`
             FROM `' . _DB_PREFIX_ . 'customer`
             WHERE `email` = \'' . pSQL($to) . '\'
               AND `deleted` = 0
             ORDER BY `id_customer` DESC'
        );
    }

    /**
     * Détermine la localisation de référence du client (pays + code postal)
     * pour le choix de la langue.
     *
     * Privilégie l'adresse de FACTURATION (le titulaire du compte qui lit
     * l'email), pas la livraison — celle-ci peut être un tiers (cadeau,
     * bureau, famille à l'étranger) et ne reflète pas la langue du lecteur.
     * Le code postal sert à départager les pays multilingues (BE, CH).
     *
     * 1. Email de commande ({id_order} présent dans templateVars) :
     *    adresse de facturation de CETTE commande (orders.id_address_invoice).
     * 2. Email hors commande (newsletter, compte…) : adresse principale du
     *    client (la plus récente) comme approximation.
     *
     * @param int   $idCustomer
     * @param array $params Paramètres de l'email (dont templateVars)
     * @return array{iso:string, postcode:string}
     */
    private function getCustomerLocation(int $idCustomer, array $params): array
    {
        // 1. Adresse de facturation de la commande liée à l'email
        $loc = $this->invoiceLocationFromOrder($params);
        if ($loc['iso'] !== '') {
            return $loc;
        }

        // 2. Repli : adresse principale du client (la plus récente)
        return $this->customerAddressLocation($idCustomer);
    }

    /**
     * Localisation (pays + code postal) de l'adresse de facturation de la
     * commande référencée par {id_order} dans les templateVars.
     *
     * @param array $params Paramètres de l'email
     * @return array{iso:string, postcode:string}
     */
    private function invoiceLocationFromOrder(array $params): array
    {
        $vars    = is_array($params['templateVars'] ?? null) ? $params['templateVars'] : [];
        $idOrder = (int) ($vars['{id_order}'] ?? 0);

        if ($idOrder <= 0) {
            return ['iso' => '', 'postcode' => ''];
        }

        $row = \Db::getInstance()->getRow(
            'SELECT co.`iso_code`, a.`postcode`
             FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'address` a ON a.`id_address` = o.`id_address_invoice`
             INNER JOIN `' . _DB_PREFIX_ . 'country` co ON co.`id_country` = a.`id_country`
             WHERE o.`id_order` = ' . $idOrder
        );

        return $this->locationRow($row);
    }

    /**
     * Localisation (pays + code postal) de l'adresse non supprimée la plus
     * récente d'un client. Approximation de l'adresse principale en l'absence
     * de commande (emails hors commande).
     *
     * @param int $idCustomer
     * @return array{iso:string, postcode:string}
     */
    private function customerAddressLocation(int $idCustomer): array
    {
        if ($idCustomer <= 0) {
            return ['iso' => '', 'postcode' => ''];
        }

        $row = \Db::getInstance()->getRow(
            'SELECT co.`iso_code`, a.`postcode`
             FROM `' . _DB_PREFIX_ . 'address` a
             INNER JOIN `' . _DB_PREFIX_ . 'country` co ON co.`id_country` = a.`id_country`
             WHERE a.`id_customer` = ' . $idCustomer . '
               AND a.`deleted` = 0
             ORDER BY a.`date_upd` DESC'
        );

        return $this->locationRow($row);
    }

    /**
     * Normalise une ligne SQL {iso_code, postcode} en localisation.
     *
     * @param array|false|null $row
     * @return array{iso:string, postcode:string}
     */
    private function locationRow($row): array
    {
        if (!is_array($row) || empty($row['iso_code'])) {
            return ['iso' => '', 'postcode' => ''];
        }

        return [
            'iso'      => strtoupper((string) $row['iso_code']),
            'postcode' => (string) ($row['postcode'] ?? ''),
        ];
    }

    // ============================================================
    // RÃ‰SOLUTION DES RESSOURCES
    // ============================================================

    /**
     * Nettoie et normalise le nom du template
     * PrestaShop peut passer 'order_conf.html' ou 'order_conf'
     *
     * @param string $raw Nom brut du template
     * @return string Nom normalisÃ© (sans extension)
     */
    private function resolveTemplate(string $raw): string
    {
        // Supprime l'extension si prÃ©sente
        $template = preg_replace('/\.(html?|txt)$/i', '', trim($raw));

        // Supprime les caractÃ¨res non autorisÃ©s (sÃ©curitÃ©)
        $template = preg_replace('/[^a-z0-9_-]/i', '', $template);

        return strtolower($template);
    }

    /**
     * Indique si un template est exclu du traitement Neria
     *
     * @param string $template Nom du template
     * @return bool
     */
    private function isExcluded(string $template): bool
    {
        return in_array($template, self::EXCLUDED_TEMPLATES, true);
    }

    /**
     * RÃ©sout l'URL publique du logo depuis son chemin relatif
     *
     * @param string $relativePath Chemin relatif (ex: data/signatures/logo_1.png)
     * @return string URL absolue ou URL du logo PS par dÃ©faut
     */
    private function resolveLogoUrl(string $relativePath): string
    {
        if (empty($relativePath)) {
            // Fallback : logo de la boutique PrestaShop
            return $this->context->link->getMediaLink(
                _PS_IMG_ . \Configuration::get('PS_LOGO')
            );
        }

        return $this->module->getModuleUrl($relativePath);
    }

    /**
     * RÃ©cupÃ¨re les donnÃ©es de la signature active pour la boutique
     * Retourne un tableau avec url, name, title
     *
     * @return array
     */
    private function resolveSignature(): array
    {
        $table  = _DB_PREFIX_ . 'neria_signature';
        $idShop = (int) $this->context->shop->id;

        $row = \Db::getInstance()->getRow(
            "SELECT `signer_name`, `signer_title`, `image_path`
             FROM `{$table}`
             WHERE `id_shop`  = {$idShop}
               AND `is_active` = 1"
        );

        if (!$row) {
            return ['url' => '', 'name' => '', 'title' => ''];
        }

        $url = !empty($row['image_path'])
            ? $this->module->getModuleUrl($row['image_path'])
            : '';

        return [
            'url'   => $url,
            'name'  => $row['signer_name'],
            'title' => $row['signer_title'],
        ];
    }

    // ============================================================
    // APERÃ‡U BACK-OFFICE (temps rÃ©el)
    // ============================================================

    /**
     * GÃ©nÃ¨re un aperÃ§u HTML d'un template pour le back-office
     * UtilisÃ© par l'onglet Design pour l'aperÃ§u en temps rÃ©el
     *
     * @param string $template    Nom du template (ex: order_conf)
     * @param string $lang        Code langue (ex: fr)
     * @param array  $designOverride Valeurs de design temporaires
     *                              (couleurs/polices non encore sauvegardÃ©es)
     * @return string HTML rendu
     */
    public function renderPreview(
        string $template,
        string $lang,
        array $designOverride = []
    ): string {
        // Chemin vers le template HTML
        $templatePath = $this->module->getModulePath(
            'mails/themes/neria_global/core/' . $template . '.html'
        );

        if (!file_exists($templatePath)) {
            return '<p style="color:red;">Template introuvable : '
                . htmlspecialchars($template) . '</p>';
        }

        // Enregistre {neria_trad} pour le rendu de l'aperÃ§u
        $this->registerSmartyFunction($template, $lang, '');

        // Variables de design (avec override pour l'aperÃ§u temps rÃ©el)
        $design = array_merge($this->config->getDesignConfig(), $designOverride);

        // Variables Smarty pour l'aperÃ§u
        $previewVars = [
            'neria_color_background' => $design['color_background'],
            'neria_color_container'  => $design['color_container'],
            'neria_color_accent'     => $design['color_accent'],
            'neria_color_text'       => $design['color_text'],
            'neria_dark_mode'        => $design['dark_mode'] ? 'true' : 'false',
            'neria_container_width'  => $design['container_width'],
            'neria_logo_width'       => $design['logo_width'],
            'neria_logo_url'         => $this->resolveLogoUrl($design['logo_path']),
            'neria_font_family'      => $this->config->getFontForLang($lang),
            'neria_dir'              => $this->engine->isRtl($lang) ? 'rtl' : 'ltr',
            'neria_text_align'       => $this->engine->isRtl($lang) ? 'right' : 'left',
            'neria_is_rtl'           => $this->engine->isRtl($lang),
            'neria_lang'             => $lang,
            'neria_has_social'       => false,
            'neria_has_signature'    => false,
            'neria_tracking_pixel'   => '', // Pas de tracking en aperÃ§u

            // Variables PrestaShop factices pour l'aperÃ§u
            'shop_name'              => \Configuration::get('PS_SHOP_NAME'),
            'shop_url'               => \Tools::getShopDomainSsl(true),
            'order_name'             => 'NR-000123',
            'date'                   => date('d/m/Y'),
            'payment'                => 'Carte bancaire',
            'total_paid'             => '189,00 â‚¬',
            'total_products'         => '189,00 â‚¬',
            'total_discounts'        => '0,00 â‚¬',
            'total_shipping'         => '0,00 â‚¬',
            'total_tax_paid'         => '31,50 â‚¬',
            'carrier'                => 'Colissimo',
            'delivery_block_html'    => '<p>12 rue de la Paix<br>75001 Paris</p>',
            'invoice_block_html'     => '<p>12 rue de la Paix<br>75001 Paris</p>',
            'history_url'            => '#',
            'guest_tracking_url'     => '#',
            'products'               => $this->getFakeProductsTable(),
            'discounts'              => '',
        ];

        // Rendu via Smarty
        $smarty = $this->context->smarty;
        $smarty->assign($previewVars);

        try {
            return $smarty->fetch($templatePath);
        } catch (\Throwable $e) {
            $this->module->log(
                'EmailRenderer::renderPreview erreur â†’ ' . $e->getMessage(),
                3
            );
            return '<p style="color:red;">Erreur de rendu : '
                . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    /**
     * GÃ©nÃ¨re un faux tableau produits HTML pour l'aperÃ§u
     *
     * @return string HTML du tableau produits
     */
    private function getFakeProductsTable(): string
    {
        return '<tr>
            <td>NR-001</td>
            <td>Montre Artisanale Edition LimitÃ©e</td>
            <td>189,00 â‚¬</td>
            <td>1</td>
            <td style="text-align:right;">189,00 â‚¬</td>
        </tr>';
    }

    /**
     * Compile le template Neria en fichier HTML plat (sans heritage Smarty)
     * Fusionne layout.html + core/{template}.html
     */
    private function compileNeriaTemplate(string $template, string $lang, ?string $outIso = null): ?string
    {
        $layoutPath = $this->module->getModulePath('mails/themes/neria_global/layout.html');
        $corePath   = $this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.html');

        if (!file_exists($layoutPath) || !file_exists($corePath)) {
            $this->watchdog()->error(
                'Template introuvable : ' . $template,
                $template,
                'EmailRenderer'
            );
            return null;
        }

        $layout = file_get_contents($layoutPath);
        $core   = file_get_contents($corePath);

        if (!preg_match('/\{block\s+name=[\'"]neria_content[\'\"]\}(.*?)\{\/block\}/s', $core, $m)) {
            return null;
        }

        $compiled = preg_replace('/\{block\s+name=[\'"]neria_content[\'\"]\}\{\/block\}/', trim($m[1]), $layout);
        $compiled = preg_replace('/\{extends\s+[^}]+\}/', '', $compiled);

        // ── Résoudre les {neria_trad key='...'} avec les vraies traductions ──
        $engine = $this->engine;
        $compiled = preg_replace_callback(
            '/\{neria_trad\s+key=[\'"]([a-z0-9_]+)[\'"]\s*\}/',
            function ($m) use ($engine, $template, $lang) {
                $v = $engine->get($template, $m[1], $lang);
                return $v !== '' ? $v : $m[0];
            },
            $compiled
        );

        // ── Résoudre les variables de design ─────────────────────────────
        $design = $this->config->getDesignConfig();
        $tplVars = [
            '{$neria_color_accent}'     => $design['color_accent'],
            '{$neria_color_background}' => $design['color_background'],
            '{$neria_color_container}'  => $design['color_container'],
            '{$neria_color_text}'       => $design['color_text'],
            '{$neria_font_family}'      => $this->config->getFontForLang($lang),
            '{$neria_dir}'              => $this->engine->isRtl($lang) ? 'rtl' : 'ltr',
            '{$neria_text_align}'       => $this->engine->isRtl($lang) ? 'right' : 'left',
            '{$neria_container_width}'  => (string) $design['container_width'],
            '{$neria_logo_url}'         => $this->resolveLogoUrl($design['logo_path']),
            '{$neria_tracking_pixel}'   => '',
            '{$neria_social_links}'     => '',
            '{$neria_lang}'             => $lang,
        ];
        $compiled = str_replace(array_keys($tplVars), array_values($tplVars), $compiled);

        // ── Nettoyer les résidus Smarty ───────────────────────────────────
        $compiled = preg_replace('/\{if\s[^}]+\}.*?\{\/if\}/s', '', $compiled);
        $compiled = preg_replace('/\{\*.*?\*\}/s', '', $compiled);
        $compiled = preg_replace('/\{\$[a-z_]+\}/', '', $compiled);

        // Dossier de sortie : l'ISO que Mail::send va lire (langue du compte,
        // $outIso) plutôt que la langue détectée du contenu ($lang). Le fichier
        // contient le texte en langue détectée, mais doit résider dans le
        // dossier où PrestaShop ira le chercher.
        $outDir = _PS_MODULE_DIR_ . 'neria/mails/' . ($outIso ?: $lang) . '/';
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $outFile = $outDir . $template . '.html';
        file_put_contents($outFile, $compiled);

        // Générer aussi la version .txt (avec résolution des {neria_trad})
        $txtPath = $this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.txt');
        if (file_exists($txtPath)) {
            $compiledTxt = file_get_contents($txtPath);
            $compiledTxt = preg_replace_callback(
                '/\{neria_trad\s+key=[\'"]([a-z0-9_]+)[\'"]\s*\}/',
                function ($m) use ($engine, $template, $lang) {
                    $v = $engine->get($template, $m[1], $lang);
                    return $v !== '' ? $v : $m[0];
                },
                $compiledTxt
            );
            // Slot du message personnalisé optionnel (vide par défaut, rempli
            // par Mail::Send via {custom_message_txt} si un message est saisi).
            $compiledTxt = rtrim($compiledTxt, "\n") . "\n{custom_message_txt}\n";
            file_put_contents($outDir . $template . '.txt', $compiledTxt);
        }

        return $outFile;
    }

    /**
     * Reformate le HTML {products} généré par PrestaShop.
     * PrestaShop injecte des tableaux imbriqués dans chaque <td> qui brisent
     * les largeurs de colonnes. Cette méthode remplace chaque <td> imbriqué
     * par un <td> simple avec les bons attributs de style Neria.
     *
     * @param array $templateVars Variables Smarty (passé par référence)
     */
    private function reformatProductsHtml(array &$templateVars): void
    {
        $key = '{products}';
        if (empty($templateVars[$key])) {
            return;
        }

        $html = $templateVars[$key];

        $base = 'padding:14px 12px; border-bottom:1px solid #f0ece6; font-size:13px; color:#2c2c2c; vertical-align:middle;';
        $styles = [
            $base . ' white-space:nowrap;',
            $base,
            $base . ' white-space:nowrap;',
            $base . ' text-align:center; white-space:nowrap;',
            $base . ' text-align:right; white-space:nowrap;',
        ];

        $dom = new \DOMDocument();
        @$dom->loadHTML(
            '<?xml encoding="UTF-8"><html><body><table>' . $html . '</table></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $rows    = $dom->getElementsByTagName('tr');
        $newRows = [];

        foreach ($rows as $tr) {

            $outerTds = [];
            foreach ($tr->childNodes as $node) {
                if ($node->nodeType === XML_ELEMENT_NODE && $node->nodeName === 'td') {
                    $outerTds[] = $node;
                }
            }

            if (empty($outerTds)) {
                continue;
            }

            $firstStyle = $outerTds[0]->getAttribute('style');
            if (strpos($firstStyle, 'border') === false) {
                continue;
            }

            $contents = [];
            foreach ($outerTds as $td) {
                $innerTds = $td->getElementsByTagName('td');
                $content  = '';
                foreach ($innerTds as $inner) {
                    if ($inner->getAttribute('width') === '5') {
                        continue;
                    }
                    $innerHTML = '';
                    foreach ($inner->childNodes as $child) {
                        $innerHTML .= $dom->saveHTML($child);
                    }
                    $innerHTML = trim($innerHTML);
                    if ($innerHTML !== '' && $innerHTML !== '&nbsp;') {
                        $content = $innerHTML;
                        break;
                    }
                }
                $contents[] = $content;
            }

            $cells = '';
            foreach ($contents as $i => $content) {
                $style  = $styles[$i] ?? $base;
                $cells .= '<td style="' . $style . '">' . $content . '</td>';
            }

            $newRows[] = '<tr>' . $cells . '</tr>';
        }

        if (!empty($newRows)) {
            $templateVars[$key] = implode("\n", $newRows);
        }
    }
}