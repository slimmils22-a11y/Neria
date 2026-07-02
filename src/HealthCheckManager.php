<?php
/**
 * NERIA — HealthCheckManager
 *
 * Diagnostic actif : contrôles proactifs qui vérifient que les mécanismes
 * clés du module produisent bien les résultats attendus, pas seulement
 * qu'ils se terminent sans exception.
 *
 * 8 contrôles automatiques (1×/jour via hookDisplayHeader) + 1 manuel.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HealthCheckManager
{
    const CONFIG_LAST_RUN  = 'NERIA_HEALTH_LAST_RUN';
    const CONFIG_RESULTS   = 'NERIA_HEALTH_RESULTS';
    const CONFIG_HDR_LAST  = 'NERIA_DISPLAY_HEADER_LAST_RUN';
    const THROTTLE_SECONDS = 86400; // 24 h

    // Clés de suivi des crons individuels
    const CRON_LAST_BEHAVIORAL = 'NERIA_CRON_LAST_BEHAVIORAL';
    const CRON_LAST_CALENDAR   = 'NERIA_CRON_LAST_CALENDAR';
    const CRON_LAST_BOUNCES    = 'NERIA_CRON_LAST_BOUNCES';
    const CRON_LAST_DOMREP     = 'NERIA_CRON_LAST_DOMREP';

    // Suivi des échecs consécutifs d'envoi
    const CFG_CONSECUTIVE_FAILURES = 'NERIA_CONSECUTIVE_FAILURES';
    const CONSECUTIVE_THRESHOLD    = 3;

    // Seuil taux de bounce (%)
    const BOUNCE_RATE_THRESHOLD = 5;

    const STATUS_OK      = 'ok';
    const STATUS_WARNING = 'warning';
    const STATUS_ERROR   = 'error';

    private Neria          $module;
    private \Db            $db;
    private int            $idShop;
    private WatchdogManager $watchdog;

    public function __construct(Neria $module)
    {
        $this->module   = $module;
        $this->db       = \Db::getInstance();
        $this->idShop   = (int) \Context::getContext()->shop->id;
        $this->watchdog = new WatchdogManager($module);
    }

    // ============================================================
    // API PUBLIQUE
    // ============================================================

    /**
     * Enregistre l'heure du dernier hookDisplayHeader (cron-like).
     * Appelé directement dans hookDisplayHeader() de neria.php.
     */
    public function recordDisplayHeaderRun(): void
    {
        \Configuration::updateValue(self::CONFIG_HDR_LAST, date('Y-m-d H:i:s'));
    }

    /**
     * Lance les 8 contrôles automatiques si 24 h se sont écoulées.
     */
    public function runAutoChecksIfDue(): void
    {
        $lastRun = (string) \Configuration::get(self::CONFIG_LAST_RUN);
        if ($lastRun && (time() - (int) strtotime($lastRun)) < self::THROTTLE_SECONDS) {
            return;
        }

        \Configuration::updateValue(self::CONFIG_LAST_RUN, date('Y-m-d H:i:s'));

        $results = $this->buildAllChecks();

        \Configuration::updateValue(self::CONFIG_RESULTS, json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->logResultsToWatchdog($results);
    }

    /**
     * Diagnostic complet à la demande — ignore le throttle 24h.
     * Utilisé par le bouton "Diagnostic complet" dans l'onglet Aide.
     */
    public function runFullDiagnostic(): array
    {
        \Configuration::updateValue(self::CONFIG_LAST_RUN, date('Y-m-d H:i:s'));
        $results = $this->buildAllChecks();
        \Configuration::updateValue(self::CONFIG_RESULTS, json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->logResultsToWatchdog($results);
        return $results;
    }

    /**
     * Retourne les résultats stockés du dernier contrôle automatique.
     */
    public function getLastResults(): array
    {
        $raw = (string) \Configuration::get(self::CONFIG_RESULTS);
        if ($raw === '' || $raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Contrôle manuel #2 : test HTTP du pixel de tracking.
     * Doit être déclenché explicitement (bouton BO) — trop coûteux pour le cron.
     */
    public function testPixelHttp(): array
    {
        $base = \Tools::getShopDomainSsl(true);
        $path = '/index.php?fc=module&module=neria&controller=track&token=_diag_';

        foreach ([$base . $path, str_replace('https://', 'http://', $base . $path)] as $url) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 5, 'ignore_errors' => true],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);

            $body    = @file_get_contents($url, false, $ctx);
            $headers = $http_response_header ?? [];
            $status  = '';
            foreach ($headers as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                    $status = $m[1];
                }
            }

            if ($body !== false && in_array($status, ['200', '204'], true)) {
                return [
                    'status' => self::STATUS_OK,
                    'detail' => AdminTranslator::tVars('health.pixel_http_ok', ['status' => $status, 'size' => strlen($body)]),
                ];
            }
        }

        return [
            'status' => self::STATUS_ERROR,
            'detail' => AdminTranslator::t('health.pixel_http_error'),
        ];
    }

    // ============================================================
    // CONSTRUCTION DE LA SUITE DE CONTRÔLES
    // ============================================================

    private function buildAllChecks(): array
    {
        return [
            // ── Flux email ──────────────────────────────────────────
            'sent_reconciliation'  => $this->checkSentReconciliation(),
            'pixel_in_html'        => $this->checkPixelInHtml(),
            'theme_override'       => $this->checkThemeOverride(),
            'template_files'       => $this->checkTemplateFiles(),
            'trad_keys'            => $this->checkTradKeys(),
            'open_rate_7d'         => $this->checkOpenRate7d(),
            'bounce_rate'          => $this->checkBounceRate(),
            'consecutive_failures' => $this->checkConsecutiveFailures(),
            // ── Infrastructure ─────────────────────────────────────
            'hooks_registered'     => $this->checkHooksRegistered(),
            'cron_triggered'       => $this->checkCronTriggered(),
            'crons_health'         => $this->checkCronsHealth(),
            'queue_blocked'        => $this->checkQueueBlocked(),
            'ajax_endpoints'       => $this->checkAjax(),
            'bounces_unprocessed'  => $this->checkBouncesUnprocessed(),
            // ── Configuration & sécurité ───────────────────────────
            'config_keys'          => $this->checkConfigKeys(),
            'version_sync'         => $this->checkVersionSync(),
            'hmac_security'        => $this->checkHmacSecurity(),
            'smtp_config'          => $this->checkSmtpConfig(),
            'list_unsubscribe'     => $this->checkListUnsubscribeApi(),
            'translation_gaps'     => $this->checkTranslationGaps(),
            // ── Ressources ─────────────────────────────────────────
            'assets'               => $this->checkAssets(),
            'managers_available'   => $this->checkManagersAvailable(),
            'critical_methods'     => $this->checkCriticalMethods(),
            // ── Surveillance avancée ────────────────────────────────
            'webhook_failures'     => $this->checkWebhookFailures(),
            'abtest_stuck'         => $this->checkAbtestStuck(),
            'crypto_key'           => $this->checkCryptoKey(),
            'send_volume_spike'    => $this->checkSendVolumeSpike(),
            'domain_rep_score'     => $this->checkDomainRepScore(),
            'ptr_record'           => $this->checkPtrRecord(),
            'db_tables'            => $this->checkDbTables(),
            'unsubscribe_url'      => $this->checkUnsubscribeUrl(),
            'waitlist_backlog'     => $this->checkWaitlistBacklog(),
            'smtp_quota'           => $this->checkSmtpQuota(),
            'postmaster_rep'       => $this->checkPostmasterReputation(),
            // ── Flux email avancé ──────────────────────────────────────
            'click_rate_7d'        => $this->checkClickRate7d(),
            'unsubscribe_spike'    => $this->checkUnsubscribeSpike(),
            'fallback_template'    => $this->checkFallbackTemplate(),
            'front_controllers'    => $this->checkFrontControllers(),
            // ── Infrastructure avancée ─────────────────────────────────
            'queue_overflow'       => $this->checkQueueOverflow(),
            'behavioral_dedup'     => $this->checkBehavioralDedupSize(),
            // ── Configuration avancée ──────────────────────────────────
            'multi_sender_json'    => $this->checkMultiSenderJson(),
            'monthly_report_cfg'   => $this->checkMonthlyReportConfig(),
            'deepl_key_valid'      => $this->checkDeeplKeyValid(),
            'php_memory_limit'     => $this->checkPhpMemoryLimit(),
            // ── Sous-systèmes ──────────────────────────────────────────
            'loyalty_integrity'    => $this->checkLoyaltyIntegrity(),
            'segment_freshness'    => $this->checkSegmentFreshness(),
            'clv_freshness'        => $this->checkClvFreshness(),
            'quote_reminders'      => $this->checkQuoteRemindersStuck(),
            'campaign_empty_seg'   => $this->checkCampaignEmptySegment(),
            // ── Qualité des données ────────────────────────────────────
            'attribution_coverage' => $this->checkAttributionCoverage(),
            'history_table_size'   => $this->checkTranslationHistorySize(),
            'abtest_trad_gaps'     => $this->checkAbtestTranslationGaps(),
            // ── Contrôles proactifs ─────────────────────────────────────
            'engagement_trend'     => $this->checkEngagementTrend(),
            'oauth_freshness'      => $this->checkOAuthFreshness(),
        ];
    }

    // ============================================================
    // CONTRÔLES AUTOMATIQUES
    // ============================================================

    /**
     * #1 — Réconciliation envois
     * Si le module est actif depuis 48 h et ps_neria_stat est vide, le
     * pipeline de tracking est probablement cassé.
     */
    private function checkSentReconciliation(): array
    {
        $installedAt = (string) \Configuration::get('NERIA_INSTALLED_AT');
        $hoursOld    = $installedAt
            ? (time() - (int) strtotime($installedAt)) / 3600
            : 9999;

        $totalSent = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_stat`
             WHERE `id_shop` = {$this->idShop} AND `event_type` = 'sent'"
        );

        if ($totalSent === 0 && $hoursOld >= 48) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.sent_warning', ['days' => round($hoursOld / 24)]),
            ];
        }

        $recentSent = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_stat`
             WHERE `id_shop` = {$this->idShop} AND `event_type` = 'sent'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.sent_ok', ['total' => $totalSent, 'recent' => $recentSent]),
        ];
    }

    /**
     * #3 — Pixel de tracking : vérifie que le contrôleur track est accessible
     * et que le module a bien enregistré le hook actionEmailSendBefore.
     * On ne compile plus le HTML en preview (le pixel est injecté par le hook
     * au moment de Mail::Send, pas dans le rendu statique).
     */
    private function checkPixelInHtml(): array
    {
        try {
            // Vérifier que track.php existe dans le module
            $trackFile = _PS_MODULE_DIR_ . 'neria/controllers/front/track.php';
            if (!file_exists($trackFile)) {
                return [
                    'status' => self::STATUS_ERROR,
                    'detail' => AdminTranslator::t('health.pixel_error'),
                ];
            }

            // Vérifier que le hook actionEmailSendBefore est bien enregistré
            $hooked = (int) \Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'hook_module` hm
                 JOIN `' . _DB_PREFIX_ . 'hook` h ON h.id_hook = hm.id_hook
                 WHERE h.name = \'actionEmailSendBefore\'
                   AND hm.id_module = ' . (int) $this->module->id
            );

            if ($hooked > 0) {
                return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.pixel_ok')];
            }

            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::t('health.pixel_error'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.pixel_compile_error', ['error' => $e->getMessage()]),
            ];
        }
    }

    /**
     * #4 — Surcharges de thème
     * Une surcharge dans themes/ prend le dessus sur les fichiers recompilés
     * — c'est presque toujours un piège pour l'architecture Neria.
     */
    private function checkThemeOverride(): array
    {
        $themesDir = rtrim(_PS_ROOT_DIR_, '/') . '/themes';
        $found     = [];

        if (is_dir($themesDir)) {
            foreach (glob($themesDir . '/*/modules/neria/mails') ?: [] as $dir) {
                $found[] = str_replace(_PS_ROOT_DIR_ . '/', '', $dir);
            }
        }

        if ($found) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.theme_warning', ['paths' => implode(', ', $found)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.theme_ok')];
    }

    /**
     * #5 — Trous dans les traductions back-office
     * AdminTranslator retourne la clé brute si une traduction manque.
     * Un marchand japonais pourrait voir "history.title" sans que personne
     * ne le sache.
     */
    private function checkTranslationGaps(): array
    {
        $langs    = TranslationEngine::SUPPORTED_LANGS;
        $jsonPath = __DIR__ . '/../data/admin_translations.json';

        if (!is_file($jsonPath)) {
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::t('health.trad_missing')];
        }

        $raw  = file_get_contents($jsonPath);
        $dict = $raw !== false ? (json_decode($raw, true) ?: []) : [];
        $totalKeys = count($dict);

        $gaps = [];
        foreach ($langs as $lang) {
            $missing = 0;
            foreach ($dict as $translations) {
                if (empty($translations[$lang])) {
                    $missing++;
                }
            }
            if ($missing > 0) {
                $gaps[$lang] = $missing;
            }
        }

        if (empty($gaps)) {
            return [
                'status' => self::STATUS_OK,
                'detail' => AdminTranslator::tVars('health.trad_ok', ['total' => $totalKeys, 'count' => count($langs)]),
            ];
        }

        $maxGap = max($gaps);
        $status = $maxGap > 50 ? self::STATUS_ERROR : self::STATUS_WARNING;
        $parts  = [];
        foreach ($gaps as $lang => $n) {
            $parts[] = $lang . '(' . $n . ')';
        }

        return [
            'status' => $status,
            'detail' => AdminTranslator::tVars('health.trad_warning', ['max' => $maxGap, 'detail' => implode(', ', $parts)]),
        ];
    }

    /**
     * #6 — Déclenchement du cron-like (hookDisplayHeader)
     * CalendarManager et MonthlyReportManager dépendent du trafic front.
     * Une boutique en pré-lancement ne les déclenche jamais.
     */
    private function checkCronTriggered(): array
    {
        $lastRun = (string) \Configuration::get(self::CONFIG_HDR_LAST);

        if ($lastRun === '' || $lastRun === false) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::t('health.cron_never'),
            ];
        }

        $hoursAgo = (time() - (int) strtotime($lastRun)) / 3600;

        if ($hoursAgo > 168) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.cron_stale', ['days' => round($hoursAgo / 24)]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.cron_ok', ['datetime' => (new \DateTime($lastRun))->format('d/m H:i')]),
        ];
    }

    /**
     * #7 — Clés de configuration manquantes en base
     * Vérifie et recrée automatiquement toute clé NERIA_* absente.
     */
    private function checkConfigKeys(): array
    {
        $expected = [
            'NERIA_ACTIVE'           => 1,
            'NERIA_COLOR_ACCENT'     => '#b38b59',
            'NERIA_COLOR_BACKGROUND' => '#f4f1eb',
            'NERIA_DARK_MODE'        => 0,
            'NERIA_CONTAINER_WIDTH'  => 620,
            'NERIA_STATS_ENABLED'    => 1,
            'NERIA_ABTEST_ENABLED'   => 0,
            'NERIA_AUTO_LANG'        => 1,
            'NERIA_LOG_INTERNAL'     => 0,
            'NERIA_VOUCHER_VALIDITY' => 30,
            MonthlyReportManager::CONFIG_ENABLED    => 1,
            MonthlyReportManager::CONFIG_RECIPIENTS => '',
        ];

        $recreated = [];
        foreach ($expected as $key => $default) {
            if (\Configuration::get($key) === false) {
                \Configuration::updateValue($key, $default);
                $recreated[] = $key;
            }
        }

        if ($recreated) {
            return [
                'status'     => self::STATUS_WARNING,
                'detail'     => AdminTranslator::tVars('health.config_fixed', ['count' => count($recreated), 'keys' => implode(', ', $recreated)]),
                'auto_fixed' => true,
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.config_ok', ['count' => count($expected)]),
        ];
    }

    /**
     * #8 — Compatibilité API List-Unsubscribe (Swift_Message)
     * Si PS migre vers Symfony Mailer, getHeaders()/addTextHeader() peuvent
     * disparaître ou changer de signature silencieusement.
     */
    private function checkListUnsubscribeApi(): array
    {
        if (!class_exists('Swift_Message')) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::t('health.swift_missing'),
            ];
        }

        try {
            $ref     = new \ReflectionClass('Swift_Message');
            $missing = [];
            foreach (['getHeaders', 'getTo'] as $method) {
                if (!$ref->hasMethod($method)) {
                    $missing[] = $method;
                }
            }

            if ($missing) {
                return [
                    'status' => self::STATUS_ERROR,
                    'detail' => AdminTranslator::tVars('health.swift_broken', ['methods' => implode(', ', $missing)]),
                ];
            }

            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.swift_ok')];
        } catch (\Throwable $e) {
            return ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::tVars('health.swift_error', ['error' => $e->getMessage()])];
        }
    }

    /**
     * #9 — Fichiers médias manquants (logo + signature active)
     * Image cassée dans l'email, sans erreur PHP.
     */
    private function checkAssets(): array
    {
        $issues     = [];
        $modulePath = rtrim($this->module->getLocalPath(), '/');

        // Logo personnalisé
        $logoPath = (string) \Configuration::get('NERIA_LOGO_PATH');
        if ($logoPath !== '' && $logoPath !== false) {
            if (!file_exists($modulePath . '/' . ltrim($logoPath, '/'))) {
                $issues[] = AdminTranslator::tVars('health.logo_missing', ['path' => $logoPath]);
            }
        }

        // Signature active
        $sigRow = $this->db->getRow(
            "SELECT `image_path` FROM `" . _DB_PREFIX_ . "neria_signature`
             WHERE `id_shop` = {$this->idShop} AND `is_active` = 1"
        );
        if ($sigRow && !empty($sigRow['image_path'])) {
            $full = $modulePath . '/' . ltrim($sigRow['image_path'], '/');
            if (!file_exists($full)) {
                $issues[] = AdminTranslator::tVars('health.signature_missing', ['path' => $sigRow['image_path']]);
            }
        }

        if ($issues) {
            return ['status' => self::STATUS_WARNING, 'detail' => implode(' ; ', $issues)];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.assets_ok')];
    }

    /**
     * #10 — Surveillance des crons métier individuels
     * Vérifie que chaque cron a bien tourné au cours des dernières 26h.
     */
    private function checkCronsHealth(): array
    {
        // Crons automatiques (hookDisplayHeader) — alertes si absent >26h
        $autoCrons = [
            self::CRON_LAST_BEHAVIORAL => 'Emails comportementaux (anniversaires, paniers abandonnés…)',
            self::CRON_LAST_CALENDAR   => 'Emails calendaires (Noël, Saint-Valentin…)',
            self::CRON_LAST_DOMREP     => 'Score de réputation de domaine',
        ];

        $stale = [];
        $never = [];
        $limit = 26 * 3600;

        foreach ($autoCrons as $key => $label) {
            $last = (string) \Configuration::get($key);
            if ($last === '' || $last === false) {
                $never[] = $label;
            } elseif ((time() - (int) strtotime($last)) > $limit) {
                $hoursAgo = round((time() - (int) strtotime($last)) / 3600);
                $stale[]  = $label . ' (dernière exéc. il y a ' . $hoursAgo . 'h)';
            }
        }

        if ($never) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Ces tâches automatiques n\'ont jamais tourné : ' . implode(', ', $never)
                    . ' → Que faire : Ces tâches se déclenchent automatiquement dès qu\'un visiteur'
                    . ' charge une page de la boutique. Si votre boutique est inactive depuis l\'installation,'
                    . ' ouvrez-la dans un navigateur pour déclencher le premier cycle.',
            ];
        }

        if ($stale) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Ces tâches automatiques sont en retard : ' . implode('; ', $stale)
                    . ' → Que faire : Ces tâches se déclenchent via les visites frontend.'
                    . ' Si votre boutique a du trafic, vérifiez les logs d\'erreur PHP'
                    . ' (erreur fatale dans hookDisplayHeader ?).',
            ];
        }

        // Cron manuel bounces IMAP — alerte seulement si déjà utilisé et en retard
        $lastBounces = (string) \Configuration::get(self::CRON_LAST_BOUNCES);
        if ($lastBounces && (time() - (int) strtotime($lastBounces)) > 72 * 3600) {
            $hoursAgo = round((time() - (int) strtotime($lastBounces)) / 3600);
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Vérification bounces IMAP en retard (dernière exéc. il y a ' . $hoursAgo . 'h).'
                    . ' → Que faire : Cliquez sur "Vérifier les bounces" dans l\'onglet Aide,'
                    . ' ou configurez un cron externe pour automatiser cette vérification.',
            ];
        }

        $bounceInfo = $lastBounces
            ? 'Vérification bounces IMAP — dernier passage : ' . $lastBounces . '.'
            : 'Vérification bounces IMAP — jamais lancée (normal si vous n\'utilisez pas l\'IMAP).';

        return ['status' => self::STATUS_OK, 'detail' => 'Toutes les tâches automatiques se sont exécutées dans les 26 dernières heures. ' . $bounceInfo];
    }

    /**
     * #11 — Configuration SMTP
     * Détecte les configurations vides ou non testées susceptibles de faire
     * échouer tous les envois sans message d'erreur explicite.
     */
    private function checkSmtpConfig(): array
    {
        $method = (string) \Configuration::get('PS_MAIL_METHOD');

        // Méthode 1 = PHP mail() — valide mais sans retour d'erreur SMTP
        if ($method === '1') {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Envoi via PHP mail() (méthode basique).'
                    . ' → Que faire : Configurez un serveur SMTP dédié dans Paramètres → Email'
                    . ' pour améliorer la délivrabilité et recevoir les erreurs de rebond.',
            ];
        }

        // Méthode 2 = SMTP
        if ($method === '2') {
            $server = (string) \Configuration::get('PS_MAIL_SERVER');
            $user   = (string) \Configuration::get('PS_MAIL_USER');

            if ($server === '') {
                return [
                    'status' => self::STATUS_ERROR,
                    'detail' => 'SMTP activé mais aucun serveur configuré.'
                        . ' → Que faire : Renseignez l\'hôte SMTP dans Paramètres → Email → Serveur SMTP.',
                ];
            }

            if ($user === '') {
                return [
                    'status' => self::STATUS_WARNING,
                    'detail' => 'Serveur SMTP configuré (' . $server . ') mais sans identifiant utilisateur.'
                        . ' → Que faire : Ajoutez l\'identifiant SMTP dans Paramètres → Email.',
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'detail' => 'SMTP configuré : ' . $server . ' (utilisateur : ' . $user . ').',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'Configuration email : méthode ' . $method . '.'];
    }

    /**
     * #12 — Taux de bounce sur 24h
     * Si plus de 5% des emails envoyés rebondissent, c'est un signal fort
     * de problème de qualité de liste ou de réputation de domaine.
     */
    private function checkBounceRate(): array
    {
        $sent24h = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_stat`
             WHERE `id_shop` = {$this->idShop}
               AND `event_type` = 'sent'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        if ($sent24h < 20) {
            return [
                'status' => self::STATUS_OK,
                'detail' => 'Moins de 20 envois dans les dernières 24h — analyse du taux de bounce non significative.',
            ];
        }

        $bounces24h = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_bounces`
             WHERE `last_bounce_at` > DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND `status` = 'active'"
        );

        $rate = round($bounces24h / $sent24h * 100, 1);

        if ($rate >= self::BOUNCE_RATE_THRESHOLD) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Taux de bounce : ' . $rate . '% sur 24h (' . $bounces24h . ' bounces / ' . $sent24h . ' envois).'
                    . ' → Que faire : Nettoyez votre liste d\'abonnés, vérifiez la réputation'
                    . ' de votre domaine dans l\'onglet Statistiques, et envisagez un double opt-in.',
            ];
        }

        if ($rate >= 2) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Taux de bounce : ' . $rate . '% sur 24h (' . $bounces24h . ' bounces / ' . $sent24h . ' envois).'
                    . ' → Que faire : Surveillez l\'évolution. Un taux > 5% dégrade sérieusement la délivrabilité.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => 'Taux de bounce : ' . $rate . '% sur 24h (' . $bounces24h . ' / ' . $sent24h . ' envois). Excellent.',
        ];
    }

    /**
     * #13 — Échecs consécutifs d'envoi
     * Plusieurs échecs à la suite indiquent un problème systémique
     * (SMTP down, quota dépassé, hook cassé).
     */
    private function checkConsecutiveFailures(): array
    {
        $count = (int) \Configuration::get(self::CFG_CONSECUTIVE_FAILURES);

        if ($count === 0) {
            return ['status' => self::STATUS_OK, 'detail' => 'Aucun échec consécutif de rendu détecté.'];
        }

        if ($count >= self::CONSECUTIVE_THRESHOLD) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => $count . ' échecs de rendu consécutifs détectés.'
                    . ' → Que faire : Consultez le journal Watchdog pour identifier le template'
                    . ' en erreur. Vérifiez les variables Smarty manquantes et les permissions'
                    . ' d\'écriture sur le dossier mails/. Un email de secours a été envoyé à chaque échec.',
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => $count . ' échec(s) de rendu récent(s) — sous le seuil critique ('
                . self::CONSECUTIVE_THRESHOLD . ').'
                . ' → Que faire : Vérifiez le journal pour identifier le template concerné.',
        ];
    }

    /**
     * #16 — Fichiers templates sur disque
     * Un .html ou .txt manquant → email part vide ou plante silencieusement.
     */
    private function checkTemplateFiles(): array
    {
        $mailsDir = rtrim($this->module->getLocalPath(), '/') . '/mails/themes/neria_global/core/';
        $templates = \NeriaTools::getTemplateLabels();
        $missing = [];

        foreach (array_keys($templates) as $tpl) {
            if (!file_exists($mailsDir . $tpl . '.html')) {
                $missing[] = $tpl . '.html';
            }
            if (!file_exists($mailsDir . $tpl . '.txt')) {
                $missing[] = $tpl . '.txt';
            }
        }

        if ($missing) {
            $count = count($missing);
            return [
                'status' => self::STATUS_ERROR,
                'detail' => $count . ' fichier(s) template manquant(s) sur disque : '
                    . implode(', ', array_slice($missing, 0, 5)) . ($count > 5 ? '… (' . ($count - 5) . ' autres)' : '')
                    . ' → Que faire : Réinstallez ou réuploadez le dossier mails/themes/neria_global/core/.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => count($templates) . ' templates présents sur disque (HTML + TXT).',
        ];
    }

    /**
     * #17 — Clés {neria_trad} non résolues en base
     * Si une clé est absente de ps_neria_translation, le placeholder s'affiche
     * en clair dans l'email du client.
     */
    private function checkTradKeys(): array
    {
        $jsonPath = __DIR__ . '/../data/translations.json';
        if (!is_file($jsonPath)) {
            return ['status' => self::STATUS_ERROR, 'detail' => 'translations.json introuvable.'];
        }

        $trad = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($trad)) {
            return ['status' => self::STATUS_ERROR, 'detail' => 'translations.json invalide (JSON corrompu).'];
        }

        // Construit l'index plat depuis la structure imbriquée {template:{lang:{key:val}}}
        $index = [];
        foreach ($trad as $block) {
            if (!is_array($block)) {
                continue;
            }
            foreach ($block as $lang => $keys) {
                if (!is_array($keys)) {
                    continue;
                }
                foreach ($keys as $k => $v) {
                    $index[$k][$lang] = $v;
                }
            }
        }

        $langs   = \TranslationEngine::SUPPORTED_LANGS;
        $missing = [];

        foreach ($index as $key => $byLang) {
            foreach ($langs as $lang) {
                if (empty($byLang[$lang])) {
                    $missing[$key][] = $lang;
                }
            }
        }

        // Vérifie aussi en DB que les clés du JSON sont bien présentes
        $dbMissing = (int) $this->db->getValue(
            "SELECT COUNT(DISTINCT `translation_key`) FROM `" . _DB_PREFIX_ . "neria_translation`
             WHERE `translation_value` = '' OR `translation_value` IS NULL"
        );

        if (empty($missing) && $dbMissing === 0) {
            return [
                'status' => self::STATUS_OK,
                'detail' => count($index) . ' clés de traduction présentes et complètes.',
            ];
        }

        $detail = '';
        if (!empty($missing)) {
            $detail .= count($missing) . ' clé(s) avec langues manquantes dans translations.json. ';
        }
        if ($dbMissing > 0) {
            $detail .= $dbMissing . ' valeur(s) vide(s) en base ps_neria_translation.';
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => $detail . ' → Que faire : Utilisez le bouton "Réparer les traductions" dans l\'onglet Traductions.',
        ];
    }

    /**
     * #18 — Hooks PS enregistrés
     * Un hook absent après mise à jour PS = feature entière silencieusement inactive.
     */
    private function checkHooksRegistered(): array
    {
        $critical = [
            'actionEmailSendBefore'      => 'Interception emails (tracking, traductions)',
            'actionMailAlterMessageBeforeSend' => 'En-tête List-Unsubscribe',
            'displayBackOfficeHeader'    => 'CSS/JS back-office',
            'actionObjectOrderAddAfter'  => 'Attribution de revenus',
            'actionOrderStatusPostUpdate' => 'Attribution revenus (statut payé)',
        ];

        $idModule = (int) $this->module->id;
        $missing  = [];

        foreach ($critical as $hookName => $desc) {
            $hooked = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "hook_module` hm
                 JOIN `" . _DB_PREFIX_ . "hook` h ON h.id_hook = hm.id_hook
                 WHERE h.`name` = '" . pSQL($hookName) . "'
                   AND hm.`id_module` = {$idModule}"
            );
            if (!$hooked) {
                $missing[] = $hookName . ' (' . $desc . ')';
            }
        }

        if ($missing) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => count($missing) . ' hook(s) critique(s) non enregistré(s) : '
                    . implode(', ', $missing)
                    . ' → Que faire : Désinstallez et réinstallez le module pour réenregistrer les hooks.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => count($critical) . ' hooks critiques correctement enregistrés.',
        ];
    }

    /**
     * #19 — Synchronisation de version
     * Si NERIA_INSTALLED_VERSION diffère de self::VERSION, un upgrade script
     * n'a pas tourné → tables ou clés config manquantes sans erreur visible.
     */
    private function checkVersionSync(): array
    {
        $installedVersion = (string) \Configuration::get('NERIA_INSTALLED_VERSION');
        $currentVersion   = $this->module->version;

        if ($installedVersion === '' || $installedVersion === false) {
            // Première install ou module trop ancien : on écrit la version courante
            \Configuration::updateValue('NERIA_INSTALLED_VERSION', $currentVersion);
            return [
                'status' => self::STATUS_OK,
                'detail' => 'Version enregistrée : ' . $currentVersion . '.',
            ];
        }

        if (version_compare($installedVersion, $currentVersion, '<')) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Version installée (' . $installedVersion . ') < version module ('
                    . $currentVersion . '). Un upgrade script n\'a peut-être pas tourné.'
                    . ' → Que faire : Allez dans Modules → Neria → Mettre à jour pour déclencher les scripts d\'upgrade.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => 'Version synchronisée : ' . $currentVersion . '.',
        ];
    }

    /**
     * #20 — Taux d'ouverture sur 7 jours
     * Un taux < 5% avec > 50 envois indique un problème de délivrabilité
     * (emails en spam) ou un pixel de tracking cassé.
     */
    private function checkOpenRate7d(): array
    {
        $table = _DB_PREFIX_ . 'neria_stat';

        $sent7 = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `id_shop`    = {$this->idShop}
               AND `event_type` = 'sent'
               AND `date_add`  > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        if ($sent7 < 50) {
            return [
                'status' => self::STATUS_OK,
                'detail' => 'Moins de 50 envois sur 7j (' . $sent7 . ') — analyse taux d\'ouverture non significative.',
            ];
        }

        $open7 = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `id_shop`    = {$this->idShop}
               AND `event_type` = 'open'
               AND `date_add`  > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $rate = round($open7 / $sent7 * 100, 1);

        if ($rate < 5) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Taux d\'ouverture 7j : ' . $rate . '% (' . $open7 . '/' . $sent7 . ').'
                    . ' → Que faire : Vérifiez le score de réputation dans l\'onglet Statistiques,'
                    . ' testez le pixel de tracking (onglet Aide) et contrôlez que les emails n\'arrivent pas en spam.',
            ];
        }

        if ($rate < 15) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Taux d\'ouverture 7j faible : ' . $rate . '% (' . $open7 . '/' . $sent7 . ').'
                    . ' → Que faire : Travaillez les objets d\'email (onglet Formation → Guide objets)'
                    . ' et vérifiez que les emails n\'arrivent pas dans les onglets promotions.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => 'Taux d\'ouverture 7j : ' . $rate . '% (' . $open7 . ' ouvertures / ' . $sent7 . ' envois).',
        ];
    }

    /**
     * #PROACTIF-1 — Tendance d'engagement (proactif)
     * Compare le taux d'ouverture des 7 derniers jours à la moyenne des
     * 30 jours précédents. Détecte une dégradation progressive AVANT qu'elle
     * ne franchisse le seuil critique fixe de checkOpenRate7d().
     */
    private function checkEngagementTrend(): array
    {
        $table = _DB_PREFIX_ . 'neria_stat';

        $recent = $this->db->getRow(
            "SELECT
                SUM(CASE WHEN event_type = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN event_type = 'open' THEN 1 ELSE 0 END) AS opened
             FROM `{$table}`
             WHERE id_shop = {$this->idShop}
               AND date_add > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $baseline = $this->db->getRow(
            "SELECT
                SUM(CASE WHEN event_type = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN event_type = 'open' THEN 1 ELSE 0 END) AS opened
             FROM `{$table}`
             WHERE id_shop = {$this->idShop}
               AND date_add > DATE_SUB(NOW(), INTERVAL 37 DAY)
               AND date_add <= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $recentSent    = (int) ($recent['sent'] ?? 0);
        $baselineSent  = (int) ($baseline['sent'] ?? 0);

        if ($recentSent < 50 || $baselineSent < 50) {
            return [
                'status' => self::STATUS_OK,
                'detail' => 'Historique insuffisant pour détecter une tendance (besoin de ≥ 50 envois sur les deux périodes).',
            ];
        }

        $recentRate   = round((int) ($recent['opened'] ?? 0) / $recentSent * 100, 1);
        $baselineRate = round((int) ($baseline['opened'] ?? 0) / $baselineSent * 100, 1);

        if ($baselineRate <= 0) {
            return ['status' => self::STATUS_OK, 'detail' => 'Taux de référence nul — comparaison ignorée.'];
        }

        $relativeChange = round((($recentRate - $baselineRate) / $baselineRate) * 100, 1);

        if ($relativeChange <= -30) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "Tendance d'engagement en baisse : {$recentRate}% cette semaine vs {$baselineRate}% en moyenne sur les 30 jours précédents"
                    . " ({$relativeChange}% relatif)."
                    . ' → Que faire : Vérifiez la réputation domaine, la fraîcheur des segments ciblés récemment,'
                    . ' et si aucun changement de contenu/fréquence n\'explique la baisse.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => "Tendance d'engagement stable : {$recentRate}% cette semaine vs {$baselineRate}% en moyenne (30j précédents).",
        ];
    }

    /**
     * #PROACTIF-2 — Fraîcheur des connexions OAuth (proactif)
     * Search Console et Postmaster Tools se ré-authentifient automatiquement
     * via leur refresh token. Si ce mécanisme casse silencieusement (jeton
     * révoqué, app en mode Test Google avec expiration 7j…), les données
     * arrêtent de se rafraîchir sans qu'aucune erreur ne soit visible tant
     * que le marchand ne consulte pas l'onglet Statistiques. Ce contrôle le
     * détecte en amont, pendant le diagnostic périodique.
     */
    private function checkOAuthFreshness(): array
    {
        $stale = [];
        $staleThresholdMinutes = 60 * 24 * 3; // 3 jours

        if (class_exists('SearchConsoleManager')) {
            $mgr = new \SearchConsoleManager($this->module);
            if ($mgr->isConnected()) {
                $age = $mgr->getCacheAge();
                if ($age === null || $age > $staleThresholdMinutes) {
                    $stale[] = 'Search Console (' . ($age === null ? 'jamais rafraîchi' : round($age / 60 / 24) . 'j') . ')';
                }
            }
        }

        if (class_exists('PostmasterManager')) {
            $mgr = new \PostmasterManager($this->module);
            if ($mgr->isConnected()) {
                $age = $mgr->getCacheAge();
                if ($age === null || $age > $staleThresholdMinutes) {
                    $stale[] = 'Postmaster Tools (' . ($age === null ? 'jamais rafraîchi' : round($age / 60 / 24) . 'j') . ')';
                }
            }
        }

        if ($stale) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Connexion(s) OAuth avec données obsolètes (> 3j) : ' . implode(', ', $stale) . '.'
                    . ' → Que faire : le refresh token a peut-être été révoqué côté Google (ou l\'app est encore'
                    . ' en mode "Test" — les refresh tokens expirent alors après 7 jours). Reconnectez-vous depuis'
                    . ' l\'onglet Statistiques, et pensez à publier l\'app OAuth dans Google Cloud Console.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'Connexions OAuth (Search Console / Postmaster) à jour ou non configurées.'];
    }

    /**
     * #22 — Sécurité HMAC désabonnement
     * Les liens de désabonnement sont signés avec _COOKIE_KEY_ (constante PS).
     * Si elle est absente ou trop courte, les liens sont falsifiables.
     */
    private function checkHmacSecurity(): array
    {
        if (!defined('_COOKIE_KEY_')) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Constante _COOKIE_KEY_ absente — les liens de désabonnement ne peuvent pas être signés.'
                    . ' → Que faire : Vérifiez que config/settings.inc.php existe et est lisible par PHP.',
            ];
        }

        $keyLength = strlen(_COOKIE_KEY_);

        if ($keyLength < 32) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => '_COOKIE_KEY_ trop courte (' . $keyLength . ' chars, minimum recommandé : 32).'
                    . ' Liens de désabonnement potentiellement falsifiables.'
                    . ' → Que faire : Régénérez les clés de sécurité PrestaShop.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => '_COOKIE_KEY_ présente et robuste (' . $keyLength . ' chars) — HMAC désabonnement sécurisé.',
        ];
    }

    /**
     * #21 — File d'envoi bloquée (fenêtre d'achat)
     * Des emails en attente ont dépassé leur heure d'envoi programmée de plus
     * de 2h → le cron QueueManager ne tourne plus ou plante silencieusement.
     */
    private function checkQueueBlocked(): array
    {
        $table = _DB_PREFIX_ . 'neria_queue';

        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Queue non activée (table absente) — sans impact.'];
        }

        $blocked = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `send_at` < DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND `status` = 'pending'"
        );

        if ($blocked === 0) {
            $pending = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'pending'"
            );
            return [
                'status' => self::STATUS_OK,
                'detail' => 'File d\'envoi fluide — ' . $pending . ' email(s) en attente programmé(s).',
            ];
        }

        return [
            'status' => self::STATUS_ERROR,
            'detail' => $blocked . ' email(s) bloqués en file depuis plus de 2h (send_at dépassé, statut pending).'
                . ' → Que faire : Vérifiez que le cron Neria s\'exécute bien'
                . ' (index.php?fc=module&module=neria&controller=cron) et consultez le journal Watchdog.',
        ];
    }

    /**
     * #23 — Endpoints AJAX back-office
     * Vérifie que les réponses JSON des actions AJAX critiques ne sont pas
     * silencieusement vides ou malformées en DB (via les dernières entrées watchdog).
     */
    private function checkAjax(): array
    {
        // Détecte des erreurs AJAX récentes loggées dans le watchdog
        $recentAjaxErrors = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_log`
             WHERE `message` LIKE '%AJAX%'
               AND `level` IN ('error','critical')
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        if ($recentAjaxErrors > 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => $recentAjaxErrors . ' erreur(s) AJAX détectée(s) dans le journal des dernières 24h.'
                    . ' → Que faire : Consultez le journal Watchdog (onglet Aide) pour identifier'
                    . ' l\'action concernée et vérifier les erreurs PHP/JS associées.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => 'Aucune erreur AJAX dans les dernières 24h — endpoints back-office opérationnels.',
        ];
    }

    /**
     * #24 — Méthodes critiques des managers
     * Vérifie via réflexion que les méthodes-clés de chaque manager existent.
     * Détecte une régression d'API après mise à jour ou refactoring partiel.
     */
    private function checkCriticalMethods(): array
    {
        $probes = [
            'StatsManager'          => ['getKpis', 'getGlobalReport', 'recordSent'],
            'EmailRenderer'         => ['processEmailParams', 'renderWithVars', 'renderPreview'],
            'TranslationEngine'     => ['get', 'getAll', 'update'],
            'ConfigManager'         => ['isActive', 'getDesignConfig', 'get'],
            'WatchdogManager'       => ['info', 'warning', 'error'],
            'BehavioralCronManager' => ['run'],
            'LoyaltyManager'        => ['getCustomerStats', 'awardPoints', 'checkAndReward'],
        ];

        $errors = [];
        $ok     = 0;

        foreach ($probes as $class => $methods) {
            if (!class_exists($class)) {
                continue; // Géré par checkManagersAvailable
            }
            try {
                $ref = new \ReflectionClass($class);
                foreach ($methods as $method) {
                    if (!$ref->hasMethod($method)) {
                        $errors[] = $class . '::' . $method . '()';
                    } else {
                        $ok++;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $class . ' (réflexion impossible : ' . $e->getMessage() . ')';
            }
        }

        if ($errors) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => count($errors) . ' méthode(s) critique(s) introuvable(s) : '
                    . implode(', ', $errors)
                    . ' → Que faire : Une mise à jour a peut-être cassé l\'API interne.'
                    . ' Vérifiez le dossier src/ et les scripts d\'upgrade.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => $ok . ' méthodes critiques vérifiées par réflexion — API interne intacte.',
        ];
    }

    /**
     * #15 — Managers PHP critiques disponibles
     * Si un fichier src/ est absent (effacement accidentel, permission, upload raté),
     * les features tombent silencieusement via class_exists() === false.
     */
    private function checkManagersAvailable(): array
    {
        $critical = [
            'StatsManager'      => 'Statistiques & tracking',
            'EmailRenderer'     => 'Rendu des emails',
            'TranslationEngine' => 'Moteur de traduction',
            'ConfigManager'     => 'Configuration module',
            'WatchdogManager'   => 'Journal de surveillance',
            'CalendarManager'   => 'Emails calendaires',
        ];

        $optional = [
            'BehavioralCronManager' => 'Emails comportementaux (anniversaires, paniers…)',
            'LoyaltyManager'        => 'Programme de fidélité',
            'SegmentManager'        => 'Segmentation comportementale',
            'DomainReputationManager' => 'Score de réputation de domaine',
            'QueueManager'          => 'Queue d\'envoi programmé',
            'BounceManager'         => 'Gestion des bounces',
            'GdprAuditManager'      => 'Audit RGPD',
            'CertificateManager'    => 'Certificats d\'authenticité',
        ];

        $missingCritical = [];
        $missingOptional = [];

        foreach ($critical as $class => $label) {
            if (!class_exists($class)) {
                $missingCritical[] = $class . ' (' . $label . ')';
            }
        }
        foreach ($optional as $class => $label) {
            if (!class_exists($class)) {
                $missingOptional[] = $class . ' (' . $label . ')';
            }
        }

        if ($missingCritical) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Manager(s) critique(s) introuvable(s) : ' . implode(', ', $missingCritical)
                    . ' → Que faire : Réinstallez le module ou re-uploadez le dossier src/.',
            ];
        }

        if ($missingOptional) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Manager(s) optionnel(s) introuvable(s) : ' . implode(', ', $missingOptional)
                    . ' → Que faire : Ces features sont désactivées. Vérifiez que src/ est complet.',
            ];
        }

        $total = count($critical) + count($optional);
        return [
            'status' => self::STATUS_OK,
            'detail' => 'Tous les managers PHP sont disponibles (' . $total . '/' . $total . ').',
        ];
    }

    /**
     * #16 — Bounces qui s'accumulent sans être traités
     * Si la table grossit mais que le cron IMAP ne tourne pas,
     * des clients blacklistés ne le sont jamais.
     */
    private function checkBouncesUnprocessed(): array
    {
        $table = _DB_PREFIX_ . 'neria_bounces';

        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Table bounces absente — feature non activée.'];
        }

        // Bounces reçus depuis 48h mais dont le statut est toujours 'active'
        // (jamais mis en 'ignored' ou traités)
        $recent = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `last_bounce_at` > DATE_SUB(NOW(), INTERVAL 48 HOUR)
               AND `status` = 'active'"
        );

        $lastCron = (string) \Configuration::get(self::CRON_LAST_BOUNCES);
        $cronAgeH = $lastCron
            ? round((time() - (int) strtotime($lastCron)) / 3600)
            : null;

        if ($recent > 0 && ($cronAgeH === null || $cronAgeH > 26)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => $recent . ' bounce(s) reçu(s) dans les 48h mais cron IMAP '
                    . ($cronAgeH === null ? 'jamais exécuté' : 'en retard (' . $cronAgeH . 'h)') . '.'
                    . ' → Que faire : Vérifiez la configuration IMAP dans l\'onglet Statistiques'
                    . ' et assurez-vous que le cron Neria s\'exécute quotidiennement.',
            ];
        }

        $total = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$table}`");
        return [
            'status' => self::STATUS_OK,
            'detail' => 'Bounces traités correctement — ' . $total . ' adresse(s) en base.',
        ];
    }

    // ============================================================
    // INTERNE
    // ============================================================

    /**
     * #24 — Webhooks en échec permanent
     * Si des webhooks ont atteint le max de retries (status=failed) dans les 48h,
     * le CRM/Zapier/Slack du marchand ne reçoit plus rien silencieusement.
     */
    private function checkWebhookFailures(): array
    {
        $table = _DB_PREFIX_ . WebhookManager::TABLE;

        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Webhooks non activés — sans impact.'];
        }

        $failed = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `status` = '" . pSQL(WebhookManager::STATUS_FAILED) . "'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );

        if ($failed === 0) {
            $pending = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'pending'"
            );
            return [
                'status' => self::STATUS_OK,
                'detail' => 'Webhooks opérationnels — ' . $pending . ' en attente.',
            ];
        }

        return [
            'status' => self::STATUS_ERROR,
            'detail' => $failed . ' webhook(s) en échec définitif dans les 48h (3 retries épuisés).'
                . ' → Que faire : Vérifiez l\'URL de destination dans l\'onglet Webhooks'
                . ' et contrôlez que le serveur distant répond correctement (code 2xx).',
        ];
    }

    /**
     * #25 — A/B tests bloqués sans gagnant
     * Un test actif depuis >30 jours sans significance déclare divise le trafic
     * indéfiniment et empêche d'optimiser les templates.
     */
    private function checkAbtestStuck(): array
    {
        $table = _DB_PREFIX_ . ABTestManager::TABLE;

        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => 'A/B Testing non activé — sans impact.'];
        }

        $stuck = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `is_active` = 1
               AND `date_add` < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        if ($stuck === 0) {
            return ['status' => self::STATUS_OK, 'detail' => 'Aucun A/B test bloqué.'];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => $stuck . ' A/B test(s) actif(s) depuis plus de 30 jours sans gagnant déclaré.'
                . ' → Que faire : Consultez l\'onglet Statistiques → A/B Testing'
                . ' et déclarez manuellement le gagnant pour arrêter le split de trafic.',
        ];
    }

    /**
     * #26 — Clé de chiffrement AES absente
     * Si la clé est absente alors que des rendered_vars chiffrés existent en base,
     * l\'historique des emails client est illisible et les données sont perdues.
     */
    private function checkCryptoKey(): array
    {
        $key = (string) \Configuration::get(CryptoManager::CONFIG_KEY);

        if ($key !== '') {
            return ['status' => self::STATUS_OK, 'detail' => 'Clé de chiffrement AES-256-GCM présente.'];
        }

        // Vérifie si des données chiffrées existent réellement
        $statTable  = _DB_PREFIX_ . 'neria_stat';
        $hasEncData = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = '{$statTable}'
               AND column_name  = 'rendered_vars'"
        );

        if (!$hasEncData) {
            return ['status' => self::STATUS_OK, 'detail' => 'Chiffrement non activé — sans impact.'];
        }

        return [
            'status' => self::STATUS_ERROR,
            'detail' => 'Clé de chiffrement AES absente (NERIA_ENCRYPTION_KEY vide).'
                . ' Les données chiffrées en base sont illisibles.'
                . ' → Que faire : Allez dans l\'onglet RGPD → Chiffrement'
                . ' et régénérez la clé, puis lancez la migration rétroactive.',
        ];
    }

    /**
     * #27 — Pic de volume d\'envoi anormal
     * Si le module envoie aujourd\'hui plus de 3× la moyenne des 7 derniers jours,
     * c\'est le signe d\'une boucle infinie ou d\'une campagne mal configurée.
     * Risque de blacklistage immédiat.
     */
    private function checkSendVolumeSpike(): array
    {
        $table = _DB_PREFIX_ . 'neria_stat';

        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Table stats absente — sans impact.'];
        }

        $today = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `event_type` = 'sent'
               AND DATE(`date_add`) = CURDATE()"
        );

        if ($today === 0) {
            return ['status' => self::STATUS_OK, 'detail' => 'Aucun envoi aujourd\'hui.'];
        }

        $avgRow = $this->db->getValue(
            "SELECT AVG(daily_count) FROM (
                SELECT COUNT(*) AS daily_count
                FROM `{$table}`
                WHERE `event_type` = 'sent'
                  AND `date_add` >= DATE_SUB(CURDATE(), INTERVAL 8 DAY)
                  AND `date_add` < CURDATE()
                GROUP BY DATE(`date_add`)
             ) AS daily_stats"
        );

        $avg = (float) $avgRow;

        if ($avg < 10) {
            return ['status' => self::STATUS_OK, 'detail' => 'Volume d\'envoi normal (' . $today . ' emails aujourd\'hui).'];
        }

        $ratio = $today / $avg;

        if ($ratio >= 5) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => sprintf(
                    'Pic d\'envoi critique : %d emails aujourd\'hui vs moyenne 7j de %.0f (×%.1f).'
                    . ' → Que faire : Vérifiez immédiatement les logs watchdog'
                    . ' et les campagnes actives — risque de blacklistage.',
                    $today, $avg, $ratio
                ),
            ];
        }

        if ($ratio >= 3) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => sprintf(
                    'Volume d\'envoi élevé : %d emails aujourd\'hui vs moyenne 7j de %.0f (×%.1f).'
                    . ' → Que faire : Vérifiez qu\'une campagne manuelle ou saisonnière'
                    . ' n\'a pas été envoyée par erreur.',
                    $today, $avg, $ratio
                ),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => sprintf('Volume d\'envoi normal (%d emails aujourd\'hui, moyenne 7j : %.0f).', $today, $avg)];
    }

    /**
     * #28 — Score de réputation de domaine sous le seuil
     * Le rapport est calculé automatiquement mais personne n'alerte
     * si le score chute sous 50 ou si le domaine est blacklisté.
     */
    private function checkDomainRepScore(): array
    {
        if (!class_exists('DomainReputationManager')) {
            return ['status' => self::STATUS_OK, 'detail' => 'DomainReputationManager absent — sans impact.'];
        }

        $mgr    = new DomainReputationManager($this->module);
        $cached = $mgr->getCachedReport();

        if ($cached === null) {
            return ['status' => self::STATUS_OK, 'detail' => 'Rapport de réputation pas encore généré.'];
        }

        $score = (int) ($cached['score'] ?? 100);
        $grade = (string) ($cached['grade'] ?? 'A');
        $hits  = count($cached['blacklists']['hits'] ?? []);

        if ($hits > 0 || $grade === 'F' || $grade === 'D') {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => sprintf(
                    'Réputation domaine critique : score %d/100 (grade %s), %d liste(s) noire(s) touchée(s).'
                    . ' → Que faire : Consultez l\'onglet Statistiques → Réputation de domaine'
                    . ' et suivez les recommandations pour vous faire retirer des blacklists.',
                    $score, $grade, $hits
                ),
            ];
        }

        if ($score < 75 || $grade === 'C') {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => sprintf(
                    'Réputation domaine dégradée : score %d/100 (grade %s).'
                    . ' → Que faire : Vérifiez votre configuration SPF/DKIM/DMARC'
                    . ' dans l\'onglet Statistiques → Réputation de domaine.',
                    $score, $grade
                ),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => sprintf('Réputation domaine saine : %d/100 (grade %s).', $score, $grade)];
    }

    /**
     * #29 — Tables DB manquantes
     * Si un script d'upgrade a échoué silencieusement, une table entière
     * peut être absente : la feature plante sans exception PHP visible.
     */
    private function checkDbTables(): array
    {
        $expected = [
            'neria_stat', 'neria_abtest', 'neria_abtest_translation',
            'neria_attribution', 'neria_behavioral_sent', 'neria_bounces',
            'neria_collection', 'neria_collection_sent',
            'neria_log', 'neria_look_rule', 'neria_look_sent',
            'neria_loyalty_points', 'neria_loyalty_rewards',
            'neria_preferences', 'neria_product_lifespan',
            'neria_propensity_score', 'neria_queue', 'neria_quote',
            'neria_reconciliation', 'neria_seasonal_campaign',
            'neria_customer_segment', 'neria_upsell', 'neria_waitlist',
            'neria_webhook_queue', 'neria_cron_health', 'neria_abtest_history',
        ];

        $existing = $this->db->executeS(
            "SELECT TABLE_NAME FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND TABLE_NAME LIKE '" . pSQL(_DB_PREFIX_) . "neria_%'"
        ) ?: [];

        $existingNames = array_column($existing, 'TABLE_NAME');
        $prefix        = _DB_PREFIX_;
        $missing       = [];

        foreach ($expected as $table) {
            if (!in_array($prefix . $table, $existingNames, true)) {
                $missing[] = $table;
            }
        }

        if ($missing) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => count($missing) . ' table(s) manquante(s) : ' . implode(', ', $missing)
                    . ' → Que faire : Désinstallez et réinstallez le module,'
                    . ' ou exécutez manuellement le script d\'upgrade correspondant.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => count($expected) . ' tables présentes en base.'];
    }

    /**
     * #30 — Lien de désabonnement accessible
     * Le header List-Unsubscribe est déjà vérifié, mais pas l'URL réelle
     * cliquable dans l'email. Si elle est cassée, le client ne peut plus
     * se désabonner — violation RGPD immédiate.
     */
    private function checkUnsubscribeUrl(): array
    {
        $testEmail = 'neria-health-check@example.com';
        $token     = substr(hash_hmac('sha256', $testEmail, _COOKIE_KEY_), 0, 32);
        $base      = \Tools::getShopDomainSsl(true);
        $url       = $base . '/index.php?fc=module&module=neria&controller=unsubscribe'
                   . '&email=' . urlencode($testEmail) . '&token=' . $token;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Lien de désabonnement injoignable (erreur cURL : ' . $error . ').'
                    . ' → Que faire : Vérifiez que votre boutique est accessible depuis internet'
                    . ' et que le contrôleur neria/unsubscribe répond.',
            ];
        }

        // 200 ou 302 = page trouvée ; 404/500 = cassé
        if ($httpCode === 0 || $httpCode >= 400) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Lien de désabonnement inaccessible (HTTP ' . $httpCode . ').'
                    . ' → Que faire : Vérifiez que le fichier controllers/front/unsubscribe.php'
                    . ' existe et que le module est correctement installé (hooks enregistrés).',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'Lien de désabonnement accessible (HTTP ' . $httpCode . ').'];
    }

    /**
     * #31 — Waitlist non notifiée
     * Des clients attendent un produit revenu en stock mais l'email
     * n'a jamais été envoyé depuis plus de 48h.
     */
    private function checkWaitlistBacklog(): array
    {
        $table = _DB_PREFIX_ . WaitlistManager::TABLE;

        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Waitlist non activée — sans impact.'];
        }

        // Clients en attente non notifiés depuis plus de 48h,
        // dont le produit est actuellement en stock (quantity > 0)
        $backlog = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` w
             JOIN `" . _DB_PREFIX_ . "stock_available` s
                  ON s.id_product = w.id_product AND s.id_product_attribute = 0
             WHERE w.notified_at IS NULL
               AND w.registered_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
               AND s.quantity > 0"
        );

        if ($backlog === 0) {
            $total = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$table}` WHERE notified_at IS NULL");
            return [
                'status' => self::STATUS_OK,
                'detail' => 'Waitlist à jour — ' . $total . ' client(s) en attente de restockage.',
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => $backlog . ' client(s) en liste d\'attente non notifié(s) alors que le produit'
                . ' est revenu en stock depuis plus de 48h.'
                . ' → Que faire : Vérifiez que le cron comportemental tourne bien'
                . ' et consultez les logs Watchdog pour des erreurs WaitlistManager.',
        ];
    }

    /**
     * #32 — Quota SMTP journalier approché
     * Certains hébergeurs limitent les envois à 200–500 emails/jour.
     * Si on approche du quota, les emails suivants rebondissent silencieusement.
     * Compare le volume du jour avec le quota configuré (si défini).
     */
    private function checkSmtpQuota(): array
    {
        $quota = (int) \Configuration::get('NERIA_SMTP_DAILY_QUOTA');

        if ($quota <= 0) {
            return ['status' => self::STATUS_OK, 'detail' => 'Aucun quota SMTP journalier configuré.'];
        }

        $table = _DB_PREFIX_ . 'neria_stat';
        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Table stats absente — sans impact.'];
        }

        $today = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `event_type` = 'sent' AND DATE(`date_add`) = CURDATE()"
        );

        $pct = ($today / $quota) * 100;

        if ($pct >= 100) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => sprintf(
                    'Quota SMTP journalier dépassé : %d/%d emails envoyés aujourd\'hui (%.0f%%).'
                    . ' → Que faire : Les emails suivants risquent d\'être rejetés.'
                    . ' Contactez votre hébergeur ou réduisez le volume d\'envoi.',
                    $today, $quota, $pct
                ),
            ];
        }

        if ($pct >= 80) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => sprintf(
                    'Quota SMTP journalier à %.0f%% : %d/%d emails envoyés aujourd\'hui.'
                    . ' → Que faire : Vous approchez de la limite de votre hébergeur.'
                    . ' Envisagez de différer les campagnes non urgentes.',
                    $pct, $today, $quota
                ),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => sprintf('Quota SMTP : %d/%d emails aujourd\'hui (%.0f%%).', $today, $quota, $pct)];
    }

    /**
     * #33 — PTR / rDNS manquant
     * Certains serveurs de réception (Orange, SFR, serveurs corporate)
     * rejettent silencieusement les emails venant d'une IP sans PTR configuré.
     */
    private function checkPtrRecord(): array
    {
        if (!class_exists('DomainReputationManager')) {
            return ['status' => self::STATUS_OK, 'detail' => 'DomainReputationManager absent — sans impact.'];
        }

        $cached = (new DomainReputationManager($this->module))->getCachedReport();

        if ($cached === null) {
            return ['status' => self::STATUS_OK, 'detail' => 'Rapport de réputation pas encore généré — PTR non vérifié.'];
        }

        $ptr = $cached['ptr'] ?? null;

        if (!is_array($ptr)) {
            return ['status' => self::STATUS_OK, 'detail' => 'PTR non encore analysé — actualisez le score de réputation.'];
        }

        if (!empty($ptr['skipped'])) {
            return ['status' => self::STATUS_OK, 'detail' => 'PTR non applicable (IP locale / développement).'];
        }

        if (empty($ptr['found'])) {
            $ip = $cached['ip'] ?? '?';
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'PTR / rDNS absent pour l\'IP ' . $ip . '.'
                    . ' Certains serveurs (Orange, SFR, serveurs corporate) rejettent les emails sans reverse DNS.'
                    . ' → Que faire : Contactez votre hébergeur pour configurer un enregistrement PTR'
                    . ' pointant vers votre nom de domaine d\'envoi.',
            ];
        }

        if (isset($ptr['valid']) && !$ptr['valid']) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'PTR configuré (' . ($ptr['hostname'] ?? '?') . ') mais la vérification inverse échoue'
                    . ' (le hostname ne résout pas vers la même IP).'
                    . ' → Que faire : Vérifiez que le PTR et l\'enregistrement A sont cohérents chez votre hébergeur.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'PTR / rDNS configuré et valide (' . ($ptr['hostname'] ?? '') . ').'];
    }

    /**
     * #34 — Réputation Google Postmaster Tools
     * Alerte si le cache contient des données dégradées (spam rate élevé,
     * réputation LOW/BAD). Silencieux si l'intégration n'est pas configurée.
     */
    private function checkPostmasterReputation(): array
    {
        if (!class_exists('PostmasterManager')) {
            return ['status' => self::STATUS_OK, 'detail' => 'PostmasterManager non disponible.'];
        }

        $mgr = new \PostmasterManager($this->module);

        if (!$mgr->isConfigured()) {
            return ['status' => self::STATUS_OK, 'detail' => 'Postmaster Tools non configuré (optionnel).'];
        }

        if (!$mgr->isConnected()) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Postmaster Tools configuré mais non connecté à Google.'
                    . ' → Que faire : Rendez-vous dans l\'onglet Statistiques et cliquez sur « Connecter avec Google ».',
            ];
        }

        $stats = $mgr->getCachedStats();
        if ($stats === null) {
            return ['status' => self::STATUS_OK, 'detail' => 'Postmaster Tools connecté — données pas encore chargées (actualisez dans l\'onglet Stats).'];
        }

        if (empty($stats)) {
            return ['status' => self::STATUS_OK, 'detail' => 'Postmaster Tools : aucune donnée disponible (volume d\'envoi insuffisant ou domaine non vérifié).'];
        }

        $errors   = [];
        $warnings = [];

        foreach ($stats as $ps) {
            $domain   = $ps['domain']            ?? '?';
            $rep      = $ps['domain_reputation'] ?? null;
            $spamRate = $ps['spam_rate']          ?? null;

            if ($rep === 'BAD') {
                $errors[] = "Réputation BLOQUÉE (BAD) pour {$domain} — Gmail rejette activement vos emails.";
            } elseif ($rep === 'LOW') {
                $errors[] = "Réputation LOW pour {$domain} — vos emails passent en spam Gmail.";
            } elseif ($rep === 'MEDIUM') {
                $warnings[] = "Réputation MEDIUM pour {$domain} — surveillance recommandée.";
            }

            if ($spamRate !== null && $spamRate > 0.3) {
                $errors[] = "Taux de spam {$spamRate}% pour {$domain} (seuil critique >0,3%) — action immédiate requise.";
            } elseif ($spamRate !== null && $spamRate > 0.1) {
                $warnings[] = "Taux de spam {$spamRate}% pour {$domain} (zone d'attention >0,1%).";
            }
        }

        if (!empty($errors)) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Postmaster Tools : ' . implode(' | ', $errors)
                    . ' → Que faire : Vérifiez vos listes d\'envoi, retirez les adresses invalides, réduisez la fréquence.'
                    . ' Consultez l\'onglet Statistiques pour le détail complet.',
            ];
        }

        if (!empty($warnings)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Postmaster Tools : ' . implode(' | ', $warnings),
            ];
        }

        $cacheAge = $mgr->getCacheAge();
        $ageStr   = $cacheAge !== null ? " (données vieilles de {$cacheAge} min)" : '';
        return ['status' => self::STATUS_OK, 'detail' => 'Postmaster Tools : réputation et taux de spam dans les normes' . $ageStr . '.'];
    }

    /**
     * #35 — Taux de clic email sur 7 jours
     * Si des ouvertures sont enregistrées mais aucun clic depuis 7j,
     * le tracking de liens est probablement cassé.
     */
    private function checkClickRate7d(): array
    {
        $db = \Db::getInstance();

        $opens = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
            WHERE event_type = \'open\'
              AND date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        if ($opens === 0) {
            return ['status' => self::STATUS_OK, 'detail' => 'Aucune ouverture enregistrée ces 7 derniers jours — taux de clic non applicable.'];
        }

        $clicks = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
            WHERE event_type = \'click\'
              AND date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        if ($clicks === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => $opens . ' ouvertures enregistrées ces 7 derniers jours, mais aucun clic.'
                    . ' → Que faire : Vérifiez que track.php est accessible et que les liens dans vos templates'
                    . ' passent bien par le pixel de suivi Neria.',
            ];
        }

        $rate = round($clicks / $opens * 100, 1);

        if ($rate < 0.5) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "Taux de clic très bas : {$rate}% ({$clicks} clics / {$opens} ouvertures) sur 7j."
                    . ' → Que faire : Vérifiez vos appels à l\'action (CTA) et la pertinence du contenu de vos emails.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "Taux de clic 7j : {$rate}% ({$clicks} clics / {$opens} ouvertures). Tracking opérationnel."];
    }

    /**
     * #36 — Pic de désabonnements sur 7 jours
     * Un taux > 0,5 % du volume envoyé signale un problème de ciblage ou de contenu.
     */
    private function checkUnsubscribeSpike(): array
    {
        $db = \Db::getInstance();

        $sent = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
            WHERE event_type = \'sent\'
              AND date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        if ($sent < 100) {
            return ['status' => self::STATUS_OK, 'detail' => 'Volume d\'envoi insuffisant pour mesurer le taux de désabonnement (< 100 emails).'];
        }

        $unsubs = (int) $db->getValue('
            SELECT COUNT(DISTINCT id_customer) FROM `' . _DB_PREFIX_ . 'neria_preferences`
            WHERE subscribed = 0
              AND date_upd >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        $rate = round($unsubs / $sent * 100, 2);

        if ($rate > 0.5) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => "Pic de désabonnements : {$rate}% sur 7j ({$unsubs} / {$sent} envois)."
                    . ' Seuil critique dépassé (> 0,5%).'
                    . ' → Que faire : Examinez les segments ciblés cette semaine, vérifiez la pertinence du contenu,'
                    . ' et suspendez temporairement les campagnes non urgentes.',
            ];
        }

        if ($rate > 0.2) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "Taux de désabonnement en hausse : {$rate}% sur 7j ({$unsubs} / {$sent} envois). Seuil d'attention (> 0,2%).",
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "Taux de désabonnement 7j : {$rate}% ({$unsubs} / {$sent} envois). Dans les normes."];
    }

    /**
     * #37 — Template neria_fallback et traduction FR présente
     * Si le template d'urgence n'a pas de traduction, il échouera silencieusement.
     */
    private function checkFallbackTemplate(): array
    {
        $db = \Db::getInstance();

        $hasTpl = (bool) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_translation`
            WHERE template = \'neria_fallback\'
        ');

        if (!$hasTpl) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Le template de secours neria_fallback est introuvable en base.'
                    . ' → Que faire : Réinstallez le module ou exécutez la migration importFromJson.',
            ];
        }

        $hasTrad = (bool) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_translation`
            WHERE template = \'neria_fallback\' AND lang = \'fr\'
              AND translation_key = \'fallback_subject\'
        ');

        if (!$hasTrad) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'neria_fallback présent mais la traduction FR (objet) est manquante.'
                    . ' → Que faire : Ouvrez le template dans l\'onglet Traductions et renseignez les champs FR.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'Template de secours neria_fallback présent avec traduction FR.'];
    }

    /**
     * #38 — Contrôleurs frontaux (track.php, unsubscribe.php, waitlist.php)
     * Ces fichiers doivent être présents dans le dossier du module.
     */
    private function checkFrontControllers(): array
    {
        $base    = _PS_MODULE_DIR_ . 'neria/controllers/front/';
        $missing = [];

        foreach (['track.php', 'unsubscribe.php', 'waitlist.php'] as $file) {
            if (!file_exists($base . $file)) {
                $missing[] = $file;
            }
        }

        if (!empty($missing)) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Fichiers frontaux manquants : ' . implode(', ', $missing) . '.'
                    . ' → Que faire : Réinstallez les fichiers depuis le package Neria.'
                    . ' Ces fichiers sont indispensables pour le tracking, les désabonnements et la liste d\'attente.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'Contrôleurs frontaux présents (track.php, unsubscribe.php, waitlist.php).'];
    }

    /**
     * #39 — Débordement de la file d'envoi
     * Plus de 1 000 messages en attente suggère un cron bloqué ou une boucle infinie.
     */
    private function checkQueueOverflow(): array
    {
        if (!class_exists('QueueManager')) {
            return ['status' => self::STATUS_OK, 'detail' => 'QueueManager absent — sans impact.'];
        }

        $pending = (int) \Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_queue`
            WHERE status = \'pending\'
        ');

        if ($pending > 5000) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => "{$pending} emails en attente dans la file — saturation probable."
                    . ' → Que faire : Vérifiez le cron d\'envoi, la connexion SMTP, et les logs Watchdog'
                    . ' pour identifier la cause de l\'accumulation.',
            ];
        }

        if ($pending > 1000) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "{$pending} emails en attente dans la file (seuil d'attention : 1 000)."
                    . ' → Que faire : Surveillez l\'évolution ; si la file continue de croître, vérifiez le cron.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "{$pending} email(s) en attente dans la file. Charge normale."];
    }

    /**
     * #40 — Table neria_behavioral_sent surdimensionnée
     * Sans purge automatique, cette table grossit indéfiniment et ralentit les crons.
     */
    private function checkBehavioralDedupSize(): array
    {
        $count = (int) \Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_behavioral_sent`
        ');

        if ($count > 200000) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => "La table neria_behavioral_sent contient {$count} lignes — taille critique."
                    . ' Cela peut ralentir significativement les crons comportementaux.'
                    . ' → Que faire : Exécutez manuellement une purge des entrées vieilles de plus de 90 jours'
                    . ' via phpMyAdmin ou SQL : DELETE FROM neria_behavioral_sent WHERE date_add < DATE_SUB(NOW(), INTERVAL 90 DAY).',
            ];
        }

        if ($count > 50000) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "La table neria_behavioral_sent contient {$count} lignes. Croissance normale mais surveillée (seuil : 50 000).",
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "Table de déduplication comportementale : {$count} lignes. Taille saine."];
    }

    /**
     * #41 — Configuration multi-expéditeur (NERIA_SENDERS_JSON)
     * Un JSON corrompu provoque l'envoi de TOUS les emails avec l'expéditeur par défaut
     * de la boutique, sans avertissement.
     */
    private function checkMultiSenderJson(): array
    {
        $raw = \Configuration::get('NERIA_SENDERS_JSON');

        if (empty($raw)) {
            return ['status' => self::STATUS_OK, 'detail' => 'Multi-expéditeur non configuré (fonctionnement mono-expéditeur).'];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'NERIA_SENDERS_JSON contient du JSON invalide : ' . json_last_error_msg() . '.'
                    . ' Tous les emails utilisent actuellement l\'expéditeur par défaut.'
                    . ' → Que faire : Corrigez la configuration dans l\'onglet Design → Multi-expéditeur.',
            ];
        }

        if (!is_array($decoded)) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'NERIA_SENDERS_JSON ne contient pas un tableau valide.'
                    . ' → Que faire : Réenregistrez la configuration multi-expéditeur depuis le BO.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => count($decoded) . ' configuration(s) d\'expéditeur chargée(s). JSON valide.'];
    }

    /**
     * #42 — Rapport mensuel : activé sans destinataire
     * Le rapport mensuel s'exécute mais n'est livré nulle part.
     */
    private function checkMonthlyReportConfig(): array
    {
        $enabled = (bool) \Configuration::get('NERIA_MONTHLY_REPORT_ENABLED');

        if (!$enabled) {
            return ['status' => self::STATUS_OK, 'detail' => 'Rapport mensuel désactivé.'];
        }

        $recipient = trim((string) \Configuration::get('NERIA_MONTHLY_REPORT_EMAIL'));

        if ($recipient === '') {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Le rapport mensuel est activé mais aucun email destinataire n\'est configuré.'
                    . ' → Que faire : Renseignez l\'adresse email dans les paramètres du rapport mensuel.',
            ];
        }

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "Le rapport mensuel est activé mais l'email destinataire est invalide : « {$recipient} »."
                    . ' → Que faire : Corrigez l\'adresse email dans les paramètres du rapport mensuel.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "Rapport mensuel actif — destinataire : {$recipient}."];
    }

    /**
     * #43 — Validité de la clé API DeepL
     * Une clé expirée ou invalide provoque l'échec silencieux des auto-traductions.
     */
    private function checkDeeplKeyValid(): array
    {
        $key = trim((string) \Configuration::get('NERIA_DEEPL_API_KEY'));

        if ($key === '') {
            return ['status' => self::STATUS_OK, 'detail' => 'Clé DeepL non configurée (traduction automatique désactivée).'];
        }

        // Appel minimal à l'endpoint /usage qui ne consomme pas de quota
        $isFree   = \Tools::substr($key, -3) === ':fx';
        $baseUrl  = $isFree ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $endpoint = $baseUrl . '/v2/usage';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['Authorization: DeepL-Auth-Key ' . $key],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $usage = json_decode($response, true);
            if (is_array($usage) && isset($usage['character_count'], $usage['character_limit'])) {
                $used  = $usage['character_count'];
                $limit = $usage['character_limit'];
                $pct   = $limit > 0 ? round($used / $limit * 100, 1) : 0;

                if ($pct >= 95) {
                    return [
                        'status' => self::STATUS_WARNING,
                        'detail' => "Quota DeepL presque épuisé : {$pct}% utilisé ({$used} / {$limit} caractères)."
                            . ' → Que faire : Passez au niveau supérieur ou désactivez la traduction automatique temporairement.',
                    ];
                }

                return ['status' => self::STATUS_OK, 'detail' => "Clé DeepL valide. Quota : {$pct}% utilisé ({$used} / {$limit} caractères)."];
            }

            return ['status' => self::STATUS_OK, 'detail' => 'Clé DeepL valide (quota illisible).'];
        }

        if ($httpCode === 403) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Clé DeepL invalide ou expirée (HTTP 403).'
                    . ' → Que faire : Renouvelez votre clé dans le compte DeepL et mettez-la à jour dans Neria.',
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => "Impossible de vérifier la clé DeepL (HTTP {$httpCode}). L'API est peut-être temporairement indisponible.",
        ];
    }

    /**
     * #44 — Mémoire PHP disponible
     * Le rendu d'emails complexes (TCPDF, CSS inlining, DeepL) nécessite >= 128 MB.
     */
    private function checkPhpMemoryLimit(): array
    {
        $raw  = ini_get('memory_limit');
        $val  = trim($raw);
        $last = strtolower(substr($val, -1));
        $num  = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024; // fall through
            case 'm': $num *= 1024; // fall through
            case 'k': $num *= 1024; break;
        }
        $bytes = $num;

        if ($bytes < 0) {
            // -1 = illimité
            return ['status' => self::STATUS_OK, 'detail' => 'Mémoire PHP illimitée (memory_limit = -1).'];
        }

        $mb = (int) ($bytes / 1024 / 1024);

        if ($mb < 64) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => "Mémoire PHP insuffisante : {$mb} MB (minimum requis : 128 MB)."
                    . ' → Que faire : Augmentez memory_limit dans php.ini ou .htaccess à 128M minimum.',
            ];
        }

        if ($mb < 128) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "Mémoire PHP limite : {$mb} MB. 128 MB recommandés pour la génération de PDF et le CSS inlining."
                    . ' → Que faire : Augmentez memory_limit à 128M dans php.ini.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "Mémoire PHP : {$mb} MB. Suffisante pour toutes les opérations Neria."];
    }

    /**
     * #45 — Intégrité du programme de fidélité
     * Détecte les soldes négatifs et les récompenses sans propriétaire.
     */
    private function checkLoyaltyIntegrity(): array
    {
        if (!class_exists('LoyaltyManager')) {
            return ['status' => self::STATUS_OK, 'detail' => 'LoyaltyManager absent — module fidélité non chargé.'];
        }

        $db = \Db::getInstance();

        $negative = (int) $db->getValue('
            SELECT COUNT(*) FROM (
                SELECT SUM(`points`) AS total
                FROM `' . _DB_PREFIX_ . 'neria_loyalty_points`
                GROUP BY `id_customer`
                HAVING total < 0
            ) AS neg
        ');

        if ($negative > 0) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => "{$negative} client(s) avec un solde de points de fidélité négatif."
                    . ' → Que faire : Vérifiez la logique de déduction des points dans LoyaltyManager.'
                    . ' Un solde négatif ne devrait jamais se produire.',
            ];
        }

        $orphaned = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_loyalty_rewards` r
            LEFT JOIN `' . _DB_PREFIX_ . 'neria_loyalty_points` p ON p.id_customer = r.id_customer
            WHERE p.id_customer IS NULL
        ');

        if ($orphaned > 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "{$orphaned} récompense(s) de fidélité sans compte points associé."
                    . ' → Que faire : Nettoyage recommandé — ces récompenses appartiennent à des clients sans historique de points.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'Programme de fidélité : intégrité des données vérifiée.'];
    }

    /**
     * #46 — Fraîcheur des segments comportementaux
     * La segmentation doit être recalculée au moins toutes les 48h.
     */
    private function checkSegmentFreshness(): array
    {
        if (!class_exists('SegmentManager')) {
            return ['status' => self::STATUS_OK, 'detail' => 'SegmentManager absent — segmentation non activée.'];
        }

        $lastRun = \Configuration::get('NERIA_SEGMENT_LAST_RUN');

        if (!$lastRun) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Segmentation comportementale jamais exécutée.'
                    . ' → Que faire : Lancez manuellement le cron de segmentation ou attendez le passage cron automatique.',
            ];
        }

        $ageH = round((time() - strtotime($lastRun)) / 3600, 1);

        if ($ageH > 72) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => "Segments non recalculés depuis {$ageH}h (dernier recalcul : {$lastRun})."
                    . ' → Que faire : Vérifiez que le cron de segmentation est déclenché chaque jour.',
            ];
        }

        if ($ageH > 48) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "Segments en retard de recalcul : {$ageH}h depuis la dernière exécution.",
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "Segments recalculés il y a {$ageH}h. À jour."];
    }

    /**
     * #47 — Fraîcheur des scores CLV (Customer Lifetime Value)
     * Des scores vieux de plus de 48h donnent des prédictions inexactes.
     */
    private function checkClvFreshness(): array
    {
        if (!class_exists('ClvManager')) {
            return ['status' => self::STATUS_OK, 'detail' => 'ClvManager absent — CLV non activé.'];
        }

        // CLV est calculé à la volée — on vérifie que les données sources (churn_score) sont fraîches
        $db = \Db::getInstance();

        $count = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_churn_score`
        ');

        if ($count === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => 'Aucun score churn en base — le CLV dynamique sera approximatif.'
                    . ' → Que faire : Attendez le premier calcul de segmentation ou déclenchez-le manuellement.',
            ];
        }

        $lastCalc = $db->getValue('
            SELECT MAX(computed_at) FROM `' . _DB_PREFIX_ . 'neria_churn_score`
        ');

        if (!$lastCalc) {
            return ['status' => self::STATUS_OK, 'detail' => "CLV dynamique actif. Scores churn présents ({$count} clients)."];
        }

        $ageH = round((time() - strtotime($lastCalc)) / 3600, 1);

        if ($ageH > 72) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "Scores churn (source CLV) non recalculés depuis {$ageH}h ({$count} clients)."
                    . ' → Que faire : Vérifiez le cron de segmentation comportementale.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "CLV dynamique actif — scores sources ({$count} clients) calculés il y a {$ageH}h."];
    }

    /**
     * #48 — Relances devis B2B bloquées
     * Un devis dépassé depuis plus de 7 jours sans relance indique un cron bloqué.
     */
    private function checkQuoteRemindersStuck(): array
    {
        $tableExists = (bool) \Db::getInstance()->getValue('
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = \'' . _DB_PREFIX_ . 'neria_quote\'
        ');

        if (!$tableExists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Table neria_quote absente — relances devis non activées.'];
        }

        $stuck = (int) \Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_quote`
            WHERE status = \'active\'
              AND sent_48h = 0
              AND date_add < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        if ($stuck > 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "{$stuck} devis actif(s) depuis plus de 7 jours sans aucune relance envoyée."
                    . ' → Que faire : Vérifiez le cron de relances devis. La première relance devrait partir sous 48h.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'Relances devis : aucun devis actif bloqué sans relance.'];
    }

    /**
     * #49 — Campagnes actives ciblant un segment vide
     * Envoyer une campagne à un segment vide déclenche 0 email mais consomme des ressources.
     */
    private function checkCampaignEmptySegment(): array
    {
        if (!class_exists('SegmentManager')) {
            return ['status' => self::STATUS_OK, 'detail' => 'SegmentManager absent — vérification ignorée.'];
        }

        $db = \Db::getInstance();

        $tableExists = (bool) $db->getValue('
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = \'' . _DB_PREFIX_ . 'neria_campaign\'
        ');

        if (!$tableExists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Table neria_campaign absente.'];
        }

        $campaigns = $db->executeS('
            SELECT id_campaign, name, target_segment
            FROM `' . _DB_PREFIX_ . 'neria_campaign`
            WHERE active = 1
              AND target_segment IS NOT NULL
              AND target_segment != \'\'
              AND target_segment != \'all\'
        ');

        if (empty($campaigns)) {
            return ['status' => self::STATUS_OK, 'detail' => 'Aucune campagne active avec ciblage de segment.'];
        }

        $emptySegments = [];
        $mgr = new \SegmentManager($this->module);

        foreach ($campaigns as $c) {
            $seg = $c['target_segment'];
            try {
                $customerCount = $mgr->getSegmentCustomerCount($seg);
                if ($customerCount === 0) {
                    $emptySegments[] = '"' . $c['name'] . '" (segment : ' . $seg . ')';
                }
            } catch (\Exception $e) {
                // Silencieux — méthode peut ne pas exister sur toutes les versions
            }
        }

        if (!empty($emptySegments)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => count($emptySegments) . ' campagne(s) active(s) ciblant un segment vide : '
                    . implode(', ', $emptySegments) . '.'
                    . ' → Que faire : Recalculez les segments ou ajustez le ciblage de ces campagnes.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => count($campaigns) . ' campagne(s) active(s) — tous les segments ciblés contiennent des clients.'];
    }

    /**
     * #50 — Couverture de l'attribution sur les commandes récentes
     * Si l'attribution est active mais que les 7 derniers jours n'ont aucun enregistrement,
     * le cookie de tracking est probablement cassé.
     */
    private function checkAttributionCoverage(): array
    {
        $enabled = (bool) \Configuration::get('NERIA_ATTRIBUTION_ENABLED');

        if (!$enabled) {
            return ['status' => self::STATUS_OK, 'detail' => 'Attribution désactivée.'];
        }

        $db = \Db::getInstance();

        $recentOrders = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
            WHERE date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND current_state NOT IN (
                SELECT id_order_state FROM `' . _DB_PREFIX_ . 'order_state` WHERE deleted = 1
              )
        ');

        if ($recentOrders < 5) {
            return ['status' => self::STATUS_OK, 'detail' => 'Attribution active. Trop peu de commandes récentes pour mesurer la couverture.'];
        }

        $tableExists = (bool) $db->getValue('
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = \'' . _DB_PREFIX_ . 'neria_attribution\'
        ');

        if (!$tableExists) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => 'Attribution activée mais table neria_attribution introuvable.'
                    . ' → Que faire : Réinstallez le module pour créer les tables manquantes.',
            ];
        }

        $attributed = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_attribution`
            WHERE date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        $rate = round($attributed / $recentOrders * 100, 1);

        if ($attributed === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "Attribution active mais 0 commande sur {$recentOrders} attribuée ces 7 derniers jours."
                    . ' → Que faire : Vérifiez que le cookie de tracking Neria est bien déposé sur le front-office.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "Attribution : {$rate}% de couverture sur les commandes des 7 derniers jours ({$attributed} / {$recentOrders})."];
    }

    /**
     * #51 — Volume de l'historique des traductions
     * La table grossit à chaque sauvegarde et peut ralentir la page Traductions.
     */
    private function checkTranslationHistorySize(): array
    {
        $count = (int) \Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_translation_history`
        ');

        if ($count > 50000) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => "L'historique des traductions contient {$count} entrées — taille importante."
                    . ' → Que faire : Nettoyez les entrées anciennes via l\'onglet Traductions'
                    . ' ou directement en SQL : DELETE FROM neria_translation_history WHERE date_add < DATE_SUB(NOW(), INTERVAL 180 DAY).',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => "Historique des traductions : {$count} entrées. Taille normale."];
    }

    /**
     * #52 — Tests A/B actifs avec variante B incomplète
     * Une variante B vide envoie exactement le même contenu que la variante A —
     * le test ne mesure rien.
     */
    private function checkAbtestTranslationGaps(): array
    {
        $db = \Db::getInstance();

        $tableExists = (bool) $db->getValue('
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = \'' . _DB_PREFIX_ . 'neria_abtest\'
        ');

        if (!$tableExists) {
            return ['status' => self::STATUS_OK, 'detail' => 'Table neria_abtest absente — tests A/B non activés.'];
        }

        $activeTests = $db->executeS('
            SELECT t.id_abtest, t.template,
                   (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_abtest_translation` tr
                    WHERE tr.id_abtest = t.id_abtest) AS b_count
            FROM `' . _DB_PREFIX_ . 'neria_abtest` t
            WHERE t.is_active = 1
        ');

        if (empty($activeTests)) {
            return ['status' => self::STATUS_OK, 'detail' => 'Aucun test A/B actif.'];
        }

        $emptyB = [];
        foreach ($activeTests as $test) {
            if ((int) $test['b_count'] === 0) {
                $emptyB[] = $test['template'];
            }
        }

        if (!empty($emptyB)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => count($emptyB) . ' test(s) A/B actif(s) sans aucune traduction en variante B : '
                    . implode(', ', $emptyB) . '.'
                    . ' → Que faire : Ouvrez ces tests dans l\'onglet Traductions et renseignez les textes de la variante B,'
                    . ' ou désactivez ces tests.',
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => count($activeTests) . ' test(s) A/B actif(s) — toutes les variantes B ont du contenu.'];
    }

    private function logResultsToWatchdog(array $results): void
    {
        foreach ($results as $checkKey => $result) {
            $status = $result['status'] ?? self::STATUS_OK;
            if ($status === self::STATUS_OK) {
                continue;
            }

            $detail  = $result['detail'] ?? '';
            $fixed   = !empty($result['auto_fixed']) ? ' [autocorrigé]' : '';
            $message = '[health:' . $checkKey . '] ' . $detail . $fixed;

            if ($status === self::STATUS_ERROR) {
                $this->watchdog->error($message, '', 'HealthCheckManager');
            } else {
                $this->watchdog->warning($message, '', 'HealthCheckManager');
            }
        }
    }

    // ============================================================
    // DIAGNOSTIC DE CODE — scan manuel à la demande uniquement
    // (jamais dans runAutoChecksIfDue : trop coûteux pour le trafic front)
    // ============================================================

    /**
     * Scan complet du code du module : clés de traduction BO utilisées mais
     * absentes du dictionnaire, et références de classes cassées
     * (class_exists() sur un fichier manquant).
     * Déclenché uniquement par le bouton "Scanner le code" de l'onglet Aide.
     *
     * Volontairement SANS vérification de syntaxe PHP via exec("php -l") :
     * tout appel système (exec/shell_exec/system) est scruté par le
     * validateur PrestaShop Addons comme surface de risque potentielle,
     * même sécurisé — et ne fonctionne de toute façon pas sur la plupart
     * des hébergements mutualisés. La syntaxe se vérifie en local avant
     * chaque déploiement (déjà fait manuellement pendant le développement).
     */
    public function runCodeDiagnostic(): array
    {
        $results = [
            'admin_trad_usage'  => $this->checkAdminTranslationKeyUsage(),
            'class_references'  => $this->checkClassReferencesIntegrity(),
        ];

        $this->logResultsToWatchdog($results);

        return $results;
    }

    /**
     * Scanne tous les templates .tpl à la recherche de clés
     * {neria_admin key='...'} littérales (sans variable Smarty) qui
     * n'existent pas dans data/admin_translations.json — le marchand
     * verrait alors un texte vide dans le BO.
     */
    private function checkAdminTranslationKeyUsage(): array
    {
        $root     = rtrim($this->module->getLocalPath(), '/');
        $jsonPath = $root . '/data/admin_translations.json';

        if (!is_file($jsonPath)) {
            return ['status' => self::STATUS_ERROR, 'detail' => 'Fichier data/admin_translations.json introuvable.'];
        }

        $dict = json_decode((string) file_get_contents($jsonPath), true) ?: [];
        $tplFiles = $this->globRecursive($root . '/views/templates/admin', '.tpl');

        $usedKeys = [];
        foreach ($tplFiles as $file) {
            $content = (string) file_get_contents($file);
            // Retire les commentaires Smarty {* ... *} — évite de capturer des
            // exemples de syntaxe écrits en doc-header (ex: {neria_admin key='...'})
            $content = preg_replace('/\{\*.*?\*\}/s', '', $content) ?? $content;
            // Ne capture que les clés littérales (guillemets simples/doubles, sans `$` ni backtick)
            if (preg_match_all('/neria_admin\s+key=([\'"])([a-zA-Z0-9_.\-]+)\1/', $content, $m)) {
                foreach ($m[2] as $key) {
                    $usedKeys[$key][] = basename($file);
                }
            }
        }

        $missing = [];
        foreach ($usedKeys as $key => $inFiles) {
            if (!array_key_exists($key, $dict)) {
                $missing[$key] = $inFiles[0];
            }
        }

        if ($missing) {
            $count  = count($missing);
            $sample = [];
            $i = 0;
            foreach ($missing as $key => $file) {
                $sample[] = "{$key} ({$file})";
                if (++$i >= 5) {
                    break;
                }
            }
            return [
                'status' => self::STATUS_ERROR,
                'detail' => $count . ' clé(s) de traduction BO utilisée(s) mais absente(s) du dictionnaire : '
                    . implode(', ', $sample) . ($count > 5 ? '… (' . ($count - 5) . ' autres)' : '')
                    . ' → Que faire : ajoutez ces clés dans data/admin_translations.json.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => count($usedKeys) . ' clé(s) de traduction BO utilisée(s) — toutes présentes dans le dictionnaire.',
        ];
    }

    /**
     * Scanne tout le code PHP à la recherche de `class_exists('X')` et
     * vérifie que src/X.php existe bien et déclare la classe attendue —
     * détecte un fichier renommé/supprimé par erreur qui ferait échouer
     * silencieusement une fonctionnalité entière.
     */
    private function checkClassReferencesIntegrity(): array
    {
        $root  = rtrim($this->module->getLocalPath(), '/');
        $files = array_merge(
            glob($root . '/*.php') ?: [],
            $this->globRecursive($root . '/src'),
            $this->globRecursive($root . '/controllers')
        );

        $referenced = [];
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            // Retire les commentaires (/* ... */, /** ... */, // ...) — évite de
            // capturer des exemples de syntaxe écrits en docblock
            $content = preg_replace('#/\*.*?\*/#s', '', $content) ?? $content;
            $content = preg_replace('#(?<!:)//.*$#m', '', $content) ?? $content;
            if (preg_match_all('/class_exists\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $content, $m)) {
                foreach ($m[1] as $class) {
                    $referenced[$class] = true;
                }
            }
        }

        $broken = [];
        foreach (array_keys($referenced) as $class) {
            // Les classes système PHP/PrestaShop ne vivent pas dans src/
            if (!is_file($root . '/src/' . $class . '.php')) {
                // Peut être une classe autoloadée ailleurs (ex: coeur PrestaShop) — on
                // ne signale que si la classe n'existe nulle part au runtime non plus.
                if (!class_exists($class)) {
                    $broken[] = $class;
                }
            }
        }

        if ($broken) {
            $count = count($broken);
            return [
                'status' => self::STATUS_ERROR,
                'detail' => $count . ' classe(s) référencée(s) via class_exists() introuvable(s) : '
                    . implode(', ', array_slice($broken, 0, 8)) . ($count > 8 ? '… (' . ($count - 8) . ' autres)' : '')
                    . ' → Que faire : fichier manquant dans src/ ou nom de classe mal orthographié.',
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => count($referenced) . ' classe(s) référencée(s) via class_exists() — toutes résolues correctement.',
        ];
    }

    /**
     * Liste récursivement les fichiers d'un répertoire filtrés par extension.
     */
    private function globRecursive(string $dir, string $ext = '.php'): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $ext)) {
                $result[] = $file->getPathname();
            }
        }

        return $result;
    }
}
