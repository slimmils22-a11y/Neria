<?php
/**
 * NERIA — Luxury Email Suite
 *
 * Module PrestaShop — Emails transactionnels & marketing haut de gamme
 * 18 langues · Adaptation culturelle · Typographie premium
 * Compatible PrestaShop 8.0.0 → 9.x
 *
 * @author    Neria
 * @version   1.0.0
 * @license   AFL (Academic Free License)
 */

// Sécurité : interdire l'accès direct au fichier PHP
if (!defined('_PS_VERSION_')) {
    exit;
}

// ============================================================
// AUTOLOAD : charge automatiquement toutes les classes src/
// ============================================================
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Classe principale du module Neria
 * Gère l'installation, la désinstallation et les hooks PrestaShop
 */
class Neria extends Module
{
    // ============================================================
    // CONSTANTES DU MODULE
    // ============================================================

    /** Version courante du module */
    const VERSION = '1.0.0';

    /** Préfixe de toutes les clés Configuration::get() du module */
    const CONFIG_PREFIX = 'NERIA_';

    /** Langues supportées par le module */
    const SUPPORTED_LANGS = [
        'fr', 'en', 'de', 'it', 'es', 'pt', 'br',
        'ar', 'ja', 'ko', 'zh', 'tw',
        'ru', 'tr', 'sv', 'no', 'da', 'nl',
    ];

    /** Langues RTL (right-to-left) */
    const RTL_LANGS = ['ar'];

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct()
    {
        $this->name          = 'neria';
        $this->tab           = 'emailing';
        $this->version       = self::VERSION;
        $this->author        = 'Neria';
        $this->need_instance = 1;
        $this->bootstrap     = true;

        // Compatibilité PrestaShop déclarée
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '9.99.99',
        ];

        // Appel obligatoire AVANT d'accéder à $this->l()
        parent::__construct();

        $this->displayName = $this->l('Neria – Luxury Email Suite');
        $this->description = $this->l(
            'Emails transactionnels et marketing haut de gamme. ' .
            '18 langues avec adaptation culturelle réelle, ' .
            'typographie par écriture et design luxe.'
        );

        // Message affiché si la version PS n'est pas compatible
        $this->confirmUninstall = $this->l(
            'Attention : désinstaller Neria supprimera toutes vos ' .
            'traductions personnalisées et vos statistiques. ' .
            'Êtes-vous certain de vouloir continuer ?'
        );
    }

    // ============================================================
    // INSTALLATION
    // ============================================================

    /**
     * Installe le module :
     * 1. Appel du parent (enregistrement en base)
     * 2. Création des tables SQL
     * 3. Enregistrement des hooks
     * 4. Création de l'onglet back-office
     * 5. Import du dictionnaire translations.json
     */
    public function install(): bool
    {
        return parent::install()
            && $this->executeSqlFile('install.sql')
            && $this->registerHooks()
            && $this->installTab()
            && $this->importTranslations()
            && $this->setDefaultConfiguration()
            && $this->configureDeliveredStatus();
    }

    // ============================================================
    // DÉSINSTALLATION
    // ============================================================

    /**
     * Désinstalle le module :
     * 1. Suppression des tables SQL
     * 2. Suppression de l'onglet back-office
     * 3. Nettoyage des clés Configuration
     * 4. Appel du parent
     */
    public function uninstall(): bool
    {
        return $this->restoreDeliveredStatus()
            && $this->executeSqlFile('uninstall.sql')
            && $this->uninstallTab()
            && $this->deleteConfiguration()
            && parent::uninstall();
    }

    // ============================================================
    // ENREGISTREMENT DES HOOKS
    // ============================================================

    /**
     * Enregistre tous les hooks nécessaires au fonctionnement du module
     * Délégation à HooksManager pour la logique métier
     */
    private function registerHooks(): bool
    {
        $hooks = [
            // ── Emails ────────────────────────────────────────────
            // Hook principal : intercepte TOUS les envois email PS
            // Permet d'injecter les traductions Neria et le tracking
            'actionEmailSendBefore',

            // ── Back-office ───────────────────────────────────────
            // Charge CSS/JS Neria dans le header du back-office
            'displayBackOfficeHeader',

            // ── Tracking stats ────────────────────────────────────
            // Enregistre l'envoi dans neria_stat
            'actionEmailSendAfter',

            // ── Occasions calendaires ─────────────────────────────
            // Vérifie chaque jour les occasions à envoyer (cron-like)
            'actionCronJob',

            // ── Support multi-boutique ────────────────────────────
            'displayHeader',
        ];

        foreach ($hooks as $hook) {
            // registerHook() retourne false si le hook est invalide
            // On ignore les hooks non-existants (compatibilité versions)
            $this->registerHook($hook);
        }

        return true;
    }

    // ============================================================
    // HOOKS — DÉLÉGATION AUX MANAGERS
    // ============================================================

    /**
     * Hook principal : intercepte l'envoi d'emails PrestaShop
     * Remplace les textes natifs par les traductions Neria
     * Injecte le pixel de tracking et les variables personnalisées
     *
     * @param array $params Paramètres passés par PrestaShop :
     *   - $params['template']   : nom du template (ex: order_conf)
     *   - $params['subject']    : sujet de l'email
     *   - $params['to']         : adresse destinataire
     *   - $params['toName']     : nom destinataire
     *   - $params['templateVars'] : variables Smarty du template
     *   - $params['idLang']     : id de langue PrestaShop
     */
    public function hookActionEmailSendBefore(array &$params): bool
    {
        if (class_exists('EmailRenderer')) {
            $renderer = new EmailRenderer($this);
            // Retourne false pour annuler l'envoi natif de PrestaShop : c'est
            // le cas quand le rendu a échoué et qu'un email de secours élégant
            // a été envoyé à la place (cf. EmailRenderer::handleRenderFailure).
            return $renderer->processEmailParams($params);
        }

        return true;
    }

    /**
     * Hook post-envoi : enregistre la stat d'envoi
     *
     * @param array $params Mêmes paramètres que actionEmailSendBefore
     */
    public function hookActionEmailSendAfter(array $params): void
    {
        if (class_exists('StatsManager')) {
            $stats = new StatsManager($this);
            $stats->recordSent($params);
        }
    }

    /**
     * Hook back-office : injecte CSS et JS Neria dans le header admin
     */
    public function hookDisplayBackOfficeHeader(): void
    {
        // Vérifie qu'on est bien sur la page de configuration Neria
        if (Tools::getValue('configure') === $this->name) {
            $this->context->controller->addCSS(
                $this->_path . 'views/css/neria-admin.css'
            );
            $this->context->controller->addJS(
                $this->_path . 'views/js/neria-admin.js'
            );
        }
    }

    /**
     * Hook cron-like : vérifie les occasions calendaires du jour
     * Déclenché par l'action displayHeader (toutes les 24h via cache)
     */
    public function hookDisplayHeader(): void
    {
        if (class_exists('CalendarManager')) {
            $calendar = new CalendarManager($this);
            $calendar->checkAndSendDailyEvents();
        }
    }

    // ============================================================
    // PANNEAU DE CONFIGURATION BACK-OFFICE
    // ============================================================

    /**
     * Point d'entrée du panneau de configuration
     * PrestaShop appelle cette méthode quand le marchand
     * clique sur "Configurer" dans la liste des modules
     */
    public function getContent(): string
    {
        // ── Aperçu email (iframe de l'onglet Design) ──────────────
        // Ne rend QUE l'email et coupe le rendu. Sinon l'iframe, dont le src
        // pointe vers cette même page, rechargerait toute la page admin (qui
        // contient l'iframe) → récursion infinie → surchauffe CPU.
        if (Tools::getValue('neria_action') === 'preview') {
            $this->outputEmailPreview();
        }

        // ── Action : envoi d'un email de test ─────────────────────
        if (Tools::getValue('neria_action') === 'send_test') {
            $this->sendTestEmail();
        }

        // ── Action : vider le journal watchdog ────────────────────
        if (Tools::getValue('neria_action') === 'clear_logs') {
            $watchdog = new WatchdogManager($this);
            $watchdog->clearLogs();
        }

        // ── Action : détection automatique de la langue ───────────
        if (Tools::getValue('neria_action') === 'save_autolang') {
            Configuration::updateValue(
                self::CONFIG_PREFIX . 'AUTO_LANG',
                (int) Tools::getValue('neria_auto_lang', 0)
            );
        }

        // ── Action : journalisation des emails internes ───────────
        if (Tools::getValue('neria_action') === 'save_log_internal') {
            Configuration::updateValue(
                self::CONFIG_PREFIX . 'LOG_INTERNAL',
                (int) Tools::getValue('neria_log_internal', 0)
            );
        }

        // ── Action : durée de validité des bons ───────────────────
        if (Tools::getValue('neria_action') === 'save_voucher_validity') {
            $days = (int) Tools::getValue('neria_voucher_validity', 30);
            $days = max(1, min(365, $days));
            Configuration::updateValue(self::CONFIG_PREFIX . 'VOUCHER_VALIDITY', $days);
        }

        // ── Action : envoi manuel d'un template à un client ───────
        if (Tools::getValue('neria_action') === 'send_manual') {
            $manual      = new ManualSendManager($this);
            $contentVars = Tools::getValue('neria_var');
            if (!is_array($contentVars)) {
                $contentVars = [];
            }
            $res = $manual->send(
                (string) Tools::getValue('neria_template'),
                (string) Tools::getValue('neria_email'),
                (string) Tools::getValue('neria_order_ref'),
                (string) Tools::getValue('neria_subject'),
                $contentVars
            );
            $this->context->smarty->assign(
                $res['ok'] ? 'neria_success' : 'neria_error',
                $res['message']
            );
        }

        // ── Action : score de délivrabilité (onglet Design) ───────
        if (Tools::getValue('neria_action') === 'deliverability_score') {
            $scoreTemplate = (string) Tools::getValue('score_template', 'order_conf');
            $scoreLang     = (string) Tools::getValue('score_lang', 'fr');

            if (class_exists('EmailRenderer') && class_exists('DeliverabilityScorer')) {
                try {
                    $renderer = new EmailRenderer($this);
                    $html     = $renderer->renderPreviewHtml($scoreTemplate, $scoreLang);
                    $engine   = new TranslationEngine($this);
                    $subject  = trim(strip_tags($engine->get($scoreTemplate, 'greeting_main', $scoreLang)));

                    $scorer = new DeliverabilityScorer();
                    $result = $scorer->score($html, $subject, $scoreLang);

                    $this->context->smarty->assign('neria_deliverability', $result);

                    // Watchdog : trace de l'analyse (warning si score faible)
                    if (class_exists('WatchdogManager')) {
                        $wd  = new WatchdogManager($this);
                        $msg = sprintf(
                            'Analyse délivrabilité : %d/100 (%s)',
                            $result['score'],
                            $result['grade']
                        );
                        if ($result['score'] < 60) {
                            $wd->warning($msg, $scoreTemplate, 'DeliverabilityScorer');
                        } else {
                            $wd->info($msg, $scoreTemplate, 'DeliverabilityScorer');
                        }
                    }
                } catch (\Throwable $e) {
                    if (class_exists('WatchdogManager')) {
                        (new WatchdogManager($this))->error(
                            'Échec analyse délivrabilité : ' . $e->getMessage(),
                            $scoreTemplate,
                            'DeliverabilityScorer'
                        );
                    }
                    $this->context->smarty->assign(
                        'neria_deliverability_error',
                        $this->l('Impossible d\'analyser ce template.')
                    );
                }
            }
        }

        // Détermine l'onglet actif (par défaut : configure)
        $activeTab = Tools::getValue('neria_tab', 'configure');

        // ── Instanciation des managers ────────────────────────────
        $config    = new ConfigManager($this);
        $stats     = new StatsManager($this);
        $calendar  = new CalendarManager($this);
        $fonts     = new FontManager($this);
        $signature = new SignatureGenerator($this);

        // ── Variables communes à tous les onglets ─────────────────
        $this->context->smarty->assign([
            'neria_version'    => self::VERSION,
            'neria_module_dir' => $this->_path,
            'neria_active_tab' => $activeTab,
            'neria_active'     => $config->isActive(),
            'auto_lang_enabled' => $config->isAutoLangEnabled(),
            'log_internal_enabled' => $config->isInternalLogEnabled(),
            'voucher_validity'  => $config->getVoucherValidity(),
            'neria_tabs'       => $this->getBackOfficeTabs(),

            // Libellés et drapeaux des 18 langues supportées
            'lang_labels'      => NeriaTools::getLangLabels(),
            'lang_flags'       => NeriaTools::getLangFlags(),

            // Libellés des 107 templates
            'template_labels'  => NeriaTools::getTemplateLabels(),

            // Configuration design (couleurs, logo, typo…)
            'design'           => $config->getDesignConfig(),

            // Variables personnalisées du marchand — transformées en tableau associatif
            // getCustomVariables() retourne [['variable_key'=>'...','variable_value'=>'...'], ...]
            // configure.tpl accède via $custom_vars.maison_name → tableau associatif requis
            'custom_vars'      => array_column(
                $config->getCustomVariables(),
                'variable_value',
                'variable_key'
            ),

            // Liens réseaux sociaux configurés
            'social_links'     => $config->getSocialLinks(),

            // KPIs des 30 derniers jours (onglet configure)
            'kpis'             => $stats->getKpis(30),

            // Rapports complets pour stats.tpl ($stats.kpis, $stats.global_30, etc.)
            'stats'            => $stats->getCachedReports(),
            'stats_days'       => (int) Tools::getValue('stats_days', 30),

            // Prochaines occasions calendaires (onglet configure)
            'upcoming_events'  => $calendar->getUpcomingDates(),

            // Polices : $font_scripts = metadata scripts, $fonts_by_script = polices par script
            'font_scripts'     => $fonts->getAllScripts(),
            'fonts_by_script'  => array_combine(
                array_keys($fonts->getAllScripts()),
                array_map(
                    fn($script) => $fonts->getFontsForScript($script),
                    array_keys($fonts->getAllScripts())
                )
            ),
            'current_fonts'    => $config->getTypographyConfig(),

            // Styles de signature disponibles (onglet configure)
            'signature_styles' => SignatureGenerator::STYLES,
            'current_signature' => $config->getSignatureConfig(),

            // Diagnostic complet pour l'onglet Aide
            'diagnostic'       => NeriaTools::getDiagnosticReport($this),

            // Journal watchdog pour l'onglet Aide
            'logs'             => (new WatchdogManager($this))->getLogs(100),
            'log_counts'       => (new WatchdogManager($this))->getCountByLevel(),
            'log_templates'    => (new WatchdogManager($this))->getTemplatesWithErrors(),

            // Variables pour send.tpl (envoi manuel — vague 1)
            // Libellés des champs traduits dans la langue du back-office.
            'send_templates'    => (new ManualSendManager($this))->getSendableTemplates(),
            'send_editable_map' => (new ManualSendManager($this))->getEditableFieldsMap(
                $this->context->language->iso_code
            ),

            // Variables pour abtest.tpl
            'eligible_templates' => (new ABTestManager($this))->getEligibleTemplates(),
            'tests_status'       => $this->getAbtestStatusMap(new ABTestManager($this)),
            'tests_data'         => $this->getAbtestDataMap(new ABTestManager($this)),
            'ab_reports'         => $this->getAbtestReportsMap($stats, new ABTestManager($this)),
        ]);

        // ── Réseaux sociaux ───────────────────────────────────────
        $this->context->smarty->assign('social_networks', [
            'instagram' => [
                'icon'        => '◉',
                'label'       => $this->l('Instagram'),
                'placeholder' => 'https://instagram.com/votre_compte',
            ],
            'pinterest' => [
                'icon'        => '⊕',
                'label'       => $this->l('Pinterest'),
                'placeholder' => 'https://pinterest.com/votre_compte',
            ],
            'facebook' => [
                'icon'        => '◈',
                'label'       => $this->l('Facebook'),
                'placeholder' => 'https://facebook.com/votre_page',
            ],
            'twitter' => [
                'icon'        => '◇',
                'label'       => $this->l('X (Twitter)'),
                'placeholder' => 'https://x.com/votre_compte',
            ],
            'youtube' => [
                'icon'        => '▷',
                'label'       => $this->l('YouTube'),
                'placeholder' => 'https://youtube.com/@votre_chaine',
            ],
            'tiktok' => [
                'icon'        => '◎',
                'label'       => $this->l('TikTok'),
                'placeholder' => 'https://tiktok.com/@votre_compte',
            ],
        ]);

        // ── Rendu navigation + contenu ────────────────────────────
        $navigation = $this->renderTemplate('navigation.tpl');
        $content    = $this->renderTab($activeTab);

        return $navigation
            . '<div class="neria-bo-content">'
            . $content
            . '</div>';
    }

    /**
     * Envoie un email de test au marchand
     * Utilise le template "test" pour vérifier que le rendu fonctionne
     */
    private function sendTestEmail(): void
    {
        $adminEmail = Configuration::get('PS_SHOP_EMAIL');
        $shopName   = Configuration::get('PS_SHOP_NAME');
        $idLang     = (int) $this->context->language->id;

        $result = Mail::Send(
            $idLang,
            'test',
            $this->l('Email de test — Neria Luxury Email Suite'),
            [
                '{firstname}' => 'Admin',
                '{lastname}'  => '',
                '{email}'     => $adminEmail,
                '{shop_name}' => $shopName,
                '{shop_url}'  => Tools::getShopDomainSsl(true, true),
            ],
            $adminEmail,
            $shopName,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'neria/mails/',
            false,
            (int) $this->context->shop->id
        );

        if ($result) {
            $this->context->smarty->assign('neria_success',
                $this->l('Email de test envoyé à ') . $adminEmail
            );
        } else {
            $this->context->smarty->assign('neria_error',
                $this->l('Échec de l\'envoi. Vérifiez la configuration email de PrestaShop.')
            );
        }
    }

    /**
     * Rend l'aperçu d'un email (iframe de l'onglet Design) et coupe le rendu.
     * Ne renvoie QUE le HTML de l'email — jamais la page admin complète — pour
     * éviter que l'iframe ne recharge la page entière (récursion infinie).
     */
    private function outputEmailPreview(): void
    {
        $template = preg_replace('/[^a-z0-9_-]/i', '', (string) Tools::getValue('neria_template', 'order_conf'));
        $lang     = preg_replace('/[^a-z-]/i', '', (string) Tools::getValue('neria_lang', 'fr'));
        if ($template === '') {
            $template = 'order_conf';
        }
        if ($lang === '') {
            $lang = 'fr';
        }

        // Override de design (valeurs non sauvegardées), validées pour éviter
        // toute injection dans le HTML de l'email.
        $override = [];
        foreach (['color_background', 'color_container', 'color_accent', 'color_text'] as $field) {
            $value = (string) Tools::getValue('preview_' . $field, '');
            if ($value !== '' && preg_match('/^#?[0-9a-fA-F]{3,8}$/', $value)) {
                $override[$field] = $value;
            }
        }
        $width = (int) Tools::getValue('preview_container_width', 0);
        if ($width >= 480 && $width <= 800) {
            $override['container_width'] = $width;
        }
        $logoWidth = (int) Tools::getValue('preview_logo_width', 0);
        if ($logoWidth >= 80 && $logoWidth <= 320) {
            $override['logo_width'] = $logoWidth;
        }

        $html = '';
        if (class_exists('EmailRenderer')) {
            $html = (new EmailRenderer($this))->renderPreviewHtml($template, $lang, $override);
        }

        // Vide tout buffer admin et ne renvoie que l'email, puis stoppe.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
        exit;
    }

    /**
     * Retourne la liste des onglets du back-office
     * Utilisé par navigation.tpl pour construire le menu
     */
    private function getBackOfficeTabs(): array
    {
        return [
            'configure'    => $this->l('Accueil'),
            'design'       => $this->l('Design'),
            'typography'   => $this->l('Typographie'),
            'translations' => $this->l('Traductions'),
            'social'       => $this->l('Réseaux sociaux'),
            'stats'        => $this->l('Statistiques'),
            'abtest'       => $this->l('A/B Testing'),
            'send'         => $this->l('Envoi manuel'),
            'help'         => $this->l('Aide'),
        ];
    }

    /**
     * Charge et retourne le contenu d'un onglet back-office
     *
     * @param string $tab Nom de l'onglet
     */
    private function renderTab(string $tab): string
    {
        $allowedTabs = array_keys($this->getBackOfficeTabs());

        // Sécurité : vérifie que l'onglet demandé est valide
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'configure';
        }

        return $this->renderTemplate($tab . '.tpl');
    }

    /**
     * Charge un template Smarty depuis views/templates/admin/
     *
     * @param string $template Nom du fichier .tpl
     */
    private function renderTemplate(string $template): string
    {
        $templatePath = 'module:neria/views/templates/admin/' . $template;

        return $this->context->smarty->fetch($templatePath);
    }

    // ============================================================
    // ONGLET BACK-OFFICE (menu latéral PrestaShop)
    // ============================================================

    /**
     * Crée l'entrée "Neria" dans le menu latéral du back-office
     * Apparaît sous l'onglet "Modules"
     */
    private function installTab(): bool
    {
        $tab             = new Tab();
        $tab->active     = 1;
        $tab->class_name = 'AdminNeria';
        $tab->name       = [];
        $tab->module     = $this->name;
        $tab->id_parent  = (int) Tab::getIdFromClassName('AdminParentModules');

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Neria';
        }

        return (bool) $tab->add();
    }

    /**
     * Supprime l'entrée "Neria" du menu latéral du back-office
     */
    private function uninstallTab(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminNeria');

        if ($idTab) {
            $tab = new Tab($idTab);
            return (bool) $tab->delete();
        }

        return true;
    }

    // ============================================================
    // SQL
    // ============================================================

    /**
     * Exécute un fichier SQL depuis le dossier sql/
     * Remplace PREFIX_ par le vrai préfixe de la BDD
     *
     * @param string $filename Nom du fichier (ex: install.sql)
     */
    private function executeSqlFile(string $filename): bool
    {
        $filePath = __DIR__ . '/sql/' . $filename;

        if (!file_exists($filePath)) {
            $this->_errors[] = sprintf(
                $this->l('Fichier SQL introuvable : %s'),
                $filename
            );
            return false;
        }

        $sql = file_get_contents($filePath);

        // Remplace PREFIX_ par le vrai préfixe (ex: ps_)
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        // Supprime les commentaires SQL et découpe en requêtes
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
        $queries = array_filter(
            array_map('trim', explode(';', $sql)),
            fn(string $q): bool => !empty($q)
        );

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                $this->_errors[] = sprintf(
                    $this->l('Erreur SQL dans %s : %s'),
                    $filename,
                    Db::getInstance()->getMsgError()
                );
                return false;
            }
        }

        return true;
    }

    // ============================================================
    // IMPORT DES TRADUCTIONS
    // ============================================================

    /**
     * Importe translations.json en base de données
     * Délégué à TranslationInstaller pour le bulk insert optimisé
     */
    private function importTranslations(): bool
    {
        if (!class_exists('TranslationInstaller')) {
            $this->_errors[] = $this->l('TranslationInstaller introuvable.');
            return false;
        }

        $installer = new TranslationInstaller($this);
        return $installer->importFromJson(
            __DIR__ . '/data/translations.json'
        );
    }

    // ============================================================
    // CONFIGURATION PAR DÉFAUT
    // ============================================================

    /**
     * Définit les valeurs de configuration par défaut
     * Ces valeurs sont également insérées dans install.sql
     * mais Configuration::get() est aussi utilisé dans le code
     */
    private function setDefaultConfiguration(): bool
    {
        $defaults = [
            self::CONFIG_PREFIX . 'ACTIVE'           => 1,
            self::CONFIG_PREFIX . 'COLOR_ACCENT'     => '#b38b59',
            self::CONFIG_PREFIX . 'COLOR_BACKGROUND' => '#f4f1eb',
            self::CONFIG_PREFIX . 'DARK_MODE'        => 0,
            self::CONFIG_PREFIX . 'CONTAINER_WIDTH'  => 620,
            self::CONFIG_PREFIX . 'STATS_ENABLED'    => 1,
            self::CONFIG_PREFIX . 'ABTEST_ENABLED'   => 0,
            self::CONFIG_PREFIX . 'AUTO_LANG'        => 1,
            self::CONFIG_PREFIX . 'LOG_INTERNAL'     => 0,
            self::CONFIG_PREFIX . 'VOUCHER_VALIDITY' => 30,
            self::CONFIG_PREFIX . 'INSTALLED_AT'     => date('Y-m-d H:i:s'),
        ];

        foreach ($defaults as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Supprime toutes les clés Configuration du module
     * Appelé lors de la désinstallation
     */
    private function deleteConfiguration(): bool
    {
        $keys = [
            self::CONFIG_PREFIX . 'ACTIVE',
            self::CONFIG_PREFIX . 'COLOR_ACCENT',
            self::CONFIG_PREFIX . 'COLOR_BACKGROUND',
            self::CONFIG_PREFIX . 'DARK_MODE',
            self::CONFIG_PREFIX . 'CONTAINER_WIDTH',
            self::CONFIG_PREFIX . 'STATS_ENABLED',
            self::CONFIG_PREFIX . 'ABTEST_ENABLED',
            self::CONFIG_PREFIX . 'AUTO_LANG',
            self::CONFIG_PREFIX . 'LOG_INTERNAL',
            self::CONFIG_PREFIX . 'VOUCHER_VALIDITY',
            self::CONFIG_PREFIX . 'INSTALLED_AT',
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    // ============================================================
    // STATUT « LIVRÉ » → TEMPLATE delivered
    // ============================================================

    /**
     * Configure le statut de commande « Livré » pour qu'il envoie l'email avec
     * le template Neria `delivered`. Par défaut, PrestaShop n'envoie AUCUN
     * email pour ce statut (delivered n'est pas un template natif). Sans cette
     * config, le template delivered ne partirait jamais.
     *
     * L'état précédent est sauvegardé pour être restauré à la désinstallation.
     * Non bloquant : retourne toujours true (ne doit pas faire échouer l'install).
     */
    private function configureDeliveredStatus(): bool
    {
        $idState = (int) Configuration::get('PS_OS_DELIVERED');
        if (!$idState) {
            return true;
        }

        $orderState = new OrderState($idState);
        if (!Validate::isLoadedObject($orderState)) {
            return true;
        }

        // Sauvegarde de l'état précédent (pour restauration à la désinstallation)
        $prevTemplate = is_array($orderState->template)
            ? (string) reset($orderState->template)
            : (string) $orderState->template;
        Configuration::updateValue(self::CONFIG_PREFIX . 'OSD_SEND', (int) $orderState->send_email);
        Configuration::updateValue(self::CONFIG_PREFIX . 'OSD_TPL', $prevTemplate);

        // Active l'email + template `delivered` pour toutes les langues
        $orderState->send_email = true;
        $tplByLang = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tplByLang[(int) $lang['id_lang']] = 'delivered';
        }
        $orderState->template = $tplByLang;
        $orderState->save();

        return true;
    }

    /**
     * Restaure le statut « Livré » dans son état d'avant l'installation Neria.
     * Non bloquant : retourne toujours true.
     */
    private function restoreDeliveredStatus(): bool
    {
        $idState = (int) Configuration::get('PS_OS_DELIVERED');
        if (!$idState) {
            return true;
        }

        $orderState = new OrderState($idState);
        if (!Validate::isLoadedObject($orderState)) {
            return true;
        }

        $prevSend = Configuration::get(self::CONFIG_PREFIX . 'OSD_SEND');
        $prevTpl  = (string) Configuration::get(self::CONFIG_PREFIX . 'OSD_TPL');

        $orderState->send_email = ($prevSend !== false) ? (bool) (int) $prevSend : false;
        $tplByLang = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tplByLang[(int) $lang['id_lang']] = $prevTpl;
        }
        $orderState->template = $tplByLang;
        $orderState->save();

        Configuration::deleteByName(self::CONFIG_PREFIX . 'OSD_SEND');
        Configuration::deleteByName(self::CONFIG_PREFIX . 'OSD_TPL');

        return true;
    }

    // ============================================================
    // UTILITAIRES PUBLICS
    // Utilisés par les classes src/ qui reçoivent $this (le module)
    // ============================================================

    /**
     * Construit la map statut A/B pour abtest.tpl
     * ['template_name' => 'active|draft|none', ...]
     */
    private function getAbtestStatusMap(ABTestManager $ab): array
    {
        $map = [];
        foreach ($ab->getEligibleTemplates() as $tpl => $label) {
            $map[$tpl] = $ab->getTestStatus($tpl);
        }
        return $map;
    }

    /**
     * Construit la map données A/B pour abtest.tpl
     * ['template_name' => ['a' => [...], 'b' => [...]], ...]
     */
    private function getAbtestDataMap(ABTestManager $ab): array
    {
        $map  = [];
        $rows = $ab->getAllActiveTests();
        foreach ($rows as $row) {
            $tpl     = $row['template'];
            $variant = strtolower($row['variant']);
            if (!isset($map[$tpl])) {
                $map[$tpl] = [];
            }
            $map[$tpl][$variant] = $row;
        }
        return $map;
    }

    /**
     * Construit la map rapports A/B pour abtest.tpl
     * ['template_name' => ['A' => [...], 'B' => [...]], ...]
     */
    private function getAbtestReportsMap(StatsManager $stats, ABTestManager $ab): array
    {
        $map = [];
        foreach ($ab->getEligibleTemplates() as $tpl => $label) {
            if ($ab->hasActiveTest($tpl)) {
                $map[$tpl] = $stats->getABTestReport($tpl, 30);
            }
        }
        return $map;
    }

    /**
     * Retourne le chemin absolu vers un fichier du module
     *
     * @param string $relativePath Chemin relatif depuis la racine du module
     */
    public function getModulePath(string $relativePath = ''): string
    {
        return __DIR__ . ($relativePath ? '/' . ltrim($relativePath, '/') : '');
    }

    /**
     * Retourne l'URL publique vers un fichier du module
     *
     * @param string $relativePath Chemin relatif depuis la racine du module
     */
    public function getModuleUrl(string $relativePath = ''): string
    {
        return $this->_path . ($relativePath ? ltrim($relativePath, '/') : '');
    }

    /**
     * Log une erreur dans le système de logs PrestaShop
     *
     * @param string $message  Message d'erreur
     * @param int    $severity Niveau : 1=info, 2=warn, 3=error, 4=critical
     */
    public function log(string $message, int $severity = 1): void
    {
        PrestaShopLogger::addLog(
            '[Neria] ' . $message,
            $severity,
            null,
            'Neria',
            0,
            true
        );
    }
}