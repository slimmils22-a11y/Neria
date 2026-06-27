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

    /**
     * Intervalle de traitement de la queue webhook (5 min)
     */
    const WEBHOOK_CHECK_INTERVAL = 300;

    /**
     * Clé Configuration:: pour le dernier traitement webhook
     */
    const CACHE_KEY_WEBHOOK = 'neria_webhook_last_process';

    // ============================================================
    // PROPRIÉTÉS
    // ============================================================

    /** @var Neria Instance du module principal */
    private Neria $module;

    /** @var \Context Contexte PrestaShop courant */
    private \Context $context;

    /** @var ConfigManager Gestionnaire de configuration */
    private ConfigManager $config;

    /** @var \WatchdogManager|null Instance paresseuse du watchdog */
    private ?\WatchdogManager $watchdog = null;

    // ============================================================
    // CONSTRUCTEUR
    // ============================================================

    public function __construct(Neria $module)
    {
        $this->module  = $module;
        $this->context = \Context::getContext();
        $this->config  = new ConfigManager($module);
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
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
            $tmpl        = $params['template'] ?? '?';
            $rawMsg      = $e->getMessage();
            $smtpHint    = self::translateSmtpError($rawMsg);
            $actionable  = $smtpHint
                ? ' → ' . $smtpHint
                : ' → Que faire : Consultez le journal Watchdog (onglet Aide) et les logs serveur PHP.';

            // Incrémenter le compteur d'échecs consécutifs
            $fails = (int) \Configuration::get(HealthCheckManager::CFG_CONSECUTIVE_FAILURES);
            \Configuration::updateValue(HealthCheckManager::CFG_CONSECUTIVE_FAILURES, $fails + 1);

            $this->watchdog()->error(
                sprintf(
                    'Erreur interception email (template : %s) : %s — PrestaShop a utilisé son rendu natif.%s',
                    $tmpl,
                    $rawMsg,
                    $actionable
                ),
                $params['template'] ?? '',
                'HooksManager'
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
        $now = time();

        // ── Queue webhook (toutes les 5 min) ─────────────────────────
        $lastWebhook = (int) \Configuration::get(self::CACHE_KEY_WEBHOOK);
        if (($now - $lastWebhook) >= self::WEBHOOK_CHECK_INTERVAL) {
            \Configuration::updateValue(self::CACHE_KEY_WEBHOOK, $now);
            try {
                (new WebhookManager($this->module))->processQueue();
            } catch (\Throwable $e) {
                // best-effort — ne doit jamais bloquer le front
            }
        }

        // ── Digest Watchdog (1×/jour, best-effort) ──────────────────
        try {
            (new WatchdogManager($this->module))->sendDailyDigestIfDue();
        } catch (\Throwable $e) {
            // silencieux — ne doit jamais bloquer le front
        }

        // ── Calendaire + comportemental (1×/jour) ─────────────────────
        $lastCheck = (int) \Configuration::get(self::CACHE_KEY_CALENDAR);
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
            $this->watchdog()->error(
                'CalendarManager a planté lors de la vérification quotidienne : ' . $e->getMessage()
                . ' — Aucun email calendaire n\'a été envoyé aujourd\'hui.',
                '', 'HooksManager'
            );
        }

        try {
            $behavioral = new BehavioralCronManager($this->module);
            $behavioral->run();
        } catch (\Throwable $e) {
            $this->watchdog()->error(
                'BehavioralCronManager a planté lors de l\'exécution quotidienne : ' . $e->getMessage()
                . ' — Aucun email comportemental n\'a été envoyé aujourd\'hui.',
                '', 'HooksManager'
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
     * @param array $params ['task' => 'neria_calendar|neria_stats_cleanup|neria_behavioral|...']
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
                    $this->watchdog()->error(
                        'Cron neria_calendar : CalendarManager a planté — ' . $e->getMessage()
                        . '. Aucun email calendaire envoyé. Vérifiez les logs serveur.',
                        '', 'HooksManager'
                    );
                }
                break;

            case 'neria_behavioral':
                try {
                    $behavioral = new BehavioralCronManager($this->module);
                    $behavioral->run();
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Cron neria_behavioral : BehavioralCronManager a planté — ' . $e->getMessage()
                        . '. Aucun email comportemental envoyé. Vérifiez les logs serveur.',
                        '', 'HooksManager'
                    );
                }
                // Résumé quotidien MPP Apple
                try {
                    $statTable  = _DB_PREFIX_ . 'neria_stat';
                    $yesterday  = date('Y-m-d', strtotime('-1 day'));
                    $mppCount   = (int) \Db::getInstance()->getValue(
                        "SELECT COUNT(*) FROM `{$statTable}`
                         WHERE `event_type` = 'open' AND `is_mpp` = 1
                           AND DATE(`date_add`) = '{$yesterday}'"
                    );
                    $realCount  = (int) \Db::getInstance()->getValue(
                        "SELECT COUNT(*) FROM `{$statTable}`
                         WHERE `event_type` = 'open' AND `is_mpp` = 0
                           AND DATE(`date_add`) = '{$yesterday}'"
                    );
                    if ($mppCount > 0) {
                        $total   = $mppCount + $realCount;
                        $mppRate = $total > 0 ? round($mppCount / $total * 100) : 0;
                        if ($mppRate >= 80) {
                            $this->watchdog()->warning(
                                sprintf(
                                    'MPP Apple — Taux anormalement élevé : %d%% des ouvertures hier étaient MPP (%d/%d). Vérifiez votre liste d\'envoi.',
                                    $mppRate, $mppCount, $total
                                ),
                                '', 'MPP'
                            );
                        } else {
                            $this->watchdog()->info(
                                sprintf(
                                    'MPP Apple — Hier : %d ouverture(s) MPP exclues, %d ouverture(s) réelles (%d%% MPP).',
                                    $mppCount, $realCount, $mppRate
                                ),
                                '', 'MPP'
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Cron MPP : erreur lors du résumé quotidien — ' . $e->getMessage(),
                        '', 'MPP'
                    );
                }
                // Vérification des conversions upsell (fenêtre 7 jours)
                try {
                    if (class_exists('UpsellManager')) {
                        $converted = (new \UpsellManager($this->module))->checkConversions();
                        if ($converted > 0) {
                            $this->watchdog()->info(
                                sprintf('Upsell — %d conversion(s) enregistrée(s) aujourd\'hui.', $converted),
                                '', 'Upsell'
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Upsell : erreur lors de la vérification des conversions — ' . $e->getMessage(),
                        '', 'Upsell'
                    );
                }

                // Recap mensuel fidélité
                try {
                    if (class_exists('LoyaltyManager') && \Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')) {
                        $sent = (new \LoyaltyManager($this->module))->sendMonthlyRecaps();
                        if ($sent > 0) {
                            $this->watchdog()->info(
                                sprintf('Fidélité recap — %d email(s) de récapitulatif mensuel envoyé(s).', $sent),
                                'loyalty_recap', 'Loyalty'
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Fidélité recap : erreur lors de l\'envoi des récapitulatifs — ' . $e->getMessage(),
                        'loyalty_recap', 'Loyalty'
                    );
                }

                // Réputation de domaine — actualisation quotidienne
                try {
                    if (class_exists('DomainReputationManager')) {
                        $repMgr = new \DomainReputationManager($this->module);
                        // Actualiser seulement si le cache est vieux (>20h) ou absent
                        $lastCheck = (int) \Configuration::get(\DomainReputationManager::CONFIG_LAST_CHECK);
                        if (!$lastCheck || (time() - $lastCheck) > 72000) {
                            $rep     = $repMgr->runFullCheck();
                            $hits    = count($rep['blacklists']['hits'] ?? []);
                            $score   = $rep['score'];
                            $msg     = sprintf(
                                'Réputation domaine %s : %d/100 (%s) — %d liste(s) noire(s) touchée(s).',
                                $rep['domain'] ?? '',
                                $score,
                                $rep['grade'],
                                $hits
                            );
                            if ($score < 50 || $hits > 0) {
                                $this->watchdog()->error($msg, '', 'DomainReputation');
                            } elseif ($score < 75) {
                                $this->watchdog()->warning($msg, '', 'DomainReputation');
                            } else {
                                $this->watchdog()->info($msg, '', 'DomainReputation');
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Réputation domaine : erreur cron — ' . $e->getMessage(),
                        '', 'DomainReputation'
                    );
                }

                // Campagnes saisonnières annuelles
                try {
                    if (class_exists('SeasonalCampaignManager')) {
                        $seasonalSent = (new \SeasonalCampaignManager($this->module))->runDueCampaigns();
                        if ($seasonalSent > 0) {
                            $this->watchdog()->info(
                                sprintf('Campagnes saisonnières — %d email(s) envoyé(s) aujourd\'hui.', $seasonalSent),
                                '', 'SeasonalCampaign'
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Campagnes saisonnières : erreur — ' . $e->getMessage(),
                        '', 'SeasonalCampaign'
                    );
                }

                // Vérification quotidienne de la rétention RGPD
                try {
                    if (class_exists('GdprAuditManager')) {
                        $modulePath = rtrim($this->module->getLocalPath(), '/\\');
                        $retention  = (new \GdprAuditManager($modulePath))->auditRetention();
                        foreach ($retention['rows'] as $row) {
                            if ($row['overdue'] > 0) {
                                $this->watchdog()->warning(
                                    sprintf(
                                        'RGPD — Rétention dépassée : %d enregistrement(s) à purger dans "%s" (limite légale : %d mois). Rendez-vous dans l\'onglet RGPD pour purger.',
                                        $row['overdue'],
                                        $row['label'],
                                        $row['months']
                                    ),
                                    '', 'RGPD'
                                );
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Cron RGPD : erreur lors de la vérification des rétentions — ' . $e->getMessage(),
                        '', 'RGPD'
                    );
                }
                break;

            case 'neria_stats_cleanup':
                try {
                    $stats = new StatsManager($this->module);
                    $stats->cleanup(365);
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Cron neria_stats_cleanup : erreur nettoyage statistiques — ' . $e->getMessage(),
                        '', 'HooksManager'
                    );
                }
                break;

            case 'neria_stats_compute':
                try {
                    $stats = new StatsManager($this->module);
                    $stats->computeReports();
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Cron neria_stats_compute : erreur calcul rapports — ' . $e->getMessage(),
                        '', 'HooksManager'
                    );
                }
                break;

            case 'neria_webhook':
                try {
                    (new WebhookManager($this->module))->processQueue();
                } catch (\Throwable $e) {
                    $this->watchdog()->error(
                        'Cron neria_webhook : erreur traitement queue webhook — ' . $e->getMessage(),
                        '', 'HooksManager'
                    );
                }
                break;

            default:
                if (!empty($task)) {
                    $this->watchdog()->warning(
                        "Tâche cron inconnue reçue : \"{$task}\" — Neria ne connaît pas cette tâche. Vérifiez la configuration de votre cron.",
                        '', 'HooksManager'
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
                'saved'         => AdminTranslator::t('msg.saved'),
                'error'         => AdminTranslator::t('msg.error'),
                'confirm_reset' => AdminTranslator::t('msg.confirm_reset_generic'),
                'preview_lang'  => AdminTranslator::t('msg.preview_lang'),
                'loading'       => AdminTranslator::t('common.loading'),
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

    /**
     * Traduit un code d'erreur SMTP brut en message compréhensible + action corrective.
     * Retourne une chaîne vide si aucun code connu n'est détecté.
     */
    public static function translateSmtpError(string $message): string
    {
        $smtpCodes = [
            '550' => 'Adresse destinataire invalide ou inexistante (code 550).'
                . ' Vérifiez l\'adresse email et activez la détection de bounces dans l\'onglet Bounces.',
            '551' => 'Utilisateur non local — l\'adresse n\'existe pas sur ce serveur (code 551).',
            '552' => 'Quota de stockage dépassé chez le destinataire (code 552).'
                . ' Rien à faire côté expéditeur — réessayez dans 24h.',
            '553' => 'Adresse email malformée (code 553).'
                . ' Vérifiez la syntaxe de l\'adresse destinataire.',
            '554' => 'Transaction rejetée — message considéré comme spam (code 554).'
                . ' Vérifiez le score de délivrabilité dans l\'onglet Statistiques.',
            '421' => 'Serveur SMTP temporairement indisponible (code 421).'
                . ' Réessayez dans quelques minutes. Si le problème persiste, contactez votre hébergeur SMTP.',
            '450' => 'Boîte du destinataire temporairement indisponible (code 450). Réessayez plus tard.',
            '451' => 'Erreur serveur temporaire (code 451) — probablement une liste noire temporaire.'
                . ' Vérifiez la réputation de domaine dans l\'onglet Statistiques.',
            '452' => 'Serveur SMTP saturé — quota d\'envoi atteint (code 452).'
                . ' Réduisez le volume d\'envoi ou upgraderez votre offre SMTP.',
            '535' => 'Authentification SMTP échouée (code 535).'
                . ' Vérifiez le nom d\'utilisateur et le mot de passe dans Paramètres → Email.',
            '530' => 'Authentification requise (code 530).'
                . ' Activez l\'authentification SMTP dans Paramètres → Email.',
            'connection refused' => 'Connexion SMTP refusée — le serveur est inaccessible.'
                . ' Vérifiez l\'hôte et le port SMTP dans Paramètres → Email.',
            'connection timed out' => 'Timeout de connexion SMTP.'
                . ' Le port est peut-être bloqué par votre hébergeur. Essayez le port 587 (STARTTLS) ou 465 (SSL).',
            'ssl' => 'Erreur SSL/TLS sur la connexion SMTP.'
                . ' Vérifiez le chiffrement configuré (SSL/TLS ou STARTTLS) dans Paramètres → Email.',
        ];

        $lower = strtolower($message);
        foreach ($smtpCodes as $pattern => $hint) {
            if (strpos($lower, (string) $pattern) !== false || strpos($message, (string) $pattern) !== false) {
                return $hint;
            }
        }

        return '';
    }
}