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
            'upgrade_integrity'    => $this->checkUpgradeIntegrity(),
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
            'secrets_encrypted'    => $this->checkSecretsEncrypted(),
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
            'visibility_freshness' => $this->checkVisibilityIntegrationsFreshness(),
            'active_cron'          => $this->checkActiveCron(),
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
     * Utilisait auparavant une liste séparée de 3 crons codés en dur
     * ($autoCrons), un système parallèle à WatchdogManager::KNOWN_CRONS
     * (8 crons, table dédiée neria_cron_health) qui alimente le widget
     * visuel de l'onglet Aide — les deux avaient divergé : 5 tâches de
     * fond (webhook, queue, rapport mensuel, conversions upsell, récaps
     * fidélité, campagnes saisonnières) n'étaient jamais surveillées ici,
     * seulement visibles dans le widget. Utilise maintenant
     * WatchdogManager::getCronHealth() comme source unique de vérité
     * (chaque cron avec son propre seuil de retard). La réputation de
     * domaine reste suivie séparément (hors KNOWN_CRONS, voir
     * DomainReputationManager::runFullCheck()).
     */
    private function checkCronsHealth(): array
    {
        $stale = [];
        $never = [];

        if (class_exists('WatchdogManager')) {
            $cronHealth = (new \WatchdogManager($this->module))->getCronHealth();
            foreach ($cronHealth as $info) {
                if ($info['last_run'] === null) {
                    $never[] = $info['label'];
                } elseif ($info['is_late']) {
                    $hoursAgo = $info['age_minutes'] !== null ? round($info['age_minutes'] / 60) : 0;
                    $stale[] = $info['label'] . ' (' . AdminTranslator::tVars('health.cron_hours_ago', ['hours' => $hoursAgo]) . ')';
                }
            }
        }

        // Réputation de domaine : cron auto hors KNOWN_CRONS, suivi séparément
        $lastDomrep  = (string) \Configuration::get(self::CRON_LAST_DOMREP);
        $domrepLabel = AdminTranslator::t('health.cron_label_domrep');
        if ($lastDomrep === '' || $lastDomrep === false) {
            $never[] = $domrepLabel;
        } elseif ((time() - (int) strtotime($lastDomrep)) > 26 * 3600) {
            $hoursAgo = round((time() - (int) strtotime($lastDomrep)) / 3600);
            $stale[] = $domrepLabel . ' (' . AdminTranslator::tVars('health.cron_hours_ago', ['hours' => $hoursAgo]) . ')';
        }

        if ($never) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.crons_never_run', ['list' => implode(', ', $never)])
                    . ' ' . AdminTranslator::t('health.crons_never_advice'),
            ];
        }

        if ($stale) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.crons_stale', ['list' => implode('; ', $stale)])
                    . ' ' . AdminTranslator::t('health.crons_stale_advice'),
            ];
        }

        // Cron manuel bounces IMAP — alerte seulement si déjà utilisé et en retard
        $lastBounces = (string) \Configuration::get(self::CRON_LAST_BOUNCES);
        if ($lastBounces && (time() - (int) strtotime($lastBounces)) > 72 * 3600) {
            $hoursAgo = round((time() - (int) strtotime($lastBounces)) / 3600);
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.cron_bounces_stale', ['hours' => $hoursAgo]),
            ];
        }

        $bounceInfo = $lastBounces
            ? AdminTranslator::tVars('health.cron_bounces_last', ['date' => $lastBounces])
            : AdminTranslator::t('health.cron_bounces_never');

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.crons_all_ok', ['bounce_info' => $bounceInfo]),
        ];
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
                'detail' => AdminTranslator::t('health.smtp_php_mail'),
            ];
        }

        // Méthode 2 = SMTP
        if ($method === '2') {
            $server = (string) \Configuration::get('PS_MAIL_SERVER');
            $user   = (string) \Configuration::get('PS_MAIL_USER');

            if ($server === '') {
                return [
                    'status' => self::STATUS_ERROR,
                    'detail' => AdminTranslator::t('health.smtp_no_server'),
                ];
            }

            if ($user === '') {
                return [
                    'status' => self::STATUS_WARNING,
                    'detail' => AdminTranslator::tVars('health.smtp_no_user', ['server' => $server]),
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'detail' => AdminTranslator::tVars('health.smtp_ok', ['server' => $server, 'user' => $user]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.smtp_other_method', ['method' => $method])];
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
                'detail' => AdminTranslator::t('health.bounce_rate_insufficient'),
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
                'detail' => AdminTranslator::tVars('health.bounce_rate_critical', ['rate' => $rate, 'bounces' => $bounces24h, 'sent' => $sent24h]),
            ];
        }

        if ($rate >= 2) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.bounce_rate_warning', ['rate' => $rate, 'bounces' => $bounces24h, 'sent' => $sent24h]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.bounce_rate_ok', ['rate' => $rate, 'bounces' => $bounces24h, 'sent' => $sent24h]),
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.consecutive_failures_ok')];
        }

        if ($count >= self::CONSECUTIVE_THRESHOLD) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.consecutive_failures_critical', ['count' => $count]),
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.consecutive_failures_warning', ['count' => $count, 'threshold' => self::CONSECUTIVE_THRESHOLD]),
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
            $list  = implode(', ', array_slice($missing, 0, 5)) . ($count > 5 ? '… (' . ($count - 5) . ' autres)' : '');
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.template_files_missing', ['count' => $count, 'list' => $list]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.template_files_ok', ['count' => count($templates)]),
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
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::t('health.trad_keys_json_missing')];
        }

        $trad = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($trad)) {
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::t('health.trad_keys_json_invalid')];
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
                'detail' => AdminTranslator::tVars('health.trad_keys_ok', ['count' => count($index)]),
            ];
        }

        $detail = '';
        if (!empty($missing)) {
            $detail .= AdminTranslator::tVars('health.trad_keys_lang_gaps', ['count' => count($missing)]);
        }
        if ($dbMissing > 0) {
            $detail .= AdminTranslator::tVars('health.trad_keys_db_gaps', ['count' => $dbMissing]);
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => $detail . AdminTranslator::t('health.trad_keys_advice'),
        ];
    }

    /**
     * #18 — Hooks PS enregistrés
     * Un hook absent après mise à jour PS = feature entière silencieusement inactive.
     */
    /**
     * Contrôle proactif — Hooks réellement enregistrés
     * Vérifiait auparavant seulement 5 hooks codés en dur ici, une copie
     * séparée qui a divergé de la vraie liste : sur les 14 hooks que le
     * module enregistre réellement (Neria::HOOKS, source unique de vérité
     * utilisée à l'installation), 9 n'étaient jamais surveillés — dont
     * actionDeleteGDPRCustomer, exactement le hook RGPD trouvé manquant le
     * 2026-07-05 (bug upgrade 1.0.16, voir [[feedback_upgrade_wrong_config_key]]).
     * Parcourt maintenant Neria::HOOKS en entier ; les 5 hooks "cœur"
     * (fonctionnement de base : envoi email, tracking, CSS admin,
     * attribution de revenus) restent en ERROR si absents, les 9 autres
     * remontent en WARNING (important mais non bloquant).
     */
    private function checkHooksRegistered(): array
    {
        $coreHooks = [
            'actionEmailSendBefore',
            'actionMailAlterMessageBeforeSend',
            'displayBackOfficeHeader',
            'actionObjectOrderAddAfter',
            'actionOrderStatusPostUpdate',
        ];

        $idModule         = (int) $this->module->id;
        $missingCore      = [];
        $missingSecondary = [];

        foreach (\Neria::HOOKS as $hookName) {
            $hooked = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "hook_module` hm
                 JOIN `" . _DB_PREFIX_ . "hook` h ON h.id_hook = hm.id_hook
                 WHERE h.`name` = '" . pSQL($hookName) . "'
                   AND hm.`id_module` = {$idModule}"
            );
            if ($hooked) {
                continue;
            }
            if (in_array($hookName, $coreHooks, true)) {
                $missingCore[] = $hookName;
            } else {
                $missingSecondary[] = $hookName;
            }
        }

        if ($missingCore || $missingSecondary) {
            $parts = [];
            if ($missingCore) {
                $parts[] = AdminTranslator::tVars('health.hooks_core_missing', [
                    'count' => count($missingCore),
                    'hooks' => implode(', ', $missingCore),
                ]);
            }
            if ($missingSecondary) {
                $parts[] = AdminTranslator::tVars('health.hooks_secondary_missing', [
                    'count' => count($missingSecondary),
                    'hooks' => implode(', ', $missingSecondary),
                ]);
            }

            return [
                'status'  => $missingCore ? self::STATUS_ERROR : self::STATUS_WARNING,
                'detail'  => implode(' ', $parts) . ' ' . AdminTranslator::t('health.hooks_missing_advice'),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.hooks_all_registered', ['count' => count(\Neria::HOOKS)]),
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
                'detail' => AdminTranslator::tVars('health.version_registered', ['version' => $currentVersion]),
            ];
        }

        if (version_compare($installedVersion, $currentVersion, '<')) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.version_outdated', ['installed' => $installedVersion, 'current' => $currentVersion]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.version_synced', ['version' => $currentVersion]),
        ];
    }

    /**
     * Contrôle proactif — Intégrité réelle des upgrades
     * checkVersionSync() ne compare que deux nombres — un numéro de version
     * peut être faux (comme le bug NERIA_VERSION vs NERIA_INSTALLED_VERSION
     * trouvé le 2026-07-05, qui a désynchronisé ce compteur pendant 9
     * versions sans que rien ne s'en aperçoive) ou avancer alors qu'un
     * upgrade a échoué à mi-chemin. Ce contrôle vérifie à la place la PREUVE
     * CONCRÈTE que chaque upgrade attendu a bien produit son effet (table
     * créée, colonne ajoutée, config initialisée, hook enregistré).
     *
     * Convention pour un futur upgrade-X.Y.Z.php : ajouter une entrée dans
     * $manifest ci-dessous décrivant comment vérifier son effet. Ne modifie
     * jamais les scripts d'upgrade existants (déjà exécutés) — uniquement ce
     * manifeste, indépendant.
     */
    private function checkUpgradeIntegrity(): array
    {
        $currentVersion = $this->module->version;

        // 1.0.5, 1.0.13 : pas d'effet distinct et vérifiable (contenu de
        // template / config tierce optionnelle) — volontairement absents.
        // 1.0.17 : couvert séparément par checkSecretsEncrypted().
        $manifest = [
            '1.0.1'  => ['type' => 'table',  'name' => 'neria_quote'],
            '1.0.2'  => ['type' => 'table',  'name' => 'neria_reconciliation'],
            '1.0.3'  => ['type' => 'table',  'name' => 'neria_product_lifespan'],
            '1.0.4'  => ['type' => 'table',  'name' => 'neria_propensity_score'],
            '1.0.6'  => ['type' => 'table',  'name' => 'neria_queue'],
            '1.0.7'  => ['type' => 'column', 'table' => 'neria_seasonal_campaign', 'name' => 'gift_mode'],
            '1.0.8'  => ['type' => 'table',  'name' => 'neria_collection'],
            '1.0.9'  => ['type' => 'table',  'name' => 'neria_look_rule'],
            '1.0.10' => ['type' => 'table',  'name' => 'neria_waitlist'],
            '1.0.11' => ['type' => 'config', 'name' => 'NERIA_GHOST_CART_ENABLED'],
            '1.0.12' => ['type' => 'table',  'name' => 'neria_preferences'],
            '1.0.14' => ['type' => 'table',  'name' => 'neria_abtest_history'],
            '1.0.15' => ['type' => 'table',  'name' => 'neria_cron_health'],
            '1.0.16' => ['type' => 'hook',   'name' => 'actionDeleteGDPRCustomer'],
            '1.0.18' => ['type' => 'config', 'name' => 'NERIA_CRON_TOKEN'],
            '1.0.19' => ['type' => 'config_global_set', 'name' => 'NERIA_CRON_ENABLED'],
            '1.0.20' => ['type' => 'table',  'name' => 'neria_voice_profile'],
        ];

        $failures = [];
        foreach ($manifest as $version => $rule) {
            if (version_compare($currentVersion, $version, '<')) {
                continue; // upgrade pas encore attendu sur cette version du module
            }
            if (!$this->verifyUpgradeRule($rule)) {
                $failures[] = $version;
            }
        }

        if ($failures) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.upgrade_integrity_failed', [
                    'count'    => count($failures),
                    'versions' => implode(', ', $failures),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.upgrade_integrity_ok')];
    }

    /**
     * Vérifie une règle du manifeste d'intégrité des upgrades (voir
     * checkUpgradeIntegrity()) : existence d'une table/colonne, présence
     * d'une config, ou enregistrement d'un hook.
     */
    private function verifyUpgradeRule(array $rule): bool
    {
        switch ($rule['type']) {
            case 'table':
                return (bool) $this->db->getValue(
                    "SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = '" . _DB_PREFIX_ . $rule['name'] . "'"
                );

            case 'column':
                return (bool) $this->db->getValue(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = '" . _DB_PREFIX_ . $rule['table'] . "'
                       AND column_name = '" . $rule['name'] . "'"
                );

            case 'config':
                return (string) \Configuration::get($rule['name']) !== '';

            case 'config_global_set':
                return \Configuration::getGlobalValue($rule['name']) !== false;

            case 'hook':
                $hookId = \Hook::getIdByName($rule['name']);
                if (!$hookId) {
                    return false;
                }
                return (bool) $this->db->getValue(
                    "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "hook_module`
                     WHERE id_hook = " . (int) $hookId . " AND id_module = " . (int) $this->module->id
                );

            default:
                return true;
        }
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
                'detail' => AdminTranslator::tVars('health.open_rate_insufficient', ['sent' => $sent7]),
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
                'detail' => AdminTranslator::tVars('health.open_rate_critical', ['rate' => $rate, 'open' => $open7, 'sent' => $sent7]),
            ];
        }

        if ($rate < 15) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.open_rate_low', ['rate' => $rate, 'open' => $open7, 'sent' => $sent7]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.open_rate_ok', ['rate' => $rate, 'open' => $open7, 'sent' => $sent7]),
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
                'detail' => AdminTranslator::t('health.engagement_insufficient'),
            ];
        }

        $recentRate   = round((int) ($recent['opened'] ?? 0) / $recentSent * 100, 1);
        $baselineRate = round((int) ($baseline['opened'] ?? 0) / $baselineSent * 100, 1);

        if ($baselineRate <= 0) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.engagement_baseline_zero')];
        }

        $relativeChange = round((($recentRate - $baselineRate) / $baselineRate) * 100, 1);

        if ($relativeChange <= -30) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.engagement_declining', ['recentRate' => $recentRate, 'baselineRate' => $baselineRate, 'relativeChange' => $relativeChange]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.engagement_stable', ['recentRate' => $recentRate, 'baselineRate' => $baselineRate]),
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
        $persistentThresholdDays = 7;
        $hasSpecificError = false;
        $maxErrorDays = 0;

        if (class_exists('SearchConsoleManager')) {
            $mgr = new \SearchConsoleManager($this->module);
            if ($mgr->isConnected()) {
                $age = $mgr->getCacheAge();
                if ($age === null || $age > $staleThresholdMinutes) {
                    $err = $mgr->getLastError();
                    if ($err !== '') {
                        $stale[] = 'Search Console (' . AdminTranslator::tVars('health.oauth_error_label', ['error' => $err]) . ')';
                        $hasSpecificError = true;
                        $errAt = $mgr->getLastErrorAt();
                        if ($errAt) {
                            $maxErrorDays = max($maxErrorDays, (int) floor((time() - $errAt) / 86400));
                        }
                    } else {
                        $ageLabel = $age === null
                            ? AdminTranslator::t('health.oauth_never_refreshed')
                            : AdminTranslator::tVars('health.oauth_age_days', ['days' => (int) round($age / 60 / 24)]);
                        $stale[] = 'Search Console (' . $ageLabel . ')';
                    }
                }
            }
        }

        if (class_exists('PostmasterManager')) {
            $mgr = new \PostmasterManager($this->module);
            if ($mgr->isConnected()) {
                $age = $mgr->getCacheAge();
                if ($age === null || $age > $staleThresholdMinutes) {
                    $err = $mgr->getLastError();
                    if ($err !== '') {
                        $stale[] = 'Postmaster Tools (' . AdminTranslator::tVars('health.oauth_error_label', ['error' => $err]) . ')';
                        $hasSpecificError = true;
                        $errAt = $mgr->getLastErrorAt();
                        if ($errAt) {
                            $maxErrorDays = max($maxErrorDays, (int) floor((time() - $errAt) / 86400));
                        }
                    } else {
                        $ageLabel = $age === null
                            ? AdminTranslator::t('health.oauth_never_refreshed')
                            : AdminTranslator::tVars('health.oauth_age_days', ['days' => (int) round($age / 60 / 24)]);
                        $stale[] = 'Postmaster Tools (' . $ageLabel . ')';
                    }
                }
            }
        }

        if ($stale) {
            // Une erreur API précise (ex. API désactivée, quota dépassé) est plus
            // actionnable que le message générique de reconnexion OAuth — sans
            // cette distinction, une vraie cause (ex. API à activer dans Google
            // Cloud Console) était masquée derrière "reconnectez-vous", qui
            // n'aurait rien résolu. Si cette erreur persiste sans interruption
            // depuis plus d'une semaine, on escalade en ERROR plutôt que
            // WARNING pour ne pas laisser une intégration cassée passer inaperçue.
            $isPersistent = $hasSpecificError && $maxErrorDays >= $persistentThresholdDays;

            if ($isPersistent) {
                $advice = AdminTranslator::tVars('health.oauth_advice_persistent', ['days' => $maxErrorDays]);
            } elseif ($hasSpecificError) {
                $advice = AdminTranslator::t('health.oauth_advice_specific_error');
            } else {
                $advice = AdminTranslator::t('health.oauth_advice_generic');
            }

            return [
                'status' => $isPersistent ? self::STATUS_ERROR : self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.oauth_stale_detail', ['list' => implode(', ', $stale)]) . ' ' . $advice,
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.oauth_all_fresh')];
    }

    /**
     * Contrôle proactif — Fraîcheur des intégrations de visibilité web
     * PageSpeedManager et SeoApiManager implémentent tous les deux
     * getCacheAge() (même mécanisme que SearchConsoleManager/PostmasterManager)
     * mais n'étaient surveillés par AUCUN contrôle Watchdog : si une clé
     * PageSpeed expire ou qu'un abonnement Semrush/Moz se termine, le score
     * de santé restait "OK" indéfiniment, l'erreur n'étant visible que dans
     * le journal brut. Même logique que checkOAuthFreshness() : staleness,
     * erreur API précise si disponible, escalade en ERROR après 7 jours
     * d'erreur continue.
     */
    private function checkVisibilityIntegrationsFreshness(): array
    {
        $stale = [];
        $staleThresholdMinutes = 60 * 24 * 3; // 3 jours
        $persistentThresholdDays = 7;
        $hasSpecificError = false;
        $maxErrorDays = 0;

        if (class_exists('PageSpeedManager')) {
            $mgr = new \PageSpeedManager($this->module);
            if ($mgr->isConfigured()) {
                $age = $mgr->getCacheAge();
                if ($age === null || $age > $staleThresholdMinutes) {
                    $err = $mgr->getLastError();
                    if ($err !== '') {
                        $stale[] = 'PageSpeed (' . AdminTranslator::tVars('health.oauth_error_label', ['error' => $err]) . ')';
                        $hasSpecificError = true;
                        $errAt = $mgr->getLastErrorAt();
                        if ($errAt) {
                            $maxErrorDays = max($maxErrorDays, (int) floor((time() - $errAt) / 86400));
                        }
                    } else {
                        $ageLabel = $age === null
                            ? AdminTranslator::t('health.oauth_never_refreshed')
                            : AdminTranslator::tVars('health.oauth_age_days', ['days' => (int) round($age / 60 / 24)]);
                        $stale[] = 'PageSpeed (' . $ageLabel . ')';
                    }
                }
            }
        }

        if (class_exists('SeoApiManager')) {
            $mgr = new \SeoApiManager($this->module);
            if ($mgr->isConfigured()) {
                $age = $mgr->getCacheAge();
                if ($age === null || $age > $staleThresholdMinutes) {
                    $err = $mgr->getLastError();
                    if ($err !== '') {
                        $stale[] = 'API SEO (' . AdminTranslator::tVars('health.oauth_error_label', ['error' => $err]) . ')';
                        $hasSpecificError = true;
                        $errAt = $mgr->getLastErrorAt();
                        if ($errAt) {
                            $maxErrorDays = max($maxErrorDays, (int) floor((time() - $errAt) / 86400));
                        }
                    } else {
                        $ageLabel = $age === null
                            ? AdminTranslator::t('health.oauth_never_refreshed')
                            : AdminTranslator::tVars('health.oauth_age_days', ['days' => (int) round($age / 60 / 24)]);
                        $stale[] = 'API SEO (' . $ageLabel . ')';
                    }
                }
            }
        }

        if ($stale) {
            $isPersistent = $hasSpecificError && $maxErrorDays >= $persistentThresholdDays;

            if ($isPersistent) {
                $advice = AdminTranslator::tVars('health.oauth_advice_persistent', ['days' => $maxErrorDays]);
            } elseif ($hasSpecificError) {
                $advice = AdminTranslator::t('health.visibility_advice_specific_error');
            } else {
                $advice = AdminTranslator::t('health.visibility_advice_generic');
            }

            return [
                'status' => $isPersistent ? self::STATUS_ERROR : self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.visibility_stale_detail', ['list' => implode(', ', $stale)]) . ' ' . $advice,
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.visibility_all_fresh')];
    }

    /**
     * Contrôle proactif — Cron externe actif
     * Neria fonctionne "out of the box" via hookDisplayHeader (déclenché
     * sur chaque page front-office), mais ce fallback dépend du trafic
     * visiteurs. Un vrai cron serveur (controllers/front/cron.php) rend
     * la surveillance réellement proactive. Ce contrôle est INFORMATIF
     * uniquement — son absence ne doit jamais faire baisser le score de
     * santé, c'est une recommandation, pas un prérequis.
     */
    private function checkActiveCron(): array
    {
        if (!class_exists('WatchdogManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.active_cron_unavailable')];
        }

        if (!\Configuration::getGlobalValue('NERIA_CRON_ENABLED')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.active_cron_disabled')];
        }

        $lastHit = (new \WatchdogManager($this->module))->getLastCronEndpointHit();

        if ($lastHit === null) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::t('health.active_cron_never'),
            ];
        }

        $ageHours = (time() - strtotime($lastHit)) / 3600;
        if ($ageHours > 26) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.active_cron_stale', ['days' => round($ageHours / 24, 1), 'lastHit' => $lastHit]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.active_cron_ok', ['lastHit' => $lastHit])];
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
                'detail' => AdminTranslator::t('health.hmac_missing'),
            ];
        }

        $keyLength = strlen(_COOKIE_KEY_);

        if ($keyLength < 32) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.hmac_short', ['length' => $keyLength]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.hmac_ok', ['length' => $keyLength]),
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.queue_disabled')];
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
                'detail' => AdminTranslator::tVars('health.queue_blocked_ok', ['pending' => $pending]),
            ];
        }

        return [
            'status' => self::STATUS_ERROR,
            'detail' => AdminTranslator::tVars('health.queue_blocked_critical', ['blocked' => $blocked]),
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
                'detail' => AdminTranslator::tVars('health.ajax_errors_recent', ['count' => $recentAjaxErrors]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::t('health.ajax_ok'),
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
                'detail' => AdminTranslator::tVars('health.critical_methods_missing', ['count' => count($errors), 'list' => implode(', ', $errors)]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.critical_methods_ok', ['ok' => $ok]),
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
                'detail' => AdminTranslator::tVars('health.managers_critical_missing', ['list' => implode(', ', $missingCritical)]),
            ];
        }

        if ($missingOptional) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.managers_optional_missing', ['list' => implode(', ', $missingOptional)]),
            ];
        }

        $total = count($critical) + count($optional);
        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.managers_all_ok', ['total' => $total]),
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.bounces_table_absent')];
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
            $cronStatus = $cronAgeH === null
                ? AdminTranslator::t('health.bounces_cron_never')
                : AdminTranslator::tVars('health.bounces_cron_late', ['hours' => $cronAgeH]);
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.bounces_cron_stale', ['recent' => $recent, 'cronStatus' => $cronStatus]),
            ];
        }

        $total = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$table}`");
        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.bounces_ok', ['total' => $total]),
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.webhooks_disabled')];
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
                'detail' => AdminTranslator::tVars('health.webhooks_ok', ['pending' => $pending]),
            ];
        }

        return [
            'status' => self::STATUS_ERROR,
            'detail' => AdminTranslator::tVars('health.webhooks_failed', ['failed' => $failed]),
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.abtest_stuck_disabled')];
        }

        $stuck = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `is_active` = 1
               AND `date_add` < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        if ($stuck === 0) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.abtest_stuck_none')];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.abtest_stuck_warning', ['stuck' => $stuck]),
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

        if ($key === '') {
            // Vérifie si des données chiffrées existent réellement
            $statTable  = _DB_PREFIX_ . 'neria_stat';
            $hasEncData = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = '{$statTable}'
                   AND column_name  = 'rendered_vars'"
            );

            if (!$hasEncData) {
                return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.crypto_disabled')];
            }

            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::t('health.crypto_key_missing'),
            ];
        }

        // La clé existe, mais existe-t-elle est-elle la BONNE clé ? Une clé
        // présente mais rotée/corrompue passerait le test ci-dessus sans
        // problème tout en cassant silencieusement le déchiffrement de tous
        // les identifiants API (IMAP, OAuth, webhook, DeepL…) — chaque appel
        // à CryptoManager::decrypt() renvoie alors '' sans lever d'erreur.
        // On tente un vrai déchiffrement sur un échantillon de secrets réels.
        if (!class_exists('CryptoManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.crypto_present_noclass')];
        }

        $secretKeys = [
            'NERIA_BOUNCE_IMAP_PASS', 'NERIA_BOUNCE_WEBHOOK_SECRET',
            'NERIA_POSTMASTER_CLIENT_SECRET', 'NERIA_SC_CLIENT_SECRET',
            'NERIA_WEBHOOK_SECRET', 'NERIA_DEEPL_KEY',
        ];
        $broken = [];
        foreach ($secretKeys as $cfgKey) {
            $stored = (string) \Configuration::getGlobalValue($cfgKey);
            if ($stored === '' || !\CryptoManager::isEncrypted($stored)) {
                continue; // rien de chiffré à tester pour cette clé
            }
            if (\CryptoManager::decrypt($stored) === '') {
                $broken[] = $cfgKey;
            }
        }

        if ($broken) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.crypto_broken', ['count' => count($broken), 'list' => implode(', ', $broken)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.crypto_ok')];
    }

    /**
     * Contrôle proactif — Secrets réellement chiffrés
     * checkCryptoKey() vérifie que la clé de chiffrement fonctionne (sur un
     * échantillon), mais pas que CHAQUE secret sensible connu est bien
     * chiffré : un upgrade de chiffrement qui n'a jamais tourné (ex. script
     * exécuté hors du flux d'upgrade officiel de PrestaShop) laisse des
     * secrets en clair sans que rien ne le signale. Ce contrôle parcourt la
     * liste centralisée CryptoManager::SENSITIVE_CONFIG_KEYS et vérifie que
     * chaque valeur non vide porte bien le préfixe ENC:.
     */
    private function checkSecretsEncrypted(): array
    {
        if (!class_exists('CryptoManager') || !\CryptoManager::isAvailable()) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.secrets_unavailable')];
        }

        $plaintext = [];
        foreach (\CryptoManager::SENSITIVE_CONFIG_KEYS as $key) {
            $value = (string) \Configuration::get($key);
            if ($value !== '' && !\CryptoManager::isEncrypted($value)) {
                $plaintext[] = $key;
            }
        }

        if (!empty($plaintext)) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.secrets_plaintext_found', [
                    'count' => count($plaintext),
                    'keys'  => implode(', ', $plaintext),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.secrets_all_encrypted')];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.send_volume_no_table')];
        }

        $today = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `event_type` = 'sent'
               AND DATE(`date_add`) = CURDATE()"
        );

        if ($today === 0) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.send_volume_none_today')];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.send_volume_normal_low', ['today' => $today])];
        }

        $ratio = $today / $avg;

        if ($ratio >= 5) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.send_volume_critical', ['today' => $today, 'avg' => sprintf('%.0f', $avg), 'ratio' => sprintf('%.1f', $ratio)]),
            ];
        }

        if ($ratio >= 3) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.send_volume_warning', ['today' => $today, 'avg' => sprintf('%.0f', $avg), 'ratio' => sprintf('%.1f', $ratio)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.send_volume_ok', ['today' => $today, 'avg' => sprintf('%.0f', $avg)])];
    }

    /**
     * #28 — Score de réputation de domaine sous le seuil
     * Le rapport est calculé automatiquement mais personne n'alerte
     * si le score chute sous 50 ou si le domaine est blacklisté.
     */
    private function checkDomainRepScore(): array
    {
        if (!class_exists('DomainReputationManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.domain_rep_disabled')];
        }

        $mgr    = new DomainReputationManager($this->module);
        $cached = $mgr->getCachedReport();

        if ($cached === null) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.domain_rep_no_report')];
        }

        $score = (int) ($cached['score'] ?? 100);
        $grade = (string) ($cached['grade'] ?? 'A');
        $hits  = count($cached['blacklists']['hits'] ?? []);

        if ($hits > 0 || $grade === 'F' || $grade === 'D') {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.domain_rep_critical', ['score' => $score, 'grade' => $grade, 'hits' => $hits]),
            ];
        }

        if ($score < 75 || $grade === 'C') {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.domain_rep_warning', ['score' => $score, 'grade' => $grade]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.domain_rep_ok', ['score' => $score, 'grade' => $grade])];
    }

    /**
     * #29 — Tables DB manquantes
     * Si un script d'upgrade a échoué silencieusement, une table entière
     * peut être absente : la feature plante sans exception PHP invisible.
     *
     * La liste attendue était codée en dur ici (26 tables) et avait divergé
     * de sql/install.sql (36 tables réelles) au fil des versions — 10 tables
     * jamais vérifiées, dont neria_translation, LA table centrale qui
     * stocke tout le contenu email personnalisable (21 000+ lignes). Pour
     * éviter que cette liste puisse à nouveau diverger silencieusement,
     * elle est maintenant extraite directement de sql/install.sql (la
     * source réellement utilisée pour créer les tables) au lieu d'être
     * recopiée à la main.
     */
    private function checkDbTables(): array
    {
        $expected = $this->getExpectedTablesFromInstallSql();

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
                'detail' => AdminTranslator::tVars('health.db_tables_missing', [
                    'count' => count($missing),
                    'list'  => implode(', ', $missing),
                ]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.db_tables_ok', ['count' => count($expected)]),
        ];
    }

    /**
     * Extrait la liste des tables `neria_*` directement de sql/install.sql
     * (source de vérité réellement utilisée à l'installation), pour que
     * checkDbTables() ne puisse plus diverger silencieusement de la
     * réalité comme cela s'est produit (26 tables vérifiées vs 36 réelles).
     * Filet de sécurité statique si le fichier est illisible/déplacé.
     */
    private function getExpectedTablesFromInstallSql(): array
    {
        $sqlFile = _PS_MODULE_DIR_ . $this->module->name . '/sql/install.sql';
        if (is_file($sqlFile)) {
            $content = file_get_contents($sqlFile);
            if ($content !== false && preg_match_all('/CREATE TABLE IF NOT EXISTS `PREFIX_(neria_[a-z_]+)`/i', $content, $matches)) {
                $tables = array_values(array_unique($matches[1]));
                if (!empty($tables)) {
                    return $tables;
                }
            }
        }

        // Filet de sécurité si sql/install.sql est introuvable/illisible.
        return [
            'neria_abtest', 'neria_abtest_history', 'neria_abtest_translation',
            'neria_attribution', 'neria_behavioral_sent', 'neria_blacklist',
            'neria_bounces', 'neria_calendar_event', 'neria_certificate',
            'neria_churn_score', 'neria_collection', 'neria_collection_sent',
            'neria_config', 'neria_cron_health', 'neria_custom_variable',
            'neria_customer_segment', 'neria_log', 'neria_look_rule',
            'neria_look_sent', 'neria_loyalty_points', 'neria_loyalty_rewards',
            'neria_preferences', 'neria_product_lifespan', 'neria_propensity_score',
            'neria_queue', 'neria_quote', 'neria_reconciliation',
            'neria_seasonal_campaign', 'neria_signature', 'neria_stat',
            'neria_translation', 'neria_translation_history', 'neria_upsell',
            'neria_voice_profile', 'neria_waitlist', 'neria_webhook_queue',
        ];
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
                'detail' => AdminTranslator::tVars('health.unsub_url_curl_error', ['error' => $error]),
            ];
        }

        // 200 ou 302 = page trouvée ; 404/500 = cassé
        if ($httpCode === 0 || $httpCode >= 400) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.unsub_url_http_error', ['code' => $httpCode]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.unsub_url_ok', ['code' => $httpCode])];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.waitlist_disabled')];
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
                'detail' => AdminTranslator::tVars('health.waitlist_ok', ['total' => $total]),
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.waitlist_warning', ['backlog' => $backlog]),
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.smtp_quota_none')];
        }

        $table = _DB_PREFIX_ . 'neria_stat';
        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.send_volume_no_table')];
        }

        $today = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `event_type` = 'sent' AND DATE(`date_add`) = CURDATE()"
        );

        $pct = ($today / $quota) * 100;

        if ($pct >= 100) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.smtp_quota_critical', ['today' => $today, 'quota' => $quota, 'pct' => sprintf('%.0f', $pct)]),
            ];
        }

        if ($pct >= 80) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.smtp_quota_warning', ['today' => $today, 'quota' => $quota, 'pct' => sprintf('%.0f', $pct)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.smtp_quota_ok', ['today' => $today, 'quota' => $quota, 'pct' => sprintf('%.0f', $pct)])];
    }

    /**
     * #33 — PTR / rDNS manquant
     * Certains serveurs de réception (Orange, SFR, serveurs corporate)
     * rejettent silencieusement les emails venant d'une IP sans PTR configuré.
     */
    private function checkPtrRecord(): array
    {
        if (!class_exists('DomainReputationManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.domain_rep_disabled')];
        }

        $cached = (new DomainReputationManager($this->module))->getCachedReport();

        if ($cached === null) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.ptr_no_report')];
        }

        $ptr = $cached['ptr'] ?? null;

        if (!is_array($ptr)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.ptr_not_analyzed')];
        }

        if (!empty($ptr['skipped'])) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.ptr_skipped')];
        }

        if (empty($ptr['found'])) {
            $ip = $cached['ip'] ?? '?';
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.ptr_missing', ['ip' => $ip]),
            ];
        }

        if (isset($ptr['valid']) && !$ptr['valid']) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.ptr_invalid', ['hostname' => $ptr['hostname'] ?? '?']),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.ptr_ok', ['hostname' => $ptr['hostname'] ?? ''])];
    }

    /**
     * #34 — Réputation Google Postmaster Tools
     * Alerte si le cache contient des données dégradées (spam rate élevé,
     * réputation LOW/BAD). Silencieux si l'intégration n'est pas configurée.
     */
    private function checkPostmasterReputation(): array
    {
        if (!class_exists('PostmasterManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.postmaster_disabled')];
        }

        $mgr = new \PostmasterManager($this->module);

        if (!$mgr->isConfigured()) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.postmaster_not_configured')];
        }

        if (!$mgr->isConnected()) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::t('health.postmaster_not_connected'),
            ];
        }

        $stats = $mgr->getCachedStats();
        if ($stats === null) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.postmaster_no_data_yet')];
        }

        if (empty($stats)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.postmaster_empty_data')];
        }

        $errors   = [];
        $warnings = [];

        foreach ($stats as $ps) {
            $domain   = $ps['domain']            ?? '?';
            $rep      = $ps['domain_reputation'] ?? null;
            $spamRate = $ps['spam_rate']          ?? null;

            if ($rep === 'BAD') {
                $errors[] = AdminTranslator::tVars('health.postmaster_rep_bad', ['domain' => $domain]);
            } elseif ($rep === 'LOW') {
                $errors[] = AdminTranslator::tVars('health.postmaster_rep_low', ['domain' => $domain]);
            } elseif ($rep === 'MEDIUM') {
                $warnings[] = AdminTranslator::tVars('health.postmaster_rep_medium', ['domain' => $domain]);
            }

            if ($spamRate !== null && $spamRate > 0.3) {
                $errors[] = AdminTranslator::tVars('health.postmaster_spam_critical', ['rate' => $spamRate, 'domain' => $domain]);
            } elseif ($spamRate !== null && $spamRate > 0.1) {
                $warnings[] = AdminTranslator::tVars('health.postmaster_spam_warning', ['rate' => $spamRate, 'domain' => $domain]);
            }
        }

        if (!empty($errors)) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.postmaster_errors_wrapper', ['errors' => implode(' | ', $errors)]),
            ];
        }

        if (!empty($warnings)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.postmaster_warnings_wrapper', ['warnings' => implode(' | ', $warnings)]),
            ];
        }

        $cacheAge = $mgr->getCacheAge();
        $ageStr   = $cacheAge !== null ? AdminTranslator::tVars('health.postmaster_age_suffix', ['min' => $cacheAge]) : '';
        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.postmaster_ok', ['ageStr' => $ageStr])];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.click_rate_no_opens')];
        }

        $clicks = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
            WHERE event_type = \'click\'
              AND date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        if ($clicks === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.click_rate_no_clicks', ['opens' => $opens]),
            ];
        }

        $rate = round($clicks / $opens * 100, 1);

        if ($rate < 0.5) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.click_rate_low', ['rate' => $rate, 'clicks' => $clicks, 'opens' => $opens]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.click_rate_ok', ['rate' => $rate, 'clicks' => $clicks, 'opens' => $opens])];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.unsub_spike_insufficient')];
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
                'detail' => AdminTranslator::tVars('health.unsub_spike_critical', ['rate' => $rate, 'unsubs' => $unsubs, 'sent' => $sent]),
            ];
        }

        if ($rate > 0.2) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.unsub_spike_warning', ['rate' => $rate, 'unsubs' => $unsubs, 'sent' => $sent]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.unsub_spike_ok', ['rate' => $rate, 'unsubs' => $unsubs, 'sent' => $sent])];
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
                'detail' => AdminTranslator::t('health.fallback_tpl_missing'),
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
                'detail' => AdminTranslator::t('health.fallback_trad_missing'),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.fallback_tpl_ok')];
    }

    /**
     * #38 — Contrôleurs frontaux (track.php, unsubscribe.php, waitlist.php)
     * Ces fichiers doivent être présents dans le dossier du module.
     */
    /**
     * Liste ne vérifiait que 3 fichiers sur 8 réellement présents dans
     * controllers/front/ — cron.php (point d'entrée cron externe),
     * oauth.php/oauthsc.php (callbacks OAuth Postmaster/Search Console)
     * et bounce.php/preferences.php n'étaient jamais vérifiés. À mettre à
     * jour si un nouveau contrôleur front est ajouté (pas de source
     * indépendante du système de fichiers à dériver ici, contrairement aux
     * tables/hooks/crons).
     */
    private function checkFrontControllers(): array
    {
        $base     = _PS_MODULE_DIR_ . $this->module->name . '/controllers/front/';
        $expected = ['bounce.php', 'cron.php', 'oauth.php', 'oauthsc.php', 'preferences.php', 'track.php', 'unsubscribe.php', 'waitlist.php'];
        $missing  = [];

        foreach ($expected as $file) {
            if (!file_exists($base . $file)) {
                $missing[] = $file;
            }
        }

        if (!empty($missing)) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.front_controllers_missing', [
                    'list' => implode(', ', $missing),
                ]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.front_controllers_ok', ['count' => count($expected)]),
        ];
    }

    /**
     * #39 — Débordement de la file d'envoi
     * Plus de 1 000 messages en attente suggère un cron bloqué ou une boucle infinie.
     */
    private function checkQueueOverflow(): array
    {
        if (!class_exists('QueueManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.queue_overflow_disabled')];
        }

        $pending = (int) \Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_queue`
            WHERE status = \'pending\'
        ');

        if ($pending > 5000) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.queue_overflow_critical', ['pending' => $pending]),
            ];
        }

        if ($pending > 1000) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.queue_overflow_warning', ['pending' => $pending]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.queue_overflow_ok', ['pending' => $pending])];
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
                'detail' => AdminTranslator::tVars('health.behavioral_dedup_critical', ['count' => $count]),
            ];
        }

        if ($count > 50000) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.behavioral_dedup_warning', ['count' => $count]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.behavioral_dedup_ok', ['count' => $count])];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.multisender_none')];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.multisender_invalid_json', ['err' => json_last_error_msg()]),
            ];
        }

        if (!is_array($decoded)) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::t('health.multisender_not_array'),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.multisender_ok', ['count' => count($decoded)])];
    }

    /**
     * #42 — Rapport mensuel : activé sans destinataire
     * Le rapport mensuel s'exécute mais n'est livré nulle part.
     */
    private function checkMonthlyReportConfig(): array
    {
        // Clés réelles : MonthlyReportManager::CONFIG_ENABLED/CONFIG_RECIPIENTS
        // (NERIA_REPORT_ENABLED / NERIA_REPORT_RECIPIENTS) — pas
        // NERIA_MONTHLY_REPORT_ENABLED/EMAIL, qui ne sont jamais écrites.
        // Ce contrôle rapportait donc systématiquement "désactivé", même
        // avec le rapport mensuel actif sans destinataire valide.
        $enabled = (bool) \Configuration::get(\MonthlyReportManager::CONFIG_ENABLED);

        if (!$enabled) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.monthly_report_disabled')];
        }

        $recipient = trim((string) \Configuration::get(\MonthlyReportManager::CONFIG_RECIPIENTS));

        if ($recipient === '') {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::t('health.monthly_report_no_recipient'),
            ];
        }

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.monthly_report_invalid_email', ['recipient' => $recipient]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.monthly_report_ok', ['recipient' => $recipient])];
    }

    /**
     * #43 — Validité de la clé API DeepL
     * Une clé expirée ou invalide provoque l'échec silencieux des auto-traductions.
     */
    private function checkDeeplKeyValid(): array
    {
        // La clé est enregistrée sous NERIA_DEEPL_KEY (ConfigManager::KEY_DEEPL_KEY,
        // action save_deepl_key) — pas NERIA_DEEPL_API_KEY, qui n'est jamais écrite.
        // Ce contrôle rapportait donc systématiquement "non configurée", même
        // avec une clé DeepL correctement enregistrée et fonctionnelle.
        $key = trim((string) \Configuration::get('NERIA_DEEPL_KEY'));

        if ($key === '') {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.deepl_not_configured')];
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
                        'detail' => AdminTranslator::tVars('health.deepl_quota_warning', ['pct' => $pct, 'used' => $used, 'limit' => $limit]),
                    ];
                }

                return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.deepl_ok_quota', ['pct' => $pct, 'used' => $used, 'limit' => $limit])];
            }

            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.deepl_ok_unreadable')];
        }

        if ($httpCode === 403) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::t('health.deepl_invalid'),
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.deepl_check_failed', ['code' => $httpCode]),
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.memory_unlimited')];
        }

        $mb = (int) ($bytes / 1024 / 1024);

        if ($mb < 64) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.memory_critical', ['mb' => $mb]),
            ];
        }

        if ($mb < 128) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.memory_warning', ['mb' => $mb]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.memory_ok', ['mb' => $mb])];
    }

    /**
     * #45 — Intégrité du programme de fidélité
     * Détecte les soldes négatifs et les récompenses sans propriétaire.
     */
    private function checkLoyaltyIntegrity(): array
    {
        if (!class_exists('LoyaltyManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.loyalty_disabled')];
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
                'detail' => AdminTranslator::tVars('health.loyalty_negative', ['negative' => $negative]),
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
                'detail' => AdminTranslator::tVars('health.loyalty_orphaned', ['orphaned' => $orphaned]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.loyalty_ok')];
    }

    /**
     * #46 — Fraîcheur des segments comportementaux
     * La segmentation doit être recalculée au moins toutes les 48h.
     */
    private function checkSegmentFreshness(): array
    {
        if (!class_exists('SegmentManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.segment_disabled')];
        }

        // SegmentManager ne tient pas de flag global de dernière exécution
        // (NERIA_SEGMENT_LAST_RUN n'est écrite nulle part) — la fraîcheur
        // réelle se lit dans computed_at, par client, dans la table de
        // segmentation. Ce contrôle rapportait donc systématiquement
        // "jamais exécuté", même la segmentation tournant chaque nuit.
        $lastRun = \Db::getInstance()->getValue(
            'SELECT MAX(`computed_at`) FROM `' . _DB_PREFIX_ . 'neria_customer_segment`'
        );

        if (!$lastRun) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::t('health.segment_never'),
            ];
        }

        $ageH = round((time() - strtotime($lastRun)) / 3600, 1);

        if ($ageH > 72) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.segment_critical', ['ageH' => $ageH, 'lastRun' => $lastRun]),
            ];
        }

        if ($ageH > 48) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.segment_late', ['ageH' => $ageH]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.segment_ok', ['ageH' => $ageH])];
    }

    /**
     * #47 — Fraîcheur des scores CLV (Customer Lifetime Value)
     * Des scores vieux de plus de 48h donnent des prédictions inexactes.
     */
    private function checkClvFreshness(): array
    {
        if (!class_exists('ClvManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.clv_disabled')];
        }

        // CLV est calculé à la volée — on vérifie que les données sources (churn_score) sont fraîches
        $db = \Db::getInstance();

        $count = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_churn_score`
        ');

        if ($count === 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::t('health.clv_no_scores'),
            ];
        }

        $lastCalc = $db->getValue('
            SELECT MAX(computed_at) FROM `' . _DB_PREFIX_ . 'neria_churn_score`
        ');

        if (!$lastCalc) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.clv_active_no_calc', ['count' => $count])];
        }

        $ageH = round((time() - strtotime($lastCalc)) / 3600, 1);

        if ($ageH > 72) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.clv_stale', ['ageH' => $ageH, 'count' => $count]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.clv_ok', ['count' => $count, 'ageH' => $ageH])];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.quote_no_table')];
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
                'detail' => AdminTranslator::tVars('health.quote_stuck', ['stuck' => $stuck]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.quote_ok')];
    }

    /**
     * #49 — Campagnes actives ciblant un segment vide
     * Envoyer une campagne à un segment vide déclenche 0 email mais consomme des ressources.
     */
    private function checkCampaignEmptySegment(): array
    {
        if (!class_exists('SegmentManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.campaign_seg_disabled')];
        }

        $db = \Db::getInstance();

        $tableExists = (bool) $db->getValue('
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = \'' . _DB_PREFIX_ . 'neria_campaign\'
        ');

        if (!$tableExists) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.campaign_no_table')];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.campaign_none_targeted')];
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
                'detail' => AdminTranslator::tVars('health.campaign_empty_warning', ['count' => count($emptySegments), 'list' => implode(', ', $emptySegments)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.campaign_ok', ['count' => count($campaigns)])];
    }

    /**
     * #50 — Couverture de l'attribution sur les commandes récentes
     * Si l'attribution est active mais que les 7 derniers jours n'ont aucun enregistrement,
     * le cookie de tracking est probablement cassé.
     */
    private function checkAttributionCoverage(): array
    {
        // L'attribution de revenus (last-click 24h) est une fonctionnalité
        // toujours active — il n'existe aucun interrupteur marchand pour la
        // désactiver (le cookie neria_ref est posé sans condition dans
        // track.php). Ce contrôle vérifiait auparavant une clé de
        // configuration NERIA_ATTRIBUTION_ENABLED qui n'était écrite nulle
        // part, ce qui le rendait toujours inactif.
        $db = \Db::getInstance();

        $recentOrders = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
            WHERE date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND current_state NOT IN (
                SELECT id_order_state FROM `' . _DB_PREFIX_ . 'order_state` WHERE deleted = 1
              )
        ');

        if ($recentOrders < 5) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.attribution_insufficient')];
        }

        $tableExists = (bool) $db->getValue('
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = \'' . _DB_PREFIX_ . 'neria_attribution\'
        ');

        if (!$tableExists) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::t('health.attribution_no_table'),
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
                'detail' => AdminTranslator::tVars('health.attribution_zero', ['recentOrders' => $recentOrders]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.attribution_ok', ['rate' => $rate, 'attributed' => $attributed, 'recentOrders' => $recentOrders])];
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
                'detail' => AdminTranslator::tVars('health.trad_history_warning', ['count' => $count]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.trad_history_ok', ['count' => $count])];
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
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.abtest_trad_no_table')];
        }

        // Chaque test A/B a 2 lignes (variant='A' et variant='B') — seule la
        // ligne B peut recevoir du texte personnalisé (neria_abtest_translation),
        // la ligne A représente le template par défaut sans surcharge. Filtrer
        // sur variant='B' évite de compter chaque test deux fois et de lister
        // le même template en double dans le message.
        $activeTests = $db->executeS('
            SELECT t.id_abtest, t.template,
                   (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_abtest_translation` tr
                    WHERE tr.id_abtest = t.id_abtest) AS b_count
            FROM `' . _DB_PREFIX_ . 'neria_abtest` t
            WHERE t.is_active = 1 AND t.variant = \'B\'
        ');

        if (empty($activeTests)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.abtest_trad_none')];
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
                'detail' => AdminTranslator::tVars('health.abtest_trad_gaps_warning', ['count' => count($emptyB), 'list' => implode(', ', $emptyB)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.abtest_trad_ok', ['count' => count($activeTests)])];
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
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::t('health.admin_trad_json_missing')];
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
            $sampleStr = implode(', ', $sample) . ($count > 5 ? '… (' . ($count - 5) . ' autres)' : '');
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.admin_trad_missing_keys', ['count' => $count, 'sample' => $sampleStr]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.admin_trad_usage_ok', ['count' => count($usedKeys)]),
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
            $list  = implode(', ', array_slice($broken, 0, 8)) . ($count > 8 ? '… (' . ($count - 8) . ' autres)' : '');
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.class_refs_broken', ['count' => $count, 'list' => $list]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.class_refs_ok', ['count' => count($referenced)]),
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
