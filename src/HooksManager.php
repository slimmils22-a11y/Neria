<?php
/**
 * NERIA — HooksManager
 *
 * Centralise la logique de tous les hooks PrestaShop du module.
 * neria.php délègue chaque hook à cette classe pour garder le
 * point d'entrée principal propre et lisible.
 *
 * Hooks gérés :
 * — actionEmailSendBefore   : interception et rendu des emails
 * — actionEmailSendAfter    : enregistrement stats d'envoi
 * — displayBackOfficeHeader : injection CSS/JS back-office
 * — displayHeader           : déclenchement occasions calendaires
 * — actionCronJob           : tâches planifiées (occasions, stats)
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HooksManager
{
    // ============================================================
    // CONSTANTES
    // ============================================================

    /**
     * Intervalle minimum entre deux vérifications calendaires
     * en secondes (24h = 86400s)
     */
    const CALENDAR_CHECK_INTERVAL = 86400;

    /**
     * Clé Configuration:: pour la dernière vérification calendaire
     */
    const CACHE_KEY_CALENDAR = 'neria_calendar_last_check';

    // ============================================================
    // PROPRIÉTÉS
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var \Context Contexte PrestaShop courant */
    private \Context $context;

    /** @var ConfigManager Gestionnaire de configuration */
    private ConfigManager $config;

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct(Neria $module)
    {
        $this->module  = $module;
        $this->context = \Context::getContext();
        $this->config  = new ConfigManager($module);
    }

    // ============================================================
    // HOOK : actionEmailSendBefore
    // ============================================================

    /**
     * Intercepte l'envoi d'un email PrestaShop avant son rendu
     * C'est le hook central de tout Neria
     *
     * @param array $params Paramètres email passés par PrestaShop
     */
    public function onEmailSendBefore(array &$params): void
    {
        try {
            $renderer = new EmailRenderer($this->module);
            $renderer->processEmailParams($params);
        } catch (\Throwable $e) {
            // Ne bloque JAMAIS l'envoi d'email en cas d'erreur Neria
            // PrestaShop continuera avec son rendu natif
            $this->module->log(
                'HooksManager::onEmailSendBefore erreur → ' . $e->getMessage(),
                3
            );
        }
    }

    // ============================================================
    // HOOK : actionEmailSendAfter
    // ============================================================

    /**
     * Enregistre la statistique d'envoi après chaque email
     *
     * @param array $params Paramètres email + tokens Neria injectés
     *                      par EmailRenderer
     */
    public function onEmailSendAfter(array $params): void
    {
        if (!$this->config->isStatsEnabled()) {
            return;
        }

        // Token absent = template exclu ou module inactif
        if (empty($params['neria_token'])) {
            return;
        }

        try {
            $stats = new StatsManager($this->module);
            $stats->recordSent($params);
        } catch (\Throwable $e) {
            $this->module->log(
                'HooksManager::onEmailSendAfter erreur → ' . $e->getMessage(),
                2
            );
        }
    }

    // ============================================================
    // HOOK : displayBackOfficeHeader
    // ============================================================

    /**
     * Injecte CSS et JS Neria dans le header du back-office
     * Uniquement sur la page de configuration Neria
     *
     * @return string HTML vide (assets injectés via addCSS/addJS)
     */
    public function onDisplayBackOfficeHeader(): string
    {
        $configure  = \Tools::getValue('configure');
        $controller = \Tools::getValue('controller');

        $isNeriaPage = ($configure === $this->module->name)
            || ($controller === 'AdminNeria');

        if (!$isNeriaPage) {
            return '';
        }

        // CSS back-office
        $this->context->controller->addCSS(
            $this->module->getModuleUrl('views/css/neria-admin.css'),
            'all',
            null,
            false
        );

        // JS back-office
        $this->context->controller->addJS(
            $this->module->getModuleUrl('views/js/neria-admin.js'),
            false
        );

        // Google Fonts pour l'aperçu temps réel back-office
        $this->context->controller->addCSS(
            'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600'
            . '&family=Noto+Serif+JP:wght@300;400'
            . '&family=Noto+Naskh+Arabic:wght@400;500&display=swap',
            'all',
            null,
            false
        );

        // Variables JS pour le back-office (aperçu, i18n, config)
        $jsVars = $this->buildJsVars();
        $this->context->controller->addJS(
            'data:text/javascript,' . urlencode($jsVars),
            false
        );

        return '';
    }

    // ============================================================
    // HOOK : displayHeader
    // ============================================================

    /**
     * Vérifie les occasions calendaires du jour
     * Limité à une vérification toutes les 24h via Configuration
     *
     * @return string HTML vide
     */
    public function onDisplayHeader(): string
    {
        $lastCheck = (int) \Configuration::get(self::CACHE_KEY_CALENDAR);
        $now       = time();

        if (($now - $lastCheck) < self::CALENDAR_CHECK_INTERVAL) {
            return '';
        }

        // Met à jour le timestamp AVANT de vérifier
        // Évite les vérifications parallèles sur sites à fort trafic
        \Configuration::updateValue(self::CACHE_KEY_CALENDAR, $now);

        try {
            $calendar = new CalendarManager($this->module);
            $calendar->checkAndSendDailyEvents();
        } catch (\Throwable $e) {
            $this->module->log(
                'HooksManager::onDisplayHeader (calendar) erreur → '
                . $e->getMessage(),
                2
            );
        }

        return '';
    }

    // ============================================================
    // HOOK : actionCronJob
    // ============================================================

    /**
     * Tâches planifiées via le cron PrestaShop
     * Plus fiable que displayHeader pour les tâches périodiques
     *
     * @param array $params ['task' => 'neria_calendar|neria_stats_cleanup|...']
     */
    public function onActionCronJob(array $params): void
    {
        $task = $params['task'] ?? '';

        switch ($task) {

            case 'neria_calendar':
                try {
                    $calendar = new CalendarManager($this->module);
                    $calendar->checkAndSendDailyEvents();
                } catch (\Throwable $e) {
                    $this->module->log(
                        'CronJob neria_calendar erreur → ' . $e->getMessage(),
                        3
                    );
                }
                break;

            case 'neria_stats_cleanup':
                try {
                    $stats = new StatsManager($this->module);
                    $stats->cleanup(365);
                } catch (\Throwable $e) {
                    $this->module->log(
                        'CronJob neria_stats_cleanup erreur → ' . $e->getMessage(),
                        3
                    );
                }
                break;

            case 'neria_stats_compute':
                try {
                    $stats = new StatsManager($this->module);
                    $stats->computeReports();
                } catch (\Throwable $e) {
                    $this->module->log(
                        'CronJob neria_stats_compute erreur → ' . $e->getMessage(),
                        3
                    );
                }
                break;
        }
    }

    // ============================================================
    // UTILITAIRES PRIVÉS
    // ============================================================

    /**
     * Construit le bloc JS contenant les variables neriaConfig
     * Disponibles dans neria-admin.js sous window.neriaConfig
     *
     * @return string Code JavaScript
     */
    private function buildJsVars(): string
    {
        $design = $this->config->getDesignConfig();

        $vars = [
            'moduleUrl'       => $this->module->getModuleUrl(),
            'adminUrl'        => $this->context->link->getAdminLink('AdminModules'),
            'moduleName'      => $this->module->name,
            'colorAccent'     => $design['color_accent'],
            'colorBackground' => $design['color_background'],
            'colorContainer'  => $design['color_container'],
            'colorText'       => $design['color_text'],
            'darkMode'        => (bool) $design['dark_mode'],
            'containerWidth'  => (int) $design['container_width'],
            'logoWidth'       => (int) $design['logo_width'],
            'statsEnabled'    => $this->config->isStatsEnabled(),
            'abtestEnabled'   => $this->config->isAbtestEnabled(),
            'previewLang'     => $this->context->language->iso_code ?? 'fr',
            'i18n'            => [
                'saved'         => $this->module->l('Sauvegardé avec succès'),
                'error'         => $this->module->l('Une erreur est survenue'),
                'confirm_reset' => $this->module->l(
                    'Réinitialiser ? Les textes personnalisés seront perdus.'
                ),
                'preview_lang'  => $this->module->l('Aperçu langue'),
                'loading'       => $this->module->l('Chargement...'),
            ],
        ];

        return 'var neriaConfig = '
            . json_encode($vars, JSON_UNESCAPED_UNICODE) . ';';
    }

    // ============================================================
    // MÉTHODES STATIQUES — appelées depuis neria.php
    // ============================================================

    /**
     * Retourne la liste complète des hooks à enregistrer
     * Utilisée par neria.php → registerHooks()
     *
     * @return array
     */
    public static function getHooksList(): array
    {
        return [
            'actionEmailSendBefore',
            'actionEmailSendAfter',
            'displayBackOfficeHeader',
            'displayHeader',
            'actionCronJob',
        ];
    }

    /**
     * Vérifie qu'un hook est bien enregistré pour ce module
     * Utilisé par l'onglet Aide du back-office (diagnostic)
     *
     * @param string $hookName Nom du hook
     * @return bool
     */
    public function isHookRegistered(string $hookName): bool
    {
        return (bool) \Hook::getIdByName($hookName)
            && $this->module->isRegisteredInHook($hookName);
    }

    /**
     * Retourne le statut de tous les hooks enregistrés
     * Affiché dans l'onglet Aide → section Diagnostic
     *
     * @return array ['hookName' => true/false, ...]
     */
    public function getHooksStatus(): array
    {
        $status = [];

        foreach (self::getHooksList() as $hook) {
            $status[$hook] = $this->isHookRegistered($hook);
        }

        return $status;
    }
}