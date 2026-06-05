<?php
/**
 * NERIA — EmailRenderer
 *
 * Orchestrateur central du rendu des emails Neria.
 * Intercepte chaque email envoyé par PrestaShop via le hook
 * actionEmailSendBefore et :
 *
 * 1. Identifie le template et la langue du destinataire
 * 2. Enregistre la fonction Smarty {neria_trad key='...'}
 * 3. Injecte les variables de design (couleurs, polices, RTL)
 * 4. Sélectionne la variante A/B si un test est actif
 * 5. Injecte le pixel de tracking pour les statistiques
 * 6. Injecte les liens réseaux sociaux
 * 7. Injecte la signature manuscrite si configurée
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
    // PROPRIÉTÉS
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
    // POINT D'ENTRÉE PRINCIPAL
    // ============================================================

    /**
     * Traite les paramètres d'un email avant son envoi
     * Appelé depuis neria.php → hookActionEmailSendBefore()
     *
     * @param array $params Paramètres passés par PrestaShop :
     *   $params['template']     : nom du template (ex: order_conf)
     *   $params['idLang']       : id langue PrestaShop
     *   $params['templateVars'] : variables Smarty du template
     *   $params['subject']      : sujet de l'email
     *   $params['to']           : adresse email destinataire
     *   $params['toName']       : nom du destinataire
     */
    public function processEmailParams(array &$params): void
    {
        // ── Vérifie que le module est actif ──────────────────────
        if (!$this->config->isActive()) {
            return;
        }

        // ── Récupère et valide le template ───────────────────────
        $template = $this->resolveTemplate($params['template'] ?? '');

        if (!$template || $this->isExcluded($template)) {
            return;
        }

        // ── Résout la langue ─────────────────────────────────────
        $lang = $this->engine->langFromId((int) ($params['idLang'] ?? 0));

        // ── Sélectionne la variante A/B si nécessaire ────────────
        $variant = $this->resolveABVariant($template, $params);

        // ── Enregistre {neria_trad} dans Smarty ──────────────────
        $this->registerSmartyFunction($template, $lang, $variant);

        // ── Injecte les variables de design dans Smarty ──────────
        $this->injectDesignVars($lang, $params['templateVars']);

        // ── Injecte les liens réseaux sociaux ────────────────────
        $this->injectSocialVars($params['templateVars']);

        // ── Injecte la signature ─────────────────────────────────
        $this->injectSignatureVars($params['templateVars']);

        // ── Injecte le pixel de tracking ─────────────────────────
        if ($this->config->isStatsEnabled()) {
            $this->injectTrackingPixel($template, $lang, $params);
        }

        // ── Log ──────────────────────────────────────────────────
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
    // SMARTY — Enregistrement de {neria_trad}
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

        // Évite d'enregistrer deux fois (cas de plusieurs emails
        // envoyés dans la même requête)
        try {
            $smarty->unregisterPlugin('function', 'neria_trad');
        } catch (\Throwable $e) {
            // Plugin pas encore enregistré — normal au premier appel
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
                    $abValue = $engine->getABVariantValue($template, $lang, $key);
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
     * @param array  $templateVars Variables Smarty (passé par référence)
     */
    private function injectDesignVars(string $lang, array &$templateVars): void
    {
        $design = $this->config->getDesignConfig();

        $templateVars = array_merge($templateVars, [
            // ── Couleurs ─────────────────────────────────────────
            'neria_color_background' => $design['color_background'],
            'neria_color_container'  => $design['color_container'],
            'neria_color_accent'     => $design['color_accent'],
            'neria_color_text'       => $design['color_text'],

            // ── Mode sombre ───────────────────────────────────────
            'neria_dark_mode'        => $design['dark_mode'] ? 'true' : 'false',

            // ── Mise en page ──────────────────────────────────────
            'neria_container_width'  => $design['container_width'],
            'neria_logo_width'       => $design['logo_width'],
            'neria_logo_url'         => $this->resolveLogoUrl($design['logo_path']),

            // ── Typographie ───────────────────────────────────────
            'neria_font_family'      => $this->config->getFontForLang($lang),

            // ── RTL ───────────────────────────────────────────────
            'neria_dir'              => $this->engine->isRtl($lang) ? 'rtl' : 'ltr',
            'neria_text_align'       => $this->engine->isRtl($lang) ? 'right' : 'left',
            'neria_is_rtl'           => $this->engine->isRtl($lang),

            // ── Langue ────────────────────────────────────────────
            'neria_lang'             => $lang,
        ]);
    }

    /**
     * Injecte les liens réseaux sociaux dans les templateVars
     * Seuls les liens renseignés sont injectés
     * Si vide → variable à chaîne vide (le template gère l'affichage)
     *
     * @param array $templateVars Variables Smarty (passé par référence)
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
     * Si aucune signature n'est configurée, injecte des chaînes vides
     *
     * @param array $templateVars Variables Smarty (passé par référence)
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
     * Génère un token de tracking unique et injecte le pixel HTML
     * dans les templateVars. Le pixel est une image 1×1 invisible
     * qui déclenche un "open" quand l'email est ouvert.
     *
     * @param string $template    Nom du template
     * @param string $lang        Code langue
     * @param array  $params      Paramètres email (passé par référence)
     */
    private function injectTrackingPixel(
        string $template,
        string $lang,
        array &$params
    ): void {
        // Génère un token unique par email
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
            true // HTTPS forcé
        );

        // Pixel HTML 1×1 invisible — compatible tous clients email
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
     * Génère un token SHA-256 unique pour un email
     *
     * @param string $template Nom du template
     * @param string $lang     Code langue
     * @param string $to       Email destinataire
     * @return string Token hexadécimal de 64 caractères
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
     * Détermine la variante A/B à utiliser pour un email donné
     * Retourne 'A', 'B' ou '' si pas de test actif
     *
     * @param string $template Nom du template
     * @param array  $params   Paramètres email
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
    // RÉSOLUTION DES RESSOURCES
    // ============================================================

    /**
     * Nettoie et normalise le nom du template
     * PrestaShop peut passer 'order_conf.html' ou 'order_conf'
     *
     * @param string $raw Nom brut du template
     * @return string Nom normalisé (sans extension)
     */
    private function resolveTemplate(string $raw): string
    {
        // Supprime l'extension si présente
        $template = preg_replace('/\.(html?|txt)$/i', '', trim($raw));

        // Supprime les caractères non autorisés (sécurité)
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
     * Résout l'URL publique du logo depuis son chemin relatif
     *
     * @param string $relativePath Chemin relatif (ex: data/signatures/logo_1.png)
     * @return string URL absolue ou URL du logo PS par défaut
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
     * Récupère les données de la signature active pour la boutique
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
               AND `is_active` = 1
             LIMIT 1"
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
    // APERÇU BACK-OFFICE (temps réel)
    // ============================================================

    /**
     * Génère un aperçu HTML d'un template pour le back-office
     * Utilisé par l'onglet Design pour l'aperçu en temps réel
     *
     * @param string $template    Nom du template (ex: order_conf)
     * @param string $lang        Code langue (ex: fr)
     * @param array  $designOverride Valeurs de design temporaires
     *                              (couleurs/polices non encore sauvegardées)
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

        // Enregistre {neria_trad} pour le rendu de l'aperçu
        $this->registerSmartyFunction($template, $lang, '');

        // Variables de design (avec override pour l'aperçu temps réel)
        $design = array_merge($this->config->getDesignConfig(), $designOverride);

        // Variables Smarty pour l'aperçu
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
            'neria_tracking_pixel'   => '', // Pas de tracking en aperçu

            // Variables PrestaShop factices pour l'aperçu
            'shop_name'              => \Configuration::get('PS_SHOP_NAME'),
            'shop_url'               => \Tools::getShopDomainSsl(true),
            'order_name'             => 'SY-000123',
            'date'                   => date('d/m/Y'),
            'payment'                => 'Carte bancaire',
            'total_paid'             => '189,00 €',
            'total_products'         => '189,00 €',
            'total_discounts'        => '0,00 €',
            'total_shipping'         => '0,00 €',
            'total_tax_paid'         => '31,50 €',
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
                'EmailRenderer::renderPreview erreur → ' . $e->getMessage(),
                3
            );
            return '<p style="color:red;">Erreur de rendu : '
                . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    /**
     * Génère un faux tableau produits HTML pour l'aperçu
     *
     * @return string HTML du tableau produits
     */
    private function getFakeProductsTable(): string
    {
        return '<tr>
            <td>SY-001</td>
            <td>Montre Artisanale Edition Limitée</td>
            <td>189,00 €</td>
            <td>1</td>
            <td style="text-align:right;">189,00 €</td>
        </tr>';
    }
}