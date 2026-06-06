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
     * Templates qui NE doivent PAS Ãªtre traitÃ©s par Neria
     * (emails admin purement techniques)
     */
    const EXCLUDED_TEMPLATES = [
        'log_alert',
        'employee_password',
        'import',
        'new_order',        // email admin notif nouvelle commande
        'backoffice_order', // email admin interne
    ];

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
        $lang = $this->engine->langFromId((int) ($params['idLang'] ?? 0));

        // â”€â”€ SÃ©lectionne la variante A/B si nÃ©cessaire â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $variant = $this->resolveABVariant($template, $params);

        // â”€â”€ Enregistre {neria_trad} dans Smarty â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->registerSmartyFunction($template, $lang, $variant);

        // â”€â”€ Injecte les variables de design dans Smarty â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->injectDesignVars($lang, $params['templateVars']);

        // â”€â”€ Injecte les liens rÃ©seaux sociaux â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->injectSocialVars($params['templateVars']);

        // â”€â”€ Injecte la signature â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $this->injectSignatureVars($params['templateVars']);

        // â”€â”€ Injecte le pixel de tracking â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($this->config->isStatsEnabled()) {
            $this->injectTrackingPixel($template, $lang, $params);
        }

        // Compiler template Neria et changer templatePath
        $compiledPath = $this->compileNeriaTemplate($template, $lang);
        if ($compiledPath && isset($params['templatePath'])) {
            $params['templatePath'] = dirname(dirname($compiledPath)) . DIRECTORY_SEPARATOR;
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
    private function compileNeriaTemplate(string $template, string $lang): ?string
    {
        $layoutPath = $this->module->getModulePath('mails/themes/neria_global/layout.html');
        $corePath   = $this->module->getModulePath('mails/themes/neria_global/core/' . $template . '.html');

        if (!file_exists($layoutPath) || !file_exists($corePath)) {
            return null;
        }

        $layout = file_get_contents($layoutPath);
        $core   = file_get_contents($corePath);

        if (!preg_match('/\{block\s+name=[\'"]neria_content[\'\"]\}(.*?)\{\/block\}/s', $core, $m)) {
            return null;
        }

        $compiled = preg_replace('/\{block\s+name=[\'"]neria_content[\'\"]\}\{\/block\}/', trim($m[1]), $layout);
        $compiled = preg_replace('/\{extends\s+[^}]+\}/', '', $compiled);

        $outDir = _PS_ROOT_DIR_ . '/var/cache/neria/' . $lang . '/';
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $outFile = $outDir . $template . '.html';
        file_put_contents($outFile, $compiled);

        return $outFile;
    }
}