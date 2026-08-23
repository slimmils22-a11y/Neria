<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — HealthCheckManager
 *
 * Diagnostic actif : contrôles proactifs qui vérifient que les mécanismes
 * clés du module produisent bien les résultats attendus, pas seulement
 * qu'ils se terminent sans exception.
 *
 * Contrôles automatiques (1×/jour via hookDisplayHeader) + contrôles manuels.
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
    const CONFIG_RENDER_CANARY_LAST_RUN = 'NERIA_RENDER_CANARY_LAST_RUN';
    const RENDER_CANARY_THROTTLE_SECONDS = 86400; // 1x/jour max
    const THROTTLE_SECONDS = 86400; // 24 h

    // Clés de suivi des crons individuels
    const CRON_LAST_BEHAVIORAL = 'NERIA_CRON_LAST_BEHAVIORAL';
    const CRON_LAST_CALENDAR   = 'NERIA_CRON_LAST_CALENDAR';
    const CRON_LAST_BOUNCES    = 'NERIA_CRON_LAST_BOUNCES';
    const CRON_LAST_DOMREP     = 'NERIA_CRON_LAST_DOMREP';

    // Round 186 : horodatage dédié à la DERNIÈRE EXÉCUTION RÉELLE du scan
    // lourd known_regressions_guard() — distinct de CONFIG_LAST_RUN, qui est
    // mis à jour aussi bien en mode léger (hookDisplayHeader, sans le scan
    // lourd) qu'en mode lourd, et masquerait donc une absence prolongée du
    // scan lourd derrière un trafic front-office normal. Ce scan ne
    // s'exécute que via le vrai cron serveur (jeton) ou le bouton
    // "Diagnostic complet" du BO — jamais via hookDisplayHeader.
    const KRG_LAST_RUN = 'NERIA_KRG_LAST_RUN';
    const KRG_STALE_THRESHOLD_HOURS = 48;

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

    /**
     * Round 141 : checkKnownRegressionsGuard() relisait certains fichiers
     * sources (BehavioralCronManager, LoyaltyManager, CertificateManager…)
     * jusqu'à 9 fois via file_get_contents() DANS UNE MÊME EXÉCUTION, sans
     * aucune mise en cache. Ce tableau mémorise le contenu déjà lu par
     * chemin de fichier pour la durée de vie de l'instance — voir
     * readModuleSrc().
     *
     * @var array<string,string>
     */
    private array $srcCache = [];

    public function __construct(Neria $module)
    {
        $this->module   = $module;
        $this->db       = \Db::getInstance();
        $this->idShop   = (int) \Context::getContext()->shop->id;
        $this->watchdog = new WatchdogManager($module);
    }

    /**
     * Lit le contenu d'un fichier source une seule fois par instance et
     * réutilise le résultat pour tous les appels suivants sur le même
     * chemin — voir $srcCache.
     */
    private function readModuleSrc(string $file): string
    {
        if (!array_key_exists($file, $this->srcCache)) {
            $this->srcCache[$file] = is_file($file) ? (file_get_contents($file) ?: '') : '';
        }

        return $this->srcCache[$file];
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
     * Lance tous les contrôles automatiques si 24 h se sont écoulées.
     *
     * @param bool $allowHeavyScans Round 141 : par défaut false — le contrôle
     *  known_regressions_guard() (~150 lectures de fichiers + centaines de
     *  regex) est trop coûteux pour tourner dans le chemin de rendu d'un
     *  visiteur front (déclenchement passif via hookDisplayHeader). Passer
     *  true uniquement depuis un déclenchement serveur réel (cron token),
     *  où aucun visiteur n'attend la réponse. Le dernier résultat connu de
     *  ce contrôle reste affiché sur la page Santé entre deux exécutions
     *  lourdes — voir buildAllChecks().
     */
    public function runAutoChecksIfDue(bool $allowHeavyScans = false): void
    {
        $lastRun = (string) \Configuration::get(self::CONFIG_LAST_RUN);
        if ($lastRun && (time() - (int) strtotime($lastRun)) < self::THROTTLE_SECONDS) {
            return;
        }

        // Round 154 : verrou MySQL — même piège déjà corrigé pour la queue
        // d'envoi, la queue webhook, le cron comportemental, le calendrier,
        // le rapport mensuel et (ce même round) le digest Watchdog/la
        // réputation de domaine. Sans lui, deux déclenchements concurrents
        // (hookDisplayHeader sur deux visiteurs simultanés, ou fallback
        // front + vrai cron serveur) pouvaient tous deux lancer
        // buildAllChecks() (jusqu'à ~150 contrôles, plus coûteux avec les
        // scans lourds) en parallèle — gaspillage CPU pur ici (le résultat
        // final `CONFIG_RESULTS` est un simple "dernier écrivain gagne",
        // idempotent), mais évité au même titre que les autres blocs de
        // runBackgroundJobs() par cohérence.
        if ((int) $this->db->getValue("SELECT GET_LOCK('neria_health_autochecks', 0)") !== 1) {
            return;
        }

        try {
            // Revérifie après obtention du verrou : un autre process a pu
            // terminer son propre passage pendant qu'on attendait.
            $lastRun = (string) \Configuration::get(self::CONFIG_LAST_RUN);
            if ($lastRun && (time() - (int) strtotime($lastRun)) < self::THROTTLE_SECONDS) {
                return;
            }

            \Configuration::updateValue(self::CONFIG_LAST_RUN, date('Y-m-d H:i:s'));

            $results = $this->buildAllChecks($allowHeavyScans);

            \Configuration::updateValue(self::CONFIG_RESULTS, json_encode($results, JSON_UNESCAPED_UNICODE));
            $this->logResultsToWatchdog($results);
        } finally {
            $this->db->execute("SELECT RELEASE_LOCK('neria_health_autochecks')");
        }
    }

    /**
     * Diagnostic complet à la demande — ignore le throttle 24h.
     * Utilisé par le bouton "Diagnostic complet" dans l'onglet Aide.
     * Déclenché explicitement par un admin dans le BO : jamais dans le
     * chemin de rendu d'un visiteur, donc les scans lourds sont autorisés.
     */
    public function runFullDiagnostic(): array
    {
        \Configuration::updateValue(self::CONFIG_LAST_RUN, date('Y-m-d H:i:s'));
        $results = $this->buildAllChecks(true);
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

    /**
     * Exécute chaque contrôle un par un avec isolation — auparavant
     * buildAllChecks() était un simple tableau littéral d'appels directs
     * ($this->checkXxx() évalué immédiatement pour chaque entrée), sans
     * try/catch : une seule exception dans UN contrôle (bug dans le
     * contrôle lui-même, dépendance manquante) faisait planter la
     * construction du tableau ENTIER, donc toute la page "Aide → Santé"
     * du BO — alors que d'autres portions du module suivent explicitement
     * la doctrine "ne bloque jamais le front" avec des try/catch
     * individuels. Un contrôle qui échoue est désormais isolé et remonté
     * comme un résultat STATUS_ERROR normal, sans affecter les ~117 autres.
     *
     * @param bool $allowHeavyScans Round 141 : si false, le contrôle
     *  known_regressions_guard() n'est PAS ré-exécuté — trop coûteux pour un
     *  chemin de rendu front. Son dernier résultat connu (getLastResults())
     *  est réutilisé tel quel s'il existe, pour que la page Santé du BO ne
     *  perde pas ce contrôle entre deux exécutions lourdes.
     */
    private function buildAllChecks(bool $allowHeavyScans = true): array
    {
        $results = [];
        $previous = $allowHeavyScans ? [] : $this->getLastResults();

        foreach ($this->checkMethodMap() as $key => $method) {
            if (!$allowHeavyScans && $key === 'known_regressions_guard') {
                $results[$key] = $previous[$key] ?? [
                    'status' => self::STATUS_OK,
                    'detail' => AdminTranslator::t('health.known_regressions_guard_pending'),
                ];
                continue;
            }

            try {
                $results[$key] = $this->$method();
                if ($key === 'known_regressions_guard') {
                    \Configuration::updateGlobalValue(self::KRG_LAST_RUN, date('Y-m-d H:i:s'));
                }
            } catch (\Throwable $e) {
                $results[$key] = [
                    'status' => self::STATUS_ERROR,
                    'detail' => AdminTranslator::tVars('health.check_internal_error', ['check' => $key, 'error' => $e->getMessage()]),
                ];
            }
        }
        return $results;
    }

    private function checkMethodMap(): array
    {
        return [
            // ── Flux email ──────────────────────────────────────────
            'sent_reconciliation'  => 'checkSentReconciliation',
            'pixel_in_html'        => 'checkPixelInHtml',
            'theme_override'       => 'checkThemeOverride',
            'class_override'       => 'checkClassOverride',
            'smarty_compile_check' => 'checkSmartyCompileCheck',
            'upgrade_script_safety' => 'checkUpgradeScriptSafety',
            'config_defaults_seeded' => 'checkConfigDefaultsSeeded',
            'upgrade_version_file'  => 'checkUpgradeScriptExistsForVersion',
            'security_pattern_scan' => 'checkSecurityPatternScan',
            'destructive_actions_post' => 'checkDestructiveActionsRequirePost',
            'hardcoded_date_format'    => 'checkHardcodedDateFormat',
            'rtl_hardcoded_align'      => 'checkRtlHardcodedAlignment',
            'display_price_missing_lang' => 'checkDisplayPriceMissingLang',
            'hardcoded_decimal_format' => 'checkHardcodedDecimalFormat',
            'chained_str_replace'   => 'checkChainedStrReplace',
            'customer_email_shop_scope' => 'checkCustomerEmailLookupMissingShop',
            'default_currency_usage' => 'checkDefaultCurrencyUsage',
            'upgrade_unique_key_shop_scope' => 'checkUpgradeUniqueKeyShopScope',
            'tpl_request_uri_escape' => 'checkTplRequestUriEscape',
            'cron_strict_date_equality' => 'checkCronStrictDateEquality',
            'cron_loop_try_catch'     => 'checkCronLoopMissingTryCatch',
            'tpl_js_escape_missing'    => 'checkTplJsEscapeMissing',
            'imap_timeout_missing'     => 'checkImapTimeoutMissing',
            'oauth_refresh_error_surfaced' => 'checkOAuthRefreshErrorSurfaced',
            'known_regressions_guard' => 'checkKnownRegressionsGuard',
            'known_regressions_guard_freshness' => 'checkKnownRegressionsGuardFreshness',
            'control_center_defaults_consistency' => 'checkControlCenterDefaultsConsistency',
            'sql_pattern_risks'     => 'checkSqlPatternRisks',
            'unescaped_like_metachars' => 'checkUnescapedLikeMetachars',
            'template_cat_mapping_complete' => 'checkTemplateCategoryMappingComplete',
            'php_mysql_clock_mismatch' => 'checkPhpMysqlClockMismatch',
            'i18n_pattern_risks'    => 'checkI18nPatternRisks',
            'hardcoded_french_text' => 'checkHardcodedFrenchText',
            'idlang_missing'        => 'checkMissingIdLangInLinks',
            'version_files_sync'    => 'checkModuleVersionFilesSync',
            'translation_dict_coverage' => 'checkTranslationDictionaryCoverage',
            'clickable_tracking_links'  => 'checkClickableTrackingLinks',
            'dev_tool_residue'          => 'checkDevToolResidue',
            'fragile_neriaconfig_usage' => 'checkFragileNeriaConfigUsage',
            'bare_template_var_keys'    => 'checkBareTemplateVarKeys',
            'txt_placeholder_coverage' => 'checkTxtPlaceholderCoverage',
            'orphaned_voucher_reservations' => 'checkOrphanedVoucherReservations',
            'orphaned_waitlist_claims' => 'checkOrphanedWaitlistClaims',
            'encoded_residual_links' => 'checkEncodedResidualLinks',
            'txt_raw_html_leak' => 'checkTxtRawHtmlLeak',
            'crypto_key_health' => 'checkCryptoKeyHealth',
            'html_txt_pairs' => 'checkHtmlTxtPairs',
            'template_files'       => 'checkTemplateFiles',
            'trad_keys'            => 'checkTradKeys',
            'open_rate_7d'         => 'checkOpenRate7d',
            'bounce_rate'          => 'checkBounceRate',
            'consecutive_failures' => 'checkConsecutiveFailures',
            // ── Infrastructure ─────────────────────────────────────
            'hooks_registered'     => 'checkHooksRegistered',
            'cron_triggered'       => 'checkCronTriggered',
            'crons_health'         => 'checkCronsHealth',
            'queue_blocked'        => 'checkQueueBlocked',
            'ajax_endpoints'       => 'checkAjax',
            'bounces_unprocessed'  => 'checkBouncesUnprocessed',
            // ── Configuration & sécurité ───────────────────────────
            'config_keys'          => 'checkConfigKeys',
            'version_sync'         => 'checkVersionSync',
            'upgrade_integrity'    => 'checkUpgradeIntegrity',
            'hmac_security'        => 'checkHmacSecurity',
            'smtp_config'          => 'checkSmtpConfig',
            'list_unsubscribe'     => 'checkListUnsubscribeApi',
            'translation_gaps'     => 'checkTranslationGaps',
            // ── Ressources ─────────────────────────────────────────
            'assets'               => 'checkAssets',
            'managers_available'   => 'checkManagersAvailable',
            'critical_methods'     => 'checkCriticalMethods',
            // ── Surveillance avancée ────────────────────────────────
            'webhook_failures'     => 'checkWebhookFailures',
            'abtest_stuck'         => 'checkAbtestStuck',
            'crypto_key'           => 'checkCryptoKey',
            'secrets_encrypted'    => 'checkSecretsEncrypted',
            'send_volume_spike'    => 'checkSendVolumeSpike',
            'domain_rep_score'     => 'checkDomainRepScore',
            'ptr_record'           => 'checkPtrRecord',
            'db_tables'            => 'checkDbTables',
            'unsubscribe_url'      => 'checkUnsubscribeUrl',
            'waitlist_backlog'     => 'checkWaitlistBacklog',
            'smtp_quota'           => 'checkSmtpQuota',
            'postmaster_rep'       => 'checkPostmasterReputation',
            // ── Flux email avancé ──────────────────────────────────────
            'click_rate_7d'        => 'checkClickRate7d',
            'unsubscribe_spike'    => 'checkUnsubscribeSpike',
            'fallback_template'    => 'checkFallbackTemplate',
            'front_controllers'    => 'checkFrontControllers',
            // ── Infrastructure avancée ─────────────────────────────────
            'queue_overflow'       => 'checkQueueOverflow',
            'behavioral_dedup'     => 'checkBehavioralDedupSize',
            // ── Configuration avancée ──────────────────────────────────
            'multi_sender_json'    => 'checkMultiSenderJson',
            'monthly_report_cfg'   => 'checkMonthlyReportConfig',
            'deepl_key_valid'      => 'checkDeeplKeyValid',
            'php_memory_limit'     => 'checkPhpMemoryLimit',
            // ── Sous-systèmes ──────────────────────────────────────────
            'loyalty_integrity'    => 'checkLoyaltyIntegrity',
            'segment_freshness'    => 'checkSegmentFreshness',
            'clv_freshness'        => 'checkClvFreshness',
            'quote_reminders'      => 'checkQuoteRemindersStuck',
            'campaign_empty_seg'   => 'checkCampaignEmptySegment',
            // ── Qualité des données ────────────────────────────────────
            'attribution_coverage' => 'checkAttributionCoverage',
            'history_table_size'   => 'checkTranslationHistorySize',
            'abtest_trad_gaps'     => 'checkAbtestTranslationGaps',
            // ── Contrôles proactifs ─────────────────────────────────────
            'engagement_trend'     => 'checkEngagementTrend',
            'oauth_freshness'      => 'checkOAuthFreshness',
            'visibility_freshness' => 'checkVisibilityIntegrationsFreshness',
            'active_cron'          => 'checkActiveCron',
            'template_staleness'   => 'checkTemplateStaleness',
            // ── Régression rendu (issus du test exhaustif 2026-07-07) ──
            'blacklist_stale_files'  => 'checkBlacklistStaleFiles',
            'residual_vars_recent'   => 'checkRecentResidualVarsWarnings',
            'sig_social_recent'      => 'checkSignatureSocialRenderedRecently',
            'action_banner_coverage' => 'checkActionBannerCoverage',
            'orphan_placeholders'    => 'checkOrphanPlaceholders',
            'render_canary_recent'   => 'checkRenderCanaryRecent',
            'milestone_order_health' => 'checkMilestoneOrderHealth',
            'custom_vars_completeness' => 'checkCustomVarsCompleteness',
            // ── Ajouts 2026-07-16 (scan de couverture Watchdog) ────────
            'churn_propensity_freshness'  => 'checkChurnPropensityFreshness',
            'collection_look_products'    => 'checkCollectionLookRulesProductValidity',
            'queue_failed_rate'           => 'checkQueueFailedRate',
            // ── Ajouts 2026-07-16 (2e passage de scan Watchdog) ────────
            'json_config_integrity'       => 'checkJsonConfigIntegrity',
            'crypto_unavailable_plain'    => 'checkCryptoUnavailableWithPlainData',
            'abtest_variant_pair'         => 'checkAbtestVariantPairIntegrity',
            'milestone_voucher_cartrule'  => 'checkMilestoneVoucherCartRuleValidity',
            'css_inliner_failures'        => 'checkCssInlinerSilentFailures',
            // ── Ajouts 2026-07-16 (3e passage de scan Watchdog) ────────
            'stored_secrets_decryptable'  => 'checkStoredSecretsDecryptable',
            'stats_snapshot_decryptable'  => 'checkStatsSnapshotDecryptable',
            'calendar_json_integrity'     => 'checkCalendarJsonIntegrity',
            // ── Ajout 2026-07-21 : checklist de première installation ──
            'first_install_checklist'     => 'checkFirstInstallChecklist',
            // ── Ajouts 2026-07-26 : tableau de bord Automatisations ────
            'all_email_crons_disabled'    => 'checkAllEmailCronsDisabled',
            'behavioral_silence'          => 'checkBehavioralSilence',
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
     * `Mail::getTemplateBasePath()` (classes/Mail.php) vérifie TOUJOURS
     * themes/{theme}/modules/neria/mails/ AVANT modules/neria/mails/, même
     * quand Neria passe explicitement son propre chemin à Mail::Send() (PS
     * ignore ce paramètre et le régénère lui-même à partir du nom de module
     * détecté dans le chemin). Confirmé en conditions réelles le 2026-07-12 :
     * un tel dossier existait sur cet environnement, avec un contenu différent
     * (donc périmé) du dossier module — passé en ERROR (pas WARNING) car ça
     * peut rendre INVISIBLE n'importe quel correctif déployé, sans qu'aucun
     * test qui rend en mémoire (renderPreviewHtml, etc.) ne le détecte.
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
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.theme_warning', ['paths' => implode(', ', $found)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.theme_ok')];
    }

    /**
     * Surcharges de CLASSE PHP (mécanisme `override/` de PrestaShop) —
     * jumeau du contrôle #4 ci-dessus, mais pour le code au lieu des
     * templates : un fichier dans override/classes/ ou override/modules/neria/
     * remplace ENTIÈREMENT une classe du module (logique métier, contrôles
     * de sécurité, tout) — silencieusement, sans qu'aucun test en mémoire ne
     * le détecte. Ajouté le 2026-07-13 après la découverte du bug de
     * surcharge de thème (piège structurellement identique, mécanisme PS
     * différent). Rien à auto-corriger : supprimer un fichier de code sans
     * que le marchand le sache serait aussi risqué que le problème lui-même.
     */
    private function checkClassOverride(): array
    {
        $overrideDir = rtrim(_PS_ROOT_DIR_, '/') . '/override';
        $found       = [];

        $moduleOverrideDir = $overrideDir . '/modules/neria';
        if (is_dir($moduleOverrideDir)) {
            foreach (glob($moduleOverrideDir . '/*.php') ?: [] as $file) {
                $found[] = str_replace(_PS_ROOT_DIR_ . '/', '', $file);
            }
        }

        $classesDir = $overrideDir . '/classes';
        if (is_dir($classesDir)) {
            // index.php exclu : c'est le stub anti-listing standard que Neria
            // place lui-même dans src/ (comme PrestaShop le fait dans chaque
            // dossier, dont override/classes/) — pas une classe métier. Sans
            // cette exclusion, la présence normale des DEUX stubs anti-listing
            // (celui de Neria dans src/, celui de PS dans override/classes/)
            // déclenchait un faux positif ERROR sur absolument toute
            // installation du module, en confondant une coïncidence de nom
            // de fichier avec une vraie surcharge de classe malveillante.
            $neriaClassFiles = array_filter(
                array_map(
                    fn (string $p): string => basename($p),
                    glob(__DIR__ . '/*.php') ?: []
                ),
                fn (string $name): bool => strtolower($name) !== 'index.php'
            );
            $neriaClassFiles[] = 'Neria.php';

            foreach ($neriaClassFiles as $className) {
                $candidate = $classesDir . '/' . $className;
                if (is_file($candidate)) {
                    $found[] = str_replace(_PS_ROOT_DIR_ . '/', '', $candidate);
                }
            }
        }

        if ($found) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.class_override_error', ['paths' => implode(', ', $found)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.class_override_ok')];
    }

    /**
     * Vérification du cache de compilation Smarty (`PS_SMARTY_FORCE_COMPILE`,
     * réglage natif PrestaShop) — troisième variante du même piège que les
     * deux contrôles ci-dessus. Si ce réglage est sur "0" (ne jamais
     * recompiler, optimisation courante en hébergement mutualisé), Smarty
     * arrête de comparer les dates des fichiers source : un template modifié
     * peut continuer à servir sa version compilée périmée jusqu'à un vidage
     * de cache manuel. Vérifié dans config/smarty.config.inc.php :
     * compile_check = ON seulement si PS_SMARTY_FORCE_COMPILE >= 1.
     */
    private function checkSmartyCompileCheck(): array
    {
        $value = (int) \Configuration::get('PS_SMARTY_FORCE_COMPILE');

        if ($value < 1) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::t('health.smarty_compile_check_off'),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.smarty_compile_check_ok')];
    }

    /**
     * Sécurité statique des scripts d'upgrade — analyse le CODE SOURCE de
     * chaque upgrade-X.Y.Z.php (sans les exécuter) pour détecter un appel à
     * une méthode `private`/`protected` de Neria depuis une fonction globale
     * upgrade_module_X_Y_Z() : ce pattern précis a fait planter
     * upgrade-1.0.5.php le 2026-07-12 (Call to private method
     * Neria::importTranslations from global scope) — et
     * `Module::runUpgradeModule()` DÉSACTIVE le module quand un script
     * échoue en cours de chaîne. Détecte le bug AVANT qu'un vrai rejeu (à
     * l'install d'une mise à jour marchande) ne le déclenche.
     */
    private function checkUpgradeScriptSafety(): array
    {
        $upgradeDir = _PS_MODULE_DIR_ . $this->module->name . '/upgrade';
        if (!is_dir($upgradeDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.upgrade_safety_ok')];
        }

        // Méthodes non-publiques de Neria — un appel `$module->methode()`
        // dans un script d'upgrade planterait de la même façon.
        $neriaSource = file_get_contents(_PS_MODULE_DIR_ . $this->module->name . '/neria.php') ?: '';
        $nonPublic   = [];
        if (preg_match_all('/\b(?:private|protected)\s+function\s+([a-zA-Z0-9_]+)\s*\(/', $neriaSource, $m)) {
            $nonPublic = $m[1];
        }

        $offenders = [];
        foreach (glob($upgradeDir . '/*.php') ?: [] as $file) {
            $content = file_get_contents($file) ?: '';
            foreach ($nonPublic as $method) {
                if (preg_match('/\$module\s*->\s*' . preg_quote($method, '/') . '\s*\(/', $content)) {
                    $offenders[] = basename($file) . ' → ' . $method . '()';
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.upgrade_safety_error', ['list' => implode(', ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.upgrade_safety_ok')];
    }

    /**
     * Garde-fou statique contre des régressions précises déjà trouvées et
     * corrigées — un canari dynamique s'est révélé trop bruyant (variables
     * métier légitimement absentes des données factices d'aperçu, cf.
     * mémoire), donc contrôle ciblé sur le code source plutôt qu'un scan
     * générique de résidus.
     *
     * Trouvées le 2026-07-14 (rapport de test externe, Claude Cowork) :
     *  1. La boucle de substitution d'EmailRenderer ne doit plus exclure les
     *     valeurs vides ($value !== '') — sinon {subject}/{custom_message}
     *     redeviennent invisibles dès qu'ils sont légitimement vides (le cas
     *     le plus courant).
     *  2. {products_txt} doit rester fourni par sendAbandonedCarts().
     *  3. ManualSendManager doit continuer à interroger ps_log pour la vraie
     *     cause d'échec plutôt que d'afficher le message générique masquant.
     *
     * Trouvées le 2026-07-19 (tests réels PS9, melleina.com) :
     *  4. La recherche de la vraie cause (ManualSendManager) doit couvrir
     *     'MailerMessage' (PS9/Symfony Mailer), pas seulement 'SwiftMessage'
     *     (PS8) — sinon le bug #3 ci-dessus revient spécifiquement sur PS9
     *     malgré le garde n°3 qui, lui, resterait vert (commit ef50c86).
     *  5. Les tris SQL (ORDER BY) de StatsManager/MonthlyReportManager ne
     *     doivent plus réutiliser l'alias d'une fonction d'agrégat dans une
     *     expression arithmétique — MySQL (contrairement à MariaDB) lève
     *     l'erreur 1247 dessus, cassait l'onglet Statistiques sur PS9
     *     réel (commit ce37170).
     *  6. `.neria-input--hex/--number/--small` doivent garder leur
     *     `!important` — sans lui, le thème admin PS9 (new-theme) regagne
     *     la spécificité et réduit les curseurs Design/Typographie à 0px de
     *     large (commits fb0fefb, 96cd826).
     */
    private function checkKnownRegressionsGuard(): array
    {
        $offenders = [];

        $rendererFile = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $rendererSrc = $this->readModuleSrc($rendererFile);
        if ($rendererSrc === '') {
            $offenders[] = 'EmailRenderer.php introuvable';
        } else {
            if (preg_match('/is_string\(\$value\)\s*&&\s*\$value\s*!==\s*\'\'/', $rendererSrc)) {
                $offenders[] = 'EmailRenderer : substitution filtre à nouveau les valeurs vides';
            }
            if (strpos($rendererSrc, "\$params['templateVars']['{subject}']") === false) {
                $offenders[] = 'EmailRenderer : {subject} n\'est plus injecté sur les envois réels';
            }
        }

        $cronFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $cronSrc = $this->readModuleSrc($cronFile);
        if ($cronSrc === '' || strpos($cronSrc, '{products_txt}') === false) {
            $offenders[] = 'BehavioralCronManager : {products_txt} n\'est plus fourni sur les paniers abandonnés';
        }

        $manualFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $manualSrc = $this->readModuleSrc($manualFile);
        if ($manualSrc === '' || strpos($manualSrc, "FROM `' . _DB_PREFIX_ . 'log`") === false) {
            $offenders[] = 'ManualSendManager : ne recherche plus la vraie cause d\'échec dans ps_log';
        }
        if ($manualSrc !== '' && strpos($manualSrc, 'MailerMessage') === false) {
            $offenders[] = 'ManualSendManager : ne couvre plus MailerMessage (PS9/Symfony Mailer) dans la recherche de la vraie cause';
        }

        $statsFile = _PS_MODULE_DIR_ . $this->module->name . '/src/StatsManager.php';
        $statsSrc = $this->readModuleSrc($statsFile);
        if ($statsSrc === '' || strpos($statsSrc, '$orderBy') === false || strpos($statsSrc, 'sentExpr') === false) {
            $offenders[] = 'StatsManager : getTopTemplatesByMetric() pourrait de nouveau trier sur un alias d\'agrégat (erreur SQL 1247 sur MySQL)';
        }

        $monthlyFile = _PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php';
        $monthlySrc = $this->readModuleSrc($monthlyFile);
        if ($monthlySrc !== '' && preg_match('/ORDER BY\s*\(total_open\s*\/\s*total_sent\)/', $monthlySrc)) {
            $offenders[] = 'MonthlyReportManager : ORDER BY réutilise de nouveau les alias total_open/total_sent (erreur SQL 1247 sur MySQL)';
        }

        $mainFile = _PS_MODULE_DIR_ . $this->module->name . '/' . $this->module->name . '.php';
        $mainSrc = $this->readModuleSrc($mainFile);
        if ($mainSrc === '') {
            $offenders[] = 'neria.php introuvable';
        } else {
            // Bug du 2026-07-20 : neria_fallback (email envoyé au CLIENT) et
            // log_alert (envoyé au marchand) étaient traités de façon
            // identique — le hook rappelait ensureInternalTemplateCompiled()
            // pour les deux, ce qui écrasait le fichier .html de
            // neria_fallback (compilé avec les vraies variables du client
            // par sendFallbackEmail()) par une version utilisant
            // PS_SHOP_EMAIL. Résultat réel confirmé : lien "Se désabonner"
            // d'un email de secours pointant vers l'admin, pas le client.
            if (preg_match(
                "/in_array\\(\\\$tplName,\\s*\\['log_alert',\\s*'neria_fallback'\\]/",
                $mainSrc
            )) {
                $offenders[] = 'neria.php : ensureInternalTemplateCompiled() est de nouveau appelé pour neria_fallback (écrase les variables du VRAI destinataire par celles du marchand)';
            }
        }

        $rendererSrc2 = $rendererSrc;
        if ($rendererSrc2 !== '') {
            // Même bug, second volet : sendFallbackEmail() doit compiler le
            // template AVEC les vraies variables ({unsubscribe_url},
            // {preferences_url}...), pas les injecter après coup en comptant
            // sur Mail::Send()/Swift pour les résoudre — le fichier écrit sur
            // disque est ce qui part tel quel, les placeholders non résolus
            // sont déjà retirés au moment de la compilation.
            if (preg_match(
                "/compileNeriaTemplate\\(\\s*'neria_fallback',\\s*\\\$lang,\\s*\\\$outIso\\s*\\)/",
                $rendererSrc2
            )) {
                $offenders[] = 'EmailRenderer : sendFallbackEmail() compile de nouveau neria_fallback sans templateVars (liens désabonnement/préférences vides dans l\'email de secours)';
            }
        }

        $cssFile = _PS_MODULE_DIR_ . $this->module->name . '/views/css/neria-admin.css';
        $cssSrc = $this->readModuleSrc($cssFile);
        if ($cssSrc === '') {
            $offenders[] = 'neria-admin.css introuvable';
        } else {
            foreach (['.neria-input--hex', '.neria-input--number', '.neria-input--small'] as $sel) {
                $escaped = preg_quote($sel, '/');
                if (!preg_match('/' . $escaped . '\s*\{[^}]*!important/s', $cssSrc)) {
                    $offenders[] = "neria-admin.css : {$sel} a perdu son !important (le thème admin PS9 new-theme regagnerait la spécificité et écraserait la largeur)";
                }
            }
        }

        $configFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php';
        $configSrc = $this->readModuleSrc($configFile);
        if ($configSrc === '') {
            $offenders[] = 'ConfigManager.php introuvable';
        } else {
            // Bug du 2026-07-21 : le centre de contrôle affichait "Inactif"
            // pour des features réellement actives par défaut, pour deux
            // raisons distinctes trouvées en réel :
            // 1. revenue_attribution pointait vers NERIA_ATTRIBUTION_ENABLED,
            //    une clé fantôme jamais écrite nulle part (l'attribution de
            //    revenus est en réalité toujours active, sans interrupteur —
            //    même constat déjà fait côté checkAttributionCoverage()).
            if (preg_match(
                "/'key'\\s*=>\\s*'revenue_attribution'.*?'enabled_key'\\s*=>\\s*'NERIA_ATTRIBUTION_ENABLED'/s",
                $configSrc
            )) {
                $offenders[] = 'ConfigManager : revenue_attribution pointe de nouveau vers NERIA_ATTRIBUTION_ENABLED (clé fantôme jamais écrite — cette feature est toujours active, sans interrupteur réel)';
            }
            // 2. time_greeting/firstname_fallback/multi_sender/signature/
            //    monthly_report sont actifs par défaut selon leur propre
            //    getter ConfigManager (défaut=1), mais jamais semés dans
            //    setDefaultConfiguration() — sans 'default_if_unset', une
            //    install jamais touchée par le marchand les affiche à tort
            //    Inactif.
            foreach (['time_greeting', 'firstname_fallback', 'multi_sender', 'signature', 'monthly_report'] as $fk) {
                if (!preg_match("/'key'\\s*=>\\s*'{$fk}'.*?'default_if_unset'\\s*=>\\s*true/s", $configSrc)) {
                    $offenders[] = "ConfigManager : {$fk} a perdu son 'default_if_unset' => true (afficherait à tort Inactif sur une install jamais configurée)";
                }
            }
        }

        if ($mainSrc !== '') {
            // Même bug, second volet : getControlCenterItems() doit exploiter
            // ce 'default_if_unset', pas relire la config brute directement.
            if (strpos($mainSrc, 'default_if_unset') === false) {
                $offenders[] = 'neria.php : getControlCenterItems() n\'utilise plus default_if_unset (statut Actif/Inactif du centre de contrôle de nouveau faux sur install jamais configurée)';
            }
        }

        // Bug du 2026-07-21 : plusieurs liens du menu déroulant (Stats et
        // Accueil) pointent vers des ancres à l'intérieur de sections qui ne
        // sont rendues QUE si des données existent (L'Heure d'Or, Comparatif
        // mensuel, Prochaines occasions — même constat déjà fait pour le
        // lien A/B Testing). Sans un booléen dédié reflétant EXACTEMENT la
        // même condition que stats.tpl/configure.tpl, le lien de menu reste
        // affiché même quand l'ancre n'existe pas dans le DOM : le clic
        // ramène en haut de page sans aucune indication pour le marchand.
        $navFile = _PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/navigation.tpl';
        $navSrc = $this->readModuleSrc($navFile);
        if ($navSrc === '') {
            $offenders[] = 'navigation.tpl introuvable';
        } else {
            $conditionalNavLinks = [
                'neria-golden-hour-section' => 'neria_has_golden_hour_data',
                'neria-monthly-comparison'  => 'neria_has_monthly_comparison',
                'neria-cfg-upcoming'        => 'neria_has_upcoming_events',
                'neria-abtest-focus'        => 'neria_has_active_abtest',
            ];
            foreach ($conditionalNavLinks as $anchor => $flag) {
                // Le lien tient sur une seule ligne ({if ...}<li><a ...
                // #anchor...>...</a></li>{/if}) — chercher la ligne complète
                // contenant l'ancre, PAS juste l'intérieur du {if ...} (qui se
                // termine par sa propre accolade fermante avant même que
                // l'ancre n'apparaisse dans le href).
                foreach (preg_split('/\r\n|\r|\n/', $navSrc) as $line) {
                    if (strpos($line, $anchor) === false || strpos($line, '{if') === false) {
                        continue;
                    }
                    if (strpos($line, $flag) === false) {
                        $offenders[] = "navigation.tpl : le lien vers #{$anchor} n'est plus gardé par \${$flag} (section conditionnelle sur des données — lien mort possible)";
                    }
                    break;
                }
            }
            if (strpos($mainSrc, 'neria_has_golden_hour_data') === false
                || strpos($mainSrc, 'neria_has_monthly_comparison') === false
                || strpos($mainSrc, 'neria_has_upcoming_events') === false) {
                $offenders[] = 'neria.php : un des booléens neria_has_golden_hour_data/neria_has_monthly_comparison/neria_has_upcoming_events a disparu';
            }
        }

        // Bug du 2026-07-21 : help.tpl fermait lui-même les wrappers
        // .neria-bo-content/.neria-bo-wrap (résidu d'avant le refactor des
        // onglets), alors que neria.php::getContent() les ouvre/ferme UNE
        // seule fois autour du rendu de l'onglet actif. Ces 2 </div> en trop
        // fermaient prématurément le conteneur parent, cassant l'imbrication
        // DOM (même symptôme que le bug de largeur déjà corrigé sur
        // stats.tpl : sections rendues en frère de .neria-bo-wrap au lieu
        // d'être imbriquées dans .neria-bo-content). Contrôle générique, PAR
        // COMPTAGE (pas par recherche de chaîne — la classe .neria-bo-wrap
        // est aussi réutilisée localement, ex. seasonal.tpl, sans rapport
        // avec le wrapper global) : chaque template admin doit contenir
        // exactement autant de <div> que de </div>. Un déséquilibre signifie
        // qu'un template ferme (ou ouvre) une balise qui ne lui appartient
        // pas.
        $adminTplDir = _PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/';
        foreach (glob($adminTplDir . '*.tpl') ?: [] as $tplFile) {
            $tplSrc = file_get_contents($tplFile) ?: '';
            // Round 169 : les commentaires Smarty {* ... *} sont retirés avant
            // le comptage — un commentaire de documentation évoquant en prose
            // d'anciennes balises ("simple <div onclick>", "une liste de
            // <div>...") faisait gonfler le compte d'ouvertures sans balise
            // réelle correspondante, provoquant un faux positif permanent sur
            // send.tpl (33 vs 31, soit exactement les 2 occurrences dans des
            // commentaires round 154) — jamais remarqué car ce garde-fou
            // n'avait jamais été rejoué dans l'agrégat complet depuis son
            // introduction.
            $tplSrcNoComments = preg_replace('/\{\*.*?\*\}/s', '', $tplSrc) ?? $tplSrc;
            $opens  = preg_match_all('/<div\b/', $tplSrcNoComments);
            $closes = preg_match_all('/<\/div>/', $tplSrcNoComments);
            // navigation.tpl ouvre volontairement .neria-bo-wrap sans le
            // refermer : neria.php::getContent() concatène
            // '<div class="neria-bo-content">' + $content + '</div></div>'
            // APRÈS la sortie de ce template, fermant les deux wrappers à
            // l'extérieur (voir le commentaire en tête de navigation.tpl).
            // Un <div> de plus que de </div> y est normal et volontaire.
            $isKnownAsymmetric = basename($tplFile) === 'navigation.tpl' && $opens === $closes + 1;
            if ($opens !== $closes && !$isKnownAsymmetric) {
                $offenders[] = basename($tplFile) . " : {$opens} <div> pour {$closes} </div> (balises déséquilibrées — imbrication DOM potentiellement cassée)";
            }

            // Bug du 2026-07-24 : Smarty interprète TOUT {...} à l'intérieur
            // d'un bloc {capture}...{/capture} comme sa propre syntaxe — un
            // quantificateur regex {4} ou des accolades JS { } littéraux y
            // sont silencieusement avalés, sans aucune erreur PHP/Smarty (le
            // HTML produit reste valide, juste sémantiquement faux). Trouvé
            // en réel sur navigation.tpl : pattern="...[A-Za-z0-9]{4}..."
            // rendu sans le {4}, cassant la validation native du champ de
            // clé de licence. Contrôle statique : tout {N} numérique brut
            // (non échappé via {ldelim}N{rdelim}) à l'intérieur d'un bloc
            // capture est presque certainement ce bug.
            if (preg_match_all('/\{capture\b.*?\}(.*?)\{\/capture\}/s', $tplSrc, $captureMatches)) {
                foreach ($captureMatches[1] as $captureBody) {
                    if (preg_match('/(?<!ldelim)\{[0-9]+\}/', $captureBody)) {
                        $offenders[] = basename($tplFile) . ' : un bloc {capture} contient un {N} numérique non échappé (probable quantificateur regex ou accolade JS avalé par Smarty — utiliser {ldelim}N{rdelim})';
                        break;
                    }
                }
            }
        }

        // Bug du 2026-07-21 : deux régressions trouvées en généralisant la
        // vérification id_shop à UpsellManager::renderUpsellBlock() (appelé
        // par les campagnes saisonnières "idées cadeaux") :
        // 1. `new \ConfigManager()` sans argument — le constructeur exige
        //    un Neria $module. Cette erreur fatale était silencieusement
        //    avalée par le try/catch de SeasonalCampaignManager, donc
        //    JAMAIS remontée au marchand : le mode cadeaux envoyait ses
        //    emails sans jamais inclure le bloc suggestion de produit,
        //    depuis la création de la fonctionnalité.
        // 2. La recherche de la dernière commande valide du client
        //    n'était pas filtrée par id_shop — un client partagé entre
        //    boutiques pouvait recevoir une suggestion basée sur une
        //    commande d'une AUTRE boutique (produit hors catalogue,
        //    fuite d'information entre boutiques).
        $upsellFile = _PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php';
        $upsellSrc = $this->readModuleSrc($upsellFile);
        if ($upsellSrc === '') {
            $offenders[] = 'UpsellManager.php introuvable';
        } else {
            if (preg_match('/new\s+\\\\?ConfigManager\s*\(\s*\)/', $upsellSrc)) {
                $offenders[] = 'UpsellManager : new ConfigManager() est de nouveau appelé sans le module (erreur fatale silencieuse — le bloc suggestion du mode cadeaux ne serait plus jamais inclus)';
            }
            if (!preg_match('/function\s+renderUpsellBlock\s*\([^)]*int\s+\$idShop/', $upsellSrc)) {
                $offenders[] = 'UpsellManager : renderUpsellBlock() n\'a plus de paramètre $idShop (suggestion potentiellement basée sur une commande d\'une autre boutique)';
            } elseif (
                // Depuis le refactor du 02/08/2026 (ajout de renderUpsellBlockTxt(),
                // commit d629020), la requête filtrée par id_shop a été extraite dans
                // findUpsellForCustomer() — plus forcément à moins de 400 caractères
                // du texte "renderUpsellBlock". On vérifie donc séparément : (a) que
                // renderUpsellBlock() délègue bien à une recherche centralisée, et
                // (b) qu'une requête filtrée sur EXACTEMENT $idShop existe toujours
                // quelque part dans le fichier — pas n'importe quel id_shop.
                !preg_match('/renderUpsellBlock[\s\S]{0,400}?(findUpsellForCustomer|AND\s+id_shop\s*=)/', $upsellSrc)
                || !preg_match('/id_shop\s*=\s*"\s*\.\s*\(int\)\s*\$idShop/', $upsellSrc)
            ) {
                $offenders[] = 'UpsellManager : renderUpsellBlock() ne filtre plus la recherche de commande par id_shop';
            }
        }

        // Bug du 2026-07-22 : PurchaseWindowManager::getPreferredHour(),
        // appelée par BehavioralCronManager pour CHAQUE email
        // comportemental quand la feature "fenêtre d'achat" est activée,
        // ne filtrait la recherche de commandes par id_shop nulle part —
        // un client partagé entre boutiques (compte mutualisé) voyait sa
        // fenêtre d'achat calculée sur ses commandes TOUTES boutiques
        // confondues : un email envoyé par la boutique A pouvait être mis
        // en file jusqu'à l'heure où ce client achète habituellement sur
        // la boutique B. Vérifié en réel : shop 1 -> heure 10h, shop 2 ->
        // heure 22h, correctement isolées après le fix.
        $pwmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/PurchaseWindowManager.php';
        $pwmSrc = $this->readModuleSrc($pwmFile);
        if ($pwmSrc === '') {
            $offenders[] = 'PurchaseWindowManager.php introuvable';
        } else {
            if (!preg_match('/function\s+getPreferredHour\s*\([^)]*int\s+\$idShop/', $pwmSrc)) {
                $offenders[] = 'PurchaseWindowManager : getPreferredHour() n\'a plus de paramètre $idShop (fenêtre d\'achat potentiellement calculée sur une autre boutique)';
            } elseif (!preg_match('/getPreferredHour[\s\S]{0,400}?id_shop\s*=/', $pwmSrc)) {
                $offenders[] = 'PurchaseWindowManager : getPreferredHour() ne filtre plus la recherche de commandes par id_shop';
            }
        }
        $behavioralFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $behavioralSrc = $this->readModuleSrc($behavioralFile);
        if ($behavioralSrc !== '' && preg_match('/getPreferredHour\(\s*\(int\)\s*\$customer\[.id_customer.\]\s*\)/', $behavioralSrc)) {
            $offenders[] = 'BehavioralCronManager : appelle de nouveau getPreferredHour() sans lui passer $idShop';
        }

        // Bug du 2026-07-22 : CooldownManager::isDuplicate() (Mode Silence,
        // appelé sur CHAQUE email via le hook actionEmailSendBefore) ne
        // filtrait la recherche de doublon par id_shop nulle part — un
        // client partagé entre boutiques recevant le même template sur
        // DEUX boutiques différentes dans la fenêtre de cooldown voyait le
        // second envoi, pourtant légitime (ex. confirmation de commande
        // réelle sur l'autre boutique), silencieusement bloqué comme
        // "doublon". Vérifié en réel : envoi shop 1 -> doublon shop 1
        // detecté (true) ; même template shop 2 -> pas de doublon (false),
        // correctement isolés après le fix.
        $cooldownFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CooldownManager.php';
        $cooldownSrc = $this->readModuleSrc($cooldownFile);
        if ($cooldownSrc === '') {
            $offenders[] = 'CooldownManager.php introuvable';
        } else {
            if (!preg_match('/function\s+isDuplicate\s*\([^)]*int\s+\$idShop/', $cooldownSrc)) {
                $offenders[] = 'CooldownManager : isDuplicate() n\'a plus de paramètre $idShop (blocage de doublon potentiellement basé sur une autre boutique)';
            } elseif (!preg_match('/isDuplicate[\s\S]{0,1200}?id_shop.\s*=/', $cooldownSrc)) {
                $offenders[] = 'CooldownManager : isDuplicate() ne filtre plus la recherche de doublon par id_shop';
            }
        }
        if ($mainSrc !== '' && preg_match('/isDuplicate\(\(string\)\s*\$to,\s*\$tpl,\s*\$cdMinutes,\s*\$cdIdOrder\)/', $mainSrc)) {
            $offenders[] = 'neria.php : appelle de nouveau CooldownManager::isDuplicate() sans lui passer id_shop';
        }

        // Bug du 2026-07-22 : QueueManager::processQueue() est appelé depuis
        // PLUSIEURS points d'entrée indépendants (cron frontend via
        // BehavioralCronManager, HealthCheckManager, bouton BO "Traiter la
        // file maintenant") sans aucun verrou — même risque déjà identifié
        // et corrigé pour WebhookManager::processQueue() (GET_LOCK). Sans
        // verrou, deux exécutions concurrentes lisent le même lot de lignes
        // 'pending' avant que l'une des deux n'ait incrémenté `attempts`,
        // envoyant chaque email en double au client. Vérifié en réel avec 2
        // connexions MySQL distinctes : la seconde est bien bloquée tant que
        // la première détient le verrou, puis processQueue() lui-même
        // retourne 0 sans traiter si le verrou est déjà pris ailleurs.
        $queueMgrFile = _PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php';
        $queueMgrSrc = $this->readModuleSrc($queueMgrFile);
        if ($queueMgrSrc === '') {
            $offenders[] = 'QueueManager.php introuvable';
        } elseif (!preg_match('/function\s+processQueue[\s\S]{0,1700}?GET_LOCK/', $queueMgrSrc)) {
            $offenders[] = 'QueueManager : processQueue() n\'utilise plus GET_LOCK (deux exécutions concurrentes pourraient de nouveau envoyer chaque email en double)';
        }

        // Bug du 2026-07-22 : le déclenchement quotidien du cron
        // comportemental (neria.php, hookDisplayHeader) reposait sur un
        // check-then-set non atomique sur CRON_LAST_BEHAVIORAL — même piège
        // déjà corrigé pour la queue d'envoi et la queue webhook. Deux
        // visiteurs déclenchant hookDisplayHeader au même moment (une fois
        // par 24h seulement, mais un site à trafic élevé peut y arriver)
        // pouvaient tous deux lire un timestamp périmé avant que
        // BehavioralCronManager::run() n'ait eu le temps de le mettre à
        // jour — les deux exécutent alors TOUTE la journée comportementale
        // en parallèle. Contrairement au voucher anniversaire (protégé par
        // une réservation atomique INSERT IGNORE), la plupart des ~20
        // méthodes d'envoi suivent un schéma "envoyer PUIS marquer envoyé" :
        // sans ce verrou, un client peut recevoir le même email
        // comportemental deux fois. Vérifié en réel via hookDisplayHeaderImpl()
        // avec un verrou externe déjà détenu : le cron ne s'exécute pas
        // (timestamp inchangé) ; sans verrou externe, il s'exécute
        // normalement (timestamp mis à jour).
        if ($mainSrc !== '' && !preg_match('/CRON_LAST_BEHAVIORAL[\s\S]{0,600}?GET_LOCK\(.neria_behavioral_cron_run./', $mainSrc)) {
            $offenders[] = 'neria.php : le déclenchement du cron comportemental quotidien n\'utilise plus GET_LOCK (deux exécutions concurrentes pourraient de nouveau envoyer chaque email comportemental en double)';
        }

        // Bug du 2026-07-22 : MonthlyReportManager::checkAndSend() (déclenché
        // depuis hookDisplayHeader sur CHAQUE page front) souffre du même
        // check-then-set non atomique, mais avec une fenêtre de course
        // BIEN PLUS LARGE que le cron comportemental : isDue() reste vrai
        // pendant TOUTE la fenêtre de rattrapage (du 1er au 7 du mois), pas
        // une fraction de seconde autour d'un seuil de 24h. N'importe quelle
        // paire de visites concurrentes pendant ces 7 jours pouvait envoyer
        // le rapport mensuel deux fois au marchand avant que markSent() n'ait
        // eu le temps de s'exécuter. Vérifié en réel (isDue() forcé à true
        // temporairement) : verrou externe déjà détenu -> rapport non
        // envoyé (NERIA_REPORT_LAST_SENT inchangé) ; sans verrou -> envoi
        // normal et marquage correct.
        $monthlyFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php';
        $monthlySrc2 = $this->readModuleSrc($monthlyFile2);
        if ($monthlySrc2 === '') {
            $offenders[] = 'MonthlyReportManager.php introuvable';
        } elseif (!preg_match('/function\s+checkAndSend[\s\S]{0,1600}?GET_LOCK\(.neria_monthly_report_check./', $monthlySrc2)) {
            $offenders[] = 'MonthlyReportManager : checkAndSend() n\'utilise plus GET_LOCK (deux visites concurrentes pendant la fenêtre du 1er au 7 du mois pourraient de nouveau envoyer le rapport mensuel en double)';
        }

        // Bug du 2026-07-22 : CalendarManager::checkAndSendDailyEvents() est
        // la fenêtre de course la plus large des cinq de cette série — AUCUN
        // throttle externe (contrairement au cron comportemental ou au
        // rapport mensuel), elle s'exécute sur CHAQUE page front, toute la
        // journée. processEvent() vérifie Configuration::get($sentKey), puis
        // envoie à TOUT le lot de clients éligibles pour la langue/pays,
        // PUIS marque envoyé — sans verrou, deux visiteurs concurrents
        // pendant la durée de l'envoi du lot peuvent déclencher le même
        // email calendaire à tout un segment de clients deux fois.
        $calendarFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CalendarManager.php';
        $calendarSrc = $this->readModuleSrc($calendarFile);
        if ($calendarSrc === '') {
            $offenders[] = 'CalendarManager.php introuvable';
        } elseif (!preg_match('/function\s+checkAndSendDailyEvents[\s\S]{0,2600}?GET_LOCK\(.neria_calendar_check./', $calendarSrc)) {
            $offenders[] = 'CalendarManager : checkAndSendDailyEvents() n\'utilise plus GET_LOCK (deux visiteurs concurrents pourraient de nouveau envoyer le même email calendaire à tout un segment de clients en double)';
        }

        // Bug du 2026-07-22 : SegmentManager classait tout client 0-ouverture
        // en 'ghost' — y compris un client tout juste inscrit sans avoir eu
        // la moindre chance d'ouvrir son premier email — au même titre
        // qu'un client réellement inactif depuis des mois. 'ghost' est le
        // segment recommandé pour les campagnes de réactivation ('win_back'),
        // donc un nouvel inscrit pouvait recevoir un email "vous nous
        // manquez" le jour même de son inscription. Même famille de bug que
        // ChurnScoreManager (données insuffisantes traitées comme pire cas).
        // Vérifié en réel : client inscrit il y a 2h -> aucune ligne segment
        // (pas encore 'ghost') ; même client avec premier envoi vieux de 30
        // jours -> correctement classé 'ghost'.
        $segmentFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SegmentManager.php';
        $segmentSrc = $this->readModuleSrc($segmentFile);
        if ($segmentSrc === '') {
            $offenders[] = 'SegmentManager.php introuvable';
        } elseif (!preg_match('/NEW_CUSTOMER_GRACE_DAYS/', $segmentSrc)
               // strpos plutot qu'une regex a double quantificateur paresseux :
               // first_sent apparait d'abord comme alias de colonne (AS
               // first_sent) bien avant son usage reel dans la clause WHERE
               // (COALESCE(m.first_sent, ...) >= DATE_SUB(...)) ; l'ecart entre
               // les deux occurrences force un backtracking qui peut depasser
               // la limite PCRE, faisant renvoyer false (erreur) a preg_match,
               // ce que !preg_match() interprete alors a tort comme "correctif absent".
               || strpos($segmentSrc, 'COALESCE(m.first_sent') === false
               || strpos($segmentSrc, '>= DATE_SUB(NOW(), INTERVAL {$newCustomerGraceDays} DAY)') === false) {
            $offenders[] = 'SegmentManager : recomputeAll() ne protège plus les clients tout juste inscrits contre un classement immédiat en \'ghost\' (segment recommandé pour les campagnes win_back)';
        }

        // Bug du 2026-07-22 : LoyaltyManager::sendMonthlyRecaps() n'avait
        // jamais reçu le passage au multi-boutique fait pour le reste du
        // programme fidélité (points/récompenses, cf. plus haut). En mode
        // "cumul séparé" (NERIA_LOYALTY_CROSS_SHOP_ENABLED désactivé), la
        // 1ère boutique à envoyer son récap du mois écrivait un throttle
        // GLOBAL (sans suffixe id_shop) qui bloquait silencieusement le
        // récap de TOUTES les autres boutiques ce mois-ci — et le total de
        // points utilisé restait cumulé toutes boutiques confondues, à
        // l'encontre même du réglage. Vérifié en réel : deux "boutiques"
        // avec des points distincts -> total shop-scopé différent du total
        // transversal, et le throttle d'une boutique ne bloque pas l'autre.
        $loyaltyFile = _PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php';
        $loyaltySrc = $this->readModuleSrc($loyaltyFile);
        if ($loyaltySrc === '') {
            $offenders[] = 'LoyaltyManager.php introuvable';
        } else {
            if (!preg_match('/function\s+sendMonthlyRecaps[\s\S]{0,2000}?isLoyaltyCrossShopEnabled/', $loyaltySrc)) {
                $offenders[] = 'LoyaltyManager : sendMonthlyRecaps() ne respecte plus le réglage de cumul séparé (le récap mensuel pourrait de nouveau se bloquer entre boutiques en mode séparé)';
            }
            if (!preg_match('/CONFIG_RECAP_LAST_SENT\s*\.\s*.\_.\s*\.\s*\$idShop/', $loyaltySrc)) {
                $offenders[] = 'LoyaltyManager : sendMonthlyRecaps() n\'utilise plus de throttle par boutique (une boutique pourrait de nouveau bloquer le récap mensuel de toutes les autres)';
            }
            // Bug du 2026-07-22 (même fichier) : getTiers() acceptait toute
            // valeur JSON valide sans vérifier qu'elle ressemblait à des
            // paliers — une config corrompue (ex : {"custom":true}) passait
            // is_array() et faisait planter en cascade (TypeError) tout ce
            // qui itère sur les paliers (getCustomerTier, checkAndReward,
            // sendMonthlyRecaps) au lieu de se replier sur DEFAULT_TIERS.
            if (!preg_match('/function\s+looksLikeTiers/', $loyaltySrc)) {
                $offenders[] = 'LoyaltyManager : getTiers() ne valide plus la structure des paliers (une config corrompue pourrait de nouveau faire planter tout le programme fidélité au lieu de se replier sur les paliers par défaut)';
            }
        }

        // Bug du 2026-07-22 : même famille que le récap fidélité ci-dessus,
        // dans MonthlyReportManager. Le rapport lui-même est entièrement
        // scopé par $this->idShop (tous les KPI filtrent par id_shop), mais
        // isDue()/markSent() utilisaient un throttle GLOBAL : la 1ère
        // boutique dont un visiteur déclenchait le hook recevait bien SON
        // rapport, mais marquait le mois "envoyé" pour TOUTES les boutiques —
        // les autres ne recevaient alors plus jamais leur rapport mensuel.
        $reportFile = _PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php';
        $reportSrc = $this->readModuleSrc($reportFile);
        if ($reportSrc === '') {
            $offenders[] = 'MonthlyReportManager.php introuvable';
        } elseif (!preg_match('/function\s+checkAndSend[\s\S]{0,3600}?Shop::getShops/', $reportSrc)
               || !preg_match('/CONFIG_LAST_SENT\s*\.\s*.\_.\s*\.\s*\$idShop/', $reportSrc)) {
            $offenders[] = 'MonthlyReportManager : checkAndSend() n\'itère plus sur chaque boutique avec un throttle dédié (une boutique pourrait de nouveau bloquer le rapport mensuel de toutes les autres)';
        }

        // Bug du 2026-07-22 : le cache 24h de DomainReputationManager était
        // stocké en config GLOBALE. Sur une install multi-boutique où chaque
        // boutique envoie depuis un domaine différent, la boutique A
        // déclenchait la vérification et mettait en cache SON domaine ; la
        // boutique B lisait ensuite ce même cache pendant 24h, affichant la
        // réputation du domaine de A comme si c'était la sienne. Corrigé une
        // 1re fois (2026-07-22) par une comparaison de domaine a posteriori,
        // puis une 2e fois (2026-08-04) en scopant DIRECTEMENT la clé de
        // cache par id_shop (4e paramètre Configuration::get/updateValue) —
        // la comparaison de domaine est devenue redondante et a été retirée ;
        // ce contrôle vérifie désormais le vrai scope id_shop, pas l'ancienne
        // comparaison. Sans ce scope : cache thrashing en multi-boutique,
        // relançant runFullCheck() (jusqu'à 8s de DNS bloquants) DANS LE
        // CHEMIN DE RENDU du visiteur front à chaque changement de boutique.
        $domRepFile = _PS_MODULE_DIR_ . $this->module->name . '/src/DomainReputationManager.php';
        $domRepSrc = $this->readModuleSrc($domRepFile);
        if ($domRepSrc === '') {
            $offenders[] = 'DomainReputationManager.php introuvable';
        } else {
            if (strpos($domRepSrc, 'Configuration::get(self::CONFIG_LAST_CHECK, null, null, $this->idShop)') === false) {
                $offenders[] = "DomainReputationManager : getCachedReport() n'utilise plus le cache scopé par \$this->idShop (cache thrashing en multi-boutique, latence DNS front)";
            }
            if (strpos($domRepSrc, 'Configuration::updateValue(self::CONFIG_CACHE, json_encode($report), false, null, $this->idShop)') === false) {
                $offenders[] = "DomainReputationManager : runFullCheck() n'écrit plus le cache scopé par \$this->idShop";
            }
        }

        // Bug du 2026-07-22 (même famille que DomainReputationManager
        // ci-dessus) : PageSpeedManager, SearchConsoleManager et
        // SeoApiManager mettaient tous les trois en cache leur rapport
        // (score PageSpeed, stats Search Console, autorité SEMrush/Moz) en
        // config GLOBALE sans jamais vérifier qu'il correspondait au domaine
        // actuel. Sur une install multi-boutique à domaines distincts, une
        // boutique pouvait afficher pendant 24h les données d'une autre.
        $pageSpeedFile = _PS_MODULE_DIR_ . $this->module->name . '/src/PageSpeedManager.php';
        $pageSpeedSrc = $this->readModuleSrc($pageSpeedFile);
        if ($pageSpeedSrc === '') {
            $offenders[] = 'PageSpeedManager.php introuvable';
        } elseif (!preg_match('/function\s+getReport[\s\S]{0,1000}?\[.url.\]\s*\?\?\s*null\)\s*===\s*\$this->getTargetUrl\(\)/', $pageSpeedSrc)) {
            $offenders[] = 'PageSpeedManager : getReport() ne vérifie plus que le cache correspond à l\'URL cible actuelle (une boutique pourrait de nouveau afficher le score PageSpeed d\'une autre boutique)';
        }

        $searchConsoleFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php';
        $searchConsoleSrc = $this->readModuleSrc($searchConsoleFile);
        if ($searchConsoleSrc === '') {
            $offenders[] = 'SearchConsoleManager.php introuvable';
        } else {
            // Bug du 2026-08-04 : la comparaison de domaine a posteriori
            // (stripos(...site_url...getShopHost())) évitait la fuite entre
            // boutiques mais provoquait un cache thrashing en multi-boutique
            // (même défaut que DomainReputationManager, corrigé le même
            // jour) — remplacée par un vrai scope id_shop sur la clé de
            // cache (cacheKey()), comme PageSpeedManager/SeoApiManager.
            if (strpos($searchConsoleSrc, "private function cacheKey(string \$base): string") === false) {
                $offenders[] = "SearchConsoleManager : n'a plus de cache scopé par boutique (cacheKey()) — cache thrashing en multi-boutique, appels API Google Search Console répétés";
            }
        }

        $seoApiFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SeoApiManager.php';
        $seoApiSrc = $this->readModuleSrc($seoApiFile);
        if ($seoApiSrc === '') {
            $offenders[] = 'SeoApiManager.php introuvable';
        } elseif (!preg_match('/function\s+getReport[\s\S]{0,1300}?\[.domain.\]\s*\?\?\s*null\)\s*===\s*\$currentDomain/', $seoApiSrc)) {
            $offenders[] = 'SeoApiManager : getReport() ne vérifie plus que le cache correspond au domaine actuel (une boutique pourrait de nouveau afficher l\'autorité SEO d\'une autre boutique)';
        }

        // Bug du 2026-08-04 (même famille, découvert en auditant à froid le
        // garde-fou ci-dessus qui omettait totalement PostmasterManager,
        // alors même que le code de PostmasterManager reconnaissait
        // explicitement le même défaut non répliqué depuis
        // SearchConsoleManager) : cache Gmail Postmaster Tools en config
        // GLOBALE, comparaison de domaine a posteriori (CONFIG_CACHE_HOST)
        // remplacée par un vrai scope id_shop.
        $postmasterFile = _PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php';
        $postmasterSrc = $this->readModuleSrc($postmasterFile);
        if ($postmasterSrc === '') {
            $offenders[] = 'PostmasterManager.php introuvable';
        } elseif (strpos($postmasterSrc, "private function cacheKey(string \$base): string") === false) {
            $offenders[] = "PostmasterManager : n'a plus de cache scopé par boutique (cacheKey()) — cache thrashing en multi-boutique, appels API Gmail Postmaster répétés (API sensible aux quotas)";
        }

        // Bug du 2026-08-04 : looksLikeBounce() utilisait des mots-clés
        // FRANÇAIS ISOLÉS ('échec', 'refusé', 'rejet') qui matchaient aussi
        // des sujets d'emails clients légitimes ("Votre demande de
        // remboursement a été refusée", "Échec du paiement") — un client
        // répondant à une campagne était alors traité comme un bounce,
        // rapprochant artificiellement son adresse du seuil de blocage
        // automatique. Remplacés par des phrases composées propres aux
        // notifications de bounce réelles.
        $bounceFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BounceManager.php';
        $bounceSrc = $this->readModuleSrc($bounceFile);
        if ($bounceSrc === '') {
            $offenders[] = 'BounceManager.php introuvable';
        } elseif (preg_match("/\\\$subjectKeywords\\s*=\\s*\\[[^\\]]*'échec'\\s*,/su", $bounceSrc)) {
            $offenders[] = "BounceManager : looksLikeBounce() utilise de nouveau le mot-clé isolé 'échec' (faux positifs sur des emails clients légitimes)";
        }

        // Bug du 2026-08-04 : le produit du certificat PDF était chargé avec
        // Context::getContext()->language->id (langue du BO de l'employé)
        // au lieu de la langue résolue du CLIENT — incohérent avec les
        // polices choisies pour le PDF, pouvant afficher le nom produit en
        // rectangles vides (arabe/CJK) sur un certificat en français.
        $certFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php';
        $certSrc2 = $this->readModuleSrc($certFile2);
        if ($certSrc2 === '') {
            $offenders[] = 'CertificateManager.php introuvable (2e vérification)';
        } elseif (strpos($certSrc2, 'new \Product($idProduct, false, $idLangProduct)') === false) {
            $offenders[] = "CertificateManager : issue() ne charge plus le produit avec la langue résolue du client (\$idLangProduct) — retour possible à la langue du BO de l'employé";
        }

        // Bug du 2026-08-04 : sanitizeHtml() ne retirait que les balises non
        // autorisées (strip_tags), jamais leurs attributs — un
        // <a href="javascript:..."> ou <span onmouseover="..."> passait
        // intégralement à travers malgré le nom de la fonction.
        $neriaToolsFile = _PS_MODULE_DIR_ . $this->module->name . '/src/NeriaTools.php';
        $neriaToolsSrc = $this->readModuleSrc($neriaToolsFile);
        if ($neriaToolsSrc === '') {
            $offenders[] = 'NeriaTools.php introuvable';
        } elseif (strpos($neriaToolsSrc, "preg_replace('/\son\w+\s*=") === false) {
            $offenders[] = "NeriaTools : sanitizeHtml() ne retire plus les attributs event handler (on*=) — XSS latent sur les balises autorisées";
        }

        // Bug du 2026-08-04 : sanitizeLang() ne normalisait pas la casse en
        // sortie (le filtre /i n'agit qu'en entrée) — un appel avec 'FR'
        // retournait 'FR' non normalisé, cassant silencieusement des
        // comparaisons strictes ailleurs dans le module.
        $voiceFile = _PS_MODULE_DIR_ . $this->module->name . '/src/VoiceProfileManager.php';
        $voiceSrc = $this->readModuleSrc($voiceFile);
        if ($voiceSrc === '') {
            $offenders[] = 'VoiceProfileManager.php introuvable';
        } elseif (!preg_match('/function\s+sanitizeLang[\s\S]{0,500}?mb_strtolower/', $voiceSrc)) {
            $offenders[] = "VoiceProfileManager : sanitizeLang() ne normalise plus la casse en sortie (mb_strtolower)";
        }

        // Bug du 2026-08-04 : les regex de suppression de propriété CSS
        // (background-image, border-radius, display:flex, gap, shadows,
        // position) s'appliquaient sur le HTML entier plutôt que sur les
        // attributs style="..." uniquement — un texte VISIBLE mentionnant
        // littéralement une déclaration CSS était tronqué dans l'aperçu.
        $previewFile = _PS_MODULE_DIR_ . $this->module->name . '/src/MultiClientPreviewManager.php';
        $previewSrc = $this->readModuleSrc($previewFile);
        if ($previewSrc === '') {
            $offenders[] = 'MultiClientPreviewManager.php introuvable';
        } elseif (strpos($previewSrc, 'private function replaceInInlineStyles(string $html, array $patterns): string') === false) {
            $offenders[] = "MultiClientPreviewManager : replaceInInlineStyles() a disparu — les règles CSS risquent de nouveau de s'appliquer sur le HTML entier (texte visible tronqué dans l'aperçu)";
        }

        // Bug du 2026-08-04 : les 3 générateurs de bons (fidélité,
        // anniversaire, palier) créaient un CartRule sans jamais restreindre
        // id_shop_list/shop_restriction — PrestaShop rend un CartRule
        // utilisable sur TOUTES les boutiques de l'installation par défaut.
        // Un client de la boutique A atteignant un palier obtenait un code
        // utilisable au checkout de la boutique B (catalogue/devise
        // différents).
        $loyaltyFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php';
        $loyaltySrc2 = $this->readModuleSrc($loyaltyFile2);
        if ($loyaltySrc2 === '') {
            $offenders[] = 'LoyaltyManager.php introuvable (2e vérification)';
        } elseif (strpos($loyaltySrc2, '$cartRule->id_shop_list     = [$reservationShopId];') === false) {
            $offenders[] = "LoyaltyManager : generateVoucher() ne restreint plus le CartRule à la boutique réelle (id_shop_list) — bon de fidélité utilisable sur n'importe quelle boutique";
        }

        $cronFile3 = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $cronSrc3 = $this->readModuleSrc($cronFile3);
        if ($cronSrc3 === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (3e vérification)';
        } elseif (strpos($cronSrc3, '$cartRule->id_shop_list     = [$idShop];') === false) {
            $offenders[] = "BehavioralCronManager : generateBirthdayVoucher() ne restreint plus le CartRule à la boutique réelle (id_shop_list) — bon d'anniversaire utilisable sur n'importe quelle boutique";
        }

        $orderTrigFile = _PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php';
        $orderTrigSrc = $this->readModuleSrc($orderTrigFile);
        if ($orderTrigSrc === '') {
            $offenders[] = 'OrderTriggersManager.php introuvable';
        } elseif (strpos($orderTrigSrc, '$cartRule->id_shop_list     = [$idShop];') === false) {
            $offenders[] = "OrderTriggersManager : generateMilestoneVoucher() ne restreint plus le CartRule à la boutique réelle (id_shop_list) — bon de palier utilisable sur n'importe quelle boutique";
        }

        // Bug du 2026-08-04 : retryOne() remettait status='pending' et
        // attempts=0 mais ne réinitialisait jamais last_attempt —
        // processQueue() ne sélectionne que WHERE last_attempt IS NULL OR
        // last_attempt <= DATE_SUB(NOW(), INTERVAL POW(2, attempts) MINUTE),
        // un clic admin "Relancer" moins d'une minute après le dernier échec
        // laissait le webhook invisible au prochain passage du cron.
        $webhookFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/WebhookManager.php';
        $webhookSrc2 = $this->readModuleSrc($webhookFile2);
        if ($webhookSrc2 === '') {
            $offenders[] = 'WebhookManager.php introuvable';
        } else {
            if (!preg_match('/function\s+retryOne[\s\S]{0,1500}?`last_attempt`\s*=\s*NULL/', $webhookSrc2)) {
                $offenders[] = "WebhookManager : retryOne() ne réinitialise plus last_attempt — relance manuelle inopérante avant 1 minute";
            }
            // Depuis le round 49, le numéro de séquence n'est plus injecté
            // dans le payload au moment de trigger() (payload 'sequence' =>
            // $idWebhook, qui exigeait un second UPDATE après l'INSERT) mais
            // à la volée dans processQueue(), juste avant l'envoi, à partir
            // de la colonne id_webhook déjà connue — cf. le check plus bas
            // qui vérifie l'absence de second UPDATE post-INSERT.
            if (strpos($webhookSrc2, "\$decodedForSeq['sequence'] = \$id") === false) {
                $offenders[] = "WebhookManager : processQueue() n'injecte plus de numéro de séquence dans le payload avant l'envoi — inversion d'ordre non détectable par les intégrateurs";
            }
        }

        // Bug du 2026-08-04 : isAllowed()/getByCustomer() retournaient un
        // opt-in aveugle pour tout destinataire résolu à id_customer=0
        // (newsletter/newsletter_voucher, pas forcément des clients
        // PrestaShop) sans jamais consulter la ligne par email — le centre
        // de préférences était un no-op permanent pour cette population
        // (non-conformité RGPD/CAN-SPAM : "préférences enregistrées"
        // affiché côté client, catégorie décochée jamais respectée).
        $prefFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/PreferencesManager.php';
        $prefSrc2 = $this->readModuleSrc($prefFile2);
        if ($prefSrc2 === '') {
            $offenders[] = 'PreferencesManager.php introuvable (2e vérification)';
        } else {
            if (!preg_match('/function\s+isAllowed[\s\S]{0,900}?`id_customer`\s*=\s*0[\s\S]{0,200}?`email`\s*=/', $prefSrc2)) {
                $offenders[] = "PreferencesManager : isAllowed() ne consulte plus la ligne par email pour les destinataires sans compte (id_customer=0) — centre de préférences de nouveau sans effet pour cette population";
            }
            if (!preg_match('/function\s+getByCustomer[\s\S]{0,600}?`id_customer`\s*=\s*0[\s\S]{0,200}?`email`\s*=/', $prefSrc2)) {
                $offenders[] = "PreferencesManager : getByCustomer() ne consulte plus la ligne par email pour les destinataires sans compte (id_customer=0)";
            }
        }

        $mainFile2 = _PS_MODULE_DIR_ . $this->module->name . '/' . $this->module->name . '.php';
        $mainSrc2 = $this->readModuleSrc($mainFile2);
        if ($mainSrc2 === '') {
            $offenders[] = 'neria.php introuvable (2e vérification)';
        } elseif (strpos($mainSrc2, 'if ($idCustPref > 0 && !(new PreferencesManager($this))->isAllowed(') !== false) {
            $offenders[] = "neria.php : le hook central a de nouveau le garde \$idCustPref > 0 devant isAllowed() — court-circuite la vérification des préférences pour les destinataires sans compte";
        }

        // Bug du 2026-07-22 : StatsManager::recordOpen() créditait des points
        // de fidélité (même famille que recordClick(), déjà protégé) sans
        // aucun verrou — eventExists()+record() n'est pas atomique. De
        // nombreux clients mail préchargent/rechargent le pixel de tracking
        // (proxy image Gmail, plusieurs appareils synchronisés) : deux
        // requêtes quasi simultanées pouvaient toutes deux lire "aucune
        // ouverture existante" et créditer des points en double pour une
        // seule ouverture réelle.
        $statsFile = _PS_MODULE_DIR_ . $this->module->name . '/src/StatsManager.php';
        $statsSrc = $this->readModuleSrc($statsFile);
        if ($statsSrc === '') {
            $offenders[] = 'StatsManager.php introuvable';
        } elseif (!preg_match('/function\s+recordOpen[\s\S]{0,1000}?.neria_open_.[\s\S]{0,300}?GET_LOCK/', $statsSrc)) {
            $offenders[] = 'StatsManager : recordOpen() n\'utilise plus GET_LOCK (une ouverture rechargée/préchargée pourrait de nouveau créditer des points de fidélité en double)';
        } elseif (!preg_match('/function\s+recordConversion[\s\S]{0,2500}?.neria_conv_.[\s\S]{0,300}?GET_LOCK/', $statsSrc)) {
            $offenders[] = 'StatsManager : recordConversion() n\'utilise plus GET_LOCK (un déclenchement en double de hookActionOrderStatusPostUpdate pourrait de nouveau créditer des points de fidélité en double)';
        }

        // Bug du 2026-07-22 : BounceManager::recordBounce() faisait un SELECT
        // puis un INSERT/UPDATE séparés (non atomique), alors qu'il est appelé
        // à la fois par le webhook ESP (SendGrid/Mailgun retentent la livraison
        // d'un webhook non acquitté assez vite) et par la vérification IMAP
        // manuelle — deux notifications quasi simultanées pour la même adresse
        // pouvaient doublonner l'incrément de bounce_count, rapprochant
        // artificiellement l'adresse du seuil de mise en liste noire.
        $bounceFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BounceManager.php';
        $bounceSrc = $this->readModuleSrc($bounceFile);
        if ($bounceSrc === '') {
            $offenders[] = 'BounceManager.php introuvable';
        } elseif (!preg_match('/function\s+recordBounce[\s\S]{0,1500}?ON DUPLICATE KEY UPDATE/', $bounceSrc)) {
            $offenders[] = 'BounceManager : recordBounce() n\'utilise plus un INSERT...ON DUPLICATE KEY UPDATE atomique (deux notifications de rebond simultanées pour la même adresse pourraient de nouveau doublonner le compteur)';
        }

        // Bug du 2026-07-22 : DomainReputationManager::getSenderDomain() et
        // WebhookManager::trigger() décodaient NERIA_SENDERS_JSON /
        // NERIA_WEBHOOK_EVENTS puis appelaient array_key_first()/in_array()
        // sans vérifier is_array() — un JSON valide mais corrompu (une simple
        // chaîne ou un nombre, suite à une écriture partielle) décode sans
        // erreur en scalaire non-null, et ces fonctions lèvent alors un
        // TypeError fatal en PHP 8. Un self-healing Watchdog existe déjà pour
        // ces deux clés, mais seulement lors de son passage périodique — pas
        // au moment réel de l'usage. Vérifié en réel (config forcée à une
        // chaîne JSON valide non-tableau) : plantait avant, ne plante plus.
        $domRepSrc2 = $domRepSrc; // déjà chargé plus haut dans ce fichier
        if ($domRepSrc2 !== '' && !preg_match('/function\s+getSenderDomain[\s\S]{0,2000}?is_array\(\s*\$senders\s*\)/', $domRepSrc2)) {
            $offenders[] = 'DomainReputationManager : getSenderDomain() ne valide plus is_array() sur NERIA_SENDERS_JSON décodé (une config corrompue pourrait de nouveau planter le contrôle de réputation domaine avec un TypeError)';
        }

        $webhookFile = _PS_MODULE_DIR_ . $this->module->name . '/src/WebhookManager.php';
        $webhookSrc = $this->readModuleSrc($webhookFile);
        if ($webhookSrc === '') {
            $offenders[] = 'WebhookManager.php introuvable';
        } elseif (!preg_match('/function\s+trigger[\s\S]{0,1600}?is_array\(\s*\$enabled\s*\)/', $webhookSrc)) {
            $offenders[] = 'WebhookManager : trigger() ne valide plus is_array() sur NERIA_WEBHOOK_EVENTS décodé (une config corrompue pourrait de nouveau bloquer tous les webhooks avec un TypeError)';
        }

        // Bug du 2026-07-29 : CertificateManager::generatePdf() lisait
        // NERIA_CERT_TITLE/SUBTITLE/BODY depuis Configuration et les
        // utilisait tels quels dans le PDF (TCPDF::Cell()). La substitution
        // de {shop_name} n'était appliquée QUE sur la valeur par défaut
        // (fallback quand le champ est vide) — dès qu'un marchand
        // personnalisait un de ces trois champs avec {shop_name}, la
        // variable brute non résolue apparaissait telle quelle dans le
        // certificat PDF envoyé au client. Trouvé en testant réellement la
        // génération d'un certificat (sous-titre "Official document issued
        // by {shop_name}" affiché mot pour mot). Contrôle statique : les
        // trois substitutions strtr(..., $pdfVars) doivent rester présentes
        // juste après la lecture de Configuration, avant toute utilisation
        // de $title/$subtitle/$bodyText dans le rendu.
        $certFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php';
        $certSrc = $this->readModuleSrc($certFile);
        if ($certSrc === '') {
            $offenders[] = 'CertificateManager.php introuvable';
        } else {
            foreach (['title', 'subtitle', 'bodyText'] as $var) {
                if (!preg_match('/\$' . $var . '\s*=\s*strtr\(\s*\$' . $var . '\s*,\s*\$pdfVars\s*\)/', $certSrc)) {
                    $offenders[] = "CertificateManager : \${$var} n'est plus passé par strtr(..., \$pdfVars) (une valeur personnalisée contenant {shop_name} s'afficherait de nouveau non résolue dans le certificat PDF)";
                }
            }

            // Bug du 2026-07-29, second volet : trois libellés fixes du PDF
            // (aide QR code, mention de signature, pied de page) étaient
            // écrits en dur en français dans le code — un certificat généré
            // en anglais (ou toute autre langue) affichait malgré tout ces
            // trois phrases en français. Trouvé en réel en comparant un
            // certificat anglais généré sur la boutique de test : "Signature
            // officielle" et "Ce document est un certificat officiel émis
            // par..." apparaissaient au milieu d'un document par ailleurs
            // entièrement en anglais. Corrigé via 3 nouvelles clés du
            // dictionnaire certificate_email (19 langues) : vérifie que les
            // 3 appels $engine->get() sont bien utilisés, ET que les phrases
            // françaises exactes ne sont pas revenues en dur.
            foreach (['certificate_pdf_qr_hint', 'certificate_pdf_signature', 'certificate_pdf_footer'] as $key) {
                if (strpos($certSrc, "'certificate_email', '{$key}'") === false) {
                    $offenders[] = "CertificateManager : la clé traduite '{$key}' n'est plus utilisée (texte du certificat PDF potentiellement de nouveau figé dans une seule langue)";
                }
            }
            foreach ([
                'Scannez ce QR code pour vérifier',
                'Signature officielle\'',
                'Ce document est un certificat officiel émis par',
            ] as $hardcodedFrench) {
                if (strpos($certSrc, $hardcodedFrench) !== false) {
                    $offenders[] = "CertificateManager : la phrase française « {$hardcodedFrench} » est de nouveau codée en dur dans le PDF (devrait passer par le dictionnaire certificate_email)";
                }
            }

            // Bug du 2026-07-29, troisième volet (trouvé par le nouveau
            // contrôle générique checkHardcodedFrenchText()) : les valeurs
            // PAR DÉFAUT du titre/sous-titre/corps (utilisées tant que le
            // marchand n'a pas personnalisé ces 3 champs en configuration)
            // étaient elles aussi codées en dur en français, indépendamment
            // de $lang. Corrigé via 3 nouvelles clés certificate_email
            // (19 langues) ; vérifie que les 3 appels sont bien utilisés et
            // que les anciennes valeurs françaises par défaut ne sont pas
            // revenues en dur.
            foreach (['certificate_pdf_default_title', 'certificate_pdf_default_subtitle', 'certificate_pdf_default_body'] as $key) {
                if (strpos($certSrc, "'certificate_email', '{$key}'") === false) {
                    $offenders[] = "CertificateManager : la clé traduite '{$key}' n'est plus utilisée (valeur par défaut du certificat PDF potentiellement de nouveau figée en français)";
                }
            }
            foreach ([
                "'Certificat d\\'Authenticité'",
                'Document officiel émis par ',
                'Ce certificat atteste que la pièce décrite ci-dessus est authentique',
            ] as $hardcodedFrenchDefault) {
                if (strpos($certSrc, $hardcodedFrenchDefault) !== false) {
                    $offenders[] = "CertificateManager : la valeur par défaut française « {$hardcodedFrenchDefault} » est de nouveau codée en dur (devrait passer par le dictionnaire certificate_email)";
                }
            }

            // Bug du 2026-08-03 : cert_qr_url n'est validée qu'avec
            // Validate::isUrl() + préfixe https:// côté BO — rien n'empêche
            // une URL contenant déjà une query string (ex.
            // https://x.fr/verify?ref=y). Concaténer systématiquement
            // '?cert=' produisait alors '...?ref=y?cert=...', un QR cassé
            // sur TOUS les certificats émis avec cette config.
            if ($certSrc !== ''
                && strpos($certSrc, "'?cert=' . urlencode(\$serial)") !== false
                && strpos($certSrc, 'strpos($qrBaseUrl') === false
            ) {
                $offenders[] = "CertificateManager : le QR code concatène de nouveau '?cert=' sans détecter une query string déjà présente dans cert_qr_url (lien cassé)";
            }
        }

        $churnFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ChurnScoreManager.php';
        $churnSrc = $this->readModuleSrc($churnFile);
        if ($churnSrc === '') {
            $offenders[] = 'ChurnScoreManager.php introuvable';
        } else {
            // Bug du 2026-08-03 : recomputeAll() ne recalculait que les
            // clients avec activité dans les 90 derniers jours mais ne
            // purgeait jamais les lignes neria_churn_score des clients
            // sortis de cette fenêtre — un score "risque élevé" figé restait
            // affiché indéfiniment sur la fiche BO sans jamais être ni
            // recalculé ni retiré, sans que checkChurnPropensityFreshness()
            // (qui ne vérifie que la fraîcheur du dernier RUN, pas des
            // lignes individuelles) ne le détecte.
            if (strpos($churnSrc, 'DELETE FROM `{$table}` WHERE `id_shop` = {$shop}') === false) {
                $offenders[] = 'ChurnScoreManager::recomputeAll() ne purge plus les clients sortis de la fenêtre de 90 jours (scores de risque figés indéfiniment)';
            }
        }

        $wdFile = _PS_MODULE_DIR_ . $this->module->name . '/src/WatchdogManager.php';
        $wdSrc = $this->readModuleSrc($wdFile);
        if ($wdSrc === '') {
            $offenders[] = 'WatchdogManager.php introuvable';
        } else {
            // Bug du 2026-08-03 : sendDailyDigestIfDue() lit bien le throttle
            // "1x/24h" sur une clé scopée par boutique (CFG_DIGEST_LAST . '_'
            // . $this->idShop), mais la branche qui envoie effectivement le
            // digest (logs à signaler) écrivait la mise à jour SANS suffixe
            // boutique — seule la branche "rien à signaler" écrivait la
            // bonne clé. Résultat réel : le throttle ne s'appliquait jamais
            // sur le chemin normal, renvoyant un digest à chaque hit
            // hookDisplayHeader au lieu d'un par 24h (spam d'alertes).
            if (preg_match(
                "/self::CFG_DIGEST_LAST\\s*,\\s*time\\(\\)\\s*\\)\\s*;/",
                $wdSrc
            )) {
                $offenders[] = "WatchdogManager : sendDailyDigestIfDue() écrit de nouveau le throttle du digest sur la clé globale non scopée par boutique (spam d'alertes possible)";
            }
        }

        $loyaltyFile = _PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php';
        $loyaltySrc = $this->readModuleSrc($loyaltyFile);
        if ($loyaltySrc === '') {
            $offenders[] = 'LoyaltyManager.php introuvable';
        } else {
            // Bug du 2026-08-03 : sendRewardEmail()/sendRecapToCustomer()
            // reçoivent un $idShop explicite, correctement utilisé pour
            // PreferencesManager::isAllowed(), mais Mail::Send() retombait
            // sur Context::getContext()->shop->id — sur un cron traitant
            // plusieurs boutiques dans un seul process PHP (mode séparé),
            // l'email partait sous l'identité de la boutique du CONTEXTE
            // d'exécution plutôt que celle du client réel. Le lookbehind
            // négatif exclut le repli légitime "$idShop ?? (int)
            // Context::getContext()->shop->id" (utilisé quand $idShop est
            // nullable) — seul un usage SANS repli sur $idShop est un bug.
            if (preg_match_all(
                '/\\n\\s*(?<!\\?\\? )\\(int\\)\\s*\\\\Context::getContext\\(\\)->shop->id\\s*\\n\\s*\\);/',
                $loyaltySrc,
                $mLoyalty
            )) {
                $offenders[] = 'LoyaltyManager : ' . count($mLoyalty[0]) . " appel(s) Mail::Send() retombe(nt) de nouveau sur Context::getContext()->shop->id au lieu du \$idShop réel du client";
            }

            // Bug du 2026-08-03, second volet : même famille que ci-dessus
            // mais sur getBaseLink()/getPageLink() (liens {shop_url}/
            // {history_url}) plutôt que Mail::Send() — les deux appels
            // doivent transmettre $idShop (avec repli légitime "?? (int)
            // Context::getContext()->shop->id" quand il est nullable).
            if (strpos($loyaltySrc, "getBaseLink(\$idShop ?? (int) \\Context::getContext()->shop->id)") === false) {
                $offenders[] = "LoyaltyManager : sendRecapToCustomer() n'utilise plus \$idShop pour {shop_url} (getBaseLink())";
            }
            if (strpos($loyaltySrc, "getPageLink('history', true, \$idLang, null, false, \$idShop ?? (int) \\Context::getContext()->shop->id)") === false) {
                $offenders[] = "LoyaltyManager : sendRecapToCustomer() n'utilise plus \$idShop pour {history_url} (getPageLink())";
            }
            if (strpos($loyaltySrc, "getPageLink('history', true, \$idLang, null, false, \$idShop)") === false) {
                $offenders[] = "LoyaltyManager : sendRewardEmail() n'utilise plus \$idShop pour {history_url} (getPageLink())";
            }
        }

        // Bug du 2026-08-03, même famille (idShop reçu/utilisé pour la
        // logique métier mais ignoré au moment du lien/image final) —
        // trouvé dans 5 fichiers supplémentaires en cherchant systématiquement
        // le même motif que LoyaltyManager ci-dessus.
        $upsellFile = _PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php';
        $upsellSrc = $this->readModuleSrc($upsellFile);
        if ($upsellSrc === '') {
            $offenders[] = 'UpsellManager.php introuvable';
        } elseif (strpos($upsellSrc, 'getBaseLink($idShop, $ssl)') === false) {
            $offenders[] = "UpsellManager : getProductImageUrl() n'utilise plus \$idShop pour getBaseLink() (image upsell pointant vers la mauvaise boutique en multi-shop)";
        }

        $cronFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $cronSrc2 = $this->readModuleSrc($cronFile2);
        if ($cronSrc2 === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (2e vérification)';
        } else {
            if (strpos($cronSrc2, "getPageLink('history', true, \$idLang > 0 ? \$idLang : null, null, false, \$idShop)") === false) {
                $offenders[] = "BehavioralCronManager : historyUrl() n'utilise plus \$idShop pour {history_url}";
            }

            // Bug du 2026-08-03 : même défaut 29 février que
            // CalendarManager/SeasonalCampaignManager, trouvé dans
            // sendBirthdays() (DAY(c.birthday)=DAY(NOW()) ne matche jamais
            // pour un client né un 29/02 une année non bissextile — email
            // d'anniversaire jamais envoyé 3 années sur 4) et
            // sendRelationshipAnniversaries() (même défaut via
            // DATE_FORMAT(...,'%m-%d')). Repli via DAY(LAST_DAY(NOW()))=28.
            if (strpos($cronSrc2, 'MONTH(c.birthday) = 2 AND DAY(c.birthday) = 29') === false) {
                $offenders[] = "BehavioralCronManager : sendBirthdays() a perdu son repli 29/02 → 28/02 — anniversaire client jamais envoyé 3 années sur 4";
            }
            if (strpos($cronSrc2, "DATE_FORMAT(MIN(o.date_add), \\'%m-%d\\') = \\'02-29\\'") === false) {
                $offenders[] = "BehavioralCronManager : sendRelationshipAnniversaries() a perdu son repli 29/02 → 28/02 — anniversaire de relation jamais envoyé 3 années sur 4";
            }
        }

        $queueFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php';
        $queueSrc2 = $this->readModuleSrc($queueFile2);
        if ($queueSrc2 === '') {
            $offenders[] = 'QueueManager.php introuvable (2e vérification)';
        } elseif (strpos($queueSrc2, "getPageLink('history', true, \$idLang, null, false, \$idShop)") === false) {
            $offenders[] = "QueueManager : processSingle() n'utilise plus \$idShop pour {history_url}";
        }

        $segmentFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SegmentManager.php';
        $segmentSrc = $this->readModuleSrc($segmentFile);
        if ($segmentSrc === '') {
            $offenders[] = 'SegmentManager.php introuvable';
        } else {
            if (strpos($segmentSrc, "getPageLink('history', true, \$idLang, null, false, \$this->idShop)") === false) {
                $offenders[] = "SegmentManager : sendToSegment() n'utilise plus \$this->idShop pour {history_url}";
            }
            // Round 102 (2026-08-07) : {shop_url} utilisait
            // \Tools::getShopDomainSsl() non scopé (résout via
            // Context::getContext()->shop) au lieu de $this->idShop — même
            // famille de bug que LoyaltyManager/SeasonalCampaignManager
            // ci-dessus, mais oublié pour SegmentManager lors de cette
            // campagne de correctifs (seul {history_url} avait été couvert).
            if (strpos($segmentSrc, 'getBaseLink($this->idShop)') === false) {
                $offenders[] = "SegmentManager : sendToSegment() n'utilise plus \$this->idShop pour {shop_url} (getBaseLink())";
            }
        }

        $seasonalFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SeasonalCampaignManager.php';
        $seasonalSrc = $this->readModuleSrc($seasonalFile);
        if ($seasonalSrc === '') {
            $offenders[] = 'SeasonalCampaignManager.php introuvable';
        } else {
            if (strpos($seasonalSrc, 'getBaseLink($this->idShop)') === false) {
                $offenders[] = "SeasonalCampaignManager : {shop_url} n'utilise plus \$this->idShop (getBaseLink())";
            }
            if (strpos($seasonalSrc, "getPageLink('history', true, \$idLang, null, false, \$this->idShop)") === false) {
                $offenders[] = "SeasonalCampaignManager : {history_url} n'utilise plus \$this->idShop (getPageLink())";
            }

            // Bug du 2026-08-03 : date('m-d', ...) ne peut jamais produire
            // '02-29' un jour où l'année courante n'est pas bissextile — une
            // campagne configurée sur cette date précise ne se déclenchait
            // jamais 3 années sur 4, sans erreur ni log visible. Même bug déjà
            // corrigé dans CalendarManager::resolveMonthDay(), répercuté ici
            // via le repli checkdate(2, 29, ...) → 28 février.
            if (strpos($seasonalSrc, "\\checkdate(2, 29, (int) date('Y', \$targetTs))") === false) {
                $offenders[] = "SeasonalCampaignManager : runDueCampaigns() n'a plus le repli checkdate(2,29,...) pour les campagnes du 29 février (jamais déclenchées 3 années sur 4)";
            }
        }

        $clvFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ClvManager.php';
        $clvSrc = $this->readModuleSrc($clvFile);
        if ($clvSrc === '') {
            $offenders[] = 'ClvManager.php introuvable';
        } else {
            // Bug du 2026-08-04 : ni computeClv() ni assembleClv() (chemin
            // batch de getTopCustomers()) ne déduisaient les remboursements
            // (order_slip) du chiffre d'affaires utilisé pour le CLV — un
            // client remboursé à 90%+ sur chaque commande obtenait le même
            // CLV qu'un client fidèle sans remboursement, faussant le
            // ciblage marketing (Top 20, segments).
            if (substr_count($clvSrc, "'order_slip` os") < 2) {
                $offenders[] = "ClvManager : la déduction des remboursements (order_slip) a disparu d'un des deux chemins de calcul (computeClv()/assembleClv())";
            }
        }

        $calendarFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CalendarManager.php';
        $calendarSrc = $this->readModuleSrc($calendarFile);
        if ($calendarSrc !== '') {
            // Bug du 2026-08-05 : buildSentKey() (marqueur "campagne
            // calendaire déjà envoyée" pour Aïd/Noël/etc.) n'incluait pas
            // idShop — sur une install multi-boutique, la Boutique A pose
            // le marqueur en posant Configuration::updateValue() sans scope
            // boutique, et la Boutique B (même event/langue/pays) le trouve
            // déjà positionné : elle n'envoie jamais sa propre campagne à
            // ses clients, silencieusement, sans erreur ni log.
            if (!preg_match('/private function buildSentKey\([^)]*\)\s*:\s*string\s*\{.*?idShop.*?\}/s', $calendarSrc)) {
                $offenders[] = 'CalendarManager : buildSentKey() n\'inclut plus idShop — marqueur d\'envoi calendaire de nouveau partagé entre boutiques';
            }
        }

        // Bug du 2026-08-05 : la substitution des variables client
        // (firstname/message/gift_message...) via str_replace() en boucle
        // au lieu de strtr() réintroduisait la fuite de cross-substitution
        // déjà corrigée pour les variables de design — une valeur cliente
        // contenant littéralement "{autre_variable}" pouvait se faire
        // re-substituer selon l'ordre d'itération du foreach.
        $rendererFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $rendererSrc3 = $this->readModuleSrc($rendererFile2);
        if ($rendererSrc3 === '') {
            $offenders[] = 'EmailRenderer.php introuvable (2e vérification)';
        } elseif (preg_match('/foreach\s*\(\s*\$htmlTemplateVars\s+as\s+\$key\s*=>\s*\$value\s*\)\s*\{\s*if\s*\(is_string\(\$value\)\)\s*\{\s*\$compiled\s*=\s*str_replace/s', $rendererSrc3)
               || preg_match('/foreach\s*\(\s*\$templateVars\s+as\s+\$key\s*=>\s*\$value\s*\)\s*\{\s*if\s*\(is_string\(\$value\)\)\s*\{\s*\$compiledTxt\s*=\s*str_replace/s', $rendererSrc3)) {
            $offenders[] = 'EmailRenderer : les variables client (HTML et/ou TXT) sont de nouveau substituées via str_replace() en boucle au lieu de strtr() — fuite de cross-substitution possible entre variables';
        }

        // Bug du 2026-08-05 : 3 requêtes de suggestion UpsellManager ne
        // regardaient que le stock "sans attribut" (id_product_attribute=0),
        // excluant systématiquement tout produit géré par déclinaisons des
        // suggestions upsell — même famille de bug que WaitlistManager.
        $upsellFile = _PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php';
        $upsellSrc = $this->readModuleSrc($upsellFile);
        if ($upsellSrc === '') {
            $offenders[] = 'UpsellManager.php introuvable';
        } elseif (preg_match('/id_product_attribute\s*=\s*0\s+AND\s+sa\.quantity\s*>\s*0/', $upsellSrc)) {
            $offenders[] = 'UpsellManager : une requête de suggestion filtre de nouveau sur id_product_attribute=0 — les produits gérés par déclinaisons seraient de nouveau exclus des suggestions même en stock';
        }

        // Bug du 2026-08-05 : le retour de Mail::Send() n'était pas vérifié
        // dans SeasonalCampaignManager::runDueCampaigns() — un échec SMTP
        // transitoire posait quand même la déduplication annuelle,
        // excluant le client de la campagne pour le reste de l'année sans
        // aucune alerte.
        $seasonalFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SeasonalCampaignManager.php';
        $seasonalSrc = $this->readModuleSrc($seasonalFile);
        if ($seasonalSrc === '') {
            $offenders[] = 'SeasonalCampaignManager.php introuvable';
        } else {
            $okAssignPos = strpos($seasonalSrc, 'Mail::Send(');
            $continuePos = $okAssignPos !== false ? strpos($seasonalSrc, 'if (!$ok) {', $okAssignPos) : false;
            if ($okAssignPos === false
                || strpos($seasonalSrc, '$ok = ') === false
                || $continuePos === false
                || ($continuePos - $okAssignPos) > 2600
            ) {
                $offenders[] = 'SeasonalCampaignManager : runDueCampaigns() ne vérifie plus le retour de Mail::Send() avant de poser la déduplication annuelle — un échec d\'envoi exclurait le client de la campagne pour le reste de l\'année';
            }
        }

        // Bug du 2026-08-05 : SegmentManager::recomputeAll() ne filtrait pas
        // is_mpp=0 sur les ouvertures, contrairement à StatsManager partout
        // ailleurs — un client n'ouvrant jamais réellement ses emails
        // (pré-chargement Apple Mail Privacy Protection) pouvait être classé
        // ambassador/loyal au lieu de ghost/dormant.
        if ($segmentSrc !== '' && preg_match('/function\s+recomputeAll[\s\S]{0,6000}?SUM\(event_type\s*=\s*.open.\)\s+AS\s+total_opens/', $segmentSrc)) {
            $offenders[] = 'SegmentManager : recomputeAll() ne filtre plus is_mpp=0 sur total_opens/last_open — de nouveau incohérent avec StatsManager, un client sans ouverture réelle (MPP Apple) pourrait être classé ambassador/loyal';
        }

        // Bug du 2026-08-05 : DomainReputationManager::getSenderDomain()
        // lisait la clé 'from' au lieu de 'email' dans NERIA_SENDERS_JSON —
        // le contrôle de réputation domaine retombait toujours sur le
        // domaine boutique par défaut, jamais sur le vrai domaine d'un
        // expéditeur multi-langue configuré.
        $domainRepFile = _PS_MODULE_DIR_ . $this->module->name . '/src/DomainReputationManager.php';
        $domainRepSrc = $this->readModuleSrc($domainRepFile);
        if ($domainRepSrc === '') {
            $offenders[] = 'DomainReputationManager.php introuvable';
        } elseif (preg_match('/\$senders\[\$lang\]\[.from.\]/', $domainRepSrc)) {
            $offenders[] = 'DomainReputationManager : getSenderDomain() lit de nouveau la clé \'from\' au lieu de \'email\' — le contrôle de réputation ignorerait de nouveau tout expéditeur multi-langue configuré';
        }

        // Bug du 2026-08-05 (round 48) : MonthlyReportManager::getMonthKpis()
        // ne filtrait pas is_mpp=0 sur les ouvertures, incohérent avec
        // StatsManager partout ailleurs sur le dashboard BO live.
        $monthlyFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php';
        $monthlySrc2 = $this->readModuleSrc($monthlyFile2);
        if ($monthlySrc2 === '') {
            $offenders[] = 'MonthlyReportManager.php introuvable';
        } else {
            if (preg_match('/CASE WHEN event_type = .open.  THEN 1 END\) AS total_open/', $monthlySrc2)) {
                $offenders[] = 'MonthlyReportManager : total_open ne filtre de nouveau plus is_mpp=0 — incohérent avec StatsManager, gonfle le taux d\'ouverture affiché au marchand';
            }
            // Round 48 : le seuil HAVING du résumé A/B (getABTestSummary) doit
            // rester aligné sur StatsManager::SIG_MIN_SAMPLE (100) — un seuil
            // rabaissé (ex. retour à 5) annoncerait un gagnant A/B sur un
            // échantillon trop petit pour être autre chose que du bruit.
            if (!preg_match('/HAVING total_sent >= .\s*\.\s*StatsManager::SIG_MIN_SAMPLE/', $monthlySrc2)) {
                $offenders[] = 'MonthlyReportManager : getABTestSummary() n\'utilise plus StatsManager::SIG_MIN_SAMPLE comme seuil — un gagnant A/B pourrait de nouveau être annoncé sur un échantillon trop petit';
            }
        }

        // Bug du 2026-08-05 (round 48) : WaitlistManager::notifyProduct() —
        // le verrou anti-doublon ne testait que notified_at IS NULL (posé
        // seulement après envoi réussi) : deux appels concurrents pendant la
        // fenêtre d'envoi remportaient tous deux le "verrou" et envoyaient
        // le même email deux fois.
        $waitlistFile = _PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php';
        $waitlistSrc = $this->readModuleSrc($waitlistFile);
        if ($waitlistSrc === '') {
            $offenders[] = 'WaitlistManager.php introuvable';
        } elseif (!preg_match('/notified_at IS NULL\s*\n\s*AND \(claim_started_at IS NULL/', $waitlistSrc)) {
            $offenders[] = 'WaitlistManager : le verrou anti-doublon de notifyProduct() ne teste plus claim_started_at — deux appels concurrents pourraient de nouveau envoyer le même email "de retour en stock" deux fois';
        }

        // Bug du 2026-08-05 (round 48) : GdprAuditManager::auditEncryption()
        // validait la clé de chiffrement sur sa seule longueur, désynchronisé
        // de CryptoManager::loadKey() (longueur + ctype_xdigit) — une clé
        // corrompue en base pouvait afficher "actif/Grade A" avec un
        // déchiffrement en réalité cassé sur toutes les données.
        $gdprFile = _PS_MODULE_DIR_ . $this->module->name . '/src/GdprAuditManager.php';
        $gdprSrc = $this->readModuleSrc($gdprFile);
        if ($gdprSrc === '') {
            $offenders[] = 'GdprAuditManager.php introuvable';
        } elseif (!preg_match('/auditEncryption[\s\S]{0,800}?ctype_xdigit\(\$rawKey\)/', $gdprSrc)) {
            $offenders[] = 'GdprAuditManager : auditEncryption() ne vérifie plus ctype_xdigit() sur la clé — une clé corrompue en base pourrait de nouveau afficher un chiffrement "actif" alors qu\'il est réellement cassé';
        }

        // Bug du 2026-08-05 (round 48) : QueueManager::processSingle()
        // recalculait le ref_id de first_anniversary sans filtre id_shop,
        // incohérent avec BehavioralCronManager qui scope explicitement par
        // boutique — cassait la traçabilité neria_behavioral_sent en
        // multi-boutique.
        $queueFile = _PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php';
        $queueSrc = $this->readModuleSrc($queueFile);
        if ($queueSrc === '') {
            $offenders[] = 'QueueManager.php introuvable';
        } elseif (preg_match('/SELECT MIN\(id_order\) FROM[\s\S]{0,300}?AND valid = 1(?! AND id_shop)/', $queueSrc)) {
            $offenders[] = 'QueueManager : le recalcul du ref_id first_anniversary a de nouveau perdu son filtre id_shop — traçabilité neria_behavioral_sent de nouveau incohérente en multi-boutique';
        }

        // Bug du 2026-08-05 (round 48) : StatsManager::recordConversion() —
        // le garde anti cross-shop était entièrement court-circuité quand
        // id_shop de la commande valait 0 (commande orpheline/legacy),
        // réintroduisant la fuite qu'il visait à éliminer.
        $statsFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/StatsManager.php';
        $statsSrc2 = $this->readModuleSrc($statsFile2);
        if ($statsSrc2 === '') {
            $offenders[] = 'StatsManager.php introuvable (2e vérification)';
        } elseif (preg_match('/if \(\$idShop > 0 && isset\(\$sent\[.id_shop.\]\)/', $statsSrc2)) {
            $offenders[] = 'StatsManager : recordConversion() a de nouveau réintroduit le garde $idShop > 0 — la vérification cross-shop serait de nouveau court-circuitée quand id_shop de la commande vaut 0';
        }

        // Bug du 2026-08-05 (round 49) : BehavioralCronManager::run()
        // appelait SegmentManager/ChurnScoreManager/PropensityScoreManager
        // UNE SEULE FOIS après restauration du contexte boutique d'origine,
        // au lieu de rester dans la boucle par boutique — seule la boutique
        // du contexte cron d'origine avait ses segments/scores recalculés
        // chaque jour sur une install multi-boutiques.
        $cronFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $cronSrc2 = $this->readModuleSrc($cronFile2);
        if ($cronSrc2 === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (2e vérification)';
        } else {
            $shopsLoopPos = strpos($cronSrc2, 'foreach ($shops as $idShop) {');
            $segmentCallPos = strpos($cronSrc2, "SegmentManager(\$this->module))->recomputeAll()");
            $churnCallPos   = strpos($cronSrc2, "ChurnScoreManager(\$this->module))->recomputeAll()");
            $propensityCallPos = strpos($cronSrc2, "'recalculatePropensityScores'");
            $lastShopsForeach = strrpos($cronSrc2, 'foreach ($shops as $idShop) {');
            if ($shopsLoopPos === false || $segmentCallPos === false || $churnCallPos === false || $propensityCallPos === false) {
                $offenders[] = 'BehavioralCronManager : structure de run() modifiée de façon inattendue — vérifier manuellement que Segment/Churn/Propensity restent dans la boucle multi-boutique';
            } elseif ($segmentCallPos < $lastShopsForeach || $churnCallPos < $lastShopsForeach || $propensityCallPos < $lastShopsForeach) {
                $offenders[] = 'BehavioralCronManager : SegmentManager/ChurnScoreManager/PropensityScoreManager ne sont plus appelés à l\'intérieur de la boucle multi-boutique — recalcul limité à une seule boutique sur une install multi-shop';
            }
        }

        // Bug du 2026-08-05 (round 49) : restauration de traduction
        // (restore_translation/restore_variant_b, neria.php) sans vérifier
        // que l'entrée d'historique récupérée appartient au bon
        // template/langue affiché (getById() ne filtre que par id_shop) —
        // pouvait écraser silencieusement une traduction sans rapport.
        $mainFile2 = _PS_MODULE_DIR_ . $this->module->name . '/' . $this->module->name . '.php';
        $mainSrc2 = $this->readModuleSrc($mainFile2);
        if ($mainSrc2 === '') {
            $offenders[] = 'neria.php introuvable (2e vérification)';
        } else {
            $restoreCount = substr_count($mainSrc2, "entry['template_key'] ?? null)");
            if ($restoreCount < 2) {
                $offenders[] = 'neria.php : la vérification template_key/lang_code sur la restauration d\'historique de traduction (restore_translation et/ou restore_variant_b) a disparu — un id_history d\'un autre template/langue pourrait de nouveau écraser une traduction sans rapport';
            }
        }

        // Bug du 2026-08-05 (round 49) : EmailRenderer — le nom d'expéditeur
        // multi-langue (NERIA_SENDERS_JSON, champ libre non validé) était
        // injecté dans fromName sans neutralisation CR/LF.
        if ($rendererSrc3 !== '' && !preg_match('/str_replace\(\["\\\\r", "\\\\n"\], .., \$sender\[.name.\]\)/', $rendererSrc3)) {
            $offenders[] = 'EmailRenderer : fromName (expéditeur multi-langue) n\'est plus neutralisé des CR/LF avant injection';
        }

        // Bug du 2026-08-05 (round 49) : WebhookManager::trigger() posait le
        // numéro de séquence via un SECOND UPDATE après l'INSERT initial —
        // un process tué entre les deux laissait la ligne définitivement
        // sans marqueur d'ordre. Corrigé en l'injectant à la volée dans
        // processQueue() à partir de id_webhook (une seule écriture).
        $webhookFile = _PS_MODULE_DIR_ . $this->module->name . '/src/WebhookManager.php';
        $webhookSrc = $this->readModuleSrc($webhookFile);
        if ($webhookSrc === '') {
            $offenders[] = 'WebhookManager.php introuvable';
        } elseif (strpos($webhookSrc, 'idWebhook = (int) $this->db->Insert_ID()') !== false) {
            $offenders[] = 'WebhookManager : trigger() pose de nouveau le numéro de séquence via un second UPDATE après l\'INSERT — une ligne pourrait de nouveau rester définitivement sans marqueur d\'ordre si le process meurt entre les deux requêtes';
        }

        // Bug du 2026-08-05 (round 50) : EmailRenderer::wrapLinksInFile()
        // capturait une URL déjà HTML-échappée (attribut href du fichier
        // compilé) sans la décoder — un lien produit avec un paramètre
        // existant (ex. déclinaison) contenait "&amp;" littéral, corrompant
        // ensuite la clé neria_ur lue par parse_str() dans track.php.
        // UpsellManager::recordClick() n'était alors jamais appelé pour ces
        // produits.
        if ($rendererSrc3 !== '' && strpos($rendererSrc3, 'html_entity_decode($am[2], ENT_QUOTES)') === false) {
            $offenders[] = 'EmailRenderer : wrapLinksInFile() ne décode plus l\'URL capturée avec html_entity_decode() — le tracking upsell (et tout lien avec un paramètre d\'URL existant) serait de nouveau cassé par la corruption de "&amp;" lors du parse_str() dans track.php';
        }

        // Bug du 2026-08-05 (round 50) : ConfigManager::saveTypographyConfig()
        // n'avait pas de whitelist contre le catalogue de polices pour les
        // polices non-titre — une valeur hors catalogue était enregistrée
        // "avec succès" côté BO mais sans aucun effet réel sur les emails.
        // Round 186 : la whitelist est passée d'un array_keys(FONT_CATALOG)
        // global à un filtrage PAR SCRIPT (getFontsForScript($script)) —
        // chaîne recherchée mise à jour en conséquence (couvre aussi le
        // garde-fou round 186 ci-dessous sur ce même point).
        $configFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php';
        $configSrc = $this->readModuleSrc($configFile);
        if ($configSrc === '') {
            $offenders[] = 'ConfigManager.php introuvable';
        } elseif (strpos($configSrc, '$validFontsForScript = array_keys($fontMgr->getFontsForScript($script));') === false) {
            $offenders[] = 'ConfigManager : saveTypographyConfig() ne valide plus les polices contre le catalogue — une police invalide (ou round 186 : d\'un autre script) pourrait de nouveau être enregistrée "avec succès" sans effet réel voulu sur les emails';
        }

        // Bug du 2026-08-05 (mise en place PHPStan) :
        // hookActionDeleteGDPRCustomerImpl() appelait `new GdprAuditManager
        // ($this)` au lieu de `new GdprAuditManager($this->getLocalPath())`
        // — GdprAuditManager attend un string (chemin du module), pas
        // l'objet module. Sans __toString() sur Neria, ce hook levait une
        // TypeError fatale à CHAQUE suppression RGPD d'un client via le
        // bouton natif PrestaShop "Supprimer + effacer les données
        // personnelles", empêchant toute purge des données Neria pour ce
        // client. Détecté par PHPStan, jamais remarqué en usage réel.
        if ($mainSrc2 !== '' && strpos($mainSrc2, 'new GdprAuditManager($this))') !== false) {
            $offenders[] = 'neria.php : hookActionDeleteGDPRCustomerImpl() appelle de nouveau new GdprAuditManager($this) au lieu de $this->getLocalPath() — TypeError fatale garantie à chaque suppression RGPD d\'un client';
        }

        // Rattrapage 2026-08-05 : 11 correctifs des rounds 46-47 n'avaient
        // reçu aucun garde-fou dédié (contrairement aux rounds 48-50).

        // Round 46 : UpsellManager::checkConversions() — fenêtre de
        // présélection des clics trop juste (7j pile, identique à la
        // fenêtre d'attribution) pouvait perdre une conversion si le cron
        // tournait en retard. Marge de sécurité portée à 14j.
        if ($upsellSrc !== '' && strpos($upsellSrc, 'clicked_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)') === false) {
            $offenders[] = 'UpsellManager : checkConversions() n\'a plus sa marge de sécurité de 14j sur la présélection des clics — un cron en retard pourrait de nouveau perdre une conversion proche de la limite';
        }

        // Round 46 : BehavioralCronManager::getCheckoutAbandonmentStats() —
        // attribution de revenu sans borne temporelle supérieure, une
        // commande passée des mois après sur le même panier était comptée
        // comme "récupérée", gonflant le ROI affiché.
        if ($cronSrc2 !== '' && strpos($cronSrc2, 'DATE_ADD(bs.sent_at, INTERVAL 7 DAY)') === false) {
            $offenders[] = 'BehavioralCronManager : getCheckoutAbandonmentStats() n\'a plus de borne temporelle sur l\'attribution de revenu — une commande tardive sur le même panier gonflerait de nouveau le ROI affiché';
        }

        // Round 46 : ABTestManager::getVariantForEmail() — clé de
        // répartition A/B changeait quand un invité créait un compte.
        // Round 182 : chaîne recherchée mise à jour pour couvrir aussi le
        // mb_strtolower() ajouté (email non normalisé en casse changeait
        // également la variante entre deux envois).
        $abtestFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ABTestManager.php';
        $abtestSrc = $this->readModuleSrc($abtestFile);
        if ($abtestSrc === '') {
            $offenders[] = 'ABTestManager.php introuvable';
        } elseif (strpos($abtestSrc, "trim(\$email) !== '' ? mb_strtolower(trim(\$email))") === false) {
            $offenders[] = 'ABTestManager : getVariantForEmail() ne priorise plus l\'email (normalisé en casse) comme clé de répartition — un client passant d\'invité à compte, ou dont la casse de l\'email change, basculerait de nouveau de variante A/B entre deux envois';
        }

        // Round 46 : DeliverabilityScorer::getSubjectSpamTriggers() exposait
        // la liste brute sans le filtre de longueur utilisé par score().
        $deliverFile = _PS_MODULE_DIR_ . $this->module->name . '/src/DeliverabilityScorer.php';
        $deliverSrc = $this->readModuleSrc($deliverFile);
        if ($deliverSrc === '') {
            $offenders[] = 'DeliverabilityScorer.php introuvable';
        } elseif (!preg_match('/function getSubjectSpamTriggers[\s\S]{0,800}?mb_strlen\(\$trigger\) >= 4/', $deliverSrc)) {
            $offenders[] = 'DeliverabilityScorer : getSubjectSpamTriggers() ne filtre plus les triggers courts (< 4 caractères) — de nouveau incohérent avec score(), faux positifs possibles';
        }

        // Round 46 : DomainReputationManager — array_key_first() sur un
        // tableau vide testait 'fr' deux fois dans la liste de repli.
        if ($domainRepSrc !== '' && strpos($domainRepSrc, "array_unique(array_filter(['fr', 'en'") === false) {
            $offenders[] = 'DomainReputationManager : la liste de repli des expéditeurs ne dédoublonne plus via array_unique/array_filter — array_key_first() sur un tableau vide testerait de nouveau \'fr\' deux fois';
        }

        // Round 47 : BehavioralCronManager::sendQuoteExpiryReminders() —
        // fenêtres 48h/Jour J se chevauchaient sur expiry_date=CURDATE(),
        // et l'offre de prolongation pouvait s'exécuter sans être passée
        // par le rappel Jour J.
        if ($cronSrc2 !== '' && strpos($cronSrc2, 'BETWEEN DATE(DATE_ADD(NOW(), INTERVAL 1 DAY))') === false) {
            $offenders[] = 'BehavioralCronManager : la fenêtre de relance devis 48h chevauche de nouveau la fenêtre Jour J (expiry_date=CURDATE()) — double email contradictoire possible';
        }
        if ($cronSrc2 !== '' && strpos($cronSrc2, 'sent_extension = 0 AND q.sent_day = 1') === false) {
            $offenders[] = 'BehavioralCronManager : l\'offre de prolongation devis ne vérifie plus sent_day=1 — un devis pourrait de nouveau passer directement à "expired" sans jamais recevoir le rappel Jour J';
        }

        // Round 47 : CollectionManager/LookCompletionManager — la
        // réservation anti-doublon (claimSend) n'était jamais libérée sur
        // un continue intermédiaire, bloquant le client à vie même une
        // fois la condition levée (stock revenu, préférences réactivées).
        $collectionFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CollectionManager.php';
        $collectionSrc = $this->readModuleSrc($collectionFile);
        if ($collectionSrc === '') {
            $offenders[] = 'CollectionManager.php introuvable';
        } elseif (substr_count($collectionSrc, 'releaseSendClaim($colId, $idCustomer, $idShop)') < 4) {
            // Signature élargie à 3 arguments depuis l'upgrade 1.0.38 (round
            // 60, id_shop dans la réservation) — même vérification, motif
            // mis à jour en conséquence.
            $offenders[] = 'CollectionManager : releaseSendClaim() n\'est plus appelé sur toutes les sorties anticipées de processCollection() — un client temporairement bloqué (stock, préférences) resterait de nouveau exclu à vie';
        }

        $lookFile = _PS_MODULE_DIR_ . $this->module->name . '/src/LookCompletionManager.php';
        $lookSrc = $this->readModuleSrc($lookFile);
        if ($lookSrc === '') {
            $offenders[] = 'LookCompletionManager.php introuvable';
        } elseif (substr_count($lookSrc, 'releaseSendClaim($idOrder, $idCustomer)') < 6) {
            $offenders[] = 'LookCompletionManager : releaseSendClaim() n\'est plus appelé sur toutes les sorties anticipées de runDailyCheck() — un client temporairement bloqué resterait de nouveau exclu à vie pour cette commande';
        }

        // Round 47 : OrderTriggersManager::checkMilestone() — seule la
        // génération du bon (optionnelle) était dédupliquée, pas l'email
        // milestone_order lui-même.
        $otFile = _PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php';
        $otSrc = $this->readModuleSrc($otFile);
        if ($otSrc === '') {
            $offenders[] = 'OrderTriggersManager.php introuvable';
        } elseif (strpos($otSrc, 'private function claimMilestone(') === false
               || strpos($otSrc, 'if (!$this->claimMilestone($idCustomer, $count, $idShop)) {') === false
        ) {
            $offenders[] = 'OrderTriggersManager : checkMilestone() n\'utilise plus claimMilestone() avant l\'envoi — un doublon d\'email milestone_order redeviendrait possible (commande annulée puis rétablie)';
        }

        // Round 47 : CertificateManager::issue() enregistrait l'id_shop du
        // contexte BO de l'employé au lieu de celui de la commande.
        $certFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php';
        $certSrc = $this->readModuleSrc($certFile);
        if ($certSrc === '') {
            $offenders[] = 'CertificateManager.php introuvable (3e vérification)';
        } elseif (strpos($certSrc, "'id_shop'         => (int) \$order->id_shop,") === false) {
            $offenders[] = 'CertificateManager : issue() n\'enregistre plus id_shop depuis la commande — un certificat émis pour une autre boutique redeviendrait invisible dans getByOrder()/getAll() de la vraie boutique';
        }

        // Round 47 : PropensityScoreManager::recalculateAll() ne purgeait
        // jamais les scores des clients sortis du périmètre.
        $propFile = _PS_MODULE_DIR_ . $this->module->name . '/src/PropensityScoreManager.php';
        $propSrc = $this->readModuleSrc($propFile);
        if ($propSrc === '') {
            $offenders[] = 'PropensityScoreManager.php introuvable';
        } elseif (strpos($propSrc, "DELETE FROM `' . _DB_PREFIX_ . 'neria_propensity_score`") === false) {
            $offenders[] = 'PropensityScoreManager : recalculateAll() ne purge plus les scores obsolètes — un client sorti du périmètre (commande annulée) réapparaîtrait de nouveau indéfiniment dans getAlertCustomers()';
        }

        // Rattrapage 2026-08-05 : 5 correctifs de la période de développement
        // initial (avant le 17/07/2026, avant la méthode "chasse aux bugs")
        // n'avaient jamais reçu de protection, alors que leurs fichiers
        // restent parmi les plus actifs du projet.

        // Commit 2fba1c1 (03/07/2026) : ConfigManager — dépôt de logo BO,
        // RCE potentielle si l'extension du fichier sauvegardé était de
        // nouveau dérivée du nom fourni par le client (webshell via un
        // fichier polyglotte "logo.php" au contenu image valide) au lieu du
        // type MIME validé côté serveur.
        if ($configSrc !== '' && strpos($configSrc, '$ext        = $extByMime[$mime];') === false) {
            $offenders[] = 'ConfigManager : le dépôt de logo BO ne dérive plus l\'extension du fichier depuis le MIME validé — RCE potentielle via un fichier polyglotte nommé "logo.php"';
        }

        // Commit 88c480e (03/07/2026) : LoyaltyManager::generateVoucher() —
        // sans la réservation atomique AVANT création du CartRule, deux
        // requêtes quasi simultanées pouvaient créer chacune un bon de
        // réduction valide pour le même palier de fidélité (fraude aux
        // points).
        $loyaltyFile = _PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php';
        $loyaltySrc = $this->readModuleSrc($loyaltyFile);
        if ($loyaltySrc === '') {
            $offenders[] = 'LoyaltyManager.php introuvable';
        } elseif (!preg_match('/function generateVoucher[\s\S]{0,1200}?INSERT IGNORE INTO/', $loyaltySrc)) {
            $offenders[] = 'LoyaltyManager : generateVoucher() ne réserve plus le palier via INSERT IGNORE avant de créer le CartRule — deux requêtes concurrentes pourraient de nouveau créer chacune un bon valide pour le même palier';
        }

        // Commit bb99e9d (03/07/2026) : ClvManager — agrégation des
        // remboursements sans filtre id_shop, exposant en multi-boutique
        // les remboursements d'un client partagé faits sur UNE AUTRE
        // boutique.
        $clvFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ClvManager.php';
        $clvSrc = $this->readModuleSrc($clvFile);
        if ($clvSrc === '') {
            $offenders[] = 'ClvManager.php introuvable';
        } elseif (strpos($clvSrc, 'o.`id_shop` = ' . '\' . $this->idShop') === false) {
            $offenders[] = 'ClvManager : l\'agrégation des remboursements ne filtre plus par id_shop — un client partagé entre boutiques verrait de nouveau ses remboursements d\'une AUTRE boutique déduits à tort de son CLV';
        }

        // Commit 9d1f17f (07/07/2026) : EmailRenderer — signature manuscrite
        // et réseaux sociaux jamais affichés dans un email réellement
        // envoyé (bloc {if isset($var) && $var}...{/if} supprimé
        // aveuglément par le nettoyage générique avant résolution).
        if ($rendererSrc3 !== '' && strpos($rendererSrc3, "'neria_social_links' => \$html,") === false) {
            $offenders[] = 'EmailRenderer : neria_social_links n\'est plus injecté dans tplVars — la signature/les réseaux sociaux redeviendraient invisibles dans les emails réellement envoyés';
        }
        if ($rendererSrc3 !== '' && strpos($rendererSrc3, "'{\$neria_signature_url}'    => \$templateVars['neria_signature_url']  ?? '',") === false) {
            $offenders[] = 'EmailRenderer : {$neria_signature_url} n\'est plus résolu depuis templateVars — le lien de signature redeviendrait cassé ({$http://...} littéral) dans les emails réels';
        }

        // Commit 571c21b (09/07/2026) : EmailRenderer — le compile TXT
        // n'avait aucun filet de sécurité équivalent au HTML pour les
        // variables jamais résolues, affichées brutes ({days_waited}) dans
        // l'email texte livré au client.
        if ($rendererSrc3 !== '' && strpos($rendererSrc3, '$residualTxtKeys = array_unique($residualTxtMatches[0]);') === false) {
            $offenders[] = 'EmailRenderer : le filet de sécurité sur les variables résiduelles a disparu du chemin TXT — un client pourrait de nouveau recevoir un email texte avec une variable non résolue affichée brute';
        }

        // Round 51 (2026-08-05) : ManualSendManager::scheduleManual()
        // ignorait $orderRef — un envoi PLANIFIÉ d'un template utilisant
        // {order_name}/{order_url} sans commande liée partait avec le
        // placeholder brut non résolu, sans que le marchand ne puisse s'en
        // apercevoir au moment de la planification.
        $manualFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $manualSrc2 = $this->readModuleSrc($manualFile2);
        if ($manualSrc2 === '') {
            $offenders[] = 'ManualSendManager.php introuvable (2e vérification)';
        } else {
            if (strpos($manualSrc2, 'function scheduleManual(') !== false
                && preg_match_all('/\$order = \(\$orderRef !== \x27\x27\) \? \$this->findOrder\(\$orderRef,\s*\$\w+\) : null;/', $manualSrc2) < 2
            ) {
                $offenders[] = 'ManualSendManager : scheduleManual() n\'applique plus le garde-fou "contexte commande" — un envoi planifié sans commande liée repartirait avec {order_name}/{order_url} non résolus';
            }
            // Round 51 : checkDuplicate() comptait les lignes de neria_log
            // (COUNT(*)) au lieu de sommer occurrence_count — un doublon
            // consolidé par WatchdogManager::record() (même message dans
            // l'heure) ne remontait jamais comme doublon.
            if (strpos($manualSrc2, 'COALESCE(SUM(`occurrence_count`), 0)') === false) {
                $offenders[] = 'ManualSendManager : checkDuplicate() ne somme plus occurrence_count — un doublon d\'envoi manuel consolidé par le Watchdog ne serait de nouveau jamais détecté';
            }
        }

        // Round 51 : BehavioralCronManager posait la dédup
        // neria_behavioral_sent AVANT l'envoi réel via la file d'attente
        // (fenêtre d'achat), pour tout template — pas seulement les
        // anniversaires. Un échec définitif d'envoi (SMTP en panne)
        // marquait quand même le template "déjà envoyé" à vie.
        if ($cronSrc2 !== '' && strpos($cronSrc2, 'La dédup n\'est PLUS posée ici') === false) {
            $offenders[] = 'BehavioralCronManager : la dédup comportementale est de nouveau posée à la mise en file (avant l\'envoi réel) — un échec SMTP définitif marquerait à tort un template "déjà envoyé" pour de bon';
        }
        $queueFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php';
        $queueSrc2 = $this->readModuleSrc($queueFile2);
        if ($queueSrc2 === '') {
            $offenders[] = 'QueueManager.php introuvable (2e vérification)';
        } elseif (strpos($queueSrc2, '$refId = (int) $row[\'ref_id\'];') === false) {
            $offenders[] = 'QueueManager : processSingle() ne généralise plus la dédup post-envoi via row[\'ref_id\'] aux templates non-anniversaires — régression du round 51';
        }

        // Round 51 : CollectionManager::processCollection() n'élargissait
        // pas group_concat_max_len — une grande collection (150+ produits)
        // pouvait tronquer silencieusement GROUP_CONCAT(od.product_id),
        // faussant le calcul du produit manquant.
        if ($collectionSrc !== '' && strpos($collectionSrc, 'SET SESSION group_concat_max_len') === false) {
            $offenders[] = 'CollectionManager : processCollection() n\'élargit plus group_concat_max_len — une grande collection pourrait de nouveau tronquer silencieusement la liste des produits déjà achetés';
        }

        // Round 51 : PostmasterManager/SearchConsoleManager écrasaient un
        // refresh_token OAuth valide par une chaîne vide quand Google n'en
        // renvoyait pas à l'échange du code.
        $postmasterFile = _PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php';
        $postmasterSrc = $this->readModuleSrc($postmasterFile);
        if ($postmasterSrc === '') {
            $offenders[] = 'PostmasterManager.php introuvable';
        } elseif (!preg_match('/function applyTokenResponse[\s\S]{0,1000}?if \(!empty\(\$response\[.refresh_token.\]\)\)/', $postmasterSrc)) {
            $offenders[] = 'PostmasterManager : applyTokenResponse() n\'a plus de garde sur refresh_token vide — un refresh_token valide pourrait de nouveau être écrasé par une chaîne vide';
        }
        $gscFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php';
        $gscSrc = $this->readModuleSrc($gscFile);
        if ($gscSrc === '') {
            $offenders[] = 'SearchConsoleManager.php introuvable';
        } elseif (!preg_match('/function applyTokenResponse[\s\S]{0,1000}?if \(!empty\(\$response\[.refresh_token.\]\)\)/', $gscSrc)) {
            $offenders[] = 'SearchConsoleManager : applyTokenResponse() n\'a plus de garde sur refresh_token vide — un refresh_token valide pourrait de nouveau être écrasé par une chaîne vide';
        }

        // Round 51 : BounceManager::reactivateBounce() ne remettait pas
        // bounce_count à 0 — la réactivation manuelle était pratiquement
        // inopérante pour toute adresse au-dessus du seuil.
        $bounceFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BounceManager.php';
        $bounceSrc = $this->readModuleSrc($bounceFile);
        if ($bounceSrc === '') {
            $offenders[] = 'BounceManager.php introuvable';
        } elseif (!preg_match('/function reactivateBounce[\s\S]{0,900}?bounce_count\` = 0/', $bounceSrc)) {
            $offenders[] = 'BounceManager : reactivateBounce() ne remet plus bounce_count à 0 — la réactivation manuelle redeviendrait pratiquement inopérante pour toute adresse au-dessus du seuil';
        }

        // Round 52 (2026-08-05) : sendDailyDigestIfDue() dérivait ses
        // compteurs et le total du sujet des 50 lignes plafonnées
        // (affichage détaillé) au lieu de la totalité des événements des
        // 24 dernières heures.
        $watchdogFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/WatchdogManager.php';
        $watchdogSrc2 = $this->readModuleSrc($watchdogFile2);
        if ($watchdogSrc2 === '') {
            $offenders[] = 'WatchdogManager.php introuvable (2e vérification)';
        } elseif (!preg_match('/function sendDailyDigestIfDue[\s\S]{0,5000}?GROUP BY `level`/', $watchdogSrc2)) {
            $offenders[] = 'WatchdogManager : sendDailyDigestIfDue() n\'utilise plus de requête de comptage séparée (GROUP BY level, sans LIMIT) — les compteurs du digest redeviendraient plafonnés à 50 événements';
        }

        // Round 52 : sig_color (aperçu + sauvegarde signature) n'était pas
        // validé via NeriaTools::sanitizeColor() avant d'atteindre
        // SignatureGenerator::hexToRgb().
        if ($mainSrc2 !== '' && substr_count($mainSrc2, "NeriaTools::sanitizeColor((string) Tools::getValue('sig_color'") < 2) {
            $offenders[] = 'neria.php : sig_color n\'est plus validé via NeriaTools::sanitizeColor() aux 2 points d\'entrée (aperçu AJAX + sauvegarde) — une couleur mal formée rendrait de nouveau la signature en noir/couleur incohérente';
        }

        // Round 52 : QueueManager::enqueueAt() ne vérifiait pas le résultat
        // de l'INSERT — un envoi manuel planifié en doublon (contrainte
        // UNIQUE) échouait silencieusement, ManualSendManager annonçant
        // quand même "programmé avec succès".
        if ($queueSrc2 !== '' && strpos($queueSrc2, "INSERT IGNORE INTO `' . \$this->prefix . 'neria_queue`\n             (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,\n              vars_json, ref_id, send_at, status, created_at)") === false) {
            $offenders[] = 'QueueManager : enqueueAt() n\'utilise plus INSERT IGNORE — un envoi manuel planifié en doublon échouerait de nouveau silencieusement sans que ManualSendManager ne le détecte';
        }
        if ($manualSrc2 !== '' && strpos($manualSrc2, '$queued = (new \QueueManager($this->module))->enqueueAt(') === false) {
            $offenders[] = 'ManualSendManager : scheduleManual() ne vérifie plus le retour d\'enqueueAt() — annoncerait de nouveau "programmé avec succès" pour un envoi jamais réellement mis en file';
        }

        // Round 53 (2026-08-05) : CooldownManager::BYPASS_TEMPLATES
        // contenait des noms de templates morts ('password_reset',
        // 'account_guest') et omettait le vrai template 'password'.
        $cooldownFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CooldownManager.php';
        $cooldownSrc = $this->readModuleSrc($cooldownFile);
        if ($cooldownSrc === '') {
            $offenders[] = 'CooldownManager.php introuvable';
        } elseif (!preg_match("/const BYPASS_TEMPLATES = \[[\s\S]{0,150}?'password',/", $cooldownSrc)) {
            $offenders[] = 'CooldownManager : BYPASS_TEMPLATES n\'exempte plus le vrai template \'password\' — un client qui double-soumet son nouveau mot de passe verrait de nouveau le 2e email silencieusement bloqué';
        }

        // Round 53 : seo_semrush_key/seo_moz_access redevenus protégés par
        // un if (empêchant leur révocation volontaire), OU seo_moz_secret
        // écrasé sans condition (effacerait le secret à chaque sauvegarde
        // du formulaire, ce champ n'étant jamais pré-rempli).
        if ($mainSrc2 !== '') {
            if (preg_match("/if \(\\\$semrushKey !== .{2}\)\s*\{\s*Configuration::updateValue\(SeoApiManager::CONFIG_SEMRUSH_KEY/", $mainSrc2)) {
                $offenders[] = 'neria.php : seo_semrush_key est de nouveau protégé par un if — la révocation volontaire d\'une clé Semrush redeviendrait impossible';
            }
            if (!preg_match("/if \(\\\$mozSecret !== .{2}\)\s*\{\s*Configuration::updateValue\(SeoApiManager::CONFIG_MOZ_SECRET/", $mainSrc2)) {
                $offenders[] = 'neria.php : seo_moz_secret n\'est plus protégé par un if — ce champ password jamais pré-rempli effacerait le secret à chaque sauvegarde du formulaire SEO';
            }
        }

        // Round 53 : hookDisplayBackOfficeHeaderImpl() réenregistrait 4
        // hooks à chaque page BO sans garde — une désactivation manuelle
        // admin via l'onglet "Hooks" était silencieusement annulée.
        if ($mainSrc2 !== '' && strpos($mainSrc2, "Configuration::get('NERIA_HOOKS_MIGRATED_V2')") === false) {
            $offenders[] = 'neria.php : hookDisplayBackOfficeHeaderImpl() n\'a plus le flag NERIA_HOOKS_MIGRATED_V2 — les 4 hooks seraient de nouveau réenregistrés à chaque page BO, annulant toute désactivation manuelle admin';
        }

        // Round 54 (2026-08-05) : controllers/front/waitlist.php calculait
        // son repli par défaut ($redirect) avec l'id_product brut avant
        // toute validation — redirection vers un produit invalide (404) au
        // lieu du repli my-account.
        $waitlistCtrlFile = _PS_MODULE_DIR_ . $this->module->name . '/controllers/front/waitlist.php';
        $waitlistCtrlSrc = $this->readModuleSrc($waitlistCtrlFile);
        if ($waitlistCtrlSrc === '') {
            $offenders[] = 'controllers/front/waitlist.php introuvable';
        } elseif (strpos($waitlistCtrlSrc, "\$redirect  = 'index.php?controller=my-account';") === false) {
            $offenders[] = 'controllers/front/waitlist.php : le repli \$redirect par défaut dépend de nouveau de \$idProduct — redirection possible vers un produit invalide (404) au lieu de my-account';
        }

        // Round 54 : EmailRenderer::isExcluded() laissait $lang = '' quand
        // idLang était absent des params — une règle de blacklist ciblée
        // sur une langue précise ne matchait jamais.
        if ($rendererSrc3 !== '' && strpos($rendererSrc3, "\\Configuration::get('PS_LANG_DEFAULT');\n        \$lang = \$idLang > 0") === false) {
            $offenders[] = 'EmailRenderer : isExcluded() ne retombe plus sur PS_LANG_DEFAULT quand idLang est absent — une règle de blacklist ciblée par langue pourrait de nouveau être contournée silencieusement';
        }

        // Round 55 (2026-08-05) : sendGhostCarts() excluait un client de la
        // relance panier fantôme si N'IMPORTE QUELLE boutique avait une
        // commande validée pour ce produit (NOT EXISTS non scopé par
        // id_shop), contrairement au reste du fichier. Sur une install
        // multi-boutiques à compte client mutualisé, un achat ancien sur
        // une autre boutique masquait silencieusement un panier abandonné
        // réel sur la boutique courante.
        $cronFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $cronSrc2 = $this->readModuleSrc($cronFile2);
        if ($cronSrc2 === '') {
            $offenders[] = 'src/BehavioralCronManager.php introuvable';
        } elseif (!preg_match('/function sendGhostCarts\(\).*?o\.valid = 1\s*AND o\.id_shop = \' \. \$idShop \. \'/s', $cronSrc2)) {
            $offenders[] = 'BehavioralCronManager::sendGhostCarts() ne filtre plus o.id_shop dans son NOT EXISTS sur les commandes — un achat validé sur une autre boutique pourrait de nouveau masquer silencieusement un panier abandonné réel';
        }

        // Round 56 (2026-08-05) : LoyaltyManager::generateVoucher() complétait
        // la réservation de palier (UPDATE id_cart_rule/voucher_code) filtrée
        // uniquement par id_customer + tier_key, sans id_shop — contrairement
        // à l'INSERT et au DELETE de rollback juste au-dessus. En mode séparé,
        // deux boutiques réservant quasi simultanément le même palier pour un
        // même client voyaient l'UPDATE de l'une écraser la réservation de
        // l'autre avec un id_cart_rule/voucher_code invalide pour elle.
        $loyaltyFile = _PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php';
        $loyaltySrc = $this->readModuleSrc($loyaltyFile);
        if ($loyaltySrc === '') {
            $offenders[] = 'src/LoyaltyManager.php introuvable';
        } elseif (strpos($loyaltySrc, "AND tier_key = '\" . pSQL(\$tier['key']) . \"'\n               AND id_shop = \" . \$reservationShopId") === false) {
            $offenders[] = 'LoyaltyManager::generateVoucher() ne filtre plus id_shop dans l\'UPDATE final de la réservation de palier — deux boutiques réservant le même palier pour le même client pourraient de nouveau s\'écraser mutuellement leur bon';
        }

        // Round 57 (2026-08-05) : SeasonalCampaignManager::runDueCampaigns()
        // lisait/écrivait neria_behavioral_sent (dédup annuelle) sans jamais
        // préciser id_shop — contrairement à tous les autres appelants de
        // cette table. Toute ligne tombait sur le défaut id_shop=1 de la
        // colonne, quelle que soit la vraie boutique.
        // Round 143 : le SELECT COUNT(*) + INSERT IGNORE après coup a été
        // remplacé par claimSend()/releaseSendClaim() (réservation atomique
        // — voir le garde-fou round 143 plus bas) ; cette vérification
        // round 57 est mise à jour pour porter sur le nouveau helper plutôt
        // que sur le code disparu.
        $seasonalFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SeasonalCampaignManager.php';
        $seasonalSrc = $this->readModuleSrc($seasonalFile);
        if ($seasonalSrc === '') {
            $offenders[] = 'src/SeasonalCampaignManager.php introuvable';
        } elseif (strpos($seasonalSrc, "VALUES ({\$idCustomer}, '\" . pSQL(\$sentKey) . \"', {\$year}, \" . (int) \$this->idShop . \", NOW())") === false
               || strpos($seasonalSrc, "(id_customer, template, ref_id, id_shop, sent_at)") === false) {
            $offenders[] = 'SeasonalCampaignManager::claimSend() ne filtre/écrit plus id_shop dans sa déduplication annuelle (neria_behavioral_sent) — un client partagé entre boutiques pourrait de nouveau recevoir une campagne saisonnière en double, ou sa dédup être purgée/conservée à tort selon la boutique';
        }

        // Round 58 (2026-08-05) : BehavioralCronManager::run() appelait
        // GdprAuditManager::purgeAllRegistryTables() une seule fois, APRÈS
        // restauration du contexte d'origine, hors de la boucle par
        // boutique — alors que GdprAuditManager scope toutes ses requêtes
        // de purge par $this->idShop capturé à la construction. Seule la
        // boutique du contexte d'origine (typiquement la première visitée)
        // voyait ses tables purgées ; les autres dépassaient silencieusement
        // leur rétention RGPD configurée sur une install multi-boutiques.
        $cronFile3b = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $cronSrc3b = $this->readModuleSrc($cronFile3b);
        if ($cronSrc3b === '') {
            $offenders[] = 'src/BehavioralCronManager.php introuvable (purge RGPD)';
        } else {
            $posLoopStart = strpos($cronSrc3b, 'foreach ($shops as $idShop) {');
            $posPurgeCall = strpos($cronSrc3b, 'purgeAllRegistryTables()');
            $posRestore   = strpos($cronSrc3b, '\Context::getContext()->shop = $originalShop;');
            if ($posLoopStart === false || $posPurgeCall === false || $posRestore === false
                || !($posPurgeCall > $posLoopStart && $posPurgeCall < $posRestore)) {
                $offenders[] = "BehavioralCronManager::run() n'appelle plus purgeAllRegistryTables() à l'intérieur de la boucle par boutique — seule la boutique du contexte d'origine serait de nouveau purgée automatiquement (RGPD)";
            }
        }

        // Round 59 (2026-08-06) : ClvManager::getTopCustomers() divisait par
        // o.conversion_rate sans protection contre 0 dans son ORDER BY de
        // présélection et son agrégat total_revenue — contrairement aux
        // remboursements de cette même méthode et à computeClv(). Une seule
        // commande à conversion_rate=0 (donnée legacy/import) rendait le
        // SUM() de tout le client NULL en SQL, l'excluant du pool des 200
        // candidats et/ou écrasant son CA réel à 0 dans le Top 20 CLV.
        $clvFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ClvManager.php';
        $clvSrc = $this->readModuleSrc($clvFile);
        if ($clvSrc === '') {
            $offenders[] = 'src/ClvManager.php introuvable';
        } elseif (strpos($clvSrc, "ORDER BY SUM(o.`total_paid_tax_incl` / IF(o.`conversion_rate` = 0, 1, o.`conversion_rate`)) DESC") === false
               || strpos($clvSrc, "SUM(o.`total_paid_tax_incl` / IF(o.`conversion_rate` = 0, 1, o.`conversion_rate`)) AS total_revenue") === false) {
            $offenders[] = "ClvManager::getTopCustomers() ne protège plus ses divisions par o.conversion_rate contre 0 — un client avec une commande à conversion_rate=0 pourrait de nouveau être exclu du Top 20 CLV ou voir son CA écrasé à 0";
        }

        // Round 60 (2026-08-06) : CollectionManager::claimSend()/
        // releaseSendClaim() (réservation anti-doublon de neria_collection_
        // sent) n'étaient pas scopés par id_shop, alors que
        // processCollection() groupe déjà les achats par (customer, shop)
        // pour ne pas mélanger les catalogues multi-boutiques. Un même
        // client complétant réellement la même collection sur deux
        // boutiques distinctes voyait sa 2e complétion bloquée à tort par
        // la réservation posée pour la 1re (upgrade 1.0.38).
        $collectionFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CollectionManager.php';
        $collectionSrc = $this->readModuleSrc($collectionFile);
        if ($collectionSrc === '') {
            $offenders[] = 'src/CollectionManager.php introuvable';
        } elseif (strpos($collectionSrc, 'private function claimSend(int $colId, int $idCustomer, int $idShop): bool') === false
               || strpos($collectionSrc, 'private function releaseSendClaim(int $colId, int $idCustomer, int $idShop): void') === false) {
            $offenders[] = "CollectionManager::claimSend()/releaseSendClaim() ne sont plus scopés par id_shop — un même client complétant réellement la même collection sur deux boutiques distinctes pourrait de nouveau voir sa 2e complétion bloquée à tort";
        }

        // Round 61 (2026-08-06) : OrderTriggersManager::checkMilestone() ne
        // libérait jamais sa réservation anti-doublon (neria_milestone_
        // voucher) en cas d'échec d'envoi de milestone_order (silent fail
        // ou exception) — contrairement à generateMilestoneVoucher() qui
        // libère bien la sienne si CartRule::add() échoue. Aucun cron de
        // retry n'existe pour ce template : un client franchissant un
        // palier au moment d'une panne SMTP transitoire perdait
        // définitivement l'email et son bon.
        $otFile = _PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php';
        $otSrc = $this->readModuleSrc($otFile);
        if ($otSrc === '') {
            $offenders[] = 'src/OrderTriggersManager.php introuvable';
        } elseif (!preg_match('/private function releaseMilestoneClaim/', $otSrc)
               || substr_count($otSrc, 'releaseMilestoneClaim($idCustomer, $count, $idShop)') < 2) {
            $offenders[] = "OrderTriggersManager::checkMilestone() ne libère plus sa réservation milestone en cas d'échec d'envoi — un client franchissant un palier au moment d'une panne d'envoi transitoire perdrait de nouveau définitivement l'email milestone_order et son bon, sans aucun mécanisme de retry";
        }

        // Round 62 (2026-08-06) : QueueManager::processSingle() recalculait
        // ref_id = (int) date('Y') pour relationship_anniversary au moment
        // de l'envoi réel, au lieu d'utiliser row['ref_id'] (le millésime
        // déjà figé à l'enqueue). Un envoi reporté au lendemain par la
        // fenêtre d'achat individuelle à cheval sur le Nouvel An écrivait
        // alors le mauvais millésime dans neria_behavioral_sent, cassant la
        // déduplication l'année suivante (client privé de son email).
        $queueFile = _PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php';
        $queueSrc = $this->readModuleSrc($queueFile);
        if ($queueSrc === '') {
            $offenders[] = 'src/QueueManager.php introuvable';
        } elseif (strpos($queueSrc, "elseif (\$row['template'] === 'relationship_anniversary')") !== false
               || strpos($queueSrc, "\$refId = (int) date('Y');") !== false) {
            $offenders[] = "QueueManager::processSingle() recalcule de nouveau ref_id via date('Y') pour relationship_anniversary au lieu d'utiliser row['ref_id'] figé à l'enqueue — un envoi reporté à cheval sur le Nouvel An écrirait de nouveau le mauvais millésime, cassant la déduplication l'année suivante";
        }

        // Round 63 (2026-08-06) : order_partial_shipped, order_on_hold,
        // refund_processed et return_received (OrderTriggersManager) ne
        // fournissaient jamais {id_order} dans templateVars — alors que
        // neria.php (hookActionEmailSendBefore) lit cette clé pour scoper
        // CooldownManager::isDuplicate() à UNE commande précise. Un client
        // avec deux commandes distinctes déclenchant le même type d'email
        // dans la même fenêtre de cooldown voyait le second bloqué à tort
        // comme doublon du premier.
        $otFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php';
        $otSrc2 = $this->readModuleSrc($otFile2);
        if ($otSrc2 === '') {
            $offenders[] = 'src/OrderTriggersManager.php introuvable (id_order cooldown)';
        } elseif (preg_match_all('/\'\{id_order\}\'\s*=>\s*\(int\)\s*\$order->id/', $otSrc2) < 4) {
            $offenders[] = "OrderTriggersManager ne fournit plus {id_order} dans templateVars pour order_partial_shipped/order_on_hold/refund_processed/return_received — le Mode Silence pourrait de nouveau bloquer à tort un email légitime pour une commande différente du même client dans la même fenêtre de cooldown";
        }

        // Round 64 (2026-08-06) : BehavioralCronManager::
        // sendRefundReconciliations() (relances refund_reconciliation_1/2/3)
        // ne fournissait jamais {id_order} dans templateVars — même pattern
        // que le round 63, qui manquait encore ici. Un client remboursé sur
        // deux commandes distinctes voyait la relance de la 2e bloquée à
        // tort comme doublon de la 1re dans la même fenêtre de cooldown.
        $cronFile4 = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $cronSrc4 = $this->readModuleSrc($cronFile4);
        if ($cronSrc4 === '') {
            $offenders[] = 'src/BehavioralCronManager.php introuvable (refund_reconciliation cooldown)';
        } elseif (preg_match_all('/\$this->send\(\'refund_reconciliation_\d\', \$customer, \$reconciliationVars, \$idOrder\)/', $cronSrc4) !== 3) {
            $offenders[] = "BehavioralCronManager::sendRefundReconciliations() ne fournit plus {id_order} dans templateVars pour refund_reconciliation_1/2/3 — le Mode Silence pourrait de nouveau bloquer à tort une relance légitime pour une commande différente du même client dans la même fenêtre de cooldown";
        }

        // Round 65 (2026-08-06) : OrderTriggersManager::handleReturn()
        // n'avait aucun verrou anti-doublon contrairement à handleRefund()
        // (verrouillé par avoir via GET_LOCK) — un double déclenchement du
        // hook actionObjectOrderReturnAddAfter (rejeu, module tiers, double
        // dispatch PrestaShop) pouvait renvoyer deux fois return_received
        // pour le même retour.
        $otFile3 = _PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php';
        $otSrc3 = $this->readModuleSrc($otFile3);
        if ($otSrc3 === '') {
            $offenders[] = 'src/OrderTriggersManager.php introuvable (verrou return)';
        } elseif (strpos($otSrc3, "GET_LOCK('\" . pSQL(\$lockName) . \"', 0)") === false
               || strpos($otSrc3, "RELEASE_LOCK('\" . pSQL(\$lockName) . \"')") === false) {
            $offenders[] = "OrderTriggersManager::handleReturn() ne pose plus (ou ne libère plus) son verrou anti-doublon GET_LOCK('neria_return_' . id) — un double déclenchement du hook actionObjectOrderReturnAddAfter pourrait de nouveau envoyer return_received deux fois pour le même retour";
        }

        // Round 66 (2026-08-06) : BlacklistManager::add()/remove()
        // renvoyaient toujours true dès lors que execute() n'échouait pas,
        // même si 0 ligne n'était réellement affectée (doublon ignoré par
        // INSERT IGNORE, ou id déjà supprimé) — faux message de succès côté
        // BO, sans perte de données.
        $blFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BlacklistManager.php';
        $blSrc = $this->readModuleSrc($blFile);
        if ($blSrc === '') {
            $offenders[] = 'src/BlacklistManager.php introuvable';
        } elseif (substr_count($blSrc, 'return (bool) $ok && (int) $this->db->Affected_Rows() > 0;') !== 2) {
            $offenders[] = "BlacklistManager::add()/remove() ne vérifient plus Affected_Rows() — un doublon ou un id déjà supprimé afficherait de nouveau un faux message de succès côté BO";
        }

        // Round 67 (2026-08-06) : les liens waitlist subscribe/unsubscribe
        // concaténaient en dur '?action=...&id_product=...&back=...' sur le
        // résultat de getModuleLink(). Avec l'URL rewriting désactivé
        // (PS_REWRITING_SETTINGS=0), getModuleLink() retourne déjà une URL
        // porteuse d'un '?' — le second '?' concaténé fusionnait 'action=...'
        // dans la VALEUR du paramètre précédent : le contrôleur ne voyait
        // jamais l'action demandée, le lien ne faisait plus rien,
        // silencieusement.
        $waitlistFile = _PS_MODULE_DIR_ . $this->module->name . '/neria.php';
        $waitlistSrc = $this->readModuleSrc($waitlistFile);
        if ($waitlistSrc === '') {
            $offenders[] = 'neria.php introuvable (liens waitlist)';
        } elseif (strpos($waitlistSrc, "'?action=unsubscribe&id_product='") !== false
               || strpos($waitlistSrc, "'?action=subscribe&id_product='") !== false) {
            $offenders[] = "neria.php concatène de nouveau '?action=...' en dur sur le résultat de getModuleLink() pour les liens waitlist — cassé sur une boutique avec l'URL rewriting désactivé";
        }

        // Round 68 (2026-08-06) : FontManager::generateFontCss() injectait
        // accentColor tel quel dans du CSS, sans passer par
        // NeriaTools::sanitizeColor() — défense en profondeur absente (non
        // exploitable aujourd'hui via l'admin, ConfigManager::
        // saveDesignConfig() validant déjà ce format à l'écriture, mais sans
        // second contrôle si la valeur en base était altérée par un autre
        // chemin).
        $fontFile = _PS_MODULE_DIR_ . $this->module->name . '/src/FontManager.php';
        $fontSrc = $this->readModuleSrc($fontFile);
        if ($fontSrc === '') {
            $offenders[] = 'src/FontManager.php introuvable';
        } elseif (strpos($fontSrc, 'NeriaTools::sanitizeColor(') === false) {
            $offenders[] = "FontManager::generateFontCss() n'appelle plus NeriaTools::sanitizeColor() sur accentColor avant injection CSS — défense en profondeur retirée";
        }

        // Round 69 (2026-08-06) : CalendarManager::getEligibleCustomers()
        // plafonnait silencieusement à 500 destinataires (ORDER BY
        // id_customer ASC), sans aucune détection ni journalisation du
        // dépassement — sur une boutique dépassant 500 clients éligibles,
        // ce tri déterministe renvoyait toujours les 500 premiers
        // id_customer, privant les clients inscrits après les 500 premiers
        // de toute campagne calendaire, année après année.
        $calFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CalendarManager.php';
        $calSrc = $this->readModuleSrc($calFile);
        if ($calSrc === '') {
            $offenders[] = 'src/CalendarManager.php introuvable';
        } elseif (strpos($calSrc, 'LIMIT " . (self::MAX_RECIPIENTS_PER_EVENT + 1);') === false
               || strpos($calSrc, "\\WatchdogManager::i18nMsg('watchdog.calendar_recipient_cap_exceeded'") === false) {
            $offenders[] = "CalendarManager::getEligibleCustomers() ne détecte/journalise plus le dépassement du plafond de 500 destinataires — les clients au-delà des 500 premiers pourraient de nouveau ne jamais recevoir de campagne calendaire, silencieusement";
        }

        // Round 70 (2026-08-06) : ClvManager::getTopCustomers() présélectionne
        // un pool de 200 candidats triés par CA BRUT (simple proxy du vrai
        // CLV), sans détecter/journaliser quand le nombre réel de clients
        // candidats dépasse ce pool — un client hors des 200 premiers en CA
        // brut mais au profil très favorable (engagement/segment/churn)
        // pouvait avoir un CLV réel supérieur à un client du pool, exclu du
        // Top N sans que l'admin n'en soit jamais informé.
        $clvFile2 = _PS_MODULE_DIR_ . $this->module->name . '/src/ClvManager.php';
        $clvSrc2 = $this->readModuleSrc($clvFile2);
        if ($clvSrc2 === '') {
            $offenders[] = 'src/ClvManager.php introuvable (pool Top 200)';
        } elseif (strpos($clvSrc2, 'SELECT COUNT(DISTINCT o.`id_customer`)') === false
               || strpos($clvSrc2, "\\WatchdogManager::i18nMsg('watchdog.clv_top_pool_capped'") === false) {
            $offenders[] = "ClvManager::getTopCustomers() ne détecte/journalise plus le dépassement du pool de 200 candidats — le Top 20 CLV pourrait de nouveau exclure silencieusement des clients à forte valeur réelle mal classés par CA brut";
        }

        // Round 71 (2026-08-06) : DomainReputationManager::checkBlacklists()
        // ne distinguait pas un budget DNS épuisé (checked=0, aucune RBL
        // réellement interrogée) d'un "0 hit après vérification complète" —
        // computeScore() accordait les points pleins (25/25) sur la
        // composante blacklist dans les deux cas, un domaine réellement
        // blacklisté au moment du check pouvant obtenir un score parfait
        // sans aucune alerte.
        $domRepFile = _PS_MODULE_DIR_ . $this->module->name . '/src/DomainReputationManager.php';
        $domRepSrc = $this->readModuleSrc($domRepFile);
        if ($domRepSrc === '') {
            $offenders[] = 'src/DomainReputationManager.php introuvable';
        } elseif (strpos($domRepSrc, "'timed_out' => \$checked < count(self::RBL_LIST),") === false
               || strpos($domRepSrc, "} elseif (!empty(\$bl['timed_out'])) {") === false) {
            $offenders[] = "DomainReputationManager ne distingue plus un budget DNS épuisé d'un '0 hit' réel sur la composante blacklist — un domaine réellement blacklisté pourrait de nouveau obtenir un score de réputation parfait sans alerte si checkDkim() épuise le budget avant checkBlacklists()";
        }

        // Round 72 (2026-08-06) : vip, private_invitation, voucher et
        // voucher_new (envoi manuel ManualSendManager::WAVE1_TEMPLATES,
        // éligibles A/B testing ABTestManager::getEligibleTemplates())
        // étaient absents de PreferencesManager::TEMPLATE_CAT — isAllowed()
        // les traitait comme "non classés" et autorisait TOUJOURS leur
        // envoi, même à un client ayant explicitement désactivé la
        // catégorie correspondante.
        $prefFile3 = _PS_MODULE_DIR_ . $this->module->name . '/src/PreferencesManager.php';
        $prefSrc3 = $this->readModuleSrc($prefFile3);
        if ($prefSrc3 === '') {
            $offenders[] = 'src/PreferencesManager.php introuvable (TEMPLATE_CAT vip/voucher)';
        } else {
            $missingCat = [];
            foreach (['vip', 'private_invitation', 'voucher', 'voucher_new'] as $tpl) {
                if (!preg_match('/\'' . preg_quote($tpl, '/') . '\'\s*=>\s*\'behav\'/', $prefSrc3)) {
                    $missingCat[] = $tpl;
                }
            }
            if ($missingCat) {
                $offenders[] = "PreferencesManager::TEMPLATE_CAT ne couvre plus " . implode(', ', $missingCat) . " — ces templates seraient de nouveau envoyés sans respecter les préférences client (catégorie 'behav')";
            }
        }

        // Round 72b (06/08/2026) : checkTemplateCategoryMappingComplete()
        // scannait uniquement les Mail::Send()/->send() littéraux — les
        // catalogues DYNAMIQUEMENT sélectionnables par le marchand
        // (ManualSendManager::WAVE1_TEMPLATES, ABTestManager::$eligible)
        // étaient invisibles au scan, ce qui a laissé passer 21 templates
        // (vip, private_invitation, voucher, voucher_new au round 72, puis
        // 21 de plus au round 72b) sans catégorie de préférence pendant des
        // semaines. Le fix EST le renforcement du scan lui-même — ce garde-
        // fou vérifie donc que ce scan étendu n'a pas été silencieusement
        // retiré par un futur refactor, pas les templates un par un (déjà
        // couvert par checkTemplateCategoryMappingComplete() lui-même de
        // façon prospective, pour tout futur ajout à ces deux catalogues).
        $selfFile = _PS_MODULE_DIR_ . $this->module->name . '/src/HealthCheckManager.php';
        $selfSrc = $this->readModuleSrc($selfFile);
        if ($selfSrc === '') {
            $offenders[] = 'src/HealthCheckManager.php introuvable (auto-vérification scan WAVE1/ABTest)';
        } else {
            if (strpos($selfSrc, 'ManualSendManager::WAVE1_TEMPLATES') === false) {
                $offenders[] = 'HealthCheckManager : checkTemplateCategoryMappingComplete() ne scanne plus ManualSendManager::WAVE1_TEMPLATES — les templates d\'envoi manuel non catégorisés redeviendraient invisibles à ce contrôle';
            }
            if (strpos($selfSrc, "\\\$eligible\\s*=\\s*\\[") === false) {
                $offenders[] = 'HealthCheckManager : checkTemplateCategoryMappingComplete() ne scanne plus $eligible d\'ABTestManager — les templates éligibles A/B non catégorisés redeviendraient invisibles à ce contrôle';
            }
        }

        // Round 73 (2026-08-06) : WatchdogManager::getQueueHealth() (3
        // requêtes stuck/failed/total_pending sur neria_queue) n'étaient pas
        // scopées par id_shop, contrairement à toutes les autres requêtes de
        // cette classe. Sur une install multi-boutiques, le widget de santé
        // de CHAQUE boutique agrégeait les emails bloqués/échoués de TOUTES
        // les boutiques — fausse alerte sur une boutique saine si une autre
        // a une panne SMTP transitoire.
        $wdFile = _PS_MODULE_DIR_ . $this->module->name . '/src/WatchdogManager.php';
        $wdSrc = $this->readModuleSrc($wdFile);
        if ($wdSrc === '') {
            $offenders[] = 'src/WatchdogManager.php introuvable (getQueueHealth id_shop)';
        } elseif (substr_count($wdSrc, "AND `id_shop` = {\$this->idShop}") < 3) {
            $offenders[] = "WatchdogManager::getQueueHealth() ne filtre plus id_shop sur ses 3 requêtes (stuck/failed/total_pending) — le widget de santé de chaque boutique agrégerait de nouveau les emails bloqués/échoués de toutes les boutiques";
        }

        // Round 74 (2026-08-06) : CertificateManager::sendCertificateEmail()
        // calculait \$idShop depuis le contexte BO courant de l'employé au
        // lieu de \$order->id_shop (la vraie boutique de la commande) —
        // contrairement à l'INSERT en base dans issue(), déjà corrigé. Un
        // employé en contexte différent de la boutique de la commande
        // envoyait l'email de certificat avec la config SMTP/expéditeur de
        // la mauvaise boutique.
        $certFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php';
        $certSrc = $this->readModuleSrc($certFile);
        if ($certSrc === '') {
            $offenders[] = 'src/CertificateManager.php introuvable (email scopé boutique)';
        } elseif (strpos($certSrc, '$idShop   = (int) $order->id_shop;') === false) {
            $offenders[] = "CertificateManager::sendCertificateEmail() ne calcule plus \$idShop depuis \$order->id_shop — l'email de certificat pourrait de nouveau être envoyé avec la config SMTP/expéditeur du contexte BO de l'employé au lieu de la vraie boutique de la commande";
        }

        // Round 75 (2026-08-06) : ManualSendManager::send()/scheduleManual()
        // résolvaient {shop_url}/{history_url} (et pour send(), isAllowed()/
        // Mail::Send() aussi) d'après le contexte BO de l'employé au lieu de
        // la vraie boutique du client — même défaut que CertificateManager
        // (round 74). findCustomer() ne sélectionnait même pas id_shop.
        $msFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $msSrc = $this->readModuleSrc($msFile);
        if ($msSrc === '') {
            $offenders[] = 'src/ManualSendManager.php introuvable (liens scopés boutique client)';
        } elseif (strpos($msSrc, "SELECT `id_customer`, `id_lang`, `firstname`, `lastname`, `id_shop`") === false
               || strpos($msSrc, 'private function resolveShopUrl(int $idShop): string') === false
               || substr_count($msSrc, '$this->resolveShopUrl($idShop') < 2) {
            $offenders[] = "ManualSendManager ne résout plus {shop_url}/{history_url} d'après la vraie boutique du client (findCustomer()/resolveShopUrl()) — send()/scheduleManual() pourraient de nouveau utiliser le contexte BO de l'employé";
        }

        // Round 76 (2026-08-06) : neria.php::runBackgroundJobs() instanciait
        // CalendarManager UNE SEULE FOIS, sans boucle multi-boutique — même
        // défaut déjà corrigé au round 49 pour Segment/Churn/Propensity.
        // Seule la boutique du premier visiteur front du jour recevait les
        // emails calendaires ; les autres boutiques n'en recevaient jamais,
        // aucun jour.
        $neriaFile2 = _PS_MODULE_DIR_ . $this->module->name . '/neria.php';
        $neriaSrc2 = $this->readModuleSrc($neriaFile2);
        if ($neriaSrc2 === '') {
            $offenders[] = 'neria.php introuvable (boucle multi-boutique CalendarManager)';
        } else {
            $posCal = strpos($neriaSrc2, "if (class_exists('CalendarManager')) {");
            $blockCal = $posCal !== false ? substr($neriaSrc2, $posCal, 1400) : '';
            if ($posCal === false
                || strpos($blockCal, 'foreach ($shopsCalendar as $idShopCalendar) {') === false
                || strpos($blockCal, 'new CalendarManager($this)') === false) {
                $offenders[] = "neria.php::runBackgroundJobs() n'instancie plus CalendarManager dans une boucle par boutique — les boutiques autres que celle du premier visiteur du jour pourraient de nouveau ne jamais recevoir de campagne calendaire";
            }
        }

        // Round 77 (2026-08-06) : même défaut que CalendarManager (round 76)
        // pour SeasonalCampaignManager — instancié UNE SEULE FOIS dans
        // runBackgroundJobs(), sans boucle multi-boutique. Une boutique à
        // faible trafic pouvait ne JAMAIS être "la première" du jour et ne
        // recevoir aucune campagne saisonnière (Noël, Saint-Valentin...),
        // indéfiniment.
        if ($neriaSrc2 === '') {
            $offenders[] = 'neria.php introuvable (boucle multi-boutique SeasonalCampaignManager)';
        } else {
            $posSeasonal = strpos($neriaSrc2, "if (class_exists('SeasonalCampaignManager')) {");
            $blockSeasonal = $posSeasonal !== false ? substr($neriaSrc2, $posSeasonal, 2000) : '';
            if ($posSeasonal === false
                || strpos($blockSeasonal, 'foreach ($shopsSeasonal as $idShopSeasonal) {') === false
                || strpos($blockSeasonal, 'new SeasonalCampaignManager($this)') === false) {
                $offenders[] = "neria.php::runBackgroundJobs() n'instancie plus SeasonalCampaignManager dans une boucle par boutique — les boutiques à faible trafic pourraient de nouveau ne jamais recevoir de campagne saisonnière";
            }
        }

        // Round 78 (2026-08-06) : même défaut que Calendar (76)/Seasonal (77)
        // pour WebhookManager — instancié UNE SEULE FOIS dans
        // runBackgroundJobs(), sans boucle multi-boutique. Les webhooks en
        // attente d'une boutique différente de celle du contexte courant
        // restaient indéfiniment 'pending', jamais traités.
        if ($neriaSrc2 === '') {
            $offenders[] = 'neria.php introuvable (boucle multi-boutique WebhookManager)';
        } else {
            $posWebhook = strpos($neriaSrc2, "// ── Queue webhook (toutes les 5 min)");
            $blockWebhook = $posWebhook !== false ? substr($neriaSrc2, $posWebhook, 2600) : '';
            if ($posWebhook === false
                || strpos($blockWebhook, 'foreach ($shopsWebhook as $idShopWebhook) {') === false
                || strpos($blockWebhook, 'new WebhookManager($this)') === false) {
                $offenders[] = "neria.php::runBackgroundJobs() n'instancie plus WebhookManager dans une boucle par boutique — les webhooks en attente d'une boutique autre que celle du contexte courant pourraient de nouveau ne jamais être traités";
            }
        }

        // Round 79 (2026-08-06) : 4e occurrence du même défaut que Calendar
        // (76)/Seasonal (77)/Webhook (78) pour DomainReputationManager —
        // instancié UNE SEULE FOIS dans runBackgroundJobs(), sans boucle
        // multi-boutique. Les boutiques autres que celle du contexte
        // courant gardaient un cache de réputation de domaine figé
        // indéfiniment, sans jamais être alertées d'une dégradation réelle.
        if ($neriaSrc2 === '') {
            $offenders[] = 'neria.php introuvable (boucle multi-boutique DomainReputationManager)';
        } else {
            $posDR = strpos($neriaSrc2, "// ── Réputation de domaine (rafraîchissement auto 24h)");
            $blockDR = $posDR !== false ? substr($neriaSrc2, $posDR, 1600) : '';
            if ($posDR === false
                || strpos($blockDR, 'foreach ($shopsDR as $idShopDR) {') === false
                || strpos($blockDR, 'new DomainReputationManager($this)') === false) {
                $offenders[] = "neria.php::runBackgroundJobs() n'instancie plus DomainReputationManager dans une boucle par boutique — les boutiques autres que celle du contexte courant pourraient de nouveau ne jamais voir leur réputation de domaine rafraîchie";
            }
        }

        // Round 80 (2026-08-06) : ChurnScoreManager::recomputeAll() et
        // PropensityScoreManager::scoreEngagement() comptaient les
        // pré-chargements automatiques Apple Mail Privacy Protection
        // (is_mpp=1) comme de vraies ouvertures — contrairement à
        // SegmentManager/StatsManager/MonthlyReportManager, qui filtrent
        // déjà systématiquement is_mpp=0. Un client Apple Mail qui n'ouvre
        // jamais réellement ses emails gardait un score de churn
        // sous-estimé et un score de propension gonflé à tort.
        $churnFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ChurnScoreManager.php';
        $churnSrc = $this->readModuleSrc($churnFile);
        $propFile  = _PS_MODULE_DIR_ . $this->module->name . '/src/PropensityScoreManager.php';
        $propSrc = $this->readModuleSrc($propFile);
        if ($churnSrc === '' || $propSrc === '') {
            $offenders[] = 'ChurnScoreManager.php/PropensityScoreManager.php introuvable (filtre is_mpp)';
        } else {
            if (substr_count($churnSrc, "event_type = 'open' AND is_mpp = 0") < 4) {
                $offenders[] = "ChurnScoreManager::recomputeAll() ne filtre plus is_mpp=0 sur ses comptages d'ouverture — un client Apple Mail qui n'ouvre jamais réellement ses emails pourrait de nouveau voir son risque de désabonnement sous-estimé";
            }
            if (strpos($propSrc, "event_type = \\'open\\' AND is_mpp = 0") === false) {
                $offenders[] = "PropensityScoreManager::scoreEngagement() ne filtre plus is_mpp=0 — un pré-chargement Apple MPP pourrait de nouveau gonfler à tort le score d'engagement";
            }
        }

        // Round 81 (2026-08-07) : CustomerEmailHistoryManager::getEmails()
        // et ::getShopAverageOpenRate() comptaient les pré-chargements
        // automatiques Apple Mail Privacy Protection (is_mpp=1) comme de
        // vraies ouvertures — contrairement à StatsManager/SegmentManager/
        // ChurnScoreManager/PropensityScoreManager/MonthlyReportManager,
        // qui filtrent déjà systématiquement is_mpp=0. Un client Apple Mail
        // qui n'ouvre jamais réellement ses emails apparaissait "Ouvert"
        // dans son historique BO, avec un badge d'engagement et un taux
        // d'ouverture moyen boutique gonflés à tort.
        $cehFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CustomerEmailHistoryManager.php';
        $cehSrc = $this->readModuleSrc($cehFile);
        if ($cehSrc === '') {
            $offenders[] = 'CustomerEmailHistoryManager.php introuvable (filtre is_mpp)';
        } elseif (substr_count($cehSrc, 'o.is_mpp = 0') < 2) {
            $offenders[] = "CustomerEmailHistoryManager::getEmails()/getShopAverageOpenRate() ne filtrent plus is_mpp=0 — un client Apple Mail qui n'ouvre jamais réellement ses emails pourrait de nouveau apparaître 'Ouvert' dans son historique BO et gonfler le taux d'ouverture moyen boutique";
        }

        // Round 82 (2026-08-07) : ClvManager::getEngagementRate() et la
        // requête batch d'engagement de getTopCustomers() comptaient les
        // pré-chargements automatiques Apple Mail Privacy Protection
        // (is_mpp=1) comme de vraies ouvertures — contrairement à
        // StatsManager/SegmentManager/ChurnScoreManager/
        // PropensityScoreManager/CustomerEmailHistoryManager, qui filtrent
        // déjà systématiquement is_mpp=0. Un client Apple Mail qui n'ouvre
        // jamais réellement ses emails voyait son taux d'engagement gonflé
        // à tort, appliquant le multiplicateur CLV "high" (x1.20) au lieu
        // de "low" (x0.85) — CLV surestimé, faux positif dans le Top 20.
        $clvFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ClvManager.php';
        $clvSrc = $this->readModuleSrc($clvFile);
        if ($clvSrc === '') {
            $offenders[] = 'ClvManager.php introuvable (filtre is_mpp)';
        } elseif (substr_count($clvSrc, "'open\\' AND `is_mpp` = 0") < 2) {
            $offenders[] = "ClvManager::getEngagementRate()/getTopCustomers() ne filtrent plus is_mpp=0 sur leurs comptages d'ouverture — un client Apple Mail qui n'ouvre jamais réellement ses emails pourrait de nouveau voir son CLV surestimé (multiplicateur d'engagement 'high' au lieu de 'low')";
        }

        // Round 83 (2026-08-07) : StatsManager::record() attribuait des
        // points de fidélité (LoyaltyManager::awardPoints()) pour un
        // événement 'open' même quand $isMpp valait 1 — contrairement à
        // tous les Managers de LECTURE déjà corrigés (rounds 74-82), ce
        // chemin d'ÉCRITURE créditait un client Apple Mail qui n'ouvre
        // jamais réellement ses emails à chaque pré-chargement du pixel
        // par le proxy Apple.
        $smFile = _PS_MODULE_DIR_ . $this->module->name . '/src/StatsManager.php';
        $smSrc = $this->readModuleSrc($smFile);
        if ($smSrc === '') {
            $offenders[] = 'StatsManager.php introuvable (filtre is_mpp sur points fidélité)';
        } elseif (strpos($smSrc, "!(\$event === 'open' && \$isMpp)") === false) {
            $offenders[] = "StatsManager::record() n'exclut plus \$isMpp avant d'attribuer des points de fidélité pour un 'open' — un client Apple Mail qui n'ouvre jamais réellement ses emails pourrait de nouveau recevoir des points à chaque pré-chargement MPP";
        }

        // Round 84 (2026-08-07) : BounceManager::recordBounce() incrémentait
        // toujours bounce_count de 1 (`bounce_count` + 1), même quand le
        // précédent soft bounce avait expiré (isBounced() le traite comme
        // réhabilité, mais le compteur en base n'était jamais remis à zéro).
        // Un seul nouveau soft bounce après expiration rebloquait aussitôt
        // l'adresse, niant la réhabilitation automatique.
        $bmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BounceManager.php';
        $bmSrc = $this->readModuleSrc($bmFile);
        if ($bmSrc === '') {
            $offenders[] = 'BounceManager.php introuvable (reset bounce_count sur expiration)';
        } elseif (strpos($bmSrc, "TIMESTAMPDIFF(MONTH, `last_bounce_at`, NOW()) >= ' . \$expiryMonths") === false) {
            $offenders[] = "BounceManager::recordBounce() ne remet plus bounce_count à 1 quand le soft bounce précédent a expiré — une adresse réhabilitée automatiquement pourrait de nouveau être rebloquée par un seul nouveau bounce";
        }

        // Round 85 (2026-08-07) : SearchConsoleManager::fetchAndCache() ne
        // comparait le siteUrl Google Search Console au host de la boutique
        // que dans un seul sens (stripos($su, $host)) — contrairement à
        // PostmasterManager, qui compare déjà bidirectionnellement. Une
        // "Domain property" GSC (siteUrl = 'sc-domain:example.com', le type
        // recommandé par Google) est plus courte que le host complet de la
        // boutique ('www.example.com'), donc ce sens échouait toujours :
        // aucune propriété ne matchait jamais, le BO affichait à tort
        // "aucun site Search Console correspondant".
        $scFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php';
        $scSrc = $this->readModuleSrc($scFile);
        if ($scSrc === '') {
            $offenders[] = 'SearchConsoleManager.php introuvable (matching bidirectionnel siteUrl)';
        } elseif (
            // Le round 101 a remplacé la comparaison stripos() (sous-chaîne
            // pure, vulnérable à "shop.com" matchant à tort "myshop.com")
            // par str_ends_with() ancré sur les frontières de labels DNS —
            // toujours bidirectionnelle, mais plus sûre. Ce garde-fou
            // vérifiait encore l'ancienne forme, jamais mise à jour depuis :
            // il aurait échoué indéfiniment même sur le code CORRECT.
            !preg_match('/function\s+matchesShopHost[\s\S]{0,800}?str_ends_with\(\$a,[\s\S]{0,80}?str_ends_with\(\$b,/', $scSrc)
        ) {
            $offenders[] = "SearchConsoleManager::matchesShopHost() ne compare plus bidirectionnellement le siteUrl GSC au host de la boutique — les Domain properties GSC pourraient de nouveau ne jamais matcher, affichant à tort 'aucun site correspondant' dans le BO";
        }

        // Round 86 (2026-08-07) : ChurnScoreManager::recomputeAll() avait un
        // early return (aucune ligne sent/open dans la fenêtre de 90 jours)
        // qui s'exécutait AVANT l'écriture de NERIA_CHURN_LAST_RUN —
        // contrairement à PropensityScoreManager::recalculateAll(), qui
        // écrit son propre repère inconditionnellement. Une boutique sans
        // aucune donnée neria_stat sortait sans jamais tracer l'exécution du
        // cron, laissant checkChurnPropensityFreshness() aveugle
        // indéfiniment pour la partie churn.
        // Round 176 : l'early return lui-même a disparu (voir plus bas,
        // "$rowsPeriods vide dès la requête SQL" — il empêchait aussi la
        // purge des lignes obsolètes). Le marqueur de recherche du early
        // return n'existe donc plus ; le garde-fou vérifie désormais que
        // l'écriture de NERIA_CHURN_LAST_RUN précède bien la normalisation
        // qui l'a remplacé.
        $csFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ChurnScoreManager.php';
        $csSrc = $this->readModuleSrc($csFile);
        if ($csSrc === '') {
            $offenders[] = 'ChurnScoreManager.php introuvable (NERIA_CHURN_LAST_RUN avant early return)';
        } else {
            $posLastRun = strpos($csSrc, "\\Configuration::updateValue('NERIA_CHURN_LAST_RUN'");
            $posNormalize = strpos($csSrc, '$rowsPeriods = is_array($rowsPeriods) ? $rowsPeriods : [];');
            if ($posLastRun === false || $posNormalize === false || $posLastRun > $posNormalize) {
                $offenders[] = "ChurnScoreManager::recomputeAll() n'écrit plus NERIA_CHURN_LAST_RUN avant la normalisation de \$rowsPeriods — une boutique sans donnée neria_stat pourrait de nouveau ne jamais tracer l'exécution du cron, rendant checkChurnPropensityFreshness() aveugle";
            }
        }

        // Round 87 (2026-08-07) : UpsellManager::enrich() résolvait le prix
        // affiché via $this->context->currency (devise du contexte
        // d'exécution courant) et générait le lien produit sans passer
        // $idShop à getProductLink() — contrairement à
        // CollectionManager::processCollection(), qui résout déjà {missing_price}
        // via PS_CURRENCY_DEFAULT scopé par $idShop et passe $idShop à
        // getProductLink(). En cron (contexte resté sur la 1re boutique),
        // un client d'une autre boutique avec une devise différente
        // recevait un bloc upsell dans la mauvaise devise et un lien
        // produit potentiellement cassé.
        $upFile = _PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php';
        $upSrc = $this->readModuleSrc($upFile);
        if ($upSrc === '') {
            $offenders[] = 'UpsellManager.php introuvable (devise/lien scopés par idShop)';
        } else {
            if (strpos($upSrc, "PS_CURRENCY_DEFAULT', null, null, \$idShop") === false) {
                $offenders[] = "UpsellManager::enrich() ne résout plus le prix affiché via la devise de la boutique du client (\$idShop) — un client d'une autre boutique pourrait de nouveau voir un prix dans la mauvaise devise";
            }
            if (strpos($upSrc, 'getProductLink(') !== false && strpos($upSrc, "null, \$idLang, \$idShop") === false) {
                $offenders[] = "UpsellManager::enrich() n'appelle plus getProductLink() avec \$idShop — le lien produit du bloc upsell pourrait de nouveau pointer vers le mauvais magasin en cron multi-boutique";
            }
        }

        // Round 88 (2026-08-07) : ManualSendManager::send() calculait le
        // ref_id de 'first_anniversary' (MIN(id_order)) sans filtre id_shop
        // — contrairement à QueueManager::processSingle(), qui applique
        // déjà ce correctif documenté dans BehavioralCronManager::
        // sendFirstAnniversaries() (« 1ère commande DE CETTE boutique »).
        // Un client partagé entre boutiques avec une commande plus ancienne
        // sur une AUTRE boutique obtenait un ref_id incohérent, cassant la
        // traçabilité de neria_behavioral_sent en multi-shop.
        $msFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $msSrc = $this->readModuleSrc($msFile);
        if ($msSrc === '') {
            $offenders[] = 'ManualSendManager.php introuvable (ref_id first_anniversary scopé par idShop)';
        } else {
            $posQuery = strpos($msSrc, "'SELECT MIN(id_order) FROM `' . _DB_PREFIX_ . 'orders`");
            if ($posQuery === false || strpos(substr($msSrc, $posQuery, 300), 'id_shop') === false) {
                $offenders[] = "ManualSendManager::send() ne filtre plus id_shop sur sa requête MIN(id_order) pour first_anniversary — un client partagé entre boutiques pourrait de nouveau obtenir un ref_id incohérent avec celui du cron";
            }
        }

        // Round 89 (2026-08-07) : BehavioralCronManager::generateBirthdayVoucher()
        // filtrait bien id_shop sur son INSERT IGNORE de réservation, mais
        // pas sur l'UPDATE final (id_cart_rule/voucher_code) ni sur le
        // DELETE de rollback — alors que la clé unique de
        // neria_birthday_voucher est (id_customer, year, id_shop) depuis
        // l'upgrade-1.0.29, précisément pour isoler les réservations par
        // boutique. Un client partagé avec un anniversaire dans deux
        // boutiques voyait l'UPDATE d'une boutique écraser la réservation
        // de l'autre avec un voucher_code inutilisable (CartRule restreint
        // à une autre boutique) — même correctif déjà appliqué à
        // OrderTriggersManager::generateMilestoneVoucher() (round 56).
        $bcFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $bcSrc = $this->readModuleSrc($bcFile);
        if ($bcSrc === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (UPDATE birthday_voucher scopé par idShop)';
        } else {
            $posUpdate = strpos($bcSrc, "SET id_cart_rule = ' . (int) \$cartRule->id . ', voucher_code = ");
            if ($posUpdate === false || strpos(substr($bcSrc, $posUpdate, 250), 'id_shop') === false) {
                $offenders[] = "BehavioralCronManager::generateBirthdayVoucher() ne filtre plus id_shop sur son UPDATE final — un client partagé entre boutiques pourrait de nouveau voir sa réservation d'une boutique écrasée par celle d'une autre";
            }
        }

        // Round 90 (2026-08-07) : ManualSendManager::checkAnniversaryConflict()
        // et ::getAnniversaryGuardStatus() ne filtraient pas id_shop sur
        // neria_behavioral_sent — alors que sa clé UNIQUE est (id_customer,
        // template, ref_id, id_shop) depuis l'upgrade-1.0.29, précisément
        // pour isoler l'historique anniversaire par boutique. Un client
        // partagé entre boutiques se voyait bloquer à tort un envoi sur la
        // Boutique B par l'historique de la Boutique A.
        $ms2File = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $ms2Src = $this->readModuleSrc($ms2File);
        if ($ms2Src === '') {
            $offenders[] = 'ManualSendManager.php introuvable (garde-fou anniversaire scopé par idShop)';
        } elseif (substr_count($ms2Src, "AND id_shop = ' . \$idShopConflict") < 2) {
            $offenders[] = "ManualSendManager::checkAnniversaryConflict()/getAnniversaryGuardStatus() ne filtrent plus tous les deux id_shop sur neria_behavioral_sent — un client partagé entre boutiques pourrait de nouveau voir un envoi bloqué à tort par l'historique d'une AUTRE boutique";
        }

        // Round 91 (2026-08-07) : UpsellManager::getStats()/getLog() (les 2
        // seules méthodes qui alimentent l'onglet BO Upsell) n'étaient pas
        // scopées par id_shop — alors que ps_neria_upsell a une colonne
        // id_shop correctement filtrée partout ailleurs dans ce fichier
        // (recordSuggestion, checkConversions, findUpsellForCustomer). Les
        // KPIs et le journal d'une boutique mélangeaient silencieusement
        // les données de TOUTES les boutiques de l'installation.
        $up2File = _PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php';
        $up2Src = $this->readModuleSrc($up2File);
        if ($up2Src === '') {
            $offenders[] = 'UpsellManager.php introuvable (getStats/getLog scopés par idShop)';
        } else {
            if (strpos($up2Src, "WHERE sent_at >= '{\$dateFrom}' AND id_shop = \" . (int) \$idShop") === false) {
                $offenders[] = "UpsellManager::getStats() ne filtre plus id_shop — les KPIs BO Upsell pourraient de nouveau mélanger les données de toutes les boutiques";
            }
            if (strpos($up2Src, 'WHERE u.id_shop = {$idShop}') === false) {
                $offenders[] = "UpsellManager::getLog() ne filtre plus id_shop — le journal BO Upsell pourrait de nouveau afficher les suggestions de toutes les boutiques";
            }
        }

        // Round 92 (2026-08-07) : checkLoyaltyIntegrity() (contrôle #45)
        // détectait un solde de points négatif via GROUP BY id_customer
        // seul, sans id_shop — contrairement à LoyaltyManager::
        // getCustomerStats()/getGlobalStats()/getTopCustomers(), qui
        // respectent tous NERIA_LOYALTY_CROSS_SHOP_ENABLED. En mode cumul
        // séparé, un solde négatif sur une boutique pouvait être masqué par
        // un solde positif sur une autre (somme globale faussement positive).
        $hcmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/HealthCheckManager.php';
        $hcmSrc = $this->readModuleSrc($hcmFile);
        if ($hcmSrc === '') {
            $offenders[] = 'HealthCheckManager.php introuvable (checkLoyaltyIntegrity scopé par idShop)';
        } elseif (strpos($hcmSrc, 'id_customer`, `id_shop`') === false) {
            $offenders[] = "checkLoyaltyIntegrity() ne groupe plus par (id_customer, id_shop) en mode cumul séparé — un solde négatif sur une boutique pourrait de nouveau être masqué par un solde positif sur une autre";
        }

        // Round 93 (2026-08-07) : WaitlistManager::notifyProduct() (et son
        // garde-fou checkWaitlistBacklog() dans ce même fichier) supposaient
        // que StockAvailable::getQuantityAvailableByProduct($id, null, ...)
        // somme le stock sur toutes les déclinaisons — FAUX dans ce cœur
        // PrestaShop, qui convertit null en 0. Un produit à déclinaisons de
        // retour en stock sur UNE combinaison précise ne déclenchait jamais
        // de notification. Un SUM(quantity) SQL direct (sans filtre
        // id_product_attribute) remplace désormais cet appel API.
        $wlFile = _PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php';
        $wlSrc = $this->readModuleSrc($wlFile);
        if ($wlSrc === '') {
            $offenders[] = 'WaitlistManager.php introuvable (SUM stock déclinaisons)';
        } elseif (strpos($wlSrc, 'SELECT COALESCE(SUM(quantity), 0) FROM `" . _DB_PREFIX_ . "stock_available`') === false) {
            $offenders[] = "WaitlistManager::notifyProduct() ne somme plus le stock via SQL direct sur toutes les déclinaisons — un produit à déclinaisons de retour en stock pourrait de nouveau ne jamais déclencher de notification";
        }
        if (strpos($hcmSrc, "AND id_shop = \" . (int) \$row['id_shop']") === false) {
            $offenders[] = "checkWaitlistBacklog() ne calcule plus le stock par SUM SQL direct sur toutes les déclinaisons — le garde-fou d'auto-réparation pourrait de nouveau rester bloqué sur 'OK' pour tout produit à déclinaisons";
        }

        // Round 94 (2026-08-07) : CertificateManager::generateSerial()
        // basait le prochain numéro de série sur MAX(id_certificate), qui
        // RÉTROGRADE quand la ligne au plus grand id est supprimée
        // (delete(), action BO "cert_delete") — contrairement au compteur
        // AUTO_INCREMENT réel d'InnoDB, jamais recyclé après un DELETE.
        // Supprimer le certificat le plus récent faisait régénérer le même
        // numéro de série pour la prochaine émission, vidant de son sens la
        // vérification d'authenticité (deux certificats différents avec le
        // même serial_number).
        $cmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php';
        $cmSrc = $this->readModuleSrc($cmFile);
        if ($cmSrc === '') {
            $offenders[] = 'CertificateManager.php introuvable (AUTO_INCREMENT anti-réémission serial)';
        } elseif (strpos($cmSrc, 'SELECT `AUTO_INCREMENT` FROM `information_schema`.`TABLES`') === false) {
            $offenders[] = "CertificateManager::generateSerial() n'interroge plus le compteur AUTO_INCREMENT réel — un certificat supprimé pourrait de nouveau faire réémettre le même numéro de série à un autre client";
        }

        // Round 95 (2026-08-07) : GdprAuditManager::purgeCustomerData()
        // purgeait neria_certificate uniquement via un JOIN sur ps_orders —
        // si la commande liée avait été supprimée du BO PrestaShop, le JOIN
        // ne matchait plus rien et le certificat (nom client en clair)
        // survivait indéfiniment à une demande d'effacement RGPD, sans
        // erreur ni avertissement. Colonne id_customer ajoutée
        // (upgrade-1.0.39, renseignée à chaque émission par
        // CertificateManager::issue()) : la purge matche désormais
        // directement, indépendamment de la survie de la commande.
        $gdprFile = _PS_MODULE_DIR_ . $this->module->name . '/src/GdprAuditManager.php';
        $gdprSrc = $this->readModuleSrc($gdprFile);
        if ($gdprSrc === '') {
            $offenders[] = 'GdprAuditManager.php introuvable (purge certificat par id_customer direct)';
        } elseif (strpos($gdprSrc, 'WHERE nc.id_customer = ') === false) {
            $offenders[] = "GdprAuditManager::purgeCustomerData() ne purge plus neria_certificate par id_customer direct — un certificat dont la commande a été supprimée pourrait de nouveau survivre à une demande d'effacement RGPD";
        }
        $cm2File = _PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php';
        $cm2Src = $this->readModuleSrc($cm2File);
        if ($cm2Src !== '' && strpos($cm2Src, "'id_customer'     => (int) \$order->id_customer,") === false) {
            $offenders[] = "CertificateManager::issue() n'enregistre plus id_customer à l'émission — la purge RGPD par id_customer direct ne pourrait plus fonctionner pour les nouveaux certificats";
        }

        // Round 96 (2026-08-07) : CssInliner::process() triait les règles
        // CSS par spécificité (usort, stable depuis PHP 8) sans inverser
        // l'ordre au préalable — à spécificité ÉGALE, le tri stable
        // conservait l'ordre d'apparition dans le CSS, donc la PREMIÈRE
        // règle déclarée gagnait systématiquement (merge() ignore toute
        // propriété déjà inlinée), à l'inverse de la cascade CSS standard
        // (dernière règle déclarée gagne à spécificité égale). Rendu
        // divergent silencieux entre Apple Mail (garde <style>) et
        // Gmail/Outlook (style inline uniquement).
        $ciFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CssInliner.php';
        $ciSrc = $this->readModuleSrc($ciFile);
        if ($ciSrc === '') {
            $offenders[] = 'CssInliner.php introuvable (ordre cascade CSS à spécificité égale)';
        } elseif (strpos($ciSrc, '$rules = array_reverse($rules);') === false) {
            $offenders[] = "CssInliner::process() n'inverse plus l'ordre des règles avant le tri par spécificité — à spécificité égale, la PREMIÈRE règle CSS déclarée pourrait de nouveau gagner au lieu de la dernière, produisant un rendu divergent entre clients mail";
        }

        // Round 97 (2026-08-07) : OrderTriggersManager fournissait
        // {id_order} au Mode Silence pour order_partial_shipped/order_on_hold/
        // refund_processed/return_received (round 63) mais jamais pour
        // milestone_order — un client atteignant deux paliers différents
        // dans la même fenêtre de cooldown voyait le second email
        // milestone_order bloqué à tort comme doublon du premier, alors
        // qu'un vrai bon de réduction distinct avait déjà été attribué pour
        // ce second palier.
        $otmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php';
        $otmSrc = $this->readModuleSrc($otmFile);
        if ($otmSrc === '') {
            $offenders[] = 'OrderTriggersManager.php introuvable ({id_order} milestone_order)';
        } elseif (substr_count($otmSrc, "'{id_order}'") < 4) {
            $offenders[] = "OrderTriggersManager ne fournit plus {id_order} pour tous ses templates liés à une commande (dont milestone_order) — le Mode Silence pourrait de nouveau bloquer à tort un email légitime";
        }

        // Round 98 (2026-08-07) : CalendarManager::getEventDisplayInfo()
        // ne cherchait le marqueur "dernier envoi" que sur [$year, $year-1]
        // — alors que processEvent() peut poser ce marqueur sur $year+1
        // (occasions à cheval sur le Nouvel An, ex. new_year J-7 envoyé le
        // 25 décembre avec eventYear = année+1). Un envoi qui venait de
        // partir restait affiché "jamais envoyé" dans le BO jusqu'au 1er
        // janvier suivant.
        $calFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CalendarManager.php';
        $calSrc = $this->readModuleSrc($calFile);
        if ($calSrc === '') {
            $offenders[] = 'CalendarManager.php introuvable (dernier envoi cherché sur year+1)';
        } elseif (strpos($calSrc, 'foreach ([$year + 1, $year, $year - 1] as $y) {') === false) {
            $offenders[] = "CalendarManager::getEventDisplayInfo() ne cherche plus le marqueur 'dernier envoi' sur \$year+1 — un envoi de fin d'année (new_year J-7 par exemple) pourrait de nouveau apparaître 'jamais envoyé' dans le BO jusqu'au 1er janvier suivant";
        }

        // Round 99 (2026-08-07) : NeriaTools::displayPrice() réaffectait
        // $context->language avant d'appeler \Tools::displayPrice() natif,
        // mais celui-ci délègue à Tools::getContextLocale() qui retourne
        // $context->getCurrentLocale() — calculé UNE SEULE FOIS par
        // Controller::init() et jamais recalculé quand $context->language
        // change en cours de script. Le switch de langue était un no-op
        // silencieux : un rapport/email envoyé à des destinataires de
        // langues différentes affichait le MÊME formatage de prix pour
        // tous (celui du cron/BO), pas celui de chaque destinataire.
        $ntFile = _PS_MODULE_DIR_ . $this->module->name . '/src/NeriaTools.php';
        $ntSrc = $this->readModuleSrc($ntFile);
        if ($ntSrc === '') {
            $offenders[] = 'NeriaTools.php introuvable (displayPrice respecte idLang explicite)';
        } elseif (strpos($ntSrc, 'if ($targetLang !== null) {') === false) {
            $offenders[] = "NeriaTools::displayPrice() ne bascule plus sur formatPriceWithIntl() quand une langue explicite diffère du contexte — le formatage de prix pourrait de nouveau suivre silencieusement la langue du cron/BO au lieu de celle du destinataire";
        }

        // Round 100 (2026-08-07) : EmailRenderer::voucherRateFromCode() lisait
        // Context::getContext()->language / Tools::getContextLocale() au
        // lieu de la langue du destinataire ($lang, résolue par
        // resolveEmailLang()) — même piège de locale figée que
        // NeriaTools::displayPrice() (round 99), réintroduit ici en
        // contournant complètement ce correctif (aucune langue transmise
        // jusqu'à cette méthode). {discount} du template newsletter_voucher
        // pouvait afficher "12,5 %" au lieu de "12.5 %" pour un destinataire
        // anglophone.
        $erFile = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $erSrc = $this->readModuleSrc($erFile);
        if ($erSrc === '') {
            $offenders[] = 'EmailRenderer.php introuvable (voucherRateFromCode scopé par lang)';
        } elseif (strpos($erSrc, 'private function voucherRateFromCode(string $code, string $lang, int $idShop): string') === false) {
            // Round 176 : signature élargie d'un 3e paramètre $idShop (scoping
            // multi-boutique du cart_rule, voir le garde-fou round 176
            // dédié ci-dessous) — la chaîne recherchée reflète cette
            // signature complète, $lang reste bien présent.
            $offenders[] = "EmailRenderer::voucherRateFromCode() ne prend plus \$lang en paramètre — {discount} du template newsletter_voucher pourrait de nouveau suivre silencieusement la langue du contexte d'exécution au lieu de celle du destinataire";
        }

        // Round 101 (2026-08-07) : PostmasterManager (ligne de filtre
        // domaine) et SearchConsoleManager::matchesShopHost() comparaient
        // les domaines via stripos() en sous-chaîne pure — "shop.com" est
        // une sous-chaîne de "myshop.com" (stripos ne retourne jamais false
        // dans ce cas), acceptant à tort un domaine totalement non
        // apparenté. Un marchand avec plusieurs domaines sous le même
        // compte Google voyait la réputation d'envoi ou les statistiques
        // SEO d'un AUTRE site affichées comme celles de sa boutique.
        $pmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php';
        $pmSrc = $this->readModuleSrc($pmFile);
        if ($pmSrc === '') {
            $offenders[] = 'PostmasterManager.php introuvable (comparaison domaine par frontière DNS)';
        } elseif (strpos($pmSrc, 'private static function domainsMatch(string $a, string $b): bool') === false) {
            $offenders[] = "PostmasterManager::domainsMatch() a disparu — le filtre domaine pourrait de nouveau accepter à tort un domaine non apparenté (sous-chaîne coïncidente)";
        }
        $sc2File = _PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php';
        $sc2Src = $this->readModuleSrc($sc2File);
        if ($sc2Src !== '' && strpos($sc2Src, "str_ends_with(\$a, '.' . \$b) || str_ends_with(\$b, '.' . \$a)") === false) {
            $offenders[] = "SearchConsoleManager::matchesShopHost() ne compare plus par frontière DNS — pourrait de nouveau accepter à tort un domaine non apparenté";
        }

        // Round 103 (2026-08-07) : WaitlistManager::notifyProduct() générait
        // {product_url}/{product_image} via Context::getContext()->link SANS
        // $idShop — contrairement à Mail::Send(), déjà correctement scopé
        // plus bas dans la même méthode. hookActionUpdateQuantity() appelle
        // notifyProduct() en boucle sur toutes les boutiques d'un groupe à
        // stock partagé, mais Context::getContext()->shop reste fixé à la
        // boutique du BO admin pendant toute la boucle : un client d'une
        // autre boutique recevait un lien/image produit pointant vers le
        // mauvais domaine.
        $wl2File = _PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php';
        $wl2Src = $this->readModuleSrc($wl2File);
        if ($wl2Src === '') {
            $offenders[] = 'WaitlistManager.php introuvable (product_url/product_image scopés par idShop)';
        } else {
            if (strpos($wl2Src, '$context->shop = new \Shop($idShop);') === false) {
                $offenders[] = "WaitlistManager::notifyProduct() ne bascule plus temporairement Context::getContext()->shop sur \$idShop — {product_url}/{product_image} pourraient de nouveau pointer vers le mauvais domaine";
            }
            if (strpos($wl2Src, 'getProductLink($product, null, null, null, $idLang, $idShop)') === false) {
                $offenders[] = "WaitlistManager::notifyProduct() ne passe plus \$idShop à getProductLink()";
            }
        }

        // Round 104 (2026-08-07) : UpsellManager::getLog() filtre bien
        // `WHERE u.id_shop = {$idShop}` (round 91) mais construisait
        // product_url via getProductLink($idProduct, null, null, null,
        // $idLang) SANS $idShop, contrairement à enrich() (déjà correctement
        // scopé, round 87). getProductLink() retombe alors sur Context::
        // getContext()->shop (contexte d'EXÉCUTION courant) au lieu du
        // paramètre $idShop explicitement reçu par getLog() : un admin
        // consultant (ou un cron traitant) le journal Upsell d'une boutique
        // B alors que le contexte reste sur la boutique A voyait des liens
        // produit pointant vers le mauvais domaine/catalogue.
        $up3File = _PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php';
        $up3Src = $this->readModuleSrc($up3File);
        if ($up3Src === '') {
            $offenders[] = 'UpsellManager.php introuvable (getLog() product_url scopé par idShop)';
        } else {
            $posGetLog = strpos($up3Src, 'function getLog(');
            $getLogBody = $posGetLog !== false ? substr($up3Src, $posGetLog, 3500) : '';
            if ($posGetLog === false || strpos($getLogBody, '$idProduct, null, null, null, $idLang, $idShop') === false) {
                $offenders[] = "UpsellManager::getLog() ne passe plus \$idShop à getProductLink() — le lien produit du journal BO Upsell pourrait de nouveau pointer vers la mauvaise boutique";
            }
        }

        // Round 105 (2026-08-07) : CollectionManager::checkAndSend() scope
        // déjà soigneusement {missing_product_url}/{missing_image_url}
        // (getProductLink avec $idShop) et {missing_price}
        // (Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop))
        // sur la boutique du client — mais {shop_name} appelait
        // Configuration::get('PS_SHOP_NAME') SANS $idShop, retombant sur la
        // valeur par défaut/du contexte d'exécution du cron. Sur une install
        // multi-boutiques avec des noms distincts, un client de la boutique 2
        // recevait "chez <nom boutique 1>" au lieu du vrai nom de sa
        // boutique.
        $colFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CollectionManager.php';
        $colSrc = $this->readModuleSrc($colFile);
        if ($colSrc === '') {
            $offenders[] = 'CollectionManager.php introuvable (shop_name scopé par idShop)';
        } elseif (strpos($colSrc, "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)") === false) {
            $offenders[] = "CollectionManager::checkAndSend() ne passe plus \$idShop à Configuration::get('PS_SHOP_NAME', ...) — {shop_name} pourrait de nouveau afficher le nom de la mauvaise boutique";
        }

        // Round 106 (2026-08-07) : balayage groupé de tous les {shop_name}
        // légitimement scopables par idShop identifiés lors de la chasse
        // dédiée au pattern Configuration::get('PS_SHOP_NAME', ...) sans son
        // 4e paramètre $idShop (round 102/103/104/105 avaient déjà traité ce
        // même piège pour d'autres placeholders/managers). Chaque entrée
        // vérifie qu'une signature précise (le fragment de code corrigé)
        // est toujours présente dans le fichier source — pas de simple
        // strpos('PS_SHOP_NAME') qui donnerait de faux négatifs sur les
        // usages légitimes (contexte courant correct) coexistant dans les
        // mêmes fichiers.
        $round106Checks = [
            'SegmentManager.php'        => "\\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop)",
            'LoyaltyManager.php'        => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
            'WaitlistManager.php'       => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
            'OrderTriggersManager.php'  => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
            'QueueManager.php'          => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
            'ManualSendManager.php'     => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
            'CertificateManager.php'    => "\\Configuration::get('PS_SHOP_NAME', null, null, (int) \$order->id_shop)",
            'LookCompletionManager.php' => "\\Configuration::get('PS_SHOP_NAME', null, null, \$idShop)",
        ];
        foreach ($round106Checks as $fileName => $needle) {
            $path = _PS_MODULE_DIR_ . $this->module->name . '/src/' . $fileName;
            $src = $this->readModuleSrc($path);
            if ($src === '') {
                $offenders[] = $fileName . " introuvable (garde-fou round 106 : {shop_name} scopé par idShop)";
            } elseif (strpos($src, $needle) === false) {
                $offenders[] = $fileName . " : {shop_name} ne semble plus scopé par idShop (régression possible du correctif round 106)";
            }
        }

        // Round 107 (2026-08-08) : CertificateManager::issue() enregistre
        // volontairement le certificat sous l'id_shop DE LA COMMANDE (round
        // 106, cf. commentaire "invisible dans getByOrder()/getAll() de la
        // vraie boutique"), mais getByOrder() et redownload() — tous deux
        // appelés depuis le bloc affiché sur la fiche commande PS
        // (hookDisplayAdminOrderMainBottom / action cert_download) —
        // filtraient encore par $this->idShop, résolu dans le constructeur
        // à partir du contexte BO couramment sélectionné par l'employé
        // (sélecteur d'en-tête), qui n'a aucune raison de correspondre à la
        // boutique réelle de la commande consultée en multi-boutique. Un
        // certificat pourtant bien enregistré redevenait invisible sur sa
        // propre fiche commande (risque de réémission en double) et le
        // bouton de retéléchargement échouait avec "certificat introuvable".
        // id_order/id_certificate sont déjà des clés globalement uniques en
        // multi-boutique PrestaShop : aucun filtre id_shop n'est nécessaire.
        $certFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php';
        $certSrc = $this->readModuleSrc($certFile);
        if ($certSrc === '') {
            $offenders[] = 'CertificateManager.php introuvable (garde-fou round 107 : getByOrder()/redownload() non scopés par le contexte BO)';
        } else {
            // Recherche le fragment SQL réel du filtre (pas une simple
            // occurrence de "$this->idShop", qui apparaît aussi dans les
            // commentaires explicatifs du correctif juste au-dessus de
            // chaque méthode et donnerait un faux positif systématique).
            $sqlFilterNeedle = "id_shop` = ' . \$this->idShop";

            $posByOrder = strpos($certSrc, 'function getByOrder(int $idOrder): array');
            $byOrderBody = $posByOrder !== false ? substr($certSrc, $posByOrder, 900) : '';
            $endByOrder  = strpos($byOrderBody, "\n    }");
            $byOrderBody = $endByOrder !== false ? substr($byOrderBody, 0, $endByOrder) : $byOrderBody;
            if ($posByOrder === false || strpos($byOrderBody, $sqlFilterNeedle) !== false) {
                $offenders[] = "CertificateManager::getByOrder() filtre de nouveau par \$this->idShop (contexte BO courant) — le bloc certificat de la fiche commande redeviendrait invisible en multi-boutique (régression round 107)";
            }

            $posRedl = strpos($certSrc, 'function redownload(int $idCertificate): array');
            $redlBody = $posRedl !== false ? substr($certSrc, $posRedl, 900) : '';
            $endRedl  = strpos($redlBody, "\n    }");
            $redlBody = $endRedl !== false ? substr($redlBody, 0, $endRedl) : $redlBody;
            if ($posRedl === false || strpos($redlBody, $sqlFilterNeedle) !== false) {
                $offenders[] = "CertificateManager::redownload() filtre de nouveau par \$this->idShop (contexte BO courant) — le bouton de retéléchargement échouerait de nouveau sur un certificat pourtant existant en multi-boutique (régression round 107)";
            }
        }

        // Round 108 (2026-08-08) : UpsellManager::getLog() — le round 104
        // avait corrigé product_url (passage de $idShop en 6e argument à
        // getProductLink()), mais thumb_url, généré juste au-dessus dans la
        // même boucle, appelait getImageLink() sans jamais basculer
        // Context::getContext()->shop — passé inaperçu au round 104 car
        // getImageLink() n'a structurellement aucun paramètre $idShop à
        // simplement rajouter (contrairement à getProductLink()), résolvant
        // toujours le domaine/thème via le contexte global courant. Un
        // admin multi-boutique consultant le journal d'une autre boutique
        // que celle active voyait thumb_url pointer vers le domaine/thème
        // de la mauvaise boutique alors que product_url, lui, était correct.
        $up4File = _PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php';
        $up4Src = $this->readModuleSrc($up4File);
        if ($up4Src === '') {
            $offenders[] = 'UpsellManager.php introuvable (garde-fou round 108 : getLog() thumb_url scopé par idShop)';
        } else {
            $posGetLog4 = strpos($up4Src, 'public function getLog(');
            $getLogBody4 = $posGetLog4 !== false ? substr($up4Src, $posGetLog4, 3200) : '';
            if ($posGetLog4 === false || strpos($getLogBody4, '$context->shop = new \Shop($idShop);') === false) {
                $offenders[] = "UpsellManager::getLog() ne bascule plus Context::getContext()->shop sur \$idShop avant de générer thumb_url — régression du bug corrigé le 08/08/2026 (round 108)";
            }
        }

        // Round 109 (2026-08-08) : WaitlistManager::notifyProduct() —
        // {product_price} utilisait Context::getContext()->currency->id,
        // jamais basculé, contrairement à {product_url}/{product_image}
        // (round 103) et {shop_name} (round 106) dans ce MÊME bloc de
        // variables. Un client d'une boutique à devise différente de celle
        // du contexte d'exécution courant (BO admin qui a déclenché la mise
        // à jour de stock) recevait un prix affiché dans la mauvaise devise.
        $wl3File = _PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php';
        $wl3Src = $this->readModuleSrc($wl3File);
        if ($wl3Src === '') {
            $offenders[] = 'WaitlistManager.php introuvable (garde-fou round 109 : product_price scopé par idShop)';
        } elseif (strpos($wl3Src, "\\Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$idShop)") === false) {
            $offenders[] = "WaitlistManager::notifyProduct() ne résout plus {product_price} via PS_CURRENCY_DEFAULT scopé par \$idShop — régression du bug corrigé le 08/08/2026 (round 109)";
        }

        // Round 110 (2026-08-08) : PreferencesManager::getPreferencesUrl()
        // n'avait pas de paramètre $idShop et résolvait le lien via
        // Context::getContext()->link->getBaseLink() — le contexte du
        // process qui ENVOIE l'email (cron/admin BO), pas la boutique du
        // CLIENT destinataire. EmailRenderer::resolveCustomerId() calculait
        // pourtant déjà l'id_shop correct sans pouvoir le transmettre.
        // Sur une installation multi-boutique, le lien "Gérer mes
        // préférences" d'un client de la boutique B pointait vers le
        // domaine de la boutique A.
        $pm5File = _PS_MODULE_DIR_ . $this->module->name . '/src/PreferencesManager.php';
        $pm5Src = $this->readModuleSrc($pm5File);
        $er5File = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $er5Src = $this->readModuleSrc($er5File);
        if ($pm5Src === '' || $er5Src === '') {
            $offenders[] = 'PreferencesManager.php/EmailRenderer.php introuvable (garde-fou round 110 : preferences_url scopé par idShop)';
        } else {
            if (strpos($pm5Src, "function getPreferencesUrl(string \$email, int \$idCustomer, string \$lang = 'fr', int \$idShop = 0)") === false
                || strpos($pm5Src, 'getBaseLink($idShop ?: null)') === false
            ) {
                $offenders[] = "PreferencesManager::getPreferencesUrl() ne résout plus le lien via \$idShop — régression du bug corrigé le 08/08/2026 (round 110)";
            }
            if (substr_count($er5Src, '$this->resolveShopId($params)') < 2) {
                $offenders[] = "EmailRenderer ne transmet plus resolveShopId(\$params) à getPreferencesUrl() pour au moins 2 appels (rendu normal + secours) — régression du bug corrigé le 08/08/2026 (round 110)";
            }
        }

        // Round 111 (2026-08-08) : MonthlyReportManager::isDue()/markSent()
        // — Configuration::get()/updateValue() sans $idShop explicite
        // résolvent la boutique via Shop::$context_id_shop, une variable
        // statique QUE SEUL Shop::setContext() modifie — jamais une simple
        // réaffectation de Context::getContext()->shop (celle que
        // checkAndSend() fait avant chaque itération de sa boucle
        // multi-boutique). Le nom de clé était déjà suffixé par boutique,
        // mais la ligne était en réalité lue/écrite sous l'id_shop RÉEL du
        // visiteur ayant déclenché le hook — cassant la déduplication entre
        // boutiques (rapport mensuel envoyé en double à certains marchands).
        $mr1File = _PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php';
        $mr1Src = $this->readModuleSrc($mr1File);
        if ($mr1Src === '') {
            $offenders[] = 'MonthlyReportManager.php introuvable (garde-fou round 111 : isDue()/markSent() scopés par idShop)';
        } else {
            if (strpos($mr1Src, "\$last   = (string) \\Configuration::get(\$key, null, null, \$idShop);") === false) {
                $offenders[] = "MonthlyReportManager::isDue() ne transmet plus \$idShop à Configuration::get() — régression du bug corrigé le 08/08/2026 (round 111)";
            }
            $posMark1 = strpos($mr1Src, 'private function markSent(');
            $markBlock1 = $posMark1 !== false ? substr($mr1Src, $posMark1, 500) : '';
            if ($posMark1 === false || preg_match('/\\\\Configuration::updateValue\(\s*\$key,\s*sprintf\(\'%04d-%02d\',\s*\$year,\s*\$month\),\s*false,\s*null,\s*\$idShop\s*\);/', $markBlock1) !== 1) {
                $offenders[] = "MonthlyReportManager::markSent() ne transmet plus \$idShop à Configuration::updateValue() — régression du bug corrigé le 08/08/2026 (round 111)";
            }
        }

        // Round 112 (2026-08-08) : BehavioralCronManager::run() — même piège
        // que round 111 (MonthlyReportManager), appliqué cette fois à
        // Configuration::get('NERIA_GDPR_AUTO_PURGE_ENABLED'). La boucle par
        // boutique réassigne Context::getContext()->shop = new \Shop($idShop),
        // ce qui met bien à jour $context->shop->id (donc GdprAuditManager
        // lui-même, correctement scopé), mais PAS Shop::$context_id_shop,
        // dont dépend Configuration::get() sans $idShop explicite. Sur une
        // install multi-boutiques à réglages de purge RGPD divergents, le
        // réglage de la boutique ambiante réelle (première visitée)
        // s'appliquait à tort à toutes les boutiques de la boucle.
        $bc1File = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $bc1Src = $this->readModuleSrc($bc1File);
        if ($bc1Src === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (garde-fou round 112 : purge RGPD auto scopée par idShop)';
        } elseif (strpos($bc1Src, "\\Configuration::get('NERIA_GDPR_AUTO_PURGE_ENABLED', null, null, \$idShop)") === false) {
            $offenders[] = "BehavioralCronManager::run() ne transmet plus \$idShop à Configuration::get('NERIA_GDPR_AUTO_PURGE_ENABLED', ...) — régression du bug corrigé le 08/08/2026 (round 112)";
        }

        // Round 113 (2026-08-08) : SeasonalCampaignManager::runDueCampaigns()
        // — même piège que rounds 111/112, appliqué cette fois à
        // Configuration::get('PS_SHOP_NAME') pour {shop_name}. neria.php
        // boucle sur les boutiques actives et réassigne
        // Context::getContext()->shop = new \Shop($idShopSeasonal) avant
        // d'instancier ce manager — $this->idShop (capturé au constructeur)
        // est bien à jour, mais Configuration::get() sans $idShop explicite
        // dépend de Shop::$context_id_shop, jamais modifiée par cette
        // réaffectation. Un client d'une boutique B recevait un email de
        // campagne saisonnière affichant le nom de la boutique ambiante
        // réelle, pas le sien.
        $sc1File = _PS_MODULE_DIR_ . $this->module->name . '/src/SeasonalCampaignManager.php';
        $sc1Src = $this->readModuleSrc($sc1File);
        if ($sc1Src === '') {
            $offenders[] = 'SeasonalCampaignManager.php introuvable (garde-fou round 113 : shop_name scopé par idShop)';
        } elseif (strpos($sc1Src, "\\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop)") === false) {
            $offenders[] = "SeasonalCampaignManager::runDueCampaigns() ne résout plus {shop_name} via \$this->idShop — régression du bug corrigé le 08/08/2026 (round 113)";
        }

        // Round 114 (2026-08-08) : CalendarManager::sendCalendarEmail() —
        // même piège que rounds 111/112/113, appliqué à Configuration::get
        // ('PS_SHOP_NAME'/'PS_SHOP_EMAIL'). neria.php boucle sur les
        // boutiques actives et réassigne Context::getContext()->shop = new
        // \Shop($idShopCalendar) avant d'instancier ce manager — $this->idShop
        // (propriété d'objet) est bien à jour, mais Configuration::get() sans
        // idShop explicite dépend de Shop::$context_id_shop, jamais modifiée
        // par cette réaffectation. Un client d'une boutique B recevait un
        // email calendaire affichant le nom ET expédié depuis l'adresse de
        // la boutique ambiante réelle, pas la sienne.
        $cal1File = _PS_MODULE_DIR_ . $this->module->name . '/src/CalendarManager.php';
        $cal1Src = $this->readModuleSrc($cal1File);
        if ($cal1Src === '') {
            $offenders[] = 'CalendarManager.php introuvable (garde-fou round 114 : shop_name/shop_email scopés par idShop)';
        } else {
            if (strpos($cal1Src, "\\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop)") === false) {
                $offenders[] = "CalendarManager::sendCalendarEmail() ne résout plus {shop_name} via \$this->idShop — régression du bug corrigé le 08/08/2026 (round 114)";
            }
            if (strpos($cal1Src, "\\Configuration::get('PS_SHOP_EMAIL', null, null, \$this->idShop)") === false) {
                $offenders[] = "CalendarManager::sendCalendarEmail() ne résout plus l'adresse d'expédition via \$this->idShop — régression du bug corrigé le 08/08/2026 (round 114)";
            }
        }

        // Round 115 (2026-08-08) : WatchdogManager::sendImmediateAlert()/
        // sendDailyDigest()/getAlertEmail() — même piège que rounds 111-114,
        // appliqué cette fois aux emails d'alerte/digest Watchdog eux-mêmes.
        // WatchdogManager est instancié à la volée par des managers tournant
        // dans une boucle multi-boutique (WebhookManager, CalendarManager…) —
        // $this->idShop (propriété d'objet) est bien à jour, mais
        // Configuration::get() sans idShop explicite dépend de
        // Shop::$context_id_shop, jamais modifiée par la réaffectation de
        // Context::getContext()->shop. Un marchand multi-boutiques recevait
        // une alerte Watchdog affichant le nom/from de la boutique ambiante
        // réelle plutôt que celle réellement en défaut.
        $wd1File = _PS_MODULE_DIR_ . $this->module->name . '/src/WatchdogManager.php';
        $wd1Src = $this->readModuleSrc($wd1File);
        if ($wd1Src === '') {
            $offenders[] = 'WatchdogManager.php introuvable (garde-fou round 115 : alertes scopées par idShop)';
        } else {
            $wd1CountName  = substr_count($wd1Src, "\\Configuration::get('PS_SHOP_NAME', null, null, \$this->idShop)");
            $wd1CountEmail = substr_count($wd1Src, "\\Configuration::get('PS_SHOP_EMAIL', null, null, \$this->idShop)");
            if ($wd1CountName < 2 || $wd1CountEmail < 3) {
                $offenders[] = "WatchdogManager ne résout plus {shop_name}/PS_SHOP_EMAIL via \$this->idShop à tous les emplacements attendus (name={$wd1CountName}/2, email={$wd1CountEmail}/3) — régression du bug corrigé le 08/08/2026 (round 115)";
            }
        }

        // Round 116 (2026-08-08) : WebhookManager::trigger()/processQueue()/
        // sendTest() — même piège que rounds 111-115, appliqué cette fois à
        // NERIA_WEBHOOK_URL/SECRET/EVENTS. neria.php boucle sur les
        // boutiques actives et réassigne Context::getContext()->shop = new
        // \Shop($idShopWebhook) avant d'instancier ce manager — $this->idShop
        // (propriété d'objet) est bien à jour, mais Configuration::get() sans
        // idShop explicite dépend de Shop::$context_id_shop, jamais modifiée
        // par cette réaffectation. Sur une install où chaque boutique a sa
        // propre URL/secret webhook, les événements d'une boutique B étaient
        // livrés à l'URL et signés avec le secret de la boutique ambiante
        // réelle — fuite cross-shop vers le mauvais système tiers.
        $wh1File = _PS_MODULE_DIR_ . $this->module->name . '/src/WebhookManager.php';
        $wh1Src = $this->readModuleSrc($wh1File);
        if ($wh1Src === '') {
            $offenders[] = 'WebhookManager.php introuvable (garde-fou round 116 : URL/secret/events scopés par idShop)';
        } else {
            $wh1CountUrl    = substr_count($wh1Src, "\\Configuration::get(self::CONFIG_URL, null, null, \$this->idShop)");
            $wh1CountSecret = substr_count($wh1Src, "\\Configuration::get(self::CONFIG_SECRET, null, null, \$this->idShop)");
            $wh1CountEvents = substr_count($wh1Src, "\\Configuration::get(self::CONFIG_EVENTS, null, null, \$this->idShop)");
            if ($wh1CountUrl < 3 || $wh1CountSecret < 2 || $wh1CountEvents < 1) {
                $offenders[] = "WebhookManager ne résout plus CONFIG_URL/CONFIG_SECRET/CONFIG_EVENTS via \$this->idShop à tous les emplacements attendus (url={$wh1CountUrl}/3, secret={$wh1CountSecret}/2, events={$wh1CountEvents}/1) — régression du bug corrigé le 08/08/2026 (round 116)";
            }
        }

        // Round 117 (2026-08-08) : ABTestManager::estimateDaysRemaining() —
        // le dénominateur (jours écoulés) doit être plafonné au paramètre
        // $windowDays, cohérent avec la fenêtre utilisée pour produire
        // $report (StatsManager::getABTestReport($template, $windowDays)).
        // Sans ce plafond, un test A/B tournant depuis plus longtemps que la
        // fenêtre du rapport (30 jours côté neria.php::getAbtestReportsMap())
        // voyait son rythme quotidien sous-estimé (numérateur plafonné à la
        // fenêtre, dénominateur basé sur le vrai date_start illimité),
        // surestimant d'autant l'ETA "days_remaining" affichée au marchand
        // dans l'onglet A/B Testing du BO.
        $ab1File = _PS_MODULE_DIR_ . $this->module->name . '/src/ABTestManager.php';
        $ab1Src = $this->readModuleSrc($ab1File);
        $ne1File = _PS_MODULE_DIR_ . $this->module->name . '/neria.php';
        $ne1Src = $this->readModuleSrc($ne1File);
        if ($ab1Src === '' || $ne1Src === '') {
            $offenders[] = 'ABTestManager.php/neria.php introuvable (garde-fou round 117 : ETA A/B plafonnée à la fenêtre du rapport)';
        } else {
            if (strpos($ab1Src, 'function estimateDaysRemaining(string $template, array $report, int $windowDays = 30)') === false
                || strpos($ab1Src, '$daysElapsed     = min($daysElapsedReal, max(1, $windowDays));') === false
            ) {
                $offenders[] = "ABTestManager::estimateDaysRemaining() ne plafonne plus \$daysElapsed à \$windowDays — régression du bug corrigé le 08/08/2026 (round 117)";
            }
            if (strpos($ne1Src, '$ab->estimateDaysRemaining($tpl, $report, 30);') === false) {
                $offenders[] = "getAbtestReportsMap() (neria.php) ne transmet plus la fenêtre 30 à estimateDaysRemaining() — régression du bug corrigé le 08/08/2026 (round 117) : incohérence de nouveau possible entre la fenêtre du rapport et celle de l'estimation ETA";
            }
        }

        // Round 118 (2026-08-08) : QueueManager::getStats()/
        // HealthCheckManager::checkQueueFailedRate() — même famille que le
        // round 117 (fenêtres temporelles incohérentes dans un ratio).
        // failed_30d filtrait sur created_at (date de mise en file,
        // potentiellement des semaines avant un échec réel pour un envoi
        // programmé via scheduleManual()) au lieu de send_at (figé sur la
        // dernière tentative planifiée par markFailedOrRetry(), donc bien
        // plus proche du moment de l'échec réel). Un échec récent mais mis
        // en file il y a longtemps redevenait invisible dans le taux
        // d'échec affiché — masquant potentiellement un vrai incident.
        $qm1File = _PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php';
        $qm1Src = $this->readModuleSrc($qm1File);
        if ($qm1Src === '') {
            $offenders[] = 'QueueManager.php introuvable (garde-fou round 118 : failed_30d filtré sur send_at)';
        } elseif (strpos($qm1Src, "AND status = \\'failed\\' AND send_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)") === false) {
            $offenders[] = "QueueManager::getStats() ne filtre plus failed_30d sur send_at — régression du bug corrigé le 08/08/2026 (round 118)";
        }

        // Round 119 (2026-08-08) : CollectionManager::getStats() — sent/
        // sentLast30 doivent filtrer neria_collection_sent par id_shop
        // (cette table EST scopée par boutique, voir hasSent() dans le même
        // fichier), pas neria_collection (les définitions de collection,
        // elles, n'ont pas de colonne id_shop — total/active restent
        // légitimement globaux). Sans ce filtre, le BO d'une boutique
        // affichait dans son KPI « Complétion de collection » les envois de
        // TOUTES les boutiques de l'installation.
        $cm1File = _PS_MODULE_DIR_ . $this->module->name . '/src/CollectionManager.php';
        $cm1Src = $this->readModuleSrc($cm1File);
        if ($cm1Src === '') {
            $offenders[] = 'CollectionManager.php introuvable (garde-fou round 119 : getStats() sent/sentLast30 scopés par idShop)';
        } elseif (strpos($cm1Src, 'neria_collection_sent` WHERE id_shop = {$idShop}') === false
            || strpos($cm1Src, 'WHERE id_shop = {$idShop} AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)') === false
        ) {
            $offenders[] = "CollectionManager::getStats() ne filtre plus sent/sentLast30 par id_shop — régression du bug corrigé le 08/08/2026 (round 119)";
        }

        // Round 120 (2026-08-08) : CertificateManager::getStats() — trend_pct
        // doit comparer $thisMonth (fenêtre partielle, du 1er du mois à
        // aujourd'hui) à $lastMonthComparable (mois précédent borné au MÊME
        // nombre de jours écoulés), pas à $lastMonth (mois précédent ENTIER,
        // affiché tel quel dans 'last_month' mais impropre à un calcul de
        // tendance). Sans ce correctif, un rythme d'émission parfaitement
        // stable produisait une chute artificielle massive en début de mois
        // (ex. -74% un 8 du mois) et une hausse trompeuse en toute fin de
        // mois — même famille de bug que rounds 117/118 (fenêtres
        // temporelles incohérentes dans un ratio).
        $cert1File = _PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php';
        $cert1Src = $this->readModuleSrc($cert1File);
        if ($cert1Src === '') {
            $offenders[] = 'CertificateManager.php introuvable (garde-fou round 120 : trend_pct comparé sur fenêtres équivalentes)';
        } elseif (strpos($cert1Src, '$lastMonthComparable > 0') === false
            || strpos($cert1Src, '($thisMonth - $lastMonthComparable) / $lastMonthComparable') === false
        ) {
            $offenders[] = "CertificateManager::getStats() ne calcule plus trend_pct via \$lastMonthComparable — régression du bug corrigé le 08/08/2026 (round 120) : trend_pct comparerait de nouveau une fenêtre partielle à un mois précédent entier";
        }

        // Round 121 (2026-08-08) : LoyaltyManager::sendMonthlyRecaps()/
        // sendRecapToCustomer() — même famille que rounds 117/118/120
        // (fenêtres temporelles incohérentes). Le throttle mensuel
        // calendaire ('Y-m') autorise un écart réel de ~1 à 31 jours entre
        // deux envois, mais sendRecapToCustomer() sommait toujours les
        // points sur une fenêtre FIXE de 30 jours — recomptant les mêmes
        // points dans deux emails rapprochés, ou en oubliant d'autres à la
        // frontière d'un écart plus long.
        $loy1File = _PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php';
        $loy1Src = $this->readModuleSrc($loy1File);
        if ($loy1Src === '') {
            $offenders[] = 'LoyaltyManager.php introuvable (garde-fou round 121 : fenêtre du récap basée sur le délai réel écoulé)';
        } elseif (strpos($loy1Src, 'private static function computeRecapWindowDays(string $lastSentRaw): int') === false
            || strpos($loy1Src, 'INTERVAL " . (int) $windowDays . " DAY)') === false
        ) {
            $offenders[] = "LoyaltyManager ne calcule plus la fenêtre du récap via computeRecapWindowDays()/\$windowDays — régression du bug corrigé le 08/08/2026 (round 121)";
        }

        // Round 122 (2026-08-08) : PostmasterManager/SearchConsoleManager —
        // getAuthUrl()/handleCallback() doivent verrouiller (GET_LOCK/
        // RELEASE_LOCK) leur cycle lecture-modification-écriture de la
        // liste des states OAuth pending. Sans ce verrou, deux flux lancés
        // à quelques centaines de ms d'écart (double clic, deux onglets BO
        // — le cas explicitement documenté par le code) peuvent tous deux
        // lire la même liste avant que l'un des deux n'écrive : le second
        // Configuration::updateValue() écrase intégralement le premier
        // state, le perdant silencieusement — l'admin voit alors échouer
        // une connexion OAuth pourtant valide côté Google.
        foreach (['PostmasterManager' => 'neria_postmaster_oauth_state', 'SearchConsoleManager' => 'neria_search_console_oauth_state'] as $oauthClass => $lockName) {
            $oauthFile = _PS_MODULE_DIR_ . $this->module->name . '/src/' . $oauthClass . '.php';
            $oauthSrc = $this->readModuleSrc($oauthFile);
            if ($oauthSrc === '') {
                $offenders[] = "{$oauthClass}.php introuvable (garde-fou round 122 : states OAuth pending verrouillés)";
            } elseif (substr_count($oauthSrc, "GET_LOCK('{$lockName}'") !== 2 || substr_count($oauthSrc, "RELEASE_LOCK('{$lockName}'") !== 2) {
                $offenders[] = "{$oauthClass} ne verrouille plus getAuthUrl()/handleCallback() via GET_LOCK('{$lockName}') — régression du bug corrigé le 08/08/2026 (round 122)";
            }
        }

        // Round 123 (2026-08-08) : ConfigManager::toggleMenuItemVisibility()
        // — même famille que round 122, appliquée cette fois à la liste JSON
        // de visibilité du menu BO (NERIA_MENU_HIDDEN_ITEMS). Sans verrou,
        // deux clics de masquage sur deux features différentes à quelques
        // centaines de ms d'écart peuvent tous deux lire la même liste avant
        // que l'un des deux n'écrive : le second Configuration::
        // updateGlobalValue() écrase intégralement le masquage posé par le
        // premier, qui réapparaît silencieusement dans le menu BO.
        $cfg1File = _PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php';
        $cfg1Src = $this->readModuleSrc($cfg1File);
        if ($cfg1Src === '') {
            $offenders[] = 'ConfigManager.php introuvable (garde-fou round 123 : toggleMenuItemVisibility() verrouillé)';
        } elseif (strpos($cfg1Src, "GET_LOCK('neria_menu_hidden_items', 3)") === false
            || strpos($cfg1Src, "RELEASE_LOCK('neria_menu_hidden_items')") === false
        ) {
            $offenders[] = "ConfigManager::toggleMenuItemVisibility() ne verrouille plus le cycle lecture-modification-écriture — régression du bug corrigé le 08/08/2026 (round 123)";
        }

        // Round 124 (2026-08-08) : PropensityScoreManager::recalculateCustomer()
        // — score doit être dérivé de la somme des 4 sous-scores DÉJÀ
        // arrondis (round(), pas une troncature (int) séparée sur la somme
        // des floats bruts). Sans ce correctif, la somme des 4 composantes
        // affichées côte à côte du score total pouvait différer du score
        // total lui-même (troncatures incohérentes), minant la confiance du
        // marchand dans le détail affiché.
        $pr1File = _PS_MODULE_DIR_ . $this->module->name . '/src/PropensityScoreManager.php';
        $pr1Src = $this->readModuleSrc($pr1File);
        if ($pr1Src === '') {
            $offenders[] = 'PropensityScoreManager.php introuvable (garde-fou round 124 : score dérivé de la somme des sous-scores arrondis)';
        } elseif (strpos($pr1Src, '$total = min(100, $scoreRecency + $scoreFrequency + $scoreEngagement + $scoreSeasonality);') === false) {
            $offenders[] = "PropensityScoreManager::recalculateCustomer() ne dérive plus \$total de la somme des sous-scores arrondis — régression du bug corrigé le 08/08/2026 (round 124)";
        }

        // Round 125 (2026-08-08) : EmailRenderer::buildCompiledHtml()
        // (partagée par renderPreviewHtml() — aperçu Design BO — et
        // renderWithVars() — renvoi depuis l'historique client) doit
        // résoudre la signature manuscrite/réseaux sociaux avec la config
        // réelle ET traiter le bloc {if isset($var) && $var}...{/if}, comme
        // le fait compileNeriaTemplate() (envoi réel). Sans ce correctif, ce
        // bloc disparaissait TOUJOURS dans l'aperçu/le renvoi, quelle que
        // soit la configuration marchand.
        $er1File = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $er1Src = $this->readModuleSrc($er1File);
        if ($er1Src === '') {
            $offenders[] = 'EmailRenderer.php introuvable (garde-fou round 125 : signature/réseaux sociaux résolus dans buildCompiledHtml())';
        } else {
            $posbch = strpos($er1Src, 'private function buildCompiledHtml(');
            $bchBlock = $posbch !== false ? substr($er1Src, $posbch, 10500) : '';
            if ($posbch === false
                || strpos($bchBlock, '$this->injectSignatureVars($sigVars, (int) $this->context->shop->id);') === false
                || strpos($bchBlock, '$this->injectSocialVars($socVars);') === false
                || strpos($bchBlock, "isset\\(\\\$([a-z_]+)\\)") === false
            ) {
                $offenders[] = "EmailRenderer::buildCompiledHtml() ne résout plus la signature/les réseaux sociaux (isset() manquant ou vars non injectées) — régression du bug corrigé le 08/08/2026 (round 125)";
            }
        }

        // Round 126 (2026-08-08) : ManualSendManager::scheduleManual() —
        // même famille que round 125 (chemins parallèles ayant dérivé).
        // send() (envoi immédiat) traite spécialement 'custom_message' →
        // {custom_message_raw}, la seule clé lue par
        // EmailRenderer::injectCustomMessage() au moment de l'envoi réel.
        // scheduleManual() avait perdu ce cas spécial et stockait
        // directement sous {custom_message} — injectCustomMessage() écrase
        // alors cette clé par une chaîne vide (raw absent), faisant
        // disparaître silencieusement le message personnalisé d'un envoi
        // manuel PLANIFIÉ (fonctionnait pour un envoi immédiat).
        $ms1File = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $ms1Src = $this->readModuleSrc($ms1File);
        if ($ms1Src === '') {
            $offenders[] = 'ManualSendManager.php introuvable (garde-fou round 126 : custom_message_raw dans scheduleManual())';
        } elseif (substr_count($ms1Src, "\$vars['{custom_message_raw}'] = (string) \$value;") !== 2) {
            $offenders[] = "ManualSendManager ne stocke plus 'custom_message' sous {custom_message_raw} dans les deux méthodes (send() ET scheduleManual()) — régression du bug corrigé le 08/08/2026 (round 126)";
        }

        // Round 127 (2026-08-08) : LookCompletionManager::getStats() —
        // sent/sent30 doivent être scopés par boutique via JOIN sur
        // orders.id_shop (neria_look_sent n'a pas de colonne id_shop propre)
        // — même pattern que CollectionManager::getStats() (round 119).
        // Sans ce scope, le BO d'une boutique affichait dans son KPI
        // "Complétez votre look" les envois de TOUTES les boutiques de
        // l'installation, juste à côté d'un KPI "Complétion de collection"
        // correctement scopé sur le même écran.
        $lc1File = _PS_MODULE_DIR_ . $this->module->name . '/src/LookCompletionManager.php';
        $lc1Src = $this->readModuleSrc($lc1File);
        if ($lc1Src === '') {
            $offenders[] = 'LookCompletionManager.php introuvable (garde-fou round 127 : getStats() sent/sent30 scopés via JOIN orders)';
        } elseif (substr_count($lc1Src, 'INNER JOIN `{$this->prefix}orders` o ON o.id_order = ls.id_order') !== 2) {
            $offenders[] = "LookCompletionManager::getStats() ne scope plus sent/sent30 via JOIN sur orders.id_shop — régression du bug corrigé le 08/08/2026 (round 127)";
        }

        // Round 128 (2026-08-08) : PostmasterManager::fetchAndCache() —
        // écriture du cache scopée par boutique via cacheKey(), comme les 3
        // lectures (getStats()/getCachedStats()/getCacheAge()) et
        // clearCache() dans ce même fichier, et comme
        // SearchConsoleManager::fetchAndCache() (méthode jumelle). Sans ce
        // scope, l'écriture retombait sur les clés brutes non suffixées,
        // jamais relues par aucune boutique — le cache n'était en réalité
        // JAMAIS exploité, chaque chargement du BO Postmaster redéclenchant
        // un appel réel à l'API Gmail Postmaster (sensible aux quotas).
        $pm2File = _PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php';
        $pm2Src = $this->readModuleSrc($pm2File);
        if ($pm2Src === '') {
            $offenders[] = 'PostmasterManager.php introuvable (garde-fou round 128 : écriture du cache scopée via cacheKey())';
        } elseif (strpos($pm2Src, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE),') === false
            || strpos($pm2Src, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_TIME), time());') === false
            || strpos($pm2Src, 'Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_HOST), $shopHost);') === false
        ) {
            $offenders[] = "PostmasterManager::fetchAndCache() n'écrit plus le cache via cacheKey() — régression du bug corrigé le 08/08/2026 (round 128) : le cache écrit ne serait plus jamais relu par aucune boutique";
        }

        // Round 129 : DomainReputationManager::getSenderDomain() résolvait
        // NERIA_SENDERS_JSON/PS_SHOP_EMAIL sans idShop explicite — le cache
        // est bien scopé par boutique mais stockait la mauvaise donnée
        // source (domaine expéditeur d'une AUTRE boutique que celle du
        // rapport SPF/DKIM/DMARC/RBL affiché).
        $drFile = _PS_MODULE_DIR_ . $this->module->name . '/src/DomainReputationManager.php';
        $drSrc = $this->readModuleSrc($drFile);
        if ($drSrc === '') {
            $offenders[] = 'DomainReputationManager.php introuvable (garde-fou round 129 : getSenderDomain() scopé idShop)';
        } elseif (strpos($drSrc, "Configuration::get('NERIA_SENDERS_JSON', null, null, \$this->idShop)") === false
            || strpos($drSrc, "Configuration::get('PS_SHOP_EMAIL', null, null, \$this->idShop)") === false
        ) {
            $offenders[] = "DomainReputationManager::getSenderDomain() ne résout plus NERIA_SENDERS_JSON/PS_SHOP_EMAIL via \$this->idShop — régression du bug corrigé le 08/08/2026 (round 129) : le domaine d'une autre boutique pourrait de nouveau être utilisé pour le rapport de réputation";
        }

        // Round 129 : CooldownManager::resolveCustomerId() résolvait le
        // client via \Customer::customerExists() sans commuter
        // Shop::setContext() — \Customer::customerExists() filtre en
        // interne sur Shop::$context_id_shop (statique), jamais mis à jour
        // par une simple réaffectation de Context::getContext()->shop.
        $cdFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CooldownManager.php';
        $cdSrc = $this->readModuleSrc($cdFile);
        if ($cdSrc === '') {
            $offenders[] = 'CooldownManager.php introuvable (garde-fou round 129 : resolveCustomerId() commute Shop::setContext())';
        } elseif (strpos($cdSrc, 'Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);') === false
            || strpos($cdSrc, 'Shop::setContext($previousContext, $previousShopId);') === false
        ) {
            $offenders[] = "CooldownManager::resolveCustomerId() ne commute plus Shop::setContext() — régression du bug corrigé le 08/08/2026 (round 129) : le Mode Silence (anti-doublon) pourrait de nouveau se désactiver silencieusement pour les boutiques suivantes de la boucle multi-boutique du cron";
        }

        // Round 129 : ManualSendManager construisait {order_url} sans idShop
        // explicite (6e argument getPageLink), contrairement aux liens
        // voisins {shop_url}/{history_url} — retombait sur le contexte BO
        // de l'employé au lieu de la boutique du client destinataire.
        $msFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $msSrc = $this->readModuleSrc($msFile);
        if ($msSrc === '') {
            $offenders[] = 'ManualSendManager.php introuvable (garde-fou round 129 : {order_url} scopé idShop)';
        } elseif (preg_match_all(
            "/getPageLink\\(\\s*'order-detail',\\s*true,\\s*\\\$idLang,\\s*\\['id_order'\\s*=>\\s*\\(int\\)\\s*\\\$order\\['id_order'\\]\\],\\s*false,\\s*\\\$idShop(Manual)?\\s*\\)/",
            $msSrc
        ) !== 2) {
            $offenders[] = "ManualSendManager : {order_url} ne semble plus scopé par idShop dans les 2 méthodes attendues (send() + envoi planifié) — régression du bug corrigé le 08/08/2026 (round 129)";
        }

        // Round 129 : FontManager::generateCssVariables() injectait les 4
        // couleurs design sans sanitizeColor(), contrairement à accentColor
        // dans generateFontCss() (même fichier) — incohérence de défense
        // en profondeur.
        $fmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/FontManager.php';
        $fmSrc = $this->readModuleSrc($fmFile);
        if ($fmSrc === '') {
            $offenders[] = 'FontManager.php introuvable (garde-fou round 129 : couleurs sanitizeColor() dans generateCssVariables())';
        } else {
            foreach (['color_background', 'color_container', 'color_accent', 'color_text'] as $colorKey) {
                if (strpos($fmSrc, "\\NeriaTools::sanitizeColor((string) \$design['{$colorKey}'],") === false) {
                    $offenders[] = "FontManager::generateCssVariables() n'applique plus sanitizeColor() sur '{$colorKey}' — régression du bug corrigé le 08/08/2026 (round 129)";
                    break;
                }
            }
        }

        // Round 130 (2026-08-08) : MonthlyReportManager::getRevenueByTemplate()
        // — les deux requêtes (revenu direct, revenu attribué) doivent filtrer
        // o.valid = 1, comme partout ailleurs dans le module où une somme de
        // montants de commande est calculée (ClvManager, UpsellManager). Sans
        // ce filtre, une commande annulée/remboursée restait comptée dans le
        // "CA attribué" du rapport mensuel envoyé au marchand, surestimant le
        // chiffre d'affaires réel.
        $mrr1File = _PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php';
        $mrr1Src = $this->readModuleSrc($mrr1File);
        if ($mrr1Src === '') {
            $offenders[] = 'MonthlyReportManager.php introuvable (garde-fou round 130 : getRevenueByTemplate() scopé o.valid=1)';
        } elseif (substr_count($mrr1Src, 'AND o.valid = 1') !== 2) {
            $offenders[] = "MonthlyReportManager::getRevenueByTemplate() ne filtre plus o.valid = 1 sur les deux requêtes (direct + attribué) — régression du bug corrigé le 08/08/2026 (round 130) : le CA du rapport mensuel inclurait de nouveau les commandes annulées/remboursées";
        }

        // Round 130 (2026-08-08) : upgrade-1.0.17.php — sonde la clé de
        // chiffrement AVANT de chiffrer les secrets sensibles, et journalise
        // via Watchdog si elle est illisible — comme
        // GdprAuditManager::encryptExistingRecords(). Sans cette sonde,
        // CryptoManager::encrypt() retourne la valeur EN CLAIR inchangée sans
        // aucune trace si la clé est absente/corrompue au moment précis de
        // l'upgrade — le marchand croit ses secrets (mot de passe IMAP,
        // tokens OAuth, clés API tierces) chiffrés alors qu'ils ne le sont
        // pas, sans que rien ne le signale au moment où ça se produit.
        $up117File = _PS_MODULE_DIR_ . $this->module->name . '/upgrade/upgrade-1.0.17.php';
        $up117Src = $this->readModuleSrc($up117File);
        if ($up117Src === '') {
            $offenders[] = 'upgrade-1.0.17.php introuvable (garde-fou round 130 : sonde de clé avant chiffrement des secrets)';
        } elseif (strpos($up117Src, "CryptoManager::encrypt('neria_key_probe')") === false) {
            $offenders[] = "upgrade_module_1_0_17() ne sonde plus la clé de chiffrement avant de chiffrer les secrets — régression du bug corrigé le 08/08/2026 (round 130) : un échec de chiffrement redeviendrait silencieux";
        }

        // Round 131 (2026-08-08) : LicenseManager::checkDomainChange() doit
        // restreindre la comparaison de domaine à la boutique par défaut de
        // l'installation. Sans ce garde-fou, sur une installation
        // multi-boutiques multi-domaines, chaque page vue d'une boutique
        // secondaire déclenchait à tort un "changement de domaine" (la
        // licence étant globale mais currentDomain() reflétant la boutique
        // visitée), contournant le cache 24h et déclenchant un appel réseau
        // en rafale vers le serveur de licence à chaque hit.
        $licFile = _PS_MODULE_DIR_ . $this->module->name . '/src/LicenseManager.php';
        $licSrc = $this->readModuleSrc($licFile);
        if ($licSrc === '') {
            $offenders[] = 'LicenseManager.php introuvable (garde-fou round 131 : checkDomainChange() restreint à la boutique par défaut)';
        } else {
            $posCdc = strpos($licSrc, 'public function checkDomainChange(): void');
            $cdcBody = $posCdc !== false ? substr($licSrc, $posCdc, 1300) : '';
            if ($posCdc === false || strpos($cdcBody, 'Shop::isFeatureActive()') === false || strpos($cdcBody, 'PS_SHOP_DEFAULT') === false) {
                $offenders[] = "LicenseManager::checkDomainChange() ne restreint plus la comparaison de domaine à la boutique par défaut de l'installation — régression du bug corrigé le 08/08/2026 (round 131) : les faux positifs de changement de domaine réapparaîtraient sur les boutiques secondaires en multi-boutiques";
            }
        }

        // Round 131 (2026-08-08) : LookCompletionManager::buildProductBlocks()
        // doit réellement commuter le contexte boutique statique via
        // Shop::setContext() (pas seulement Context->shop) avant de charger
        // les produits suggérés — même piège que CooldownManager/
        // DomainReputationManager (round 129) : Shop::$context_id_shop n'est
        // mis à jour QUE par Shop::setContext(), consulté en interne par le
        // constructeur Product et Product::getCover().
        $lcmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/LookCompletionManager.php';
        $lcmSrc = $this->readModuleSrc($lcmFile);
        if ($lcmSrc === '') {
            $offenders[] = 'LookCompletionManager.php introuvable (garde-fou round 131 : buildProductBlocks() commute Shop::setContext())';
        } else {
            $posBpb = strpos($lcmSrc, 'private function buildProductBlocks(array $productIds, int $idLang, int $idShop): array');
            // Round 184 : fenêtre élargie 3600→5700 — le remplacement de
            // StockAvailable::getQuantityAvailableByProduct() par un SUM
            // SQL direct et l'ajout de safeProductPrice() ont repoussé le
            // point de restauration du contexte plus loin dans la méthode.
            $bpbBody = $posBpb !== false ? substr($lcmSrc, $posBpb, 5700) : '';
            if ($posBpb === false
                || strpos($bpbBody, 'Shop::setContext(\Shop::CONTEXT_SHOP, $idShop)') === false
                || strpos($bpbBody, 'Shop::setContext(\Shop::CONTEXT_SHOP, $originalShopId)') === false
            ) {
                $offenders[] = "LookCompletionManager::buildProductBlocks() ne commute plus le contexte boutique statique via Shop::setContext() — régression du bug corrigé le 08/08/2026 (round 131) : un produit désactivé sur la boutique du client pourrait de nouveau être suggéré à tort";
            }
        }

        // Round 131 (2026-08-08) : PostmasterManager::apiGet() doit
        // journaliser CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT (+ alerte
        // Watchdog) sur un échec transport (timeout/DNS/TLS) ou une réponse
        // JSON invalide, pas seulement sur une erreur HTTP>=400 explicite.
        // Sans ce garde-fou, une panne réseau persistante redevenait
        // totalement silencieuse, empêchant HealthCheckManager de fournir un
        // vrai diagnostic sur l'ancienneté de l'erreur.
        $pmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php';
        $pmSrc = $this->readModuleSrc($pmFile);
        if ($pmSrc === '') {
            $offenders[] = 'PostmasterManager.php introuvable (garde-fou round 131 : apiGet() journalise les échecs transport)';
        } else {
            $posApiGet = strpos($pmSrc, 'private function apiGet(string $path, string $token): ?array');
            $apiGetBody = $posApiGet !== false ? substr($pmSrc, $posApiGet, 2800) : '';
            if ($posApiGet === false
                || strpos($apiGetBody, 'if ($body === false)') === false
                || strpos($apiGetBody, "'invalid JSON response'") === false
            ) {
                $offenders[] = "PostmasterManager::apiGet() ne journalise plus les échecs transport/JSON invalide dans CONFIG_LAST_ERROR — régression du bug corrigé le 08/08/2026 (round 131) : une panne réseau persistante redeviendrait silencieuse";
            }
        }

        // Round 132 (2026-08-08) : BehavioralCronManager::sendGhostCarts()
        // doit instancier Product avec l'idShop explicite ET commuter le
        // contexte boutique statique via Shop::setContext() avant
        // Product::getCover() — même piège Shop::$context_id_shop que les
        // rounds 129/131. Sans ce garde-fou, l'email "panier fantôme"
        // pourrait de nouveau afficher le nom/description/image d'un
        // produit tel que catalogué dans une autre boutique.
        $bcmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php';
        $bcmSrc = $this->readModuleSrc($bcmFile);
        if ($bcmSrc === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (garde-fou round 132 : sendGhostCarts() Product idShop + Shop::setContext())';
        } else {
            $posGhost = strpos($bcmSrc, 'private function sendGhostCarts(');
            $ghostBody = $posGhost !== false ? substr($bcmSrc, $posGhost, 5200) : '';
            if ($posGhost === false
                || strpos($ghostBody, 'new \Product($idProduct, false, $idLang, $ghostShopId)') === false
                || strpos($ghostBody, 'Shop::setContext(\Shop::CONTEXT_SHOP, $ghostShopId)') === false
                || strpos($ghostBody, 'Shop::setContext(\Shop::CONTEXT_SHOP, $originalGhostShopId)') === false
            ) {
                $offenders[] = "BehavioralCronManager::sendGhostCarts() ne commute plus le contexte boutique statique via Shop::setContext() avant Product/getCover() — régression du bug corrigé le 08/08/2026 (round 132) : les données produit d'une autre boutique pourraient réapparaître dans l'email panier fantôme";
            }
        }

        // Round 132 (2026-08-08) : ConfigManager::get() doit transmettre
        // $this->idShop en 4e argument à Configuration::get() — même piège
        // Shop::$context_id_shop. Sans ce garde-fou, un ConfigManager
        // instancié dans une boucle multi-boutique (BehavioralCronManager)
        // pourrait de nouveau lire les réglages d'une autre boutique.
        $cfgFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php';
        $cfgSrc = $this->readModuleSrc($cfgFile);
        if ($cfgSrc === '') {
            $offenders[] = 'ConfigManager.php introuvable (garde-fou round 132 : get() scopé idShop + toggles verrouillés)';
        } else {
            if (strpos($cfgSrc, '\Configuration::get($key, null, null, $this->idShop)') === false) {
                $offenders[] = "ConfigManager::get() ne transmet plus \$this->idShop à Configuration::get() — régression du bug corrigé le 08/08/2026 (round 132) : la configuration d'une autre boutique pourrait de nouveau polluer une boucle multi-boutique";
            }
            // Les 4 toggles booléens BO doivent passer par le helper
            // verrouillé plutôt qu'un cycle isX()/setX() séparé — sans ce
            // garde-fou, la race condition sur double-clic/deux onglets BO
            // (désynchronisation UI/état réel) pourrait réapparaître.
            $posHelper = strpos($cfgSrc, 'private function toggleBooleanKey(');
            // Fenêtre élargie à 1300 (round 141) après le correctif de
            // vérification du retour de GET_LOCK(), qui a allongé la
            // méthode — mesurée sur le fichier réel (~1118 octets).
            $helperBody = $posHelper !== false ? substr($cfgSrc, $posHelper, 1300) : '';
            if ($posHelper === false || strpos($helperBody, "GET_LOCK('") === false || strpos($helperBody, "RELEASE_LOCK('") === false) {
                $offenders[] = "ConfigManager::toggleBooleanKey() n'utilise plus GET_LOCK/RELEASE_LOCK — régression du bug corrigé le 08/08/2026 (round 132) : la race condition sur les toggles booléens BO pourrait réapparaître";
            }
            if (substr_count($cfgSrc, 'toggleBooleanKey(self::KEY_') !== 4) {
                $offenders[] = "ConfigManager : un ou plusieurs des 4 toggles booléens BO (time_greeting/firstname_fallback/multi_sender/signature) n'appellent plus toggleBooleanKey() — régression du bug corrigé le 08/08/2026 (round 132)";
            }
        }

        // Round 132 (2026-08-08) : LookCompletionManager::getOrderCategoryIds()
        // doit exclure id_category_default = 0 en plus de NULL.
        $lcm2File = _PS_MODULE_DIR_ . $this->module->name . '/src/LookCompletionManager.php';
        $lcm2Src = $this->readModuleSrc($lcm2File);
        if ($lcm2Src === '') {
            $offenders[] = 'LookCompletionManager.php introuvable (garde-fou round 132 : getOrderCategoryIds() exclut id_category_default=0)';
        } else {
            $posCat = strpos($lcm2Src, 'private function getOrderCategoryIds(int $idOrder): array');
            $catBody = $posCat !== false ? substr($lcm2Src, $posCat, 1300) : '';
            if ($posCat === false || strpos($catBody, 'id_category_default != 0') === false) {
                $offenders[] = "LookCompletionManager::getOrderCategoryIds() ne filtre plus id_category_default != 0 — régression du bug corrigé le 08/08/2026 (round 132) : une catégorie 0 corrompue pourrait de nouveau polluer findMatchingRule()";
            }
        }

        // Round 133 (2026-08-08) : OrderTriggersManager::generateMilestoneVoucher()
        // ne doit plus libérer la réservation anti-doublon dans son bloc
        // d'échec de CartRule::add() — seul checkMilestone() décide de la
        // libérer, selon le résultat réel de l'envoi de l'email. Sans ce
        // garde-fou, un double bon pour le même palier pourrait réapparaître
        // après un échec transitoire de CartRule::add().
        $otmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php';
        $otmSrc = $this->readModuleSrc($otmFile);
        if ($otmSrc === '') {
            $offenders[] = 'OrderTriggersManager.php introuvable (garde-fou round 133 : generateMilestoneVoucher() ne libère plus la réservation sur échec CartRule)';
        } else {
            $posAddCheck = strpos($otmSrc, 'if (!$cartRule->add()) {');
            $posThrowMv  = $posAddCheck !== false ? strpos($otmSrc, 'throw new \RuntimeException(', $posAddCheck) : false;
            $failureBlock = ($posAddCheck !== false && $posThrowMv !== false) ? substr($otmSrc, $posAddCheck, $posThrowMv - $posAddCheck) : '__MISSING__';
            if ($posAddCheck === false || $posThrowMv === false || strpos($failureBlock, 'DELETE FROM') !== false) {
                $offenders[] = "OrderTriggersManager::generateMilestoneVoucher() supprime de nouveau la réservation anti-doublon dans son bloc d'échec de CartRule::add() — régression du bug corrigé le 08/08/2026 (round 133) : un double bon pour le même palier pourrait réapparaître";
            }
            if (strpos($otmSrc, "minimum_amount_currency = (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$idShop)") === false) {
                $offenders[] = "OrderTriggersManager::generateMilestoneVoucher() ne résout plus minimum_amount_currency avec l'idShop explicite — régression du bug corrigé le 08/08/2026 (round 133)";
            }
        }

        // Round 133 (2026-08-08) : ConfigManager::set() doit transmettre
        // $this->idShop en 5e argument à Configuration::updateValue(),
        // symétriquement à get() (round 132). Sans ce garde-fou, l'asymétrie
        // lecture/écriture pourrait réapparaître pour tout futur appelant en
        // boucle multi-boutique (ex. NERIA_SENDERS_JSON).
        $cfg2File = _PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php';
        $cfg2Src = $this->readModuleSrc($cfg2File);
        if ($cfg2Src === '') {
            $offenders[] = 'ConfigManager.php introuvable (garde-fou round 133 : set() scopé idShop)';
        } elseif (strpos($cfg2Src, '\Configuration::updateValue($key, $value, false, null, $this->idShop)') === false) {
            $offenders[] = "ConfigManager::set() ne transmet plus \$this->idShop à Configuration::updateValue() — régression du bug corrigé le 08/08/2026 (round 133) : l'écriture de configuration pourrait de nouveau polluer le mauvais id_shop en boucle multi-boutique";
        }

        // Round 133 (2026-08-08) : SeoApiManager::getLastError()/recordError()/
        // clearError() doivent tous passer par cacheKey() (scopé par
        // boutique), comme le cache. Sans ce garde-fou, une erreur SEO d'une
        // boutique pourrait de nouveau être effacée/mélangée par un
        // événement sur une autre boutique.
        $seoFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SeoApiManager.php';
        $seoSrc = $this->readModuleSrc($seoFile);
        if ($seoSrc === '') {
            $offenders[] = 'SeoApiManager.php introuvable (garde-fou round 133 : LAST_ERROR scopé par boutique)';
        } else {
            if (strpos($seoSrc, '\Configuration::get($this->cacheKey(self::CONFIG_LAST_ERROR))') === false
                || strpos($seoSrc, '\Configuration::updateValue($this->cacheKey(self::CONFIG_LAST_ERROR), $msg)') === false
                || strpos($seoSrc, '\Configuration::deleteByName($this->cacheKey(self::CONFIG_LAST_ERROR))') === false
            ) {
                $offenders[] = "SeoApiManager : CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT ne passent plus par cacheKey() — régression du bug corrigé le 08/08/2026 (round 133) : une erreur SEO d'une boutique pourrait de nouveau être effacée/mélangée par une autre boutique";
            }
        }

        // Round 134 (2026-08-08) : PageSpeedManager::getLastError()/recordError()
        // doivent, comme SeoApiManager (round 133), passer par cacheKey().
        $psmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/PageSpeedManager.php';
        $psmSrc = $this->readModuleSrc($psmFile);
        if ($psmSrc === '') {
            $offenders[] = 'PageSpeedManager.php introuvable (garde-fou round 134 : LAST_ERROR scopé par boutique)';
        } elseif (strpos($psmSrc, '\Configuration::get($this->cacheKey(self::CONFIG_LAST_ERROR))') === false
            || strpos($psmSrc, '\Configuration::updateValue($this->cacheKey(self::CONFIG_LAST_ERROR), $msg)') === false
        ) {
            $offenders[] = "PageSpeedManager : CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT ne passent plus par cacheKey() — régression du bug corrigé le 08/08/2026 (round 134) : une erreur PageSpeed d'une boutique pourrait de nouveau être effacée/mélangée par une autre boutique";
        }

        // Round 134 (2026-08-08) : l'action BO save_carbon (neria.php) doit
        // écrire via ConfigManager::set() (scopé idShop), pas
        // Configuration::updateValue() en direct.
        $mainSrc134 = file_get_contents(_PS_MODULE_DIR_ . $this->module->name . '/neria.php') ?: '';
        $posCarbon = strpos($mainSrc134, "'neria_action') === 'save_carbon'");
        $carbonBlock = $posCarbon !== false ? substr($mainSrc134, $posCarbon, 900) : '';
        if ($posCarbon === false
            || strpos($carbonBlock, '$carbonMgr->set(ConfigManager::KEY_CARBON_ENABLED') === false
            || strpos($carbonBlock, '$carbonMgr->set(ConfigManager::KEY_CARBON_LINK') === false
        ) {
            $offenders[] = "neria.php : l'action save_carbon n'écrit plus via ConfigManager::set() — régression du bug corrigé le 08/08/2026 (round 134) : le bloc CO₂ pourrait de nouveau diverger entre boutiques selon celle où la config a été enregistrée";
        }

        // Round 134 (2026-08-08) : MultiClientPreviewManager::submitToLitmus()/
        // submitToEmailOnAcid() doivent capturer curl_error() et journaliser
        // via Watchdog, comme leurs pendants pollLitmus()/pollEmailOnAcid().
        $mcpFile = _PS_MODULE_DIR_ . $this->module->name . '/src/MultiClientPreviewManager.php';
        $mcpSrc = $this->readModuleSrc($mcpFile);
        if ($mcpSrc === '') {
            $offenders[] = 'MultiClientPreviewManager.php introuvable (garde-fou round 134 : submitTo*() capturent curl_error())';
        } else {
            $curlErrCount = substr_count($mcpSrc, '$curlErr  = curl_error($ch);');
            if ($curlErrCount < 4) {
                $offenders[] = "MultiClientPreviewManager : submitToLitmus()/submitToEmailOnAcid() ne capturent plus curl_error(\$ch) — régression du bug corrigé le 08/08/2026 (round 134) : un échec transport redeviendrait indiscernable d'une simple erreur HTTP inexploitable (attendu 4 occurrences avec pollLitmus()/pollEmailOnAcid(), trouvé {$curlErrCount})";
            }
        }

        // Round 134 (2026-08-08) : le badge "issues" du multi-preview
        // (neria.php) doit refléter count($detail), pas un comptage de
        // blocs <style> supprimés (sous-comptait les anomalies Outlook/
        // ProtonMail, neutralisées en styles inline).
        if (strpos($mainSrc134, "'issues' => count(\$detail)") === false) {
            $offenders[] = "neria.php : le badge 'issues' du multi-preview ne se base plus sur count(\$detail) — régression du bug corrigé le 08/08/2026 (round 134) : les anomalies inline (Outlook/ProtonMail) redeviendraient sous-comptées";
        }

        // Round 134 (2026-08-08) : l'action BO save_multipreview_keys doit
        // valider le format de la clé Email on Acid (présence de ':')
        // avant de l'enregistrer.
        $posEoaKeys = strpos($mainSrc134, "'neria_action') === 'save_multipreview_keys'");
        $eoaBlock = $posEoaKeys !== false ? substr($mainSrc134, $posEoaKeys, 900) : '';
        if ($posEoaKeys === false || strpos($eoaBlock, "strpos(\$eoaKey, ':') === false") === false) {
            $offenders[] = "neria.php : l'action save_multipreview_keys ne valide plus le format de la clé Email on Acid — régression du bug corrigé le 08/08/2026 (round 134) : une clé mal formée serait de nouveau enregistrée sans contrôle";
        }

        // Round 135 (2026-08-08) : VoiceProfileManager::textContainsWords()
        // doit ignorer les mots CJK d'un seul caractère (faux positifs
        // massifs par sous-chaîne), et saveProfile() doit dédupliquer/
        // plafonner la liste de mots avant écriture.
        $vpmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/VoiceProfileManager.php';
        $vpmSrc = $this->readModuleSrc($vpmFile);
        if ($vpmSrc === '') {
            $offenders[] = 'VoiceProfileManager.php introuvable (garde-fou round 135 : CJK 1 caractère ignoré + saveProfile() normalisé)';
        } else {
            if (strpos($vpmSrc, 'if (mb_strlen($word) < 2) {') === false) {
                $offenders[] = "VoiceProfileManager::textContainsWords() n'ignore plus les mots CJK d'un seul caractère — régression du bug corrigé le 08/08/2026 (round 135) : les faux positifs massifs sur CJK réapparaîtraient";
            }
            if (strpos($vpmSrc, 'private function normalizeWordListInput(string $raw): string') === false
                || strpos($vpmSrc, '$bannedWords    = $this->normalizeWordListInput($bannedWords);') === false
            ) {
                $offenders[] = "VoiceProfileManager::saveProfile() ne normalise plus (dédup/plafond) la liste de mots avant écriture — régression du bug corrigé le 08/08/2026 (round 135)";
            }
        }

        // Round 135 (2026-08-08) : SearchConsoleManager::apiGet()/apiPost()
        // doivent journaliser les échecs transport/JSON invalide, comme
        // PostmasterManager::apiGet() (round 131) — jamais appliqué ici
        // malgré la même famille de code (OAuth Google).
        $scmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php';
        $scmSrc = $this->readModuleSrc($scmFile);
        if ($scmSrc === '') {
            $offenders[] = 'SearchConsoleManager.php introuvable (garde-fou round 135 : apiGet()/apiPost() journalisent les échecs transport)';
        } elseif (substr_count($scmSrc, "'network error: '") < 2 || substr_count($scmSrc, "'invalid JSON response'") < 2) {
            $offenders[] = "SearchConsoleManager : apiGet()/apiPost() ne journalisent plus les échecs transport/JSON invalide — régression du bug corrigé le 08/08/2026 (round 135) : une panne réseau redeviendrait totalement silencieuse";
        }

        // Round 135 (2026-08-08) : PostmasterManager::httpPost() doit
        // capturer curl_error() et journaliser via Watchdog, comme
        // SearchConsoleManager::httpPost() déjà en place.
        $pmFile135 = _PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php';
        $pmSrc135 = $this->readModuleSrc($pmFile135);
        if ($pmSrc135 === '') {
            $offenders[] = 'PostmasterManager.php introuvable (garde-fou round 135 : httpPost() journalise les échecs transport)';
        } else {
            $posHttpPost = strpos($pmSrc135, 'private function httpPost(string $url, array $data): array');
            $httpPostBody = $posHttpPost !== false ? substr($pmSrc135, $posHttpPost, 1200) : '';
            if ($posHttpPost === false || strpos($httpPostBody, '$curlErr = curl_error($ch);') === false) {
                $offenders[] = "PostmasterManager::httpPost() ne capture plus curl_error(\$ch) — régression du bug corrigé le 08/08/2026 (round 135) : une panne réseau lors du rafraîchissement du token OAuth redeviendrait indiagnosticable";
            }
        }

        // Round 135 (2026-08-08) : GoldenHourManager::getRecommendations()
        // doit mettre en cache son résultat (navigation.tpl l'appelle sur
        // toute page admin, pas seulement l'onglet Statistiques).
        $ghmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/GoldenHourManager.php';
        $ghmSrc = $this->readModuleSrc($ghmFile);
        if ($ghmSrc === '') {
            $offenders[] = 'GoldenHourManager.php introuvable (garde-fou round 135 : getRecommendations() mis en cache)';
        } elseif (strpos($ghmSrc, 'private function computeRecommendations(int $days): array') === false
            || strpos($ghmSrc, 'CONFIG_CACHE_TIME') === false
        ) {
            $offenders[] = "GoldenHourManager::getRecommendations() n'est plus mis en cache — régression du bug corrigé le 08/08/2026 (round 135) : le LEFT JOIN sur 90 jours d'historique redeviendrait rejoué à chaque page admin";
        }

        // Round 136 (2026-08-08) : ManualSendManager::send()/scheduleManual()
        // doivent instancier BlacklistManager avec l'idShop explicite du
        // client, pas le contexte BO ambiant de l'opérateur.
        $msmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $msmSrc = $this->readModuleSrc($msmFile);
        if ($msmSrc === '') {
            $offenders[] = 'ManualSendManager.php introuvable (garde-fou round 136 : BlacklistManager scopé idShop client)';
        } elseif (strpos($msmSrc, 'new \BlacklistManager($idShop))') === false
            || strpos($msmSrc, 'new \BlacklistManager($idShopManual))') === false
        ) {
            $offenders[] = "ManualSendManager : send()/scheduleManual() n'instancient plus BlacklistManager avec l'idShop explicite du client — régression du bug corrigé le 08/08/2026 (round 136) : un opérateur en contexte d'une autre boutique pourrait de nouveau contourner (ou déclencher à tort) une règle de blacklist";
        }

        // Round 136 (2026-08-08) : BlacklistManager::isBlacklisted()/add()
        // doivent comparer/normaliser le nom du template de façon
        // insensible à la casse.
        $blmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/BlacklistManager.php';
        $blmSrc = $this->readModuleSrc($blmFile);
        if ($blmSrc === '') {
            $offenders[] = 'BlacklistManager.php introuvable (garde-fou round 136 : comparaison insensible à la casse)';
        } elseif (strpos($blmSrc, 'mb_strtolower($rule[\'template\'])') === false
            || strpos($blmSrc, 'pSQL(mb_strtolower(trim($template)))') === false
        ) {
            $offenders[] = "BlacklistManager : isBlacklisted()/add() ne normalisent plus la casse du template — régression du bug corrigé le 08/08/2026 (round 136)";
        }

        // Round 136 (2026-08-08) : ConfigManager::resetDesignConfig() ne
        // doit plus réinitialiser KEY_DESIGN_WIZARD_SEEN (pas un réglage de
        // design).
        $cfg3File = _PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php';
        $cfg3Src = $this->readModuleSrc($cfg3File);
        if ($cfg3Src === '') {
            $offenders[] = 'ConfigManager.php introuvable (garde-fou round 136 : resetDesignConfig() préserve KEY_DESIGN_WIZARD_SEEN)';
        } else {
            $posResetDesign = strpos($cfg3Src, 'public function resetDesignConfig(): bool');
            $resetBody = $posResetDesign !== false ? substr($cfg3Src, $posResetDesign, 900) : '';
            if ($posResetDesign === false || strpos($resetBody, 'self::KEY_DESIGN_WIZARD_SEEN,') !== false) {
                $offenders[] = "ConfigManager::resetDesignConfig() réinitialise de nouveau KEY_DESIGN_WIZARD_SEEN — régression du bug corrigé le 08/08/2026 (round 136) : le bandeau assistant réapparaîtrait à tort après un simple reset du Design";
            }
        }

        // Round 137 (2026-08-08) : CssInliner ne doit plus laisser fuiter le
        // PI XML en tête du HTML, doit respecter !important, et résoudre
        // les sélecteurs via un index de classes (pas une requête XPath par
        // règle — complexité quadratique).
        $cssFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CssInliner.php';
        $cssSrc = $this->readModuleSrc($cssFile);
        if ($cssSrc === '') {
            $offenders[] = 'CssInliner.php introuvable (garde-fou round 137 : PI XML/!important/index de classes)';
        } else {
            if (strpos($cssSrc, "preg_replace('/<\?xml encoding=\"utf-8\" \?' . '>\s*/'") === false) {
                $offenders[] = "CssInliner : le nettoyage du PI XML n'utilise plus la regex non ancrée — régression du bug corrigé le 08/08/2026 (round 137) : le PI XML redeviendrait visible en tête de tous les emails envoyés";
            }
            if (strpos($cssSrc, "preg_match('/!\s*important\s*\$/i', \$v)") === false) {
                $offenders[] = "CssInliner::merge() ne détecte plus !important — régression du bug corrigé le 08/08/2026 (round 137) : la cascade CSS standard serait de nouveau violée";
            }
            if (strpos($cssSrc, "\$xpath->query('//*[@class]')") === false
                || strpos($cssSrc, 'private static function resolveSelector(') === false
            ) {
                $offenders[] = "CssInliner ne construit plus l'index de classes en une seule passe — régression du bug corrigé le 08/08/2026 (round 137) : le comportement quadratique (une requête XPath par règle CSS) pourrait réapparaître";
            }
        }

        // Round 137 (2026-08-08) : CollectionManager::getProductImageUrl()
        // doit commuter le contexte boutique statique via Shop::setContext()
        // avant Product::getCover(), et runDailyCheck() doit instancier le
        // produit manquant avec l'idShop explicite.
        $colFile = _PS_MODULE_DIR_ . $this->module->name . '/src/CollectionManager.php';
        $colSrc = $this->readModuleSrc($colFile);
        if ($colSrc === '') {
            $offenders[] = 'CollectionManager.php introuvable (garde-fou round 137 : Shop::setContext() + Product idShop)';
        } else {
            $posGPI = strpos($colSrc, 'private function getProductImageUrl(int $idProduct, int $idLang, int $idShop): string');
            $gpiBody = $posGPI !== false ? substr($colSrc, $posGPI, 1600) : '';
            if ($posGPI === false || strpos($gpiBody, 'Shop::setContext(\Shop::CONTEXT_SHOP, $idShop)') === false) {
                $offenders[] = "CollectionManager::getProductImageUrl() ne commute plus le contexte boutique statique via Shop::setContext() avant Product::getCover() — régression du bug corrigé le 08/08/2026 (round 137)";
            }
            if (strpos($colSrc, 'new \Product($missingId, false, $idLang, $idShop)') === false) {
                $offenders[] = "CollectionManager::runDailyCheck() n'instancie plus Product avec l'idShop explicite pour le produit manquant — régression du bug corrigé le 08/08/2026 (round 137)";
            }
        }

        // Round 137 (2026-08-08) : neria.php::abtestBelongsToShop() doit
        // accepter un paramètre $template, et restore_variant_b doit
        // l'utiliser pour empêcher d'écraser la variante B d'un autre
        // template.
        $mainSrc137 = file_get_contents(_PS_MODULE_DIR_ . $this->module->name . '/neria.php') ?: '';
        if (strpos($mainSrc137, 'private function abtestBelongsToShop(int $idAbtest, string $template = \'\'): bool') === false) {
            $offenders[] = "neria.php : abtestBelongsToShop() n'accepte plus le paramètre \$template — régression du bug corrigé le 08/08/2026 (round 137) : restore_variant_b pourrait de nouveau écraser la variante B d'un autre template";
        }
        if (strpos($mainSrc137, 'abtestBelongsToShop($idAbtestB, $tplKey)') === false) {
            $offenders[] = "neria.php : restore_variant_b n'appelle plus abtestBelongsToShop() avec \$tplKey — régression du bug corrigé le 08/08/2026 (round 137)";
        }

        // Round 138 (2026-08-08) : WaitlistManager::notifyProduct() doit
        // commuter le contexte boutique statique via Shop::setContext()
        // avant Product/getCover() — même piège que CollectionManager
        // (round 137), qui n'avait lui-même jamais reçu le correctif
        // complet malgré des commentaires le citant comme référence.
        $wlmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php';
        $wlmSrc = $this->readModuleSrc($wlmFile);
        if ($wlmSrc === '') {
            $offenders[] = 'WaitlistManager.php introuvable (garde-fou round 138 : Shop::setContext() + Product idShop)';
        } else {
            $posNP = strpos($wlmSrc, 'public function notifyProduct(');
            $npBody = $posNP !== false ? substr($wlmSrc, $posNP, 9500) : '';
            if ($posNP === false
                || strpos($npBody, 'Shop::setContext(\Shop::CONTEXT_SHOP, $idShop)') === false
                || strpos($npBody, 'new \Product($idProduct, false, $idLang, $idShop)') === false
            ) {
                $offenders[] = "WaitlistManager::notifyProduct() ne commute plus le contexte boutique statique via Shop::setContext() avant Product/getCover() — régression du bug corrigé le 08/08/2026 (round 138)";
            }
        }

        // Round 138 (2026-08-08) : EmailRenderer::isInternalEmail() doit
        // résoudre PS_SHOP_EMAIL avec l'idShop explicite, et
        // resolveSignature()/injectSignatureVars() doivent recevoir l'idShop
        // du destinataire réel, pas le contexte d'exécution ambiant.
        $emrFile = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $emrSrc = $this->readModuleSrc($emrFile);
        if ($emrSrc === '') {
            $offenders[] = 'EmailRenderer.php introuvable (garde-fou round 138 : isInternalEmail()/signature scopés idShop)';
        } else {
            if (strpos($emrSrc, "\\Configuration::get('PS_SHOP_EMAIL', null, null, \$idShop)") === false) {
                $offenders[] = "EmailRenderer::isInternalEmail() ne résout plus PS_SHOP_EMAIL avec l'idShop explicite — régression du bug corrigé le 08/08/2026 (round 138)";
            }
            if (strpos($emrSrc, 'private function resolveSignature(int $idShop): array') === false) {
                $offenders[] = "EmailRenderer::resolveSignature() n'accepte plus l'idShop explicite en paramètre — régression du bug corrigé le 08/08/2026 (round 138) : la signature manuscrite pourrait de nouveau fuiter entre boutiques";
            }
            if (strpos($emrSrc, '$idShopFallback = $this->resolveShopId($params);') === false) {
                $offenders[] = "EmailRenderer : le chemin fallback ne calcule plus \$idShopFallback via resolveShopId(\$params) — régression du bug corrigé le 08/08/2026 (round 138)";
            }
        }

        // Round 138 (2026-08-08) : TranslationHistoryManager doit verrouiller
        // record()/pruneKey() via GET_LOCK, et ne plus filtrer par id_shop
        // (neria_translation elle-même est globale, sans colonne id_shop).
        $thmFile = _PS_MODULE_DIR_ . $this->module->name . '/src/TranslationHistoryManager.php';
        $thmSrc = $this->readModuleSrc($thmFile);
        if ($thmSrc === '') {
            $offenders[] = 'TranslationHistoryManager.php introuvable (garde-fou round 138 : verrou + visibilité globale)';
        } else {
            if (strpos($thmSrc, "GET_LOCK('") === false || strpos($thmSrc, "RELEASE_LOCK('") === false) {
                $offenders[] = "TranslationHistoryManager::record() n'utilise plus GET_LOCK/RELEASE_LOCK — régression du bug corrigé le 08/08/2026 (round 138)";
            }
            $posGHFT = strpos($thmSrc, 'public function getHistoryForTemplate(');
            $ghftBody = $posGHFT !== false ? substr($thmSrc, $posGHFT, 500) : '';
            if ($posGHFT === false || strpos($ghftBody, 'id_shop') !== false) {
                $offenders[] = "TranslationHistoryManager::getHistoryForTemplate() filtre de nouveau par id_shop — régression du bug corrigé le 08/08/2026 (round 138) : l'historique d'une traduction (globale) redeviendrait invisible depuis les autres boutiques";
            }
        }

        // Round 139 (2026-08-08) : neria.php::hookActionUpdateQuantity()
        // doit être enveloppé par NeriaErrorHandler::wrapHookVoid(), comme
        // tous les autres hooks du fichier.
        $mainSrc139 = file_get_contents(_PS_MODULE_DIR_ . $this->module->name . '/neria.php') ?: '';
        $posHUQ = strpos($mainSrc139, 'public function hookActionUpdateQuantity(array $params): void');
        $huqBody = $posHUQ !== false ? substr($mainSrc139, $posHUQ, 1200) : '';
        if ($posHUQ === false || strpos($huqBody, "NeriaErrorHandler::wrapHookVoid('hookActionUpdateQuantity'") === false) {
            $offenders[] = "neria.php : hookActionUpdateQuantity() n'est plus enveloppé par NeriaErrorHandler::wrapHookVoid() — régression du bug corrigé le 08/08/2026 (round 139) : une exception avant le try/catch interne pourrait de nouveau casser le tunnel de commande";
        }

        // Round 139 (2026-08-08) : TranslationInstaller::flushBatch() ne
        // doit exécuter SET NAMES qu'une seule fois par opération d'import
        // (flag charsetSet), pas à chaque lot.
        $tiFile = _PS_MODULE_DIR_ . $this->module->name . '/src/TranslationInstaller.php';
        $tiSrc = $this->readModuleSrc($tiFile);
        if ($tiSrc === '') {
            $offenders[] = 'TranslationInstaller.php introuvable (garde-fou round 139 : SET NAMES une seule fois)';
        } elseif (strpos($tiSrc, 'private bool $charsetSet = false;') === false || strpos($tiSrc, 'if (!$this->charsetSet) {') === false) {
            $offenders[] = "TranslationInstaller::flushBatch() n'utilise plus le flag charsetSet — régression du bug corrigé le 08/08/2026 (round 139) : SET NAMES redeviendrait exécuté à chaque lot d'import";
        }

        // Round 140 (2026-09-08) : TranslationInstaller::importTemplate() ne
        // doit plus valider (COMMIT) la suppression des traductions par
        // défaut d'un template si le batch de réinsertion est vide, et
        // importFromJson()/importTemplate() doivent réinitialiser leurs
        // compteurs (début d'appel + après ROLLBACK).
        $ti2File = _PS_MODULE_DIR_ . $this->module->name . '/src/TranslationInstaller.php';
        $ti2Src = $this->readModuleSrc($ti2File);
        if ($ti2Src === '') {
            $offenders[] = 'TranslationInstaller.php introuvable (garde-fou round 140 : batch vide non commité + compteurs réinitialisés)';
        } else {
            $posIT = strpos($ti2Src, 'public function importTemplate(string $jsonPath, string $template): bool');
            $itBody = $posIT !== false ? substr($ti2Src, $posIT, 5200) : '';
            if ($posIT === false || strpos($itBody, '$ok = !$batchWasEmpty && $this->flushBatch($batch);') === false) {
                $offenders[] = "TranslationInstaller::importTemplate() ne protège plus contre un batch vide — régression du bug corrigé le 09/08/2026 (round 140) : le DELETE des traductions par défaut pourrait de nouveau être validé sans réinsertion, perte de données silencieuse";
            }
            $posIFJ = strpos($ti2Src, 'public function importFromJson(string $jsonPath): bool');
            $ifjBody = $posIFJ !== false ? substr($ti2Src, $posIFJ, 700) : '';
            if ($posIFJ === false || strpos($ifjBody, '$this->countInserted = 0;') === false) {
                $offenders[] = "TranslationInstaller::importFromJson() ne réinitialise plus les compteurs en début d'appel — régression du bug corrigé le 09/08/2026 (round 140)";
            }
            if ($posIT === false || strpos($itBody, '$this->countInserted = 0;') === false) {
                $offenders[] = "TranslationInstaller::importTemplate() ne réinitialise plus les compteurs en début d'appel — régression du bug corrigé le 09/08/2026 (round 140) : le rapport BO redeviendrait cumulatif si l'instance est réutilisée";
            }
        }

        // Round 141 (2026-09-09) : ConfigManager::deleteAll() et
        // resetTimeGreetings(null) doivent rester scopés à la boutique
        // courante via Configuration::deleteFromContext($key, null,
        // $this->idShop) — pas Configuration::deleteByName(), qui efface la
        // clé pour TOUTES les boutiques.
        $cfgFile141 = _PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php';
        $cfgSrc141 = $this->readModuleSrc($cfgFile141);
        if ($cfgSrc141 === '') {
            $offenders[] = 'ConfigManager.php introuvable (garde-fou round 141 : deleteAll/resetTimeGreetings scopés par boutique)';
        } else {
            $posDeleteAll = strpos($cfgSrc141, 'public function deleteAll(): bool');
            $deleteAllBody = $posDeleteAll !== false ? substr($cfgSrc141, $posDeleteAll, 700) : '';
            if ($posDeleteAll === false || strpos($deleteAllBody, '\Configuration::deleteFromContext($key, null, $this->idShop)') === false) {
                $offenders[] = "ConfigManager::deleteAll() n'utilise plus deleteFromContext() scopé par boutique — régression du bug corrigé le 09/08/2026 (round 141) : réinitialiser Neria depuis une boutique effacerait de nouveau la config des autres boutiques";
            }
            if (strpos($deleteAllBody, 'Configuration::deleteByName($key)') !== false) {
                $offenders[] = "ConfigManager::deleteAll() utilise de nouveau Configuration::deleteByName() (non scopé) — régression du bug corrigé le 09/08/2026 (round 141)";
            }

            $posResetTG = strpos($cfgSrc141, 'public function resetTimeGreetings(?string $lang = null): bool');
            $resetTGBody = $posResetTG !== false ? substr($cfgSrc141, $posResetTG, 500) : '';
            if ($posResetTG === false || strpos($resetTGBody, '\Configuration::deleteFromContext(self::KEY_TIME_GREETINGS, null, $this->idShop)') === false) {
                $offenders[] = "ConfigManager::resetTimeGreetings(null) n'utilise plus deleteFromContext() scopé par boutique — régression du bug corrigé le 09/08/2026 (round 141) : réinitialiser les salutations horaires d'une boutique effacerait de nouveau celles des autres boutiques";
            }

            $posToggle = strpos($cfgSrc141, 'private function toggleBooleanKey(string $key, string $getter, string $setter): bool');
            $toggleBody = $posToggle !== false ? substr($cfgSrc141, $posToggle, 900) : '';
            if ($posToggle === false || strpos($toggleBody, '$gotLock = (int) $db->getValue(') === false || strpos($toggleBody, 'if ($gotLock !== 1) {') === false) {
                $offenders[] = "ConfigManager::toggleBooleanKey() ne vérifie plus le retour de GET_LOCK() — régression du bug corrigé le 09/08/2026 (round 141) : sous contention DB, la protection anti-course deviendrait de nouveau inopérante sans que rien ne le signale";
            }

            if (strpos($cfgSrc141, 'self::KEY_DEEPL_KEY') === false || strpos($cfgSrc141, 'self::KEY_DEEPL_KEY                 => \'\',') === false) {
                $offenders[] = "ConfigManager::DEFAULTS n'inclut plus KEY_DEEPL_KEY — régression du bug corrigé le 09/08/2026 (round 141) : la clé API DeepL redeviendrait orpheline en base après deleteAll()";
            }
        }

        // Round 141 (2026-09-09) : checkKnownRegressionsGuard() (ce contrôle
        // lui-même) ne doit plus tourner de façon synchrone dans le chemin
        // de rendu du visiteur front — buildAllChecks(false) doit réutiliser
        // le dernier résultat connu au lieu de recalculer, et readModuleSrc()
        // doit mettre les lectures de fichiers en cache pour éviter les
        // relectures redondantes constatées (jusqu'à 9x un même fichier par
        // exécution).
        $selfFile141 = _PS_MODULE_DIR_ . $this->module->name . '/src/HealthCheckManager.php';
        $selfSrc141 = $this->readModuleSrc($selfFile141);
        if ($selfSrc141 === '') {
            $offenders[] = 'HealthCheckManager.php introuvable (garde-fou round 141 : scan lourd non bloquant + cache de lecture)';
        } else {
            if (strpos($selfSrc141, 'private array $srcCache = [];') === false || strpos($selfSrc141, 'private function readModuleSrc(string $file): string') === false) {
                $offenders[] = "HealthCheckManager::readModuleSrc()/\$srcCache introuvables — régression du bug corrigé le 09/08/2026 (round 141) : checkKnownRegressionsGuard() relirait de nouveau plusieurs fois les mêmes fichiers par exécution";
            }
            $posBuildAll = strpos($selfSrc141, 'private function buildAllChecks(bool $allowHeavyScans = true): array');
            if ($posBuildAll === false) {
                $offenders[] = "HealthCheckManager::buildAllChecks() n'accepte plus de paramètre \$allowHeavyScans — régression du bug corrigé le 09/08/2026 (round 141) : known_regressions_guard() redeviendrait toujours ré-exécuté, y compris dans le chemin de rendu front";
            } else {
                $buildAllBody = substr($selfSrc141, $posBuildAll, 700);
                if (strpos($buildAllBody, "!\$allowHeavyScans && \$key === 'known_regressions_guard'") === false) {
                    $offenders[] = "HealthCheckManager::buildAllChecks() ne saute plus known_regressions_guard() en mode léger — régression du bug corrigé le 09/08/2026 (round 141) : le scan lourd (~150 lectures de fichiers) bloquerait de nouveau la première requête d'un visiteur front après expiration du throttle 24h";
                }
            }
            if (strpos($selfSrc141, 'public function runAutoChecksIfDue(bool $allowHeavyScans = false): void') === false) {
                $offenders[] = "HealthCheckManager::runAutoChecksIfDue() n'a plus \$allowHeavyScans=false par défaut — régression du bug corrigé le 09/08/2026 (round 141) : le déclenchement passif front (hookDisplayHeader) autoriserait de nouveau le scan lourd par défaut";
            }
        }
        $mainFile141 = _PS_MODULE_DIR_ . $this->module->name . '/' . $this->module->name . '.php';
        $mainSrc141 = $this->readModuleSrc($mainFile141);
        if ($mainSrc141 !== '' && strpos($mainSrc141, 'public function runBackgroundJobs(bool $allowHeavyScans = false): array') === false) {
            $offenders[] = "neria.php::runBackgroundJobs() n'a plus \$allowHeavyScans=false par défaut — régression du bug corrigé le 09/08/2026 (round 141) : hookDisplayHeader() autoriserait de nouveau le scan lourd sur chaque visiteur front";
        }
        $cronFile141 = _PS_MODULE_DIR_ . $this->module->name . '/controllers/front/cron.php';
        $cronSrc141 = $this->readModuleSrc($cronFile141);
        if ($cronSrc141 !== '' && strpos($cronSrc141, 'runBackgroundJobs(true)') === false) {
            $offenders[] = "controllers/front/cron.php n'appelle plus runBackgroundJobs(true) — régression du bug corrigé le 09/08/2026 (round 141) : le scan lourd known_regressions_guard() ne s'exécuterait plus jamais, même via le vrai cron serveur";
        }

        // Round 142 (2026-09-09) : CalendarManager::getManualOverride()/
        // setManualOverride()/deleteManualOverride()/getDateSource()/
        // getAllManualOverrides() doivent rester scopés par boutique via un
        // suffixe `_SHOP{idShop}` dans la clé (même correctif que
        // buildSentKey(), round 40) — sans lui, un override marchand pour
        // une boutique est silencieusement ignoré si le contexte statique
        // Shop::$context_id_shop est resté figé sur une autre boutique
        // pendant une boucle multi-boutique.
        $calFile142 = _PS_MODULE_DIR_ . $this->module->name . '/src/CalendarManager.php';
        $calSrc142 = $this->readModuleSrc($calFile142);
        if ($calSrc142 === '') {
            $offenders[] = 'CalendarManager.php introuvable (garde-fou round 142 : overrides manuels scopés boutique + traçabilité suppression + repli année N+1)';
        } else {
            $posGetOv = strpos($calSrc142, 'private function getManualOverride(');
            $getOvBody = $posGetOv !== false ? substr($calSrc142, $posGetOv, 1000) : '';
            if ($posGetOv === false || strpos($getOvBody, "'_SHOP' . \$this->idShop") === false) {
                $offenders[] = "CalendarManager::getManualOverride() n'inclut plus le suffixe boutique dans sa clé de config — régression du bug corrigé le 09/08/2026 (round 142) : un override marchand pourrait de nouveau être silencieusement ignoré pendant une boucle multi-boutique";
            }
            $posSetOv = strpos($calSrc142, 'public function setManualOverride(');
            $setOvBody = $posSetOv !== false ? substr($calSrc142, $posSetOv, 700) : '';
            if ($posSetOv === false || strpos($setOvBody, "'_SHOP' . \$this->idShop") === false) {
                $offenders[] = "CalendarManager::setManualOverride() n'inclut plus le suffixe boutique dans sa clé de config — régression du bug corrigé le 09/08/2026 (round 142)";
            }
            $posDelOv = strpos($calSrc142, 'public function deleteManualOverride(string $eventKey, int $year): void');
            $delOvBody = $posDelOv !== false ? substr($calSrc142, $posDelOv, 900) : '';
            if ($posDelOv === false || strpos($delOvBody, "'_SHOP' . \$this->idShop") === false) {
                $offenders[] = "CalendarManager::deleteManualOverride() n'inclut plus le suffixe boutique dans sa clé de config — régression du bug corrigé le 09/08/2026 (round 142)";
            }
            if ($posDelOv === false || strpos($delOvBody, '$this->watchdog()->info(') === false) {
                $offenders[] = "CalendarManager::deleteManualOverride() ne journalise plus la suppression via Watchdog — régression du bug corrigé le 09/08/2026 (round 142) : trou de traçabilité, aucune trace ne relierait un problème de calcul automatique à cette suppression";
            }
            $posAllOv = strpos($calSrc142, 'public function getAllManualOverrides(): array');
            $allOvBody = $posAllOv !== false ? substr($calSrc142, $posAllOv, 900) : '';
            if ($posAllOv === false || strpos($allOvBody, '_SHOP" . (int) $this->idShop') === false) {
                $offenders[] = "CalendarManager::getAllManualOverrides() ne filtre plus par suffixe boutique — régression du bug corrigé le 09/08/2026 (round 142) : le tableau BO d'une boutique remélangerait les overrides de toutes les boutiques";
            }
            $posDisp = strpos($calSrc142, 'public function getEventDisplayInfo(array $event): array');
            $dispBody = $posDisp !== false ? substr($calSrc142, $posDisp, 1700) : '';
            if ($posDisp === false || strpos($dispBody, 'if (!$eventDate) {') === false) {
                $offenders[] = "CalendarManager::getEventDisplayInfo() ne teste plus explicitement le cas où la résolution de l'année courante renvoie null — régression du bug corrigé le 09/08/2026 (round 142) : incohérence d'affichage BO avec getUpcomingDates()/processEvent()";
            }
        }

        // Round 142 (2026-09-09) : WatchdogManager::shopLang() doit accepter
        // un idShop explicite (sinon retombe sur le static
        // Shop::$context_id_shop, figé sur la boutique d'origine pendant
        // une boucle multi-boutique) et ses 7 appelants externes connus
        // doivent le lui fournir.
        $wdFile142 = _PS_MODULE_DIR_ . $this->module->name . '/src/WatchdogManager.php';
        $wdSrc142 = $this->readModuleSrc($wdFile142);
        if ($wdSrc142 === '') {
            $offenders[] = 'WatchdogManager.php introuvable (garde-fou round 142 : shopLang() idShop explicite)';
        } elseif (strpos($wdSrc142, 'public static function shopLang(?int $idShop = null): string') === false) {
            $offenders[] = "WatchdogManager::shopLang() n'accepte plus de paramètre \$idShop optionnel — régression du bug corrigé le 09/08/2026 (round 142) : les messages Watchdog composés pendant une boucle multi-boutique pourraient de nouveau être dans la mauvaise langue";
        }
        $shopLangCallers142 = [
            'src/ABTestManager.php'        => '\WatchdogManager::shopLang($this->idShop)',
            'src/EmailRenderer.php'        => 'WatchdogManager::shopLang((int) \Context::getContext()->shop->id)',
            'src/PostmasterManager.php'    => '\WatchdogManager::shopLang((int) \Context::getContext()->shop->id)',
            'src/SearchConsoleManager.php' => '\WatchdogManager::shopLang((int) \Context::getContext()->shop->id)',
        ];
        foreach ($shopLangCallers142 as $relPath => $expectedCall) {
            $callerSrc = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/' . $relPath);
            if ($callerSrc !== '' && strpos($callerSrc, $expectedCall) === false) {
                $offenders[] = basename($relPath) . " n'appelle plus WatchdogManager::shopLang() avec un idShop explicite — régression du bug corrigé le 09/08/2026 (round 142)";
            }
        }
        foreach (['src/PageSpeedManager.php', 'src/SeoApiManager.php'] as $relPath) {
            $callerSrc = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/' . $relPath);
            if ($callerSrc !== '' && substr_count($callerSrc, 'WatchdogManager::shopLang((int) \Context::getContext()->shop->id)') < 2) {
                $offenders[] = basename($relPath) . " : moins de 2 appels à WatchdogManager::shopLang() avec un idShop explicite — régression du bug corrigé le 09/08/2026 (round 142)";
            }
        }

        // Round 143 (2026-09-10) : SeasonalCampaignManager::runDueCampaigns()
        // doit réserver l'envoi via claimSend() AVANT Mail::Send() (compare-
        // and-swap), pas un SELECT COUNT(*) suivi d'un INSERT après coup —
        // même correctif que CollectionManager::claimSend()/
        // LookCompletionManager (rounds 63/87). countEligible() doit aussi
        // filtrer les préférences, pas seulement le ciblage.
        $seasFile143 = _PS_MODULE_DIR_ . $this->module->name . '/src/SeasonalCampaignManager.php';
        $seasSrc143 = $this->readModuleSrc($seasFile143);
        if ($seasSrc143 === '') {
            $offenders[] = 'SeasonalCampaignManager.php introuvable (garde-fou round 143 : claim/release atomique + countEligible filtré préférences)';
        } else {
            if (strpos($seasSrc143, 'private function claimSend(int $idCustomer, string $sentKey, int $year): bool') === false) {
                $offenders[] = "SeasonalCampaignManager::claimSend() introuvable — régression du bug corrigé le 09/08/2026 (round 143) : la réservation atomique anti-doublon a disparu, un client pourrait de nouveau recevoir deux fois la même campagne saisonnière";
            }
            $posRun = strpos($seasSrc143, 'public function runDueCampaigns(): int');
            $runBody = $posRun !== false ? substr($seasSrc143, $posRun, 4200) : '';
            if ($posRun === false || strpos($runBody, 'if (!$this->claimSend($idCustomer, $sentKey, $year)) {') === false) {
                $offenders[] = "SeasonalCampaignManager::runDueCampaigns() n'appelle plus claimSend() avant l'envoi — régression du bug corrigé le 09/08/2026 (round 143)";
            }
            $posCount = strpos($seasSrc143, 'public function countEligible(array $campaign): int');
            // Round 185 : fenêtre élargie 900→1400 — l'ajout de l'override
            // gift_mode → target_segment (même correctif que
            // runDueCampaigns()) a repoussé l'appel isAllowed() plus loin.
            $countBody = $posCount !== false ? substr($seasSrc143, $posCount, 1400) : '';
            if ($posCount === false || strpos($countBody, "\$prefs->isAllowed((int) \$customer['id_customer'], \$template, \$this->idShop)") === false) {
                $offenders[] = "SeasonalCampaignManager::countEligible() ne filtre plus les préférences — régression du bug corrigé le 09/08/2026 (round 143) : l'aperçu BO surestimerait de nouveau le nombre réel de destinataires";
            }
        }

        // Round 143 (2026-09-10) : CollectionManager::resolveProducts() doit
        // filtrer pl.id_shop + p.active, comme sa jumelle searchProducts().
        $colFile143 = _PS_MODULE_DIR_ . $this->module->name . '/src/CollectionManager.php';
        $colSrc143 = $this->readModuleSrc($colFile143);
        if ($colSrc143 === '') {
            $offenders[] = 'CollectionManager.php introuvable (garde-fou round 143 : resolveProducts() scopé boutique)';
        } else {
            $posResolve = strpos($colSrc143, 'private static function resolveProducts(array $ids, int $idLang, int $idShop): array');
            $resolveBody = $posResolve !== false ? substr($colSrc143, $posResolve, 900) : '';
            if ($posResolve === false || strpos($resolveBody, 'AND pl.id_shop = " . (int) $idShop . "') === false || strpos($resolveBody, 'AND p.active = 1') === false) {
                $offenders[] = "CollectionManager::resolveProducts() n'accepte plus \$idShop ou ne filtre plus p.active — régression du bug corrigé le 09/08/2026 (round 143) : le nom/référence affiché dans l'écran BO Collections pourrait de nouveau être celui d'une autre boutique";
            }
        }

        // Round 143 (2026-09-10) : ManualSendManager::scheduleManual() doit
        // valider le format ET la cohérence temporelle de $sendAt avant de
        // planifier — même garde-fou que CalendarManager::setManualOverride().
        $msFile143 = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $msSrc143 = $this->readModuleSrc($msFile143);
        if ($msSrc143 === '') {
            $offenders[] = 'ManualSendManager.php introuvable (garde-fou round 143 : scheduleManual() date validée)';
        } elseif (strpos($msSrc143, "\$sendAtDt = \DateTime::createFromFormat('Y-m-d H:i:s', \$sendAt);") === false
            || strpos($msSrc143, 'if ($sendAtDt <= new \DateTime()) {') === false
        ) {
            $offenders[] = "ManualSendManager::scheduleManual() ne valide plus le format/la cohérence temporelle de \$sendAt — régression du bug corrigé le 09/08/2026 (round 143) : une date malformée ou déjà passée pourrait de nouveau déclencher un envoi immédiat non désiré";
        }

        // Round 143 (2026-09-10) : ClvManager::computeClv()/assembleClv()
        // doivent traiter $avgOrder <= 0.0 explicitement en 'low' avant le
        // test de seuil 'high'.
        $clvFile143 = _PS_MODULE_DIR_ . $this->module->name . '/src/ClvManager.php';
        $clvSrc143 = $this->readModuleSrc($clvFile143);
        if ($clvSrc143 === '') {
            $offenders[] = 'ClvManager.php introuvable (garde-fou round 143 : label CLV avgOrder=0)';
        } else {
            $guardCount143 = substr_count($clvSrc143, 'if ($avgOrder <= 0.0) {');
            if ($guardCount143 < 2) {
                $offenders[] = "ClvManager : le garde-fou \$avgOrder <= 0.0 n'est plus présent dans computeClv() et assembleClv() ({$guardCount143}/2) — régression du bug corrigé le 09/08/2026 (round 143) : un client à CLV=0€ (remboursement total) serait de nouveau étiqueté 'high value'";
            }
        }

        // Round 143 (2026-09-10) : ChurnScoreManager::computeScore() doit
        // clamper rate1/2/3 à 1.0 (un même email peut générer plusieurs
        // 'open' pour un seul 'sent').
        $churnFile143 = _PS_MODULE_DIR_ . $this->module->name . '/src/ChurnScoreManager.php';
        $churnSrc143 = $this->readModuleSrc($churnFile143);
        if ($churnSrc143 === '') {
            $offenders[] = 'ChurnScoreManager.php introuvable (garde-fou round 143 : rate1/2/3 clampés)';
        } else {
            $posCompute143 = strpos($churnSrc143, 'private function computeScore(array $r): array');
            $computeBody143 = $posCompute143 !== false ? substr($churnSrc143, $posCompute143, 900) : '';
            if ($posCompute143 === false || substr_count($computeBody143, 'min(1.0, (int) $r[') < 3) {
                $offenders[] = "ChurnScoreManager::computeScore() ne clampe plus rate1/2/3 à 1.0 — régression du bug corrigé le 09/08/2026 (round 143) : la composante 'Taux récent' pourrait de nouveau sortir de sa plage documentée [0, 30]";
            }
        }

        // Round 144 (2026-09-11) : GdprAuditManager::purgeCustomerData() ne
        // doit plus matcher le payload webhook par LIKE '%email%' (sous-
        // chaîne non ancrée) mais décoder chaque payload et comparer les
        // valeurs EXACTES (customer_id en priorité).
        // encryptExistingRecords() doit rester scopé id_shop. Le rapport PDF
        // doit compter 4 axes (+1 de plus qu'avant) dans son dénominateur.
        $gdprFile144 = _PS_MODULE_DIR_ . $this->module->name . '/src/GdprAuditManager.php';
        $gdprSrc144 = $this->readModuleSrc($gdprFile144);
        if ($gdprSrc144 === '') {
            $offenders[] = 'GdprAuditManager.php introuvable (garde-fou round 144 : purge webhook exacte + encryptExistingRecords scopé + dénominateur rapport)';
        } else {
            if (strpos($gdprSrc144, "(int) (\$decoded['customer_id'] ?? 0) === \$idCustomer") === false) {
                $offenders[] = "GdprAuditManager::purgeCustomerData() ne compare plus le customer_id exact du payload webhook — régression du bug corrigé le 09/08/2026 (round 144) : un LIKE '%email%' non ancré purgerait de nouveau les données d'un tiers non consentant sur simple coïncidence de sous-chaîne";
            }
            $posEncrypt144 = strpos($gdprSrc144, 'public function encryptExistingRecords(): int');
            $encryptBody144 = $posEncrypt144 !== false ? substr($gdprSrc144, $posEncrypt144, 2200) : '';
            if ($posEncrypt144 === false || strpos($encryptBody144, "WHERE `id_shop` = \" . (int) \$this->idShop . \"") === false) {
                $offenders[] = "GdprAuditManager::encryptExistingRecords() n'est plus scopé par boutique — régression du bug corrigé le 09/08/2026 (round 144) : une action déclenchée depuis une boutique chiffrerait de nouveau les données de toutes les autres";
            }
            if (strpos($gdprSrc144, "count(\$audit['retention']['rows']) + 3 + 1 + 1") === false) {
                $offenders[] = "GdprAuditManager::generateReport() n'affiche plus le dénominateur correct (4 axes) — régression du bug corrigé le 09/08/2026 (round 144) : le rapport PDF de conformité afficherait de nouveau un total de critères incohérent avec le numérateur";
            }
        }

        // Round 144 (2026-09-11) : WebhookManager::cleanup() doit être
        // appelée AVANT les return précoces de validation URL/secret (pas
        // seulement dans le finally qui les suit), et processQueue() doit
        // isoler chaque ligne du lot dans son propre try/catch.
        $whFile144 = _PS_MODULE_DIR_ . $this->module->name . '/src/WebhookManager.php';
        $whSrc144 = $this->readModuleSrc($whFile144);
        if ($whSrc144 === '') {
            $offenders[] = 'WebhookManager.php introuvable (garde-fou round 144 : cleanup atteignable + try/catch par ligne)';
        } else {
            $posPQ144 = strpos($whSrc144, 'public function processQueue(): void');
            $posCleanupCall144 = strpos($whSrc144, 'if (random_int(1, 10) === 1) {', $posPQ144);
            $posUrlReturn144 = strpos($whSrc144, "if (\$url === '' || !self::isPublicUrl(\$url)) {", $posPQ144);
            if ($posPQ144 === false || $posCleanupCall144 === false || $posUrlReturn144 === false || $posCleanupCall144 > $posUrlReturn144) {
                $offenders[] = "WebhookManager::processQueue() n'appelle plus cleanup() avant ses return précoces de validation URL/secret — régression du bug corrigé le 09/08/2026 (round 144) : cleanup() redeviendrait inatteignable si la clé de chiffrement maîtresse devient illisible durablement, ps_neria_webhook_queue croîtrait sans borne";
            }
            $loopBody144 = $posPQ144 !== false ? substr($whSrc144, strpos($whSrc144, 'foreach ($rows as $row) {', $posPQ144), 3600) : '';
            if (strpos($loopBody144, 'watchdog.webhook_row_exception') === false) {
                $offenders[] = "WebhookManager::processQueue() n'isole plus chaque ligne du lot dans son propre try/catch — régression du bug corrigé le 09/08/2026 (round 144) : une exception sur une ligne interromprait de nouveau le traitement de tout le reste du lot";
            }
        }

        // Round 144 (2026-09-11) : DomainReputationManager doit invalider
        // son cache au changement d'expéditeur, checkDkim()/checkPtr()
        // doivent signaler une troncature par budget DNS (timed_out).
        $drFile144 = _PS_MODULE_DIR_ . $this->module->name . '/src/DomainReputationManager.php';
        $drSrc144 = $this->readModuleSrc($drFile144);
        if ($drSrc144 === '') {
            $offenders[] = 'DomainReputationManager.php introuvable (garde-fou round 144 : cache invalidé + DKIM/PTR timed_out)';
        } else {
            if (strpos($drSrc144, 'public static function invalidateCache(int $idShop): void') === false) {
                $offenders[] = "DomainReputationManager::invalidateCache() introuvable — régression du bug corrigé le 09/08/2026 (round 144) : le tableau de bord afficherait de nouveau jusqu'à 24h le score de l'ancien expéditeur après un changement";
            }
            if (strpos($drSrc144, "'record' => null, 'timed_out' => true];") === false) {
                $offenders[] = "DomainReputationManager::checkDkim() ne signale plus 'timed_out' — régression du bug corrigé le 09/08/2026 (round 144) : une troncature DNS serait de nouveau traitée comme un DKIM absent après vérification complète";
            }
            $posPtr144 = strpos($drSrc144, 'private function checkPtr(string $ip, ?float $deadline = null): array');
            $ptrBody144 = $posPtr144 !== false ? substr($drSrc144, $posPtr144, 1100) : '';
            if ($posPtr144 === false || strpos($ptrBody144, "return ['found' => false, 'hostname' => null, 'timed_out' => true];") === false) {
                $offenders[] = "DomainReputationManager::checkPtr() ne respecte plus le budget DNS partagé — régression du bug corrigé le 09/08/2026 (round 144) : gethostbyaddr()/gethostbyname() pourraient de nouveau bloquer un visiteur front même quand le budget est déjà épuisé";
            }
        }
        $mainFile144 = _PS_MODULE_DIR_ . $this->module->name . '/' . $this->module->name . '.php';
        $mainSrc144 = $this->readModuleSrc($mainFile144);
        if ($mainSrc144 !== '' && strpos($mainSrc144, 'DomainReputationManager::invalidateCache((int) $this->context->shop->id)') === false) {
            $offenders[] = "neria.php::save_senders n'appelle plus DomainReputationManager::invalidateCache() — régression du bug corrigé le 09/08/2026 (round 144)";
        }

        // Round 144 (2026-09-11) : MultiClientPreviewManager — 6 méthodes de
        // transformation doivent avoir un filet `?? $html`/`?? $css` contre
        // un preg_replace()/preg_replace_callback() qui renverrait null.
        $mcpFile144 = _PS_MODULE_DIR_ . $this->module->name . '/src/MultiClientPreviewManager.php';
        $mcpSrc144 = $this->readModuleSrc($mcpFile144);
        if ($mcpSrc144 === '') {
            $offenders[] = 'MultiClientPreviewManager.php introuvable (garde-fou round 144 : filets ?? contre échec PCRE)';
        } elseif (substr_count($mcpSrc144, '?? $html') < 6) {
            $offenders[] = "MultiClientPreviewManager : moins de 6 filets '?? \$html' détectés — régression du bug corrigé le 09/08/2026 (round 144) : un échec PCRE (backtrack_limit dépassé) pourrait de nouveau vider l'aperçu ou provoquer un TypeError fatal selon le client simulé";
        }

        // Round 145 (2026-09-12) : MonthlyReportManager::checkAndSend() ne
        // doit plus avoir de "fast exit" global sur CONFIG_ENABLED avant la
        // boucle multi-boutique — revérifié PAR BOUTIQUE dans la boucle.
        // getRecipients() doit rester scopé id_shop.
        $mrFile145 = _PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php';
        $mrSrc145 = $this->readModuleSrc($mrFile145);
        if ($mrSrc145 === '') {
            $offenders[] = 'MonthlyReportManager.php introuvable (garde-fou round 145 : CONFIG_ENABLED par boutique + getRecipients scopé)';
        } else {
            $posCAS145 = strpos($mrSrc145, 'public function checkAndSend(): void');
            $posLoop145 = $posCAS145 !== false ? strpos($mrSrc145, 'foreach ($shops as $idShop) {', $posCAS145) : false;
            $posEnabledInLoop145 = $posLoop145 !== false ? strpos($mrSrc145, "\Configuration::get(self::CONFIG_ENABLED, null, null, \$idShop)", $posLoop145) : false;
            if ($posCAS145 === false || $posLoop145 === false || $posEnabledInLoop145 === false) {
                $offenders[] = "MonthlyReportManager::checkAndSend() ne revérifie plus CONFIG_ENABLED avec idShop explicite dans la boucle — régression du bug corrigé le 09/08/2026 (round 145) : une boutique désactivée pourrait de nouveau court-circuiter la vérification de toutes les autres";
            }
            $preLoop145 = ($posCAS145 !== false && $posLoop145 !== false) ? substr($mrSrc145, $posCAS145, $posLoop145 - $posCAS145) : '';
            if (strpos($preLoop145, 'if (!(int) \Configuration::get(self::CONFIG_ENABLED)) {') !== false) {
                $offenders[] = "MonthlyReportManager::checkAndSend() a de nouveau un fast-exit global sur CONFIG_ENABLED avant la boucle multi-boutique — régression du bug corrigé le 09/08/2026 (round 145)";
            }
            $posRecip145 = strpos($mrSrc145, 'private function getRecipients(): array');
            $recipBody145 = $posRecip145 !== false ? substr($mrSrc145, $posRecip145, 1500) : '';
            if ($posRecip145 === false
                || strpos($recipBody145, "\Configuration::get(self::CONFIG_RECIPIENTS, null, null, \$this->idShop)") === false
                || strpos($recipBody145, "\Configuration::get('PS_SHOP_EMAIL', null, null, \$this->idShop)") === false
            ) {
                $offenders[] = "MonthlyReportManager::getRecipients() n'est plus scopé par \$this->idShop — régression du bug corrigé le 09/08/2026 (round 145) : le rapport d'une boutique pourrait de nouveau partir aux destinataires configurés pour une autre boutique";
            }
        }

        // Round 145 (2026-09-12) : UpsellManager::findByAccessories()/
        // findByCoPurchase()/findByCategoryBestseller() doivent filtrer le
        // SUM de stock par id_shop.
        $upFile145 = _PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php';
        $upSrc145 = $this->readModuleSrc($upFile145);
        if ($upSrc145 === '') {
            $offenders[] = 'UpsellManager.php introuvable (garde-fou round 145 : stock upsell scopé boutique)';
        } elseif (substr_count($upSrc145, "\$idShop !== null ? ' AND sa.id_shop = ' . (int) \$idShop : ''") < 3) {
            $offenders[] = "UpsellManager : moins de 3 méthodes findBy*() filtrent le stock par id_shop — régression du bug corrigé le 09/08/2026 (round 145) : un produit épuisé sur la boutique du destinataire mais en stock ailleurs redeviendrait suggéré à tort en upsell";
        }

        // Round 145 (2026-09-12) : TranslationEngine::update() doit
        // normaliser la langue (normalizeLang()), comme get()/getAll().
        $teFile145 = _PS_MODULE_DIR_ . $this->module->name . '/src/TranslationEngine.php';
        $teSrc145 = $this->readModuleSrc($teFile145);
        if ($teSrc145 === '') {
            $offenders[] = 'TranslationEngine.php introuvable (garde-fou round 145 : update() normalise la langue)';
        } else {
            $posUpd145 = strpos($teSrc145, 'string $key,' . "\n" . '        string $value' . "\n" . '    ): bool {');
            $updBody145 = $posUpd145 !== false ? substr($teSrc145, $posUpd145, 800) : '';
            if ($posUpd145 === false || strpos($updBody145, '$lang  = $this->normalizeLang($lang);') === false) {
                $offenders[] = "TranslationEngine::update() ne normalise plus la langue avant écriture — régression du bug corrigé le 09/08/2026 (round 145) : une traduction personnalisée écrite avec un code langue non normalisé (majuscules, alias PS) redeviendrait introuvable au rendu";
            }
        }

        // Round 145 (2026-09-12) : SignatureGenerator — les anciens fichiers
        // PNG doivent être supprimés au changement de style
        // (neria.php::generate_signature), et generate() doit exposer le
        // style RÉELLEMENT rendu via $resolvedStyle.
        $sgFile145 = _PS_MODULE_DIR_ . $this->module->name . '/src/SignatureGenerator.php';
        $sgSrc145 = $this->readModuleSrc($sgFile145);
        if ($sgSrc145 === '') {
            $offenders[] = 'SignatureGenerator.php introuvable (garde-fou round 145 : resolvedStyle + orphelins nettoyés)';
        } else {
            $posGetFont145 = strpos($sgSrc145, 'private function getFontPath(string $style, ?string &$resolvedStyle = null): ?string');
            if ($posGetFont145 === false) {
                $offenders[] = "SignatureGenerator::getFontPath() n'accepte plus \$resolvedStyle par référence — régression du bug corrigé le 09/08/2026 (round 145) : le style réellement rendu ne serait plus distinguable du style demandé";
            }
            $posGen145 = strpos($sgSrc145, 'int    $idShop = 1,');
            $genBody145 = $posGen145 !== false ? substr($sgSrc145, $posGen145, 1700) : '';
            if ($posGen145 === false || strpos($genBody145, 'buildFilename($idShop, $resolvedStyle ?? $style)') === false) {
                $offenders[] = "SignatureGenerator::generate() ne construit plus le nom de fichier avec le style réellement résolu — régression du bug corrigé le 09/08/2026 (round 145) : métadonnée mensongère si la police demandée est absente";
            }
        }
        $mainSrc145 = $this->readModuleSrc($mainFile144);
        if ($mainSrc145 !== '' && strpos($mainSrc145, '$sigGenerator->delete($idShop') === false) {
            $offenders[] = "neria.php::generate_signature n'appelle plus SignatureGenerator::delete() avant de régénérer — régression du bug corrigé le 09/08/2026 (round 145) : les anciens fichiers PNG de signature s'accumuleraient de nouveau sur disque à chaque changement de style";
        }

        // Round 145 (2026-09-12) : EmailRenderer::resolveSignature() doit
        // ajouter un paramètre de cache-busting (?v=filemtime) à l'URL.
        $erFile145 = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $erSrc145 = $this->readModuleSrc($erFile145);
        if ($erSrc145 === '') {
            $offenders[] = 'EmailRenderer.php introuvable (garde-fou round 145 : cache-busting signature)';
        } else {
            $posRS145 = strpos($erSrc145, 'private function resolveSignature(int $idShop): array');
            $rsBody145 = $posRS145 !== false ? substr($erSrc145, $posRS145, 1700) : '';
            if ($posRS145 === false || strpos($rsBody145, "'v=' . \$mtime") === false) {
                $offenders[] = "EmailRenderer::resolveSignature() n'ajoute plus de cache-busting à l'URL de signature — régression du bug corrigé le 09/08/2026 (round 145) : le cache navigateur/client email afficherait de nouveau une version périmée après régénération";
            }
        }

        // Round 146 (2026-08-09) : SegmentManager::preflightCheck() doit
        // filtrer les destinataires réellement abonnés via PreferencesManager,
        // comme sendToSegment() le fait déjà.
        $smFile146 = _PS_MODULE_DIR_ . $this->module->name . '/src/SegmentManager.php';
        $smSrc146 = $this->readModuleSrc($smFile146);
        if ($smSrc146 === '') {
            $offenders[] = 'SegmentManager.php introuvable (garde-fou round 146 : preflightCheck filtre préférences)';
        } else {
            $posPre146 = strpos($smSrc146, 'function preflightCheck(');
            $preBody146 = $posPre146 !== false ? substr($smSrc146, $posPre146, 2200) : '';
            if ($posPre146 === false || strpos($preBody146, 'msg.segment_all_unsubscribed') === false) {
                $offenders[] = "SegmentManager::preflightCheck() ne détecte plus un segment entièrement désabonné du template — régression du bug corrigé le 09/08/2026 (round 146) : le marchand ne serait de nouveau alerté qu'après un envoi réel à 0 destinataire";
            }
        }

        // Round 146 (2026-08-09) : neria-admin.js::initTranslationsLoader()
        // doit désactiver le bouton avant de soumettre le formulaire.
        $jsFile146 = _PS_MODULE_DIR_ . $this->module->name . '/views/js/neria-admin.js';
        $jsSrc146 = $this->readModuleSrc($jsFile146);
        if ($jsSrc146 === '') {
            $offenders[] = 'neria-admin.js introuvable (garde-fou round 146 : anti-double-clic + jeton de requête)';
        } else {
            $posTl146 = strpos($jsSrc146, 'function initTranslationsLoader()');
            $tlBody146 = $posTl146 !== false ? substr($jsSrc146, $posTl146, 1200) : '';
            if ($posTl146 === false || strpos($tlBody146, 'if (btn.disabled) return;') === false || strpos($tlBody146, 'btn.disabled = true;') === false) {
                $offenders[] = "neria-admin.js::initTranslationsLoader() ne désactive plus le bouton avant soumission — régression du bug corrigé le 09/08/2026 (round 146) : un double-clic pourrait de nouveau générer deux requêtes POST concurrentes";
            }

            $posSp146 = strpos($jsSrc146, 'function initSignaturePreview()');
            $spBody146 = $posSp146 !== false ? substr($jsSrc146, $posSp146, 3000) : '';
            if ($posSp146 === false
                || strpos($spBody146, 'var requestToken = 0;') === false
                || strpos($spBody146, 'var thisRequest = ++requestToken;') === false
                || substr_count($spBody146, 'if (thisRequest !== requestToken) return;') < 2
            ) {
                $offenders[] = "neria-admin.js::initSignaturePreview() ne protège plus contre les réponses fetch() obsolètes via un jeton de requête — régression du bug corrigé le 09/08/2026 (round 146) : un aperçu de signature plus récent pourrait de nouveau être écrasé par une réponse plus lente et périmée";
            }
        }

        // Round 146 (2026-08-09) : script de rattrapage upgrade-1.0.40.php
        // (3 bugs historiques : backfill id_shop, Configuration non scopée,
        // && court-circuitant la recréation de contraintes UNIQUE).
        $upFile146 = _PS_MODULE_DIR_ . $this->module->name . '/upgrade/upgrade-1.0.40.php';
        $upSrc146 = $this->readModuleSrc($upFile146);
        if ($upSrc146 === '' || strpos($upSrc146, 'function upgrade_module_1_0_40(Neria $module): bool') === false) {
            $offenders[] = "upgrade/upgrade-1.0.40.php introuvable ou incomplet — régression du bug corrigé le 09/08/2026 (round 146) : les 3 correctifs historiques (id_shop mal renseigné, Configuration non scopée boutique, contraintes UNIQUE non recréées) ne seraient de nouveau plus appliqués sur les installations existantes";
        }

        // Round 147 (2026-08-09) : AbTestManager::activateTest() ne doit
        // journaliser un succès que si l'UPDATE SQL a réellement réussi.
        $abFile147 = _PS_MODULE_DIR_ . $this->module->name . '/src/ABTestManager.php';
        $abSrc147 = $this->readModuleSrc($abFile147);
        if ($abSrc147 === '') {
            $offenders[] = 'ABTestManager.php introuvable (garde-fou round 147 : logs activate/create/delete conditionnés au résultat SQL)';
        } else {
            $posAct147 = strpos($abSrc147, 'public function activateTest(string $template): bool');
            $actBody147 = $posAct147 !== false ? substr($abSrc147, $posAct147, 1800) : '';
            if ($posAct147 === false
                || strpos($actBody147, 'if ($result !== false) {') === false
                || strpos($actBody147, 'abtest_activate_failed') === false
            ) {
                $offenders[] = "AbTestManager::activateTest() ne conditionne plus son log Watchdog au résultat réel de l'UPDATE — régression du bug corrigé le 09/08/2026 (round 147) : le log 'activated' mentirait de nouveau même en cas d'échec SQL";
            }

            $posCreate147 = strpos($abSrc147, 'public function createTest(');
            $posActStart147 = strpos($abSrc147, 'public function activateTest(', $posCreate147);
            $createBody147 = ($posCreate147 !== false && $posActStart147 !== false) ? substr($abSrc147, $posCreate147, $posActStart147 - $posCreate147) : '';
            if ($posCreate147 === false || substr_count($createBody147, 'abtest_create_failed') < 2) {
                $offenders[] = "AbTestManager::createTest() ne journalise plus d'erreur Watchdog sur ses branches d'échec SQL — régression du bug corrigé le 09/08/2026 (round 147)";
            }

            $posDelete147 = strpos($abSrc147, 'public function deleteTests(');
            $deleteBody147 = $posDelete147 !== false ? substr($abSrc147, $posDelete147, 3400) : '';
            if ($posDelete147 === false
                || strpos($deleteBody147, '$ok = $this->db->execute(') === false
                || strpos($deleteBody147, 'return $ok;') === false
                || strpos($deleteBody147, 'abtest_delete_failed') === false
            ) {
                $offenders[] = "AbTestManager::deleteTests() ne répercute plus le résultat réel de ses opérations SQL (ou ne journalise plus d'erreur) — régression du bug corrigé le 09/08/2026 (round 147) : un échec partiel redeviendrait indétectable et silencieux";
            }
        }

        // Round 147 (2026-08-09) : neria-admin.js::initTranslationsReset()
        // et initDesignReset() doivent désactiver leur bouton avant de
        // soumettre le formulaire (jumeaux non alignés sur le correctif
        // round 146 de initTranslationsLoader()).
        $jsSrc147 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/js/neria-admin.js');
        if ($jsSrc147 === '') {
            $offenders[] = 'neria-admin.js introuvable (garde-fou round 147 : anti-double-clic reset traductions/design)';
        } else {
            $posTr147 = strpos($jsSrc147, 'function initTranslationsReset()');
            $posTrSubmit147 = $posTr147 !== false ? strpos($jsSrc147, 'form.submit();', $posTr147) : false;
            $trBody147 = ($posTr147 !== false && $posTrSubmit147 !== false) ? substr($jsSrc147, $posTr147, $posTrSubmit147 - $posTr147) : '';
            if ($posTr147 === false || strpos($trBody147, 'if (btn.disabled) return;') === false || strpos($trBody147, 'btn.disabled = true;') === false) {
                $offenders[] = "neria-admin.js::initTranslationsReset() ne désactive plus le bouton avant soumission — régression du bug corrigé le 09/08/2026 (round 147) : un double-clic pourrait de nouveau générer deux requêtes POST concurrentes";
            }

            $posDes147 = strpos($jsSrc147, 'function initDesignReset()');
            $posDesSubmit147 = $posDes147 !== false ? strpos($jsSrc147, 'form.submit();', $posDes147) : false;
            $desBody147 = ($posDes147 !== false && $posDesSubmit147 !== false) ? substr($jsSrc147, $posDes147, $posDesSubmit147 - $posDes147) : '';
            if ($posDes147 === false || strpos($desBody147, 'if (btn.disabled) return;') === false || strpos($desBody147, 'btn.disabled = true;') === false) {
                $offenders[] = "neria-admin.js::initDesignReset() ne désactive plus le bouton avant soumission — régression du bug corrigé le 09/08/2026 (round 147) : un double-clic pourrait de nouveau générer deux requêtes POST concurrentes";
            }
        }

        // Round 148 (2026-08-09) : EmailRenderer doit échapper toute
        // variable de texte libre par défaut (liste NOIRE de fragments HTML
        // sûrs), pas une liste blanche figée de 5 clés.
        $erFile148 = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $erSrc148 = $this->readModuleSrc($erFile148);
        if ($erSrc148 === '') {
            $offenders[] = 'EmailRenderer.php introuvable (garde-fou round 148 : echappement liste noire variables libres)';
        } else {
            // Round 169 : la liste blanche a depuis été factorisée en
            // constante de classe self::HTML_SAFE_RAW_KEYS (au lieu d'une
            // variable locale $htmlSafeRawKeys) — le garde-fou vérifiait
            // encore l'ancien nom, jamais mis à jour après ce refactor.
            $posHtv148 = strpos($erSrc148, 'const HTML_SAFE_RAW_KEYS = [');
            $hardenBody148 = $posHtv148 !== false ? substr($erSrc148, $posHtv148, 900) : '';
            if ($posHtv148 === false
                || strpos($erSrc148, 'foreach ($htmlTemplateVars as $nameKey => $nameValue) {') === false
                || strpos($erSrc148, '!in_array($nameKey, self::HTML_SAFE_RAW_KEYS, true)') === false
            ) {
                $offenders[] = "EmailRenderer ne durcit plus TOUTE variable de texte libre par defaut — régression du bug corrigé le 09/08/2026 (round 148) : une liste blanche figée de quelques clés laisserait de nouveau passer des variables comme {reply}/{apology_reason}/{alteration_status} sans échappement, XSS stocké possible via l'envoi manuel BO";
            }
        }

        // Round 148 (2026-08-09) : controllers/front/preferences.php doit
        // construire son lien de désabonnement via getUnsubscribeUrl()
        // (donc getModuleLink()), pas en dur.
        $prefFile148 = _PS_MODULE_DIR_ . $this->module->name . '/controllers/front/preferences.php';
        $prefSrc148 = $this->readModuleSrc($prefFile148);
        if ($prefSrc148 === '' || strpos($prefSrc148, "'/module/neria/unsubscribe'") !== false || strpos($prefSrc148, '$this->module->getUnsubscribeUrl(') === false) {
            $offenders[] = "controllers/front/preferences.php construit de nouveau son lien de désabonnement en dur — régression du bug corrigé le 09/08/2026 (round 148) : casserait de nouveau sans URL rewriting";
        }

        // Round 148 (2026-08-09) : pattern "log Watchdog conditionné/manquant"
        // (déjà trouvé round 147 dans AbTestManager), retrouvé 5 fois de
        // plus : QueueManager::enqueue(), SegmentManager::recomputeAll(),
        // ChurnScoreManager::recomputeAll(), WebhookManager::trigger(),
        // AbTestManager::copyVariantBToDefault()/archiveTest().
        $qmSrc148 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php');
        if ($qmSrc148 === '' || strpos($qmSrc148, '$execOk = $this->db->execute(') === false || strpos($qmSrc148, 'queue_scheduling_failed') === false) {
            $offenders[] = "QueueManager::enqueue() ne distingue plus un échec SQL réel d'un doublon ignoré — régression du bug corrigé le 09/08/2026 (round 148)";
        }

        $segSrc148 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SegmentManager.php');
        if ($segSrc148 === '' || strpos($segSrc148, 'segment_recompute_sql_failed') === false) {
            $offenders[] = "SegmentManager::recomputeAll() ne journalise plus d'erreur Watchdog sur un échec SQL réel — régression du bug corrigé le 09/08/2026 (round 148)";
        }

        $churnSrc148 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ChurnScoreManager.php');
        if ($churnSrc148 === '' || strpos($churnSrc148, 'churn_score_partial_failure') === false || strpos($churnSrc148, 'if ($chunkOk !== false) {') === false) {
            $offenders[] = "ChurnScoreManager::recomputeAll() ne journalise plus d'erreur Watchdog sur un échec SQL partiel — régression du bug corrigé le 09/08/2026 (round 148)";
        }

        $whSrc148 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WebhookManager.php');
        if ($whSrc148 === '' || strpos($whSrc148, 'webhook_trigger_insert_failed') === false) {
            $offenders[] = "WebhookManager::trigger() ne journalise plus d'erreur Watchdog quand l'INSERT de mise en file échoue — régression du bug corrigé le 09/08/2026 (round 148)";
        }

        $abSrc148 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ABTestManager.php');
        if ($abSrc148 === '' || strpos($abSrc148, 'abtest_variant_b_promote_failed') === false || strpos($abSrc148, 'abtest_archive_failed') === false) {
            $offenders[] = "AbTestManager::copyVariantBToDefault()/archiveTest() ne conditionnent plus leur log au résultat SQL réel — régression du bug corrigé le 09/08/2026 (round 148)";
        }

        // Round 149 (2026-08-09) : buildCompiledHtml() (aperçu Design BO +
        // renvoi depuis l'historique client) doit échapper ses
        // $extraReplacements comme compileNeriaTemplate() (envoi réel) —
        // même liste noire partagée self::HTML_SAFE_RAW_KEYS, désormais
        // complète (shipped_items/messages/virtualProducts inclus, oubliés
        // au round 148).
        $erSrc149 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php');
        if ($erSrc149 === '') {
            $offenders[] = 'EmailRenderer.php introuvable (garde-fou round 149 : echappement buildCompiledHtml + liste noire complete)';
        } else {
            $posConst149 = strpos($erSrc149, 'const HTML_SAFE_RAW_KEYS = [');
            $constBody149 = $posConst149 !== false ? substr($erSrc149, $posConst149, 1200) : '';
            if ($posConst149 === false
                || strpos($constBody149, "'{shipped_items}', '{messages}', '{virtualProducts}',") === false
            ) {
                $offenders[] = "EmailRenderer::HTML_SAFE_RAW_KEYS n'est plus complète (shipped_items/messages/virtualProducts) — régression du bug corrigé le 09/08/2026 (round 149) : order_partial_shipped/forward_msg/download_product afficheraient de nouveau du HTML échappé au lieu du contenu formaté";
            }

            $posBch149 = strpos($erSrc149, 'private function buildCompiledHtml(');
            // Round 188 : fenêtre élargie 9000→9600 — le correctif
            // preg_replace_callback() (bug $ + chiffre traité comme
            // rétro-référence) a allongé cette méthode de part et d'autre.
            $bchBody149 = $posBch149 !== false ? substr($erSrc149, $posBch149, 9600) : '';
            if ($posBch149 === false
                || strpos($bchBody149, '$safeExtraReplacements = $extraReplacements;') === false
                || strpos($bchBody149, '!in_array($nameKey, self::HTML_SAFE_RAW_KEYS, true)') === false
            ) {
                $offenders[] = "EmailRenderer::buildCompiledHtml() n'échappe plus ses \$extraReplacements — régression du bug corrigé le 09/08/2026 (round 149) : XSS stocké exécuté côté BO via l'aperçu d'historique client (renderWithVars())";
            }

            $posItv149 = strpos($erSrc149, 'private function injectTextVariants(');
            $itvBody149 = $posItv149 !== false ? substr($erSrc149, $posItv149, 2200) : '';
            if ($posItv149 === false || strpos($itvBody149, '$htmlKeysWithSuffix = [') === false) {
                $offenders[] = "EmailRenderer::injectTextVariants() ne couvre plus tous les fragments HTML connus — régression du bug corrigé le 09/08/2026 (round 149) : un template Neria propre réutilisant products/delivery_block_html/etc. sans variante _txt afficherait de nouveau du HTML brut en version texte";
            }
        }

        // Round 149 (2026-08-09) : ConfigManager::DEFAULTS doit inclure les
        // 3 clés JSON KEY_FIRSTNAME_FALLBACKS/KEY_TIME_GREETINGS/
        // KEY_TARGET_COUNTRIES, et sanitizeUrl() doit restreindre le schéma
        // à http/https.
        $cmSrc149 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php');
        if ($cmSrc149 === '') {
            $offenders[] = 'ConfigManager.php introuvable (garde-fou round 149 : DEFAULTS complet + sanitizeUrl schema)';
        } else {
            if (strpos($cmSrc149, 'self::KEY_FIRSTNAME_FALLBACKS       => \'\',') === false
                || strpos($cmSrc149, 'self::KEY_TIME_GREETINGS            => \'\',') === false
                || strpos($cmSrc149, 'self::KEY_TARGET_COUNTRIES          => \'\',') === false
            ) {
                $offenders[] = "ConfigManager::DEFAULTS n'inclut plus KEY_FIRSTNAME_FALLBACKS/KEY_TIME_GREETINGS/KEY_TARGET_COUNTRIES — régression du bug corrigé le 09/08/2026 (round 149) : deleteAll() les laisserait de nouveau orphelines en base";
            }

            $posSu149 = strpos($cmSrc149, 'private function sanitizeUrl(string $url)');
            $suBody149 = $posSu149 !== false ? substr($cmSrc149, $posSu149, 1100) : '';
            if ($posSu149 === false || strpos($suBody149, "in_array(\$scheme, ['http', 'https'], true)") === false) {
                $offenders[] = "ConfigManager::sanitizeUrl() ne restreint plus le schéma à http/https — régression du bug corrigé le 09/08/2026 (round 149) : un lien social javascript:/data: pourrait de nouveau être enregistré et injecté dans chaque email envoyé";
            }
        }

        // Round 149 (2026-08-09) : loyalty_recap.txt doit afficher
        // {progress_pct}, comme loyalty_recap.html.
        $lrTxt149 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/mails/themes/neria_global/core/loyalty_recap.txt');
        if ($lrTxt149 === '' || strpos($lrTxt149, '{progress_pct}') === false) {
            $offenders[] = "loyalty_recap.txt n'affiche plus {progress_pct} — régression du bug corrigé le 09/08/2026 (round 149) : le client ne verrait plus son pourcentage de progression fidélité en version texte brute";
        }

        // Round 150 (2026-08-09) : la version .txt d'un email compilé doit
        // nettoyer (pas seulement résoudre brut) une traduction {neria_trad}
        // — certaines clés (history_info, guest_tracking_info, tracking_info)
        // contiennent un lien HTML complet pensé pour le rendu HTML.
        $erSrc150 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php');
        if ($erSrc150 === '' || strpos($erSrc150, "\$v !== '' ? NeriaTools::sanitizeText(\$v) : \$m[0];") === false) {
            $offenders[] = "EmailRenderer ne nettoie plus les traductions {neria_trad} injectées dans la version .txt — régression du bug corrigé le 09/08/2026 (round 150) : des balises HTML brutes (<a href=...>) réapparaîtraient dans la version texte de 15 templates transactionnels (bankwire, order_conf, payment, refund, shipped...)";
        }

        // Round 150 (2026-08-09) : PageSpeedManager::runCheck() ne doit
        // effacer CONFIG_LAST_ERROR que si mobile ET desktop ont réussi.
        $psmSrc150 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PageSpeedManager.php');
        if ($psmSrc150 === '') {
            $offenders[] = 'PageSpeedManager.php introuvable (garde-fou round 150 : LAST_ERROR non écrasé entre stratégies)';
        } else {
            $posFetch150 = strpos($psmSrc150, 'private function fetchStrategy(string $url, string $key, string $strategy): ?array');
            $fetchBody150 = $posFetch150 !== false ? substr($psmSrc150, $posFetch150, 3400) : '';
            if ($posFetch150 === false || strpos($fetchBody150, 'CONFIG_LAST_ERROR') !== false) {
                $offenders[] = "PageSpeedManager::fetchStrategy() efface/écrit de nouveau CONFIG_LAST_ERROR sur son propre succès — régression du bug corrigé le 09/08/2026 (round 150) : le succès d'une stratégie effacerait de nouveau l'erreur enregistrée par l'échec de l'autre";
            }
            $posRun150 = strpos($psmSrc150, 'public function runCheck(): ?array');
            $runBody150 = $posRun150 !== false ? substr($psmSrc150, $posRun150, 3600) : '';
            if ($posRun150 === false || strpos($runBody150, 'if ($mobile !== null && $desktop !== null) {') === false) {
                $offenders[] = "PageSpeedManager::runCheck() ne conditionne plus l'effacement de CONFIG_LAST_ERROR à la réussite des deux stratégies — régression du bug corrigé le 09/08/2026 (round 150)";
            }
        }

        // Round 150 (2026-08-09) : PostmasterManager/SearchConsoleManager
        // doivent revalider le domaine du cache avant de le servir.
        $pmSrc150 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php');
        if ($pmSrc150 === '' || strpos($pmSrc150, 'self::domainsMatch($cachedHost, $this->getShopHost())') === false) {
            $offenders[] = "PostmasterManager ne revalide plus le domaine du cache (CONFIG_CACHE_HOST) avant de le servir — régression du bug corrigé le 09/08/2026 (round 150) : la réputation d'un ancien domaine pourrait de nouveau être affichée comme actuelle après une migration";
        }
        $scSrc150 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php');
        if ($scSrc150 === '' || substr_count($scSrc150, '$this->matchesShopHost(') < 3) {
            $offenders[] = "SearchConsoleManager ne revalide plus le domaine (site_url) du cache avant de le servir dans getStats()/getCachedStats() — régression du bug corrigé le 09/08/2026 (round 150)";
        }

        // Round 150 bis (2026-08-09) : return_slip.txt doit utiliser
        // {items_txt} (variante texte auto-dérivée), pas {items} (fragment
        // HTML brut) — bug distinct trouvé PAR le nouveau contrôle prospectif
        // checkTxtRawHtmlLeak() lui-même, juste après son ajout.
        $rsTxt150 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/mails/themes/neria_global/core/return_slip.txt');
        if ($rsTxt150 === '' || strpos($rsTxt150, '{items_txt}') === false) {
            $offenders[] = "return_slip.txt n'utilise plus {items_txt} — régression du bug corrigé le 09/08/2026 (round 150 bis) : la liste d'articles réapparaîtrait en HTML brut (<p>...</p>) dans le bon de retour envoyé au client";
        }

        // Round 150 bis (2026-08-09) : le contrôle prospectif
        // checkTxtRawHtmlLeak() lui-même doit rester enregistré — sans lui,
        // toute future clé de traduction ou variable HTML mal référencée
        // dans un .txt (comme return_slip ci-dessus) redeviendrait
        // silencieuse jusqu'à ce qu'un client s'en plaigne.
        $selfSrc150 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/HealthCheckManager.php');
        if ($selfSrc150 === '' || strpos($selfSrc150, "'txt_raw_html_leak' => 'checkTxtRawHtmlLeak',") === false) {
            $offenders[] = "checkTxtRawHtmlLeak() n'est plus enregistré dans la liste des contrôles Watchdog — régression du bug corrigé le 09/08/2026 (round 150 bis) : la fuite de balises HTML brutes dans un .txt ne serait de nouveau plus détectée proactivement";
        }

        // Round 151 (2026-08-09) : CssInliner::merge() doit découper les
        // déclarations CSS via splitDeclarations() (respecte parenthèses/
        // guillemets), pas un explode(';', ...) naïf qui corrompt une data
        // URI base64 (logos/signatures embarqués).
        $ciSrc151 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CssInliner.php');
        if ($ciSrc151 === ''
            || strpos($ciSrc151, 'private static function splitDeclarations(string $css): array') === false
            || substr_count($ciSrc151, 'self::splitDeclarations(') < 2
        ) {
            $offenders[] = "CssInliner::merge() ne découpe plus les déclarations CSS via splitDeclarations() — régression du bug corrigé le 09/08/2026 (round 151) : une data URI base64 (logo/signature) contenant un ';' serait de nouveau corrompue lors de l'inlining CSS";
        }

        // Round 151 (2026-08-09) : checkout_abandonment doit injecter
        // {products_txt} (même correctif que abandoned_cart_1/2/3).
        $bcmSrc151 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php');
        $posCa151 = $bcmSrc151 !== '' ? strpos($bcmSrc151, 'function sendCheckoutAbandonment') : false;
        $caBody151 = $posCa151 !== false ? substr($bcmSrc151, $posCa151, 3200) : '';
        if ($posCa151 === false || strpos($caBody151, "'{products_txt}'") === false || strpos($caBody151, '$this->buildCartProductsTxt($idCart)') === false) {
            $offenders[] = "BehavioralCronManager::sendCheckoutAbandonment() n'injecte plus {products_txt} — régression du bug corrigé le 09/08/2026 (round 151) : la version texte de la relance panier abandonné 1h n'afficherait de nouveau aucun article";
        }
        $caTxt151 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/mails/themes/neria_global/core/checkout_abandonment.txt');
        if ($caTxt151 === '' || strpos($caTxt151, '{products_txt}') === false) {
            $offenders[] = "checkout_abandonment.txt ne référence plus {products_txt} — régression du bug corrigé le 09/08/2026 (round 151)";
        }

        // Round 151 (2026-08-09) : DeliverabilityScorer::getDnsStatus() doit
        // avoir un budget de temps DNS et score() ne doit plus pénaliser une
        // vérification DNS interrompue comme un domaine réellement mal
        // configuré.
        $dsSrc151 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/DeliverabilityScorer.php');
        if ($dsSrc151 === ''
            || strpos($dsSrc151, 'private const DNS_TIME_BUDGET_SECS = 8.0;') === false
            || strpos($dsSrc151, 'if (microtime(true) >= $deadline) {') === false
            || strpos($dsSrc151, "if (!empty(\$dns['timed_out'])) {") === false
        ) {
            $offenders[] = "DeliverabilityScorer::getDnsStatus()/score() n'a plus de budget de temps DNS ou ne neutralise plus la pénalité en cas de panne — régression du bug corrigé le 09/08/2026 (round 151) : un résolveur DNS lent pourrait de nouveau bloquer la page BO, et une panne réseau transitoire ferait de nouveau chuter le score de 24 points à tort";
        }

        // Round 151 (2026-08-09) : TranslationHistoryManager::record() doit
        // vérifier GET_LOCK() et journaliser les échecs SQL.
        $thmSrc151 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/TranslationHistoryManager.php');
        if ($thmSrc151 === ''
            || strpos($thmSrc151, '$acquired = (int) $this->db->getValue(') === false
            || strpos($thmSrc151, 'watchdog.translation_history_insert_failed') === false
        ) {
            $offenders[] = "TranslationHistoryManager::record() ne vérifie plus GET_LOCK()/n'journalise plus les échecs SQL — régression du bug corrigé le 09/08/2026 (round 151)";
        }

        // Round 152 (2026-08-09) : SeoApiManager::httpGet() (Semrush) doit
        // journaliser une alerte Watchdog sur un échec réseau/HTTP, comme
        // sa jumelle fetchMoz() (même fichier) le fait déjà.
        $samSrc152 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SeoApiManager.php');
        if ($samSrc152 === '') {
            $offenders[] = 'SeoApiManager.php introuvable (garde-fou round 152 : alerte Watchdog httpGet Semrush)';
        } else {
            $posHg152 = strpos($samSrc152, 'private function httpGet(string $url): ?string');
            $hgBody152 = $posHg152 !== false ? substr($samSrc152, $posHg152, 1900) : '';
            if ($posHg152 === false || strpos($hgBody152, 'watchdog.semrush_http_error') === false) {
                $offenders[] = "SeoApiManager::httpGet() ne journalise plus d'alerte Watchdog sur un échec réseau/HTTP — régression du bug corrigé le 09/08/2026 (round 152) : une panne Semrush prolongée redeviendrait invisible du flux de notifications standard";
            }
        }

        // Round 153 (2026-08-09) : reset_variant_b/save_variant_b doivent
        // vérifier id_abtest_b contre le template affiché (tplKey), comme
        // restore_variant_b (round 137).
        $nSrc153 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/neria.php');
        if ($nSrc153 === '') {
            $offenders[] = 'neria.php introuvable (garde-fou round 153 : variant_b actions scopées par template)';
        } else {
            $posReset153 = strpos($nSrc153, "if (\$tradAction === 'reset_variant_b') {");
            $resetBody153 = $posReset153 !== false ? substr($nSrc153, $posReset153, 1000) : '';
            if ($posReset153 === false || strpos($resetBody153, 'abtestBelongsToShop($idAbtestB, $tplKey)') === false) {
                $offenders[] = "reset_variant_b ne vérifie plus id_abtest_b contre \$tplKey — régression du bug corrigé le 09/08/2026 (round 153) : un id_abtest_b manipulé pourrait de nouveau vider la variante B d'un AUTRE template actif sur la même boutique";
            }
            $posSave153 = strpos($nSrc153, "if (\$tradAction === 'save_variant_b' && class_exists('ABTestManager')) {");
            $saveBody153 = $posSave153 !== false ? substr($nSrc153, $posSave153, 600) : '';
            if ($posSave153 === false || strpos($saveBody153, 'abtestBelongsToShop($idAbtestB, $tplKey)') === false) {
                $offenders[] = "save_variant_b ne vérifie plus id_abtest_b contre \$tplKey — régression du bug corrigé le 09/08/2026 (round 153) : un id_abtest_b manipulé pourrait de nouveau écraser la variante B d'un AUTRE template actif sur la même boutique";
            }
        }

        // Round 153 (2026-08-09) : PreferencesManager::isAllowedBatch() doit
        // rester présente (utilisée par SegmentManager pour éviter un N+1
        // de centaines de requêtes SQL sur un envoi de campagne segment).
        $pmSrc153 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PreferencesManager.php');
        if ($pmSrc153 === '' || strpos($pmSrc153, 'public function isAllowedBatch(array $idCustomers, string $template, ?int $idShop = null): array') === false) {
            $offenders[] = "PreferencesManager::isAllowedBatch() a disparu — régression du bug corrigé le 09/08/2026 (round 153) : un N+1 de centaines de requêtes SQL redeviendrait possible sur chaque envoi de campagne segment";
        }
        $sgSrc153 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SegmentManager.php');
        if ($sgSrc153 === '' || substr_count($sgSrc153, '->isAllowedBatch(') < 2) {
            $offenders[] = "SegmentManager n'utilise plus isAllowedBatch() dans preflightCheck()/sendToSegment() — régression du bug corrigé le 09/08/2026 (round 153) : le N+1 de requêtes SQL individuelles redeviendrait possible sur un envoi de campagne segment";
        }

        // Round 154 (2026-08-09) : PropensityScoreManager::recalculateAll()
        // doit rester pré-chargé par lots (agrégats GROUP BY) au lieu de
        // ré-interroger 7 fois par client (jusqu'à ~35 000 requêtes/nuit).
        $psmSrc154 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PropensityScoreManager.php');
        if ($psmSrc154 === '') {
            $offenders[] = 'PropensityScoreManager.php introuvable (garde-fou round 154 : recalculateAll() pré-chargé par lots)';
        } else {
            $posRa154 = strpos($psmSrc154, 'public function recalculateAll(): void');
            $raBody154 = $posRa154 !== false ? substr($psmSrc154, $posRa154, 5900) : '';
            if ($posRa154 === false
                || substr_count($raBody154, "GROUP BY id_customer'") < 3
            ) {
                $offenders[] = "PropensityScoreManager::recalculateAll() n'utilise plus les 3 requêtes agrégées GROUP BY id_customer — régression du bug corrigé le 09/08/2026 (round 154) : le N+1 (jusqu'à ~35 000 requêtes SQL/nuit) redeviendrait possible";
            }
            foreach (['calcRecencyScore', 'calcFrequencyScore', 'calcEngagementScore', 'calcSeasonalityScore', 'persistScores'] as $m154) {
                if (strpos($psmSrc154, 'function ' . $m154) === false) {
                    $offenders[] = "PropensityScoreManager::{$m154}() a disparu — régression du bug corrigé le 09/08/2026 (round 154) : le calcul batché et le calcul par client risqueraient de nouveau de diverger";
                }
            }
        }

        // Round 154 (2026-08-09) : trois blocs de runBackgroundJobs()
        // identifiés sans protection anti-chevauchement doivent conserver
        // leur GET_LOCK()/RELEASE_LOCK() (digest Watchdog, réputation de
        // domaine, auto-diagnostics).
        $wdSrc154 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WatchdogManager.php');
        if ($wdSrc154 === '' || strpos($wdSrc154, "GET_LOCK('neria_watchdog_digest_") === false || strpos($wdSrc154, "RELEASE_LOCK('neria_watchdog_digest_") === false) {
            $offenders[] = "WatchdogManager::sendDailyDigestIfDue() n'a plus de verrou GET_LOCK() — régression du bug corrigé le 09/08/2026 (round 154) : le digest quotidien pourrait de nouveau partir en double au marchand";
        }
        $drmSrc154 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/DomainReputationManager.php');
        if ($drmSrc154 === '' || strpos($drmSrc154, "GET_LOCK('neria_domain_reputation_") === false || strpos($drmSrc154, "RELEASE_LOCK('neria_domain_reputation_") === false) {
            $offenders[] = "DomainReputationManager::runFullCheck() n'a plus de verrou GET_LOCK() — régression du bug corrigé le 09/08/2026 (round 154) : double charge DNS/RBL et risque d'alerte dupliquée redeviendraient possibles";
        }
        $hcmSrc154 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/HealthCheckManager.php');
        if ($hcmSrc154 !== '') {
            $posAc154 = strpos($hcmSrc154, 'public function runAutoChecksIfDue(bool $allowHeavyScans = false): void');
            $acBody154 = $posAc154 !== false ? substr($hcmSrc154, $posAc154, 2400) : '';
            if ($posAc154 === false || strpos($acBody154, "GET_LOCK('neria_health_autochecks'") === false || strpos($acBody154, "RELEASE_LOCK('neria_health_autochecks'") === false) {
                $offenders[] = "HealthCheckManager::runAutoChecksIfDue() n'a plus de verrou GET_LOCK() — régression du bug corrigé le 09/08/2026 (round 154)";
            }
        }

        // Round 154 (2026-08-09) : 6 défauts d'accessibilité BO corrigés
        // (audit dédié) doivent rester en place.
        $navSrc154 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/navigation.tpl');
        if ($navSrc154 === ''
            || strpos($navSrc154, 'role="dialog"') === false
            || strpos($navSrc154, 'aria-modal="true"') === false
            || strpos($navSrc154, "e.key === 'Escape'") === false
            || strpos($navSrc154, '_neriaModalTrigger') === false
        ) {
            $offenders[] = "navigation.tpl : la modale de confirmation globale a perdu son accessibilité (role dialog/aria-modal/Échap/restitution du focus) — régression du bug corrigé le 09/08/2026 (round 154)";
        }
        $sendSrc154 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/send.tpl');
        if ($sendSrc154 === ''
            || strpos($sendSrc154, 'role="listbox"') === false
            || strpos($sendSrc154, "setAttribute('role', 'option')") === false
            || strpos($sendSrc154, "e.key === 'ArrowDown'") === false
            || strpos($sendSrc154, 'for="neria-send-var-') === false
            || strpos($sendSrc154, 'role="button" tabindex="0" aria-expanded="false" aria-controls="neria-schedule-body"') === false
            || strpos($sendSrc154, 'id="neria-preview-close" aria-label=') === false
        ) {
            $offenders[] = "send.tpl : l'un des correctifs d'accessibilité (autocomplétion clavier, labels de champs dynamiques, panneau de planification, bouton de fermeture) a régressé — régression du bug corrigé le 09/08/2026 (round 154)";
        }
        $statsSrc154 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/stats.tpl');
        if ($statsSrc154 === '' || (substr_count($statsSrc154, ' alt="" ') + substr_count($statsSrc154, "' alt=\"\" ")) < 4) {
            $offenders[] = "stats.tpl : moins de 4 images produit/client ont un attribut alt — régression du bug corrigé le 09/08/2026 (round 154)";
        }

        // Round 155 (09/08/2026) : score_template (action AJAX
        // deliverability_score) doit rester normalisé comme neria_template/
        // mp_template ailleurs dans ce fichier — sans ça, un path traversal
        // redevient possible dans EmailRenderer::buildCompiledHtml().
        $mainSrc155 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/neria.php');
        if ($mainSrc155 === '') {
            $offenders[] = 'neria.php introuvable (garde-fou round 155 : score_template normalisé)';
        } else {
            $posAction155 = strpos($mainSrc155, "if (Tools::getValue('neria_action') === 'deliverability_score') {");
            $actionBody155 = $posAction155 !== false ? substr($mainSrc155, $posAction155, 700) : '';
            if ($posAction155 === false
                || strpos($actionBody155, "preg_replace('/[^a-z0-9_-]/i', '', (string) Tools::getValue('score_template', 'order_conf'))") === false
                || strpos($actionBody155, "\$scoreTemplate = 'order_conf';") === false
            ) {
                $offenders[] = "score_template (action deliverability_score) n'est plus normalisé — régression du bug corrigé le 09/08/2026 (round 155) : path traversal de nouveau possible dans EmailRenderer::buildCompiledHtml()";
            }
        }

        // Round 155 (09/08/2026) : NeriaTools::trackingSignKey() ne doit
        // plus jamais retomber sur un secret prévisible codé en dur.
        $ntSrc155 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/NeriaTools.php');
        if ($ntSrc155 === '' || strpos($ntSrc155, "return 'neria-fallback-static-key';") !== false || strpos($ntSrc155, 'NERIA_TRACKING_FALLBACK_KEY') === false) {
            $offenders[] = "NeriaTools::trackingSignKey() retombe de nouveau sur un secret HMAC prévisible codé en dur — régression du bug corrigé le 09/08/2026 (round 155) : casserait la protection anti-open-redirect du tracking cliquable";
        }

        // Round 155 (09/08/2026) : 11 défauts d'accessibilité BO corrigés
        // (audit dédié, hors navigation.tpl/send.tpl/stats.tpl déjà
        // couverts au round 154) doivent rester en place.
        $histSrc155 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/_customer_history_content.tpl');
        if ($histSrc155 === ''
            || strpos($histSrc155, 'role="dialog"') === false
            || strpos($histSrc155, "e.key === 'Escape'") === false
            || strpos($histSrc155, '_neriaHistoryModalTrigger') === false
            || strpos($histSrc155, 'for="neria-history-filter-template"') === false
        ) {
            $offenders[] = "_customer_history_content.tpl : la modale d'aperçu email ou les filtres ont régressé en accessibilité — régression du bug corrigé le 09/08/2026 (round 155)";
        }
        $academySrc155 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/academy.tpl');
        if ($academySrc155 === ''
            || substr_count($academySrc155, 'role="button" tabindex="0"') < 8
            || substr_count($academySrc155, 'role="checkbox" tabindex="0" aria-checked="false"') < 8
        ) {
            $offenders[] = "academy.tpl : les cartes de guide ou la checklist RGPD ne sont plus accessibles au clavier — régression du bug corrigé le 09/08/2026 (round 155)";
        }
        $calSrc155 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/calendar.tpl');
        if ($calSrc155 === '' || strpos($calSrc155, "aria-label=\"{neria_admin key='calendar.delete_btn' esc='html'}\"") === false) {
            $offenders[] = "calendar.tpl : le lien de suppression d'événement n'a plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 155)";
        }
        $helpSrc155 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/help.tpl');
        if ($helpSrc155 === ''
            || strpos($helpSrc155, "toast.setAttribute('role', 'status')") === false
            || strpos($helpSrc155, "drop.setAttribute('role', 'menu')") === false
            || strpos($helpSrc155, 'closeDropAndRestoreFocus') === false
            || strpos($helpSrc155, 'for="neria-share-add-name"') === false
        ) {
            $offenders[] = "help.tpl : le bandeau PDF ou le menu de partage ont régressé en accessibilité — régression du bug corrigé le 09/08/2026 (round 155)";
        }
        $tradSrc155 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/translations.tpl');
        if ($tradSrc155 === ''
            || strpos($tradSrc155, 'role="combobox"') === false
            || strpos($tradSrc155, "e.key === 'ArrowDown'") === false
            || strpos($tradSrc155, "aria-label=\"{neria_admin key='translations.search_clear'}\"") === false
        ) {
            $offenders[] = "translations.tpl : la recherche globale n'est plus accessible au clavier — régression du bug corrigé le 09/08/2026 (round 155)";
        }
        $abtestSrc155 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/abtest.tpl');
        if ($abtestSrc155 === '' || substr_count($abtestSrc155, "aria-label=\"{neria_admin key='abtest.variant_") < 2) {
            $offenders[] = "abtest.tpl : les champs de nom de variante A/B n'ont plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 155)";
        }

        // Round 156 (09/08/2026) : ManualSendManager::findOrder() doit
        // rester scopé par l'idShop explicitement reçu (client réel), pas
        // par Context::getContext()->shop->id (contexte BO de l'opérateur).
        $msmSrc156 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php');
        if ($msmSrc156 === '') {
            $offenders[] = 'ManualSendManager.php introuvable (garde-fou round 156 : findOrder() scopé par idShop explicite)';
        } else {
            $posFo156 = strpos($msmSrc156, 'private function findOrder(string $ref, int $idShop): ?array');
            $foBody156 = $posFo156 !== false ? substr($msmSrc156, $posFo156, 450) : '';
            if ($posFo156 === false || strpos($foBody156, "AND `id_shop` = ' . \$idShop . '") === false) {
                $offenders[] = "ManualSendManager::findOrder() n'est plus scopé par le paramètre \$idShop explicite — régression du bug corrigé le 09/08/2026 (round 156) : un envoi manuel/planifié référençant une commande valide serait de nouveau bloqué à tort dès que l'opérateur n'est pas dans le même contexte boutique que le client destinataire";
            }
            if (strpos($msmSrc156, "\$this->findOrder(\$orderRef, \$idShop)") === false
                || strpos($msmSrc156, "\$this->findOrder(\$orderRef, \$idShopManual)") === false
            ) {
                $offenders[] = "ManualSendManager::send()/scheduleManual() n'appellent plus findOrder() avec l'idShop du client réel — régression du bug corrigé le 09/08/2026 (round 156)";
            }
        }

        // Round 156 (09/08/2026) : 2 règles CSS directionnelles oubliées du
        // correctif RTL (audit dédié) doivent rester en place.
        $cssSrc156 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/css/neria-admin.css');
        if ($cssSrc156 === ''
            || strpos($cssSrc156, '.neria-bo-wrap[dir="rtl"] .neria-table thead th { text-align:right; }') === false
            || strpos($cssSrc156, '.neria-bo-wrap[dir="rtl"] .neria-abtest-variant__metrics { margin-left:0; margin-right:auto; }') === false
        ) {
            $offenders[] = "neria-admin.css : le tableau générique ou les métriques A/B testing ne sont plus inversés en RTL — régression du bug corrigé le 09/08/2026 (round 156)";
        }

        // Round 157 (09/08/2026) : CollectionManager/LookCompletionManager
        // doivent conserver leur try/catch englobant tout le traitement par
        // ligne (pas seulement Mail::Send()), sans quoi une exception hors
        // du bloc mail fuit à nouveau la réservation claimSend() et
        // interrompt le reste du batch.
        $colSrc157 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CollectionManager.php');
        if ($colSrc157 === '' || substr_count($colSrc157, 'catch (\Throwable $e) {') < 2 || substr_count($colSrc157, 'watchdog.collection_item_error') < 2) {
            $offenders[] = "CollectionManager::processCollection() n'englobe plus tout le traitement par ligne dans un try/catch — régression du bug corrigé le 09/08/2026 (round 157) : une exception hors du bloc mail ferait de nouveau fuiter la réservation et interromprait le reste du batch";
        }
        $lookSrc157 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LookCompletionManager.php');
        if ($lookSrc157 === '' || substr_count($lookSrc157, 'catch (\Throwable $e) {') < 2 || substr_count($lookSrc157, 'watchdog.look_completion_item_error') < 2) {
            $offenders[] = "LookCompletionManager::runDailyCheck() n'englobe plus tout le traitement par commande dans un try/catch — régression du bug corrigé le 09/08/2026 (round 157)";
        }

        // Round 157 (09/08/2026) : MonthlyReportManager::deliverReport()
        // doit rester verrouillé (fichier de rendu partagé) et markSent()
        // ne doit dépendre que d'AU MOINS UN envoi réussi, pas de 100%.
        $mrmSrc157 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php');
        if ($mrmSrc157 === ''
            || strpos($mrmSrc157, "GET_LOCK('neria_monthly_report_deliver'") === false
            || strpos($mrmSrc157, '$anySent = true;') === false
            || strpos($mrmSrc157, 'return $anySent;') === false
        ) {
            $offenders[] = "MonthlyReportManager::deliverReport() n'a plus de verrou global ou ne distingue plus \$anySent de \$ok — régression du bug corrigé le 09/08/2026 (round 157) : un seul destinataire en échec ferait de nouveau échouer markSent() pour tout le mois, et le fichier de rendu partagé ne serait plus protégé entre envoi auto et manuel";
        }

        // Round 157 (09/08/2026) : PostmasterManager/SearchConsoleManager
        // doivent continuer d'effacer CONFIG_LAST_ERROR une 2e fois avant/
        // après l'écriture du cache final réussi (pas seulement après le
        // tout premier appel API).
        $pmSrc157 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php');
        if ($pmSrc157 === '' || substr_count($pmSrc157, 'Configuration::deleteByName(self::CONFIG_LAST_ERROR)') < 2) {
            $offenders[] = "PostmasterManager::fetchAndCache() n'efface plus CONFIG_LAST_ERROR une 2e fois avant l'écriture du cache final — régression du bug corrigé le 09/08/2026 (round 157)";
        }
        $gscSrc157 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php');
        if ($gscSrc157 === '' || substr_count($gscSrc157, 'Configuration::deleteByName(self::CONFIG_LAST_ERROR)') < 2) {
            $offenders[] = "SearchConsoleManager::fetchAndCache() n'efface plus CONFIG_LAST_ERROR une 2e fois après l'écriture du cache final — régression du bug corrigé le 09/08/2026 (round 157)";
        }

        // Round 158 (09/08/2026) : QueueManager::processSingle() doit
        // conserver son repli sur row['ref_id'] pour first_anniversary
        // quand le recalcul MIN(id_order) ne trouve plus de commande valide.
        $qmSrc158 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php');
        if ($qmSrc158 === '') {
            $offenders[] = 'QueueManager.php introuvable (garde-fou round 158 : repli ref_id first_anniversary)';
        } else {
            $posRa158 = strpos($qmSrc158, "\$refId = (int) \$this->db->getValue(");
            $raBody158 = $posRa158 !== false ? substr($qmSrc158, $posRa158, 1800) : '';
            if ($posRa158 === false || strpos($raBody158, 'if ($refId <= 0) {') === false || strpos($raBody158, "\$refId = (int) \$row['ref_id'];") === false) {
                $offenders[] = "QueueManager::processSingle() n'a plus de repli sur row['ref_id'] pour first_anniversary — régression du bug corrigé le 09/08/2026 (round 158) : la dédup neria_behavioral_sent redeviendrait silencieusement sautée si la commande la plus ancienne du client bascule à valid=0 entre l'enqueue et l'envoi différé";
            }
        }

        // Round 158 (09/08/2026) : BehavioralCronManager::sendGhostCarts()
        // doit conserver son try/catch englobant tout le traitement par
        // ligne (pas seulement le bloc Product/Link).
        $bcmSrc158 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php');
        if ($bcmSrc158 === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (garde-fou round 158 : sendGhostCarts try/catch englobant)';
        } else {
            $posGc158 = strpos($bcmSrc158, 'private function sendGhostCarts(');
            $gcBody158 = $posGc158 !== false ? substr($bcmSrc158, $posGc158, 6500) : '';
            if ($posGc158 === false || strpos($gcBody158, 'catch (\Throwable $e) {') === false || substr_count($gcBody158, "'ghost_cart'") < 2) {
                $offenders[] = "BehavioralCronManager::sendGhostCarts() n'englobe plus tout le traitement par ligne dans un try/catch — régression du bug corrigé le 09/08/2026 (round 158) : une exception hors du try/finally interrompait de nouveau tout le lot du jour sans log exploitable";
            }
        }

        // Round 158 (09/08/2026) : CertificateManager::resolveCertificateLang()
        // doit conserver la vérification de NERIA_AUTO_LANG avant d'appliquer
        // l'algorithme pays/code-postal.
        $certSrc158 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php');
        if ($certSrc158 === '') {
            $offenders[] = 'CertificateManager.php introuvable (garde-fou round 158 : resolveCertificateLang respecte NERIA_AUTO_LANG)';
        } else {
            $posRcl158 = strpos($certSrc158, 'private function resolveCertificateLang(');
            $rclBody158 = $posRcl158 !== false ? substr($certSrc158, $posRcl158, 1200) : '';
            if ($posRcl158 === false || strpos($rclBody158, 'isAutoLangEnabled()') === false) {
                $offenders[] = "CertificateManager::resolveCertificateLang() ne vérifie plus NERIA_AUTO_LANG avant d'appliquer l'algorithme pays/code-postal — régression du bug corrigé le 09/08/2026 (round 158) : le PDF et l'email pourraient de nouveau partir dans des langues différentes quand la détection auto est désactivée";
            }
        }

        // Round 159 (09/08/2026) : CalendarManager::checkAndSendDailyEvents()
        // doit conserver son verrou scopé par idShop, pas global.
        $calSrc159 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CalendarManager.php');
        if ($calSrc159 === '' || strpos($calSrc159, "GET_LOCK('neria_calendar_check_") === false || strpos($calSrc159, "RELEASE_LOCK('neria_calendar_check_") === false) {
            $offenders[] = "CalendarManager::checkAndSendDailyEvents() n'a plus de verrou scopé par idShop — régression du bug corrigé le 09/08/2026 (round 159) : le traitement calendaire d'une boutique bloquerait de nouveau à tort celui d'une autre boutique concurrente";
        }

        // Round 159 (09/08/2026) : FontManager::generateCssVariables() doit
        // conserver ses vrais défauts de marque (pas #000000 générique)
        // pour les 4 couleurs design.
        $fmSrc159 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/FontManager.php');
        if ($fmSrc159 === '') {
            $offenders[] = 'FontManager.php introuvable (garde-fou round 159 : generateCssVariables couleurs de marque par défaut)';
        } else {
            $posGcv159 = strpos($fmSrc159, 'public function generateCssVariables(string $lang): string');
            $gcvBody159 = $posGcv159 !== false ? substr($fmSrc159, $posGcv159, 2800) : '';
            if ($posGcv159 === false
                || substr_count($gcvBody159, '\ConfigManager::DEFAULTS[\ConfigManager::KEY_COLOR_') < 4
            ) {
                $offenders[] = "FontManager::generateCssVariables() n'utilise plus les vrais défauts de marque (ConfigManager::DEFAULTS) pour sanitizeColor() — régression du bug corrigé le 09/08/2026 (round 159) : une couleur design corrompue retomberait de nouveau sur #000000 générique (fond noir + texte noir = email invisible) au lieu des vraies couleurs de marque";
            }
        }

        // Round 160 (09/08/2026) : PostmasterManager::fetchDomainStats() ne
        // doit boucler que sur l'absence légitime de données, pas sur une
        // erreur réelle (429/quota inclus).
        $pmSrc160 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php');
        if ($pmSrc160 === '') {
            $offenders[] = 'PostmasterManager.php introuvable (garde-fou round 160 : fetchDomainStats break sur erreur réelle)';
        } else {
            $posFds160 = strpos($pmSrc160, 'private function fetchDomainStats(');
            $fdsBody160 = $posFds160 !== false ? substr($pmSrc160, $posFds160, 1800) : '';
            if ($posFds160 === false || strpos($fdsBody160, 'if ($stat === null) {') === false) {
                $offenders[] = "PostmasterManager::fetchDomainStats() ne s'arrête plus immédiatement sur une erreur réelle — régression du bug corrigé le 09/08/2026 (round 160) : un 429/quota redéclencherait jusqu'à 6 appels immédiats supplémentaires, aggravant activement la pénalité";
            }
        }

        // Round 160 (09/08/2026) : les 4 boutons BO "rafraîchissement forcé"
        // doivent conserver leur débit minimal (neriaForceRefreshAllowed()).
        $mainSrc160 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/' . $this->module->name . '.php');
        if ($mainSrc160 === ''
            || strpos($mainSrc160, 'private function neriaForceRefreshAllowed(') === false
            || substr_count($mainSrc160, 'neriaForceRefreshAllowed(') < 5
        ) {
            $offenders[] = "neria.php : neriaForceRefreshAllowed() a disparu ou n'est plus appelée par les 4 boutons de rafraîchissement forcé — régression du bug corrigé le 09/08/2026 (round 160) : un double-clic redéclencherait de nouveau un appel réel sans aucun débit minimal";
        }

        // Round 160 (09/08/2026) : SignatureGenerator doit conserver l'ordre
        // generate() puis delete() (pas l'inverse), et l'alerte Watchdog sur
        // échec imagepng().
        if ($mainSrc160 !== '' && strpos($mainSrc160, "\$sigGenerator->delete(\$idShop, '', \$path);") === false) {
            $offenders[] = "neria.php : la signature n'est plus supprimée APRÈS generate() (avec le nouveau fichier exclu) — régression du bug corrigé le 09/08/2026 (round 160) : une régénération échouée effacerait de nouveau la signature fonctionnelle précédente";
        }
        $sgSrc160 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SignatureGenerator.php');
        if ($sgSrc160 === '' || strpos($sgSrc160, 'watchdog.signature_save_failed') === false) {
            $offenders[] = "SignatureGenerator::generate() n'alerte plus le Watchdog sur un échec imagepng() — régression du bug corrigé le 09/08/2026 (round 160)";
        }

        // Round 160 (09/08/2026) : LicenseManager doit conserver son
        // throttle réseau indépendant, son verrou, sa distinction de
        // révocation, et la purge de l'état résiduel quand CONFIG_KEY est vide.
        $lmSrc160 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LicenseManager.php');
        if ($lmSrc160 === ''
            || strpos($lmSrc160, 'CONFIG_LAST_ATTEMPT') === false
            || strpos($lmSrc160, "GET_LOCK('neria_license_validate'") === false
            || strpos($lmSrc160, "array_key_exists('valid', \$response)") === false
            || strpos($lmSrc160, 'if ((int) \Configuration::get(self::CONFIG_LAST_CHECK) > 0) {') === false
        ) {
            $offenders[] = "LicenseManager n'a plus l'un des 4 correctifs du round 160 (throttle réseau CONFIG_LAST_ATTEMPT, verrou GET_LOCK, distinction array_key_exists('valid',...), purge de CONFIG_LAST_CHECK résiduel) — régression du bug corrigé le 09/08/2026 (round 160)";
        }

        // Round 160 (09/08/2026) : CssInliner doit conserver son compteur
        // d'échecs scopé par boutique et protégé par GET_LOCK.
        $ciSrc160 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CssInliner.php');
        if ($ciSrc160 === ''
            || strpos($ciSrc160, "'NERIA_CSS_INLINE_FAILURES_' . \$idShop") === false
            || strpos($ciSrc160, "GET_LOCK('neria_css_inline_failures_") === false
        ) {
            $offenders[] = "CssInliner::inline() n'a plus de compteur d'échecs scopé par boutique et protégé par GET_LOCK — régression du bug corrigé le 09/08/2026 (round 160)";
        }
        $hcmSelf160 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/HealthCheckManager.php');
        if ($hcmSelf160 === '' || strpos($hcmSelf160, "'NERIA_CSS_INLINE_FAILURES_' . \$this->idShop") === false) {
            $offenders[] = "HealthCheckManager::checkCssInlinerSilentFailures() ne lit/reset plus la clé scopée par idShop — régression du bug corrigé le 09/08/2026 (round 160)";
        }

        // Round 161 (13/08/2026) : TranslationInstaller::importTemplate()
        // doit toujours journaliser via Watchdog ses 3 branches d'échec
        // (fichier introuvable/illisible/JSON invalide), et
        // importFromJson() doit détecter les clés dupliquées du JSON source
        // avant insertion (sinon l'INSERT IGNORE les tranche silencieusement).
        $tiSrc161 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/TranslationInstaller.php');
        if ($tiSrc161 === ''
            || strpos($tiSrc161, 'watchdog.translation_import_template_source_unreadable') === false
            || strpos($tiSrc161, '$seenKeys[$dedupKey]') === false
            || strpos($tiSrc161, 'watchdog.translation_duplicate_key') === false
        ) {
            $offenders[] = "TranslationInstaller a perdu l'un des correctifs du round 161 (log Watchdog des échecs silencieux d'importTemplate(), détection des clés dupliquées dans importFromJson()) — régression du bug corrigé le 13/08/2026 (round 161)";
        }

        // Round 161 (13/08/2026) : uninstall() doit nettoyer explicitement
        // les PDF nominatifs de certificates/ et mails/, indépendamment de
        // la case « supprimer les fichiers du module » du BO PS.
        if ($mainSrc160 === ''
            || strpos($mainSrc160, "cleanupNominativeFiles('certificates'") === false
            || strpos($mainSrc160, "cleanupNominativeFiles('mails'") === false
        ) {
            $offenders[] = "neria.php : uninstall() ne nettoie plus les PDF nominatifs de certificates/ et mails/ — régression du bug corrigé le 13/08/2026 (round 161) : un risque RGPD réapparaîtrait si le marchand décoche « supprimer les fichiers du module »";
        }

        // Round 161 (13/08/2026) : install() doit réconcilier un éventuel
        // schéma orphelin (tables restées en base après une suppression
        // FTP sans désinstallation propre) via needUpgrade()/runUpgradeModule().
        if ($mainSrc160 === ''
            || strpos($mainSrc160, '\Module::needUpgrade($this)') === false
            || strpos($mainSrc160, '$this->runUpgradeModule()') === false
        ) {
            $offenders[] = "neria.php : install() ne réconcilie plus le schéma via needUpgrade()/runUpgradeModule() après un succès — régression du bug corrigé le 13/08/2026 (round 161) : une réinstallation par-dessus des tables orphelines à schéma ancien ne serait plus rattrapée";
        }

        // Round 162 (13/08/2026) : registerHooks() doit lire le retour de
        // chaque registerHook() et journaliser les échecs, save_typography
        // doit inclure 'font_cyrillic'.
        if ($mainSrc160 === ''
            || strpos($mainSrc160, 'if (!$this->registerHook($hook)) {') === false
            || strpos($mainSrc160, '$failed[] = $hook;') === false
        ) {
            $offenders[] = "neria.php : registerHooks() ne détecte/journalise plus les échecs de registerHook() — régression du bug corrigé le 13/08/2026 (round 162) : un hook non enregistré (résidu ps_hook_module orphelin, panne DB) redeviendrait indétectable";
        }
        if ($mainSrc160 === '') {
            $offenders[] = 'neria.php introuvable (garde-fou round 162 : font_cyrillic dans save_typography)';
        } else {
            $posTypo162 = strpos($mainSrc160, "=== 'save_typography'");
            $typoBody162 = $posTypo162 !== false ? substr($mainSrc160, $posTypo162, 900) : '';
            if ($posTypo162 === false || strpos($typoBody162, "'font_cyrillic'            => (string) Tools::getValue") === false) {
                $offenders[] = "neria.php : le handler save_typography a de nouveau perdu 'font_cyrillic' — régression du bug corrigé le 13/08/2026 (round 162) : un marchand changeant la police cyrillique verrait 'Enregistré' sans que la valeur n'atteigne la base";
            }
        }

        // Round 162 (13/08/2026) : controllers/front/cron.php doit vérifier
        // Module::isEnabled('neria') avant de traiter la requête.
        $cronSrc162 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/controllers/front/cron.php');
        if ($cronSrc162 === '' || strpos($cronSrc162, "Module::isEnabled('neria')") === false) {
            $offenders[] = "controllers/front/cron.php ne vérifie plus Module::isEnabled('neria') — régression du bug corrigé le 13/08/2026 (round 162) : un module désactivé en BO n'empêcherait plus le cron externe de continuer à tourner";
        }

        // Round 162 (13/08/2026) : neria-admin.js doit gérer le groupe radio
        // heading_weight (retour visuel), désactiver le bouton d'aperçu
        // signature pendant la requête, et limiter le fetch Watchdog par
        // un timeout.
        $jsSrc162 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/js/neria-admin.js');
        if ($jsSrc162 === ''
            || strpos($jsSrc162, "querySelectorAll('.neria-radio-card__input')") === false
            || strpos($jsSrc162, "classList.toggle('neria-radio-card--selected'") === false
        ) {
            $offenders[] = "neria-admin.js : le groupe radio heading_weight (.neria-radio-card__input) n'a plus de retour visuel — régression du bug corrigé le 13/08/2026 (round 162)";
        }
        if ($jsSrc162 !== '') {
            $posSig162 = strpos($jsSrc162, 'function initSignaturePreview()');
            $sigBody162 = $posSig162 !== false ? substr($jsSrc162, $posSig162, 3600) : '';
            if ($posSig162 === false || strpos($sigBody162, 'btn.disabled = true;') === false || strpos($sigBody162, 'btn.disabled = false;') === false) {
                $offenders[] = "neria-admin.js : initSignaturePreview() ne désactive plus le bouton pendant la requête — régression du bug corrigé le 13/08/2026 (round 162)";
            }
        }
        if ($jsSrc162 !== '' && strpos($jsSrc162, 'new AbortController()') === false) {
            $offenders[] = "neria-admin.js : initWatchdogAnalyze() n'a plus de timeout (AbortController) sur son fetch() — régression du bug corrigé le 13/08/2026 (round 162)";
        }

        // Round 163 (13/08/2026) : WebhookManager::processQueue() doit
        // forcer status='failed' si une exception survient à la dernière
        // tentative (sinon la ligne reste 'pending'/attempts=MAX_ATTEMPTS
        // à vie, fuite permanente en base).
        $whSrc163 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WebhookManager.php');
        if ($whSrc163 === ''
            || strpos($whSrc163, '$attemptsAfterException') === false
            || strpos($whSrc163, "if (\$attemptsAfterException >= self::MAX_ATTEMPTS) {") === false
        ) {
            $offenders[] = "WebhookManager::processQueue() ne force plus status='failed' sur exception à la dernière tentative — régression du bug corrigé le 13/08/2026 (round 163) : une ligne pourrait rester 'pending'/attempts=MAX_ATTEMPTS à vie, fuite permanente en base et événement webhook perdu";
        }

        // Round 163 (13/08/2026) : neria.php ne doit plus transmettre le
        // mot de passe IMAP des bounces en clair au template BO.
        if ($mainSrc160 === '' || strpos($mainSrc160, "'pass'     => '',") === false) {
            $offenders[] = "neria.php : bounce_cfg.pass n'est plus toujours vide au rendu — régression du bug corrigé le 13/08/2026 (round 163) : le mot de passe IMAP redeviendrait lisible en clair dans le code source de la page BO";
        }

        // Round 163 (13/08/2026) : l'action auto_toggle doit gérer
        // explicitement une clé invalide (message d'erreur), pas un faux
        // message de succès basé sur une variable non initialisée. NB : on
        // ne cherche PAS l'ancien motif buggé ($current ?? false ? ...) —
        // il apparaît aussi dans le commentaire explicatif du correctif
        // lui-même (même piège que font_cyrillic au round 161), donc on
        // vérifie seulement la présence du nouveau comportement.
        if ($mainSrc160 === '' || strpos($mainSrc160, "'msg.invalid_action'") === false) {
            $offenders[] = "neria.php : l'action auto_toggle ne gère plus explicitement une clé invalide — régression du bug corrigé le 13/08/2026 (round 163) : une clé altérée côté client redonnerait un faux message de succès";
        }

        // Round 164 (13/08/2026) : design.tpl doit échapper ses champs de
        // couleur et calculer logo_url à partir de logo_path.
        $designTpl164 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/design.tpl');
        if ($designTpl164 === '' || preg_match_all("/\\|default:'#[0-9a-fA-F]+'\\|escape:'html'/", $designTpl164) < 14) {
            $offenders[] = "design.tpl : les champs de couleur ont perdu leur échappement — régression du bug corrigé le 13/08/2026 (round 164)";
        }
        if ($mainSrc160 === '' || strpos($mainSrc160, "\$designConfig['logo_url'] = !empty(\$designConfig['logo_path'])") === false) {
            $offenders[] = "neria.php : logo_url n'est plus calculée à partir de logo_path — régression du bug corrigé le 13/08/2026 (round 164) : le panneau 'Logo actuel' redeviendrait invisible même après un upload réussi";
        }

        // Round 164 (13/08/2026) : navigation.tpl doit échapper la date
        // d'expiration de licence (donnée d'un service tiers distant).
        $navTpl164 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/navigation.tpl');
        if ($navTpl164 === '' || strpos($navTpl164, "{\$ls.expires_at|escape:'html'}") === false) {
            $offenders[] = "navigation.tpl : \$ls.expires_at n'est plus échappée — régression du bug corrigé le 13/08/2026 (round 164)";
        }

        // Round 164 (13/08/2026) : QueueManager doit filtrer les messages
        // d'erreur avant stockage Watchdog/base (identifiants/tokens).
        $qmSrc164 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php');
        if ($qmSrc164 === ''
            || strpos($qmSrc164, 'private static function sanitizeErrorMessage(string $message): string') === false
            || strpos($qmSrc164, 'self::sanitizeErrorMessage($e->getMessage())') === false
            || strpos($qmSrc164, 'self::sanitizeErrorMessage($error)') === false
        ) {
            $offenders[] = "QueueManager n'assainit plus les messages d'erreur avant stockage — régression du bug corrigé le 13/08/2026 (round 164) : des identifiants/tokens d'un driver SMTP pourraient de nouveau fuiter en clair dans les logs";
        }

        // Round 164 (13/08/2026) : track.php doit scoper le throttling
        // anti-abus par IP+token, pas IP seule.
        $trackSrc164 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/controllers/front/track.php');
        if ($trackSrc164 === '' || strpos($trackSrc164, "'neria_track_rl_' . md5(\$ip . '|' . \$token)") === false) {
            $offenders[] = "track.php : la clé de throttling n'est plus scopée par IP+token — régression du bug corrigé le 13/08/2026 (round 164) : un NAT/proxy partagé referait sous-compter les stats de destinataires légitimes";
        }

        // Round 165 (14/08/2026) : MonthlyReportManager doit filtrer o.id_shop
        // dans l'attribution de CA, restaurer $this->idShop après sa boucle
        // multi-boutique, et signaler un écart de plusieurs mois dans isDue().
        $mrmSrc165 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php');
        if ($mrmSrc165 === '' || strpos($mrmSrc165, 'AND o.id_shop = {$this->idShop}') === false) {
            $offenders[] = "MonthlyReportManager::getRevenueByTemplate() ne filtre plus o.id_shop dans l'attribution de CA — régression du bug corrigé le 14/08/2026 (round 165) : le CA fuiterait de nouveau entre boutiques partageant leurs clients";
        }
        if ($mrmSrc165 === ''
            || strpos($mrmSrc165, '$originalIdShop = $this->idShop;') === false
            || strpos($mrmSrc165, '$this->idShop = $originalIdShop;') === false
        ) {
            $offenders[] = "MonthlyReportManager::checkAndSend() ne restaure plus \$this->idShop après sa boucle multi-boutique — régression du bug corrigé le 14/08/2026 (round 165)";
        }
        if ($mrmSrc165 === '' || strpos($mrmSrc165, 'watchdog.monthly_report_gap_detected') === false) {
            $offenders[] = "MonthlyReportManager::isDue() ne journalise plus d'écart détecté — régression du bug corrigé le 14/08/2026 (round 165) : un rapport mensuel manqué redeviendrait indétectable";
        }

        // Round 165 (14/08/2026) : DomainReputationManager doit propager le
        // budget DNS à checkSpf/checkDmarc/checkMx/checkBimi/resolveIp,
        // attendre réellement le verrou en cold start, et distinguer
        // ip_missing (0 pt) de skipped (IP privée, points pleins).
        $drmSrc165 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/DomainReputationManager.php');
        if ($drmSrc165 === ''
            || strpos($drmSrc165, 'private function checkSpf(string $domain, ?float $deadline = null): array') === false
            || strpos($drmSrc165, 'private function checkDmarc(string $domain, ?float $deadline = null): array') === false
            || strpos($drmSrc165, 'private function checkMx(string $domain, ?float $deadline = null): array') === false
            || strpos($drmSrc165, 'private function checkBimi(string $domain, array $dmarc, ?float $deadline = null): array') === false
            || strpos($drmSrc165, 'private function resolveIp(string $domain, ?float $deadline = null): ?string') === false
        ) {
            $offenders[] = "DomainReputationManager : le budget DNS n'est plus propagé à checkSpf/checkDmarc/checkMx/checkBimi/resolveIp — régression du bug corrigé le 14/08/2026 (round 165) : le budget censé borner le blocage du visiteur front ne couvrirait de nouveau qu'une partie du chemin d'exécution";
        }
        if ($drmSrc165 === '' || strpos($drmSrc165, "GET_LOCK('neria_domain_reputation_\" . \$this->idShop . \"', 6)") === false) {
            $offenders[] = "DomainReputationManager::runFullCheck() n'attend plus réellement le verrou en cold start (pas de cache) — régression du bug corrigé le 14/08/2026 (round 165) : le double-envoi d'alerte au tout premier check d'une boutique redeviendrait possible";
        }
        if ($drmSrc165 === '' || strpos($drmSrc165, "!empty(\$ptr['ip_missing'])") === false || strpos($drmSrc165, "!empty(\$bl['ip_missing'])") === false) {
            $offenders[] = "DomainReputationManager::computeScore() ne distingue plus ip_missing de skipped — régression du bug corrigé le 14/08/2026 (round 165) : un domaine expéditeur cassé obtiendrait de nouveau un plancher de score de 30 pts au lieu de 0";
        }

        // Round 166 (14/08/2026) : SegmentManager::recomputeAll() doit
        // purger les segments des clients sortis du périmètre de calcul,
        // comme ChurnScoreManager/PropensityScoreManager.
        $smSrc166 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SegmentManager.php');
        if ($smSrc166 === '' || strpos($smSrc166, 'DELETE t FROM `{$table}` t') === false || strpos($smSrc166, 'watchdog.segment_purge_failed') === false) {
            $offenders[] = "SegmentManager::recomputeAll() ne purge plus les segments des clients sortis du périmètre — régression du bug corrigé le 14/08/2026 (round 166) : un client purgé RGPD garderait de nouveau indéfiniment son ancien segment";
        }

        // Round 166 (14/08/2026) : ChurnScoreManager::recomputeAll() doit
        // vérifier $sqlOk AVANT le retour anticipé sur $rows vide.
        $csmSrc166 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ChurnScoreManager.php');
        if ($csmSrc166 !== '') {
            $posSqlOk166 = strpos($csmSrc166, '$sqlOk = $this->db->execute($purgeSql);');
            $posEmpty166 = $posSqlOk166 !== false ? strpos($csmSrc166, 'if (empty($rows)) {', $posSqlOk166) : false;
            $posCheck166 = $posSqlOk166 !== false ? strpos($csmSrc166, 'if (!$sqlOk) {', $posSqlOk166) : false;
            if ($posSqlOk166 === false || $posEmpty166 === false || $posCheck166 === false || $posCheck166 >= $posEmpty166) {
                $offenders[] = "ChurnScoreManager::recomputeAll() ne vérifie plus \$sqlOk avant son retour anticipé sur \$rows vide — régression du bug corrigé le 14/08/2026 (round 166) : un échec de purge redeviendrait invisible pour une boutique sans client éligible";
            }
        } else {
            $offenders[] = 'ChurnScoreManager.php introuvable (garde-fou round 166)';
        }

        // Round 166 (14/08/2026) : PropensityScoreManager::recalculateAll()
        // doit capturer le résultat du DELETE de purge et le journaliser.
        $psmSrc166 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PropensityScoreManager.php');
        if ($psmSrc166 === '' || strpos($psmSrc166, '$purgeOk = $this->db->execute($purgeSql);') === false || strpos($psmSrc166, 'watchdog.propensity_purge_failed') === false) {
            $offenders[] = "PropensityScoreManager::recalculateAll() ne capture plus le résultat du DELETE de purge — régression du bug corrigé le 14/08/2026 (round 166) : des scores obsolètes pourraient rester en base indéfiniment sans trace";
        }

        // Round 166 (14/08/2026) : LoyaltyManager::awardPoints() doit
        // resynchroniser \$idShop sur la boutique réelle de id_stat.
        $lmSrc166 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php');
        if ($lmSrc166 === '' || strpos($lmSrc166, 'SELECT id_shop FROM `{$this->prefix}neria_stat` WHERE id_stat = ') === false) {
            $offenders[] = "LoyaltyManager::awardPoints() ne resynchronise plus \$idShop sur la boutique réelle de id_stat — régression du bug corrigé le 14/08/2026 (round 166) : un \$idShop incohérent pourrait de nouveau faire échouer silencieusement un recrédit de points";
        }

        // Round 166 (14/08/2026) : neria-emergency.php doit rattraper
        // \Throwable (pas seulement \Exception) au chargement de config et
        // à la connexion PDO, et ne plus afficher de message SQL brut.
        $emergSrc166 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/neria-emergency.php');
        if ($emergSrc166 === '' || substr_count($emergSrc166, 'catch (\Throwable $e) {') < 2) {
            $offenders[] = "neria-emergency.php ne rattrape plus \\Throwable sur le chargement de config et la connexion PDO — régression du bug corrigé le 14/08/2026 (round 166) : un ParseError/Error redeviendrait fatal et non géré";
        }
        if ($emergSrc166 === '' || strpos($emergSrc166, '$logsError = $e->getMessage();') !== false) {
            $offenders[] = "neria-emergency.php affiche de nouveau le message d'exception brut de la lecture des logs — régression du bug corrigé le 14/08/2026 (round 166) : une erreur SQL redeviendrait exposée en clair après authentification";
        }

        // Round 167 (14/08/2026) : WaitlistManager doit gérer le stock
        // partagé, verrouiller notifyProduct(), re-vérifier l'inscription
        // avant l'envoi, suivre les déclinaisons et purger les entrées
        // obsolètes.
        $wlSrc167 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php');
        if ($wlSrc167 === ''
            || strpos($wlSrc167, '->getGroup()->share_stock') === false
            || strpos($wlSrc167, 'id_shop = 0 AND id_shop_group = ') === false
        ) {
            $offenders[] = "WaitlistManager ne gère plus le mode stock partagé entre boutiques d'un groupe — régression du bug corrigé le 14/08/2026 (round 167) : aucun inscrit ne serait de nouveau jamais notifié pour une boutique en stock partagé";
        }
        if ($wlSrc167 === '' || strpos($wlSrc167, "GET_LOCK('\" . pSQL(\$lockName) . \"', 0)") === false) {
            $offenders[] = "WaitlistManager::notifyProduct() n'est plus protégée par un GET_LOCK — régression du bug corrigé le 14/08/2026 (round 167) : deux appels concurrents pourraient de nouveau chacun notifier des inscrits différents pour la même quantité limitée";
        }
        if ($wlSrc167 === '' || strpos($wlSrc167, '$stillRegistered') === false) {
            $offenders[] = "WaitlistManager ne re-vérifie plus l'inscription juste avant l'envoi — régression du bug corrigé le 14/08/2026 (round 167) : un client désinscrit entre le claim et l'envoi recevrait de nouveau l'email sans mitigation";
        }
        if ($wlSrc167 === ''
            || strpos($wlSrc167, 'int $idProductAttribute = 0): bool') === false
            || strpos($wlSrc167, 'id_product_attribute = {$idProductAttribute}') === false
        ) {
            $offenders[] = "WaitlistManager ne suit plus les inscriptions par déclinaison précise — régression du bug corrigé le 14/08/2026 (round 167) : un client pourrait de nouveau être notifié pour la mauvaise taille/couleur";
        }
        if ($wlSrc167 === '' || strpos($wlSrc167, 'function purgeStaleEntries(int $olderThanDays = 365, ?int $idShop = null): int') === false) {
            $offenders[] = "WaitlistManager::purgeStaleEntries() a disparu — régression du bug corrigé le 14/08/2026 (round 167) : neria_waitlist grossirait de nouveau indéfiniment";
        }

        // Round 167 (14/08/2026) : checkWaitlistBacklog() doit gérer le
        // même mode stock partagé que WaitlistManager::notifyProduct().
        $hcmSelf167 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/HealthCheckManager.php');
        if ($hcmSelf167 === ''
            || strpos($hcmSelf167, "\$rowShop = new \Shop((int) \$row['id_shop']);") === false
            || strpos($hcmSelf167, "if ((bool) \$rowShop->getGroup()->share_stock) {") === false
        ) {
            $offenders[] = "HealthCheckManager::checkWaitlistBacklog() ne gère plus le mode stock partagé — régression du bug corrigé le 14/08/2026 (round 167) : ce garde-fou ne détecterait de nouveau jamais de backlog pour une boutique en stock partagé";
        }

        // Round 167 (14/08/2026) : CertificateManager — redownload() doit
        // vérifier l'erreur de generatePdf(), le QR doit se désactiver sur
        // URL de base invalide, la note de l'artisan doit être bornée, un
        // échec d'envoi email doit être journalisé.
        $cmSrc167 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php');
        if ($cmSrc167 === '' || strpos($cmSrc167, "isset(\$result['error'])") === false) {
            $offenders[] = "CertificateManager::redownload() ne vérifie plus le retour de generatePdf() — régression du bug corrigé le 14/08/2026 (round 167)";
        }
        if ($cmSrc167 === ''
            || strpos($cmSrc167, "\$qrBaseUrl === '' || !\Validate::isUrl(\$qrBaseUrl)") === false
            || strpos($cmSrc167, 'watchdog.certificate_qr_base_url_invalid') === false
        ) {
            $offenders[] = "CertificateManager ne désactive plus le QR code sur URL de base invalide — régression du bug corrigé le 14/08/2026 (round 167) : un QR cassé pourrait de nouveau être imprimé silencieusement";
        }
        if ($cmSrc167 === '' || strpos($cmSrc167, 'mb_strlen($artisanNote) > 280') === false) {
            $offenders[] = "CertificateManager ne borne plus la longueur de la note de l'artisan — régression du bug corrigé le 14/08/2026 (round 167) : une note très longue pourrait de nouveau chevaucher le pied de page du certificat PDF";
        }
        if ($cmSrc167 === '' || strpos($cmSrc167, 'Certificat émis mais email non envoyé') === false) {
            $offenders[] = "CertificateManager n'journalise plus l'échec d'envoi d'email de certificat — régression du bug corrigé le 14/08/2026 (round 167) : un certificat 'fantôme' redeviendrait indétectable dans le journal";
        }

        // Round 167 (14/08/2026) : UpsellManager — cast (int) sur $idShop et
        // GROUP BY déterministe pour findByCategoryBestseller().
        $umSrc167 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php');
        if ($umSrc167 === '' || strpos($umSrc167, "AND id_shop = \" . (int) \$idShop") === false) {
            $offenders[] = "UpsellManager::getStats() n'interpole plus \$idShop avec un cast (int) explicite — régression du bug corrigé le 14/08/2026 (round 167)";
        }
        if ($umSrc167 === '' || strpos($umSrc167, 'MIN(pl.name) AS name, MIN(cp.id_category) AS id_category') === false) {
            $offenders[] = "UpsellManager::findByCategoryBestseller() n'est plus déterministe (GROUP BY/MIN manquants) — régression du bug corrigé le 14/08/2026 (round 167) : un produit multi-catégories redeviendrait affiché avec une catégorie non déterministe";
        }

        // Round 168 (14/08/2026) : StatsManager::record() doit formater le
        // revenu via number_format() (jamais sprintf('%.2f'), sensible à
        // LC_NUMERIC) et detectMpp() doit calculer le délai écoulé côté
        // MySQL (TIMESTAMPDIFF), pas via time()-strtotime() côté PHP.
        $smSrc168 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/StatsManager.php');
        if ($smSrc168 === '' || strpos($smSrc168, "number_format(\$revenue, 2, '.', '')") === false) {
            $offenders[] = "StatsManager::record() ne formate plus le revenu via number_format() — régression du bug corrigé le 14/08/2026 (round 168) : sous une locale à virgule décimale (LC_NUMERIC), l'INSERT échouerait de nouveau silencieusement et la conversion serait perdue";
        }
        if ($smSrc168 === '' || strpos($smSrc168, 'TIMESTAMPDIFF(SECOND') === false) {
            $offenders[] = "StatsManager::detectMpp() ne calcule plus le délai écoulé côté MySQL (TIMESTAMPDIFF) — régression du bug corrigé le 14/08/2026 (round 168) : la classification MPP redeviendrait dépendante de l'alignement des fuseaux PHP/MySQL";
        }

        // Round 168 (14/08/2026) : EmailRenderer doit factoriser le calcul
        // de l'empreinte carbone dans buildCarbonHtml() (formule dupliquée
        // auparavant) et adapter le séparateur décimal du CO2 à la langue.
        $erSrc168 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php');
        if ($erSrc168 === '' || strpos($erSrc168, 'private function buildCarbonHtml(string $compiled, string $lang): string') === false) {
            $offenders[] = "EmailRenderer::buildCarbonHtml() a disparu — régression du bug corrigé le 14/08/2026 (round 168) : le calcul CO2 redeviendrait dupliqué entre l'aperçu BO et l'envoi réel, risquant une désynchronisation";
        }
        if ($erSrc168 === '' || strpos($erSrc168, 'CARBON_COMMA_DECIMAL_LANGS') === false) {
            $offenders[] = "EmailRenderer ne choisit plus le séparateur décimal du CO2 selon la langue — régression du bug corrigé le 14/08/2026 (round 168) : un footer en français afficherait de nouveau '~0.3g CO₂' avec un point";
        }

        // Round 168 (14/08/2026) : QueueManager::getStats() doit fenêtrer le
        // calcul du peak_hour sur 90 jours, cohérent avec les autres stats
        // de la méthode (30j), pas sur tout l'historique des commandes.
        $qmSrc168 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php');
        if ($qmSrc168 === '' || strpos($qmSrc168, 'date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)') === false) {
            $offenders[] = "QueueManager::getStats() ne fenêtre plus le calcul du peak_hour — régression du bug corrigé le 14/08/2026 (round 168) : le pic horaire redeviendrait calculé sur tout l'historique, de moins en moins représentatif sur une boutique ancienne";
        }

        // Round 170 (15/08/2026) : neria_certificate doit avoir id_customer
        // dès l'installation fraîche (install.sql), convergent avec
        // upgrade-1.0.39.php.
        $installSql170 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/sql/install.sql');
        if ($installSql170 === '') {
            $offenders[] = 'install.sql introuvable (garde-fou round 170 : id_customer sur neria_certificate)';
        } else {
            $posCert170 = strpos($installSql170, 'CREATE TABLE IF NOT EXISTS `PREFIX_neria_certificate`');
            $certBody170 = $posCert170 !== false ? substr($installSql170, $posCert170, 700) : '';
            if ($posCert170 === false || strpos($certBody170, '`id_customer`') === false) {
                $offenders[] = "install.sql ne définit plus id_customer sur neria_certificate — régression du bug corrigé le 15/08/2026 (round 170) : toute installation fraîche recréerait une table sans cette colonne, cassant purgeCustomerData() et laissant les certificats survivre à une demande d'effacement RGPD";
            }
        }

        // Round 170 (15/08/2026) : MultiClientPreviewManager — addBanner()
        // doit garder son filet ?? $html, et pollLitmus()/pollEmailOnAcid()
        // doivent valider $testId en interne.
        $mcpSrc170 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/MultiClientPreviewManager.php');
        if ($mcpSrc170 === '') {
            $offenders[] = 'MultiClientPreviewManager.php introuvable (garde-fou round 170)';
        } else {
            $posAb170 = strpos($mcpSrc170, 'private function addBanner(string $html, string $client): string');
            // Round 186 : fenêtre élargie 1500→1900 — le correctif de la
            // regex (attributs entre guillemets) a allongé cette méthode.
            $abBody170 = $posAb170 !== false ? substr($mcpSrc170, $posAb170, 1900) : '';
            if ($posAb170 === false || strpos($abBody170, "\$html, 1) ?? \$html;") === false) {
                $offenders[] = "MultiClientPreviewManager::addBanner() n'a plus de filet ?? \$html — régression du bug corrigé le 15/08/2026 (round 170) : un échec PCRE (pcre.backtrack_limit dépassé) redeviendrait une TypeError fatale plantant tout le rendu multi-client";
            }
            if (substr_count($mcpSrc170, "preg_match('/^[a-zA-Z0-9_\\-]+\$/', \$testId)") < 2) {
                $offenders[] = "MultiClientPreviewManager::pollLitmus()/pollEmailOnAcid() ne valident plus \$testId en interne — régression du bug corrigé le 15/08/2026 (round 170) : la sanitisation redeviendrait entièrement déléguée à l'appelant";
            }
        }

        // Round 170 (15/08/2026) : VoiceProfileManager — getProfile() null-
        // safe, normalizeWordListInput() borne la longueur d'entrée,
        // textContainsWords() nettoie l'UTF-8 invalide avant matching.
        $vpmSrc170 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/VoiceProfileManager.php');
        if ($vpmSrc170 === '') {
            $offenders[] = 'VoiceProfileManager.php introuvable (garde-fou round 170)';
        } else {
            if (strpos($vpmSrc170, "(string) (\$row['banned_words'] ?? '')") === false) {
                $offenders[] = "VoiceProfileManager::getProfile() n'est plus null-safe sur les colonnes texte — régression du bug corrigé le 15/08/2026 (round 170) : (string) null émettrait de nouveau un warning de dépréciation sous PHP 8.1+ pour une ligne insérée hors de saveProfile()";
            }
            if (strpos($vpmSrc170, 'MAX_WORD_LENGTH') === false) {
                $offenders[] = "VoiceProfileManager ne borne plus la longueur d'une entrée de la liste de mots — régression du bug corrigé le 15/08/2026 (round 170) : un mot de taille arbitraire pourrait de nouveau être stocké verbatim puis réinjecté dans une regex à chaque sauvegarde de traduction";
            }
            if (strpos($vpmSrc170, "mb_convert_encoding(\$plainText, 'UTF-8', 'UTF-8')") === false) {
                $offenders[] = "VoiceProfileManager::textContainsWords() ne nettoie plus l'UTF-8 invalide avant matching — régression du bug corrigé le 15/08/2026 (round 170) : une ligne avec de l'UTF-8 corrompu échapperait de nouveau silencieusement à tout l'audit (bannis ET préférés)";
            }
        }

        // Round 171 (15/08/2026) : SearchConsoleManager::fetchAndCache()
        // doit écrire le cache sur ses 2 retours anticipés (no_site,
        // no_matching_site), et ne préserver CONFIG_LAST_ERROR que si
        // queries ET pages n'ont pas échoué.
        $gscSrc171 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php');
        if ($gscSrc171 === '') {
            $offenders[] = 'SearchConsoleManager.php introuvable (garde-fou round 171)';
        } else {
            $posNoSite171 = strpos($gscSrc171, 'watchdog.gsc_no_site');
            $bodyNoSite171 = $posNoSite171 !== false ? substr($gscSrc171, $posNoSite171, 900) : '';
            if ($posNoSite171 === false || strpos($bodyNoSite171, 'CONFIG_CACHE_TIME), time());') === false) {
                $offenders[] = "SearchConsoleManager::fetchAndCache() n'écrit plus le cache sur son retour 'aucun site vérifié' — régression du bug corrigé le 15/08/2026 (round 171) : chaque page BO rappellerait de nouveau l'API GSC /sites en direct";
            }
            $posNoMatch171 = strpos($gscSrc171, 'watchdog.gsc_no_matching_site');
            $bodyNoMatch171 = $posNoMatch171 !== false ? substr($gscSrc171, $posNoMatch171, 700) : '';
            if ($posNoMatch171 === false || strpos($bodyNoMatch171, 'CONFIG_CACHE_TIME), time());') === false) {
                $offenders[] = "SearchConsoleManager::fetchAndCache() n'écrit plus le cache sur son retour 'aucun site ne correspond' — régression du bug corrigé le 15/08/2026 (round 171)";
            }
            if (strpos($gscSrc171, 'if ($queries !== null && $pages !== null) {') === false) {
                $offenders[] = "SearchConsoleManager::fetchAndCache() ne conditionne plus l'effacement de CONFIG_LAST_ERROR à la réussite de queries ET pages — régression du bug corrigé le 15/08/2026 (round 171) : un échec isolé effacerait de nouveau silencieusement une vraie erreur";
            }
        }

        // Round 171 (15/08/2026) : PageSpeedManager — cooldown après échec
        // total (isInFailureCooldown()), messages d'erreur combinés par
        // stratégie (recordStrategyError()), 429 distingué des autres codes.
        $psmSrc171 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PageSpeedManager.php');
        if ($psmSrc171 === '') {
            $offenders[] = 'PageSpeedManager.php introuvable (garde-fou round 171)';
        } else {
            if (strpos($psmSrc171, 'private function isInFailureCooldown(): bool') === false) {
                $offenders[] = "PageSpeedManager::isInFailureCooldown() a disparu — régression du bug corrigé le 15/08/2026 (round 171) : un échec total (mobile ET desktop) ne bloquerait plus les tentatives suivantes, risquant d'épuiser le quota API pendant une panne";
            }
            if (strpos($psmSrc171, 'private function recordStrategyError(string $strategy, string $msg): void') === false) {
                $offenders[] = "PageSpeedManager::recordStrategyError() a disparu — régression du bug corrigé le 15/08/2026 (round 171) : le message d'erreur mobile et desktop redeviendrait écrasé au lieu d'être combiné";
            }
            if (strpos($psmSrc171, "if (\$httpCode === 429) {") === false) {
                $offenders[] = "PageSpeedManager::fetchStrategy() ne distingue plus le 429 (quota dépassé) des autres erreurs HTTP — régression du bug corrigé le 15/08/2026 (round 171) : plus de cooldown spécifique plus long après un rate-limit";
            }
        }

        // Round 171 (15/08/2026) : SeoApiManager — getReport() retombe sur
        // le dernier rapport en cache sur échec de runCheck(), et
        // httpGet()/fetchMoz() utilisent $body === false (pas !$body).
        $seoSrc171 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SeoApiManager.php');
        if ($seoSrc171 === '') {
            $offenders[] = 'SeoApiManager.php introuvable (garde-fou round 171)';
        } else {
            if (strpos($seoSrc171, '$stale = $this->getCachedReport();') === false) {
                $offenders[] = "SeoApiManager::getReport() ne retombe plus sur le dernier rapport en cache sur échec — régression du bug corrigé le 15/08/2026 (round 171) : une panne transitoire provoquerait de nouveau un black-out complet du widget SEO";
            }
            if (substr_count($seoSrc171, 'if ($body === false || $httpCode !== 200) {') < 2) {
                $offenders[] = "SeoApiManager::httpGet()/fetchMoz() n'utilisent plus \$body === false — régression du bug corrigé le 15/08/2026 (round 171) : une réponse HTTP 200 dont le corps vaut '0' serait de nouveau traitée à tort comme un échec";
            }
        }

        // Round 172 (15/08/2026) : CryptoManager — lastDecryptFailed()
        // distingue un échec réel de déchiffrement d'une valeur jamais
        // configurée, et le throttle de log est par motif distinct.
        $cryptoSrc172 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CryptoManager.php');
        if ($cryptoSrc172 === '') {
            $offenders[] = 'CryptoManager.php introuvable (garde-fou round 172)';
        } else {
            if (strpos($cryptoSrc172, 'public static function lastDecryptFailed(): bool') === false) {
                $offenders[] = "CryptoManager::lastDecryptFailed() a disparu — régression du bug corrigé le 15/08/2026 (round 172) : un échec réel de déchiffrement redeviendrait indiscernable d'un secret jamais configuré, masquant silencieusement une intégration cassée (OAuth, IMAP...)";
            }
            if (strpos($cryptoSrc172, 'static $loggedReasons = [];') === false) {
                $offenders[] = "CryptoManager::logDecryptFailure() ne journalise plus par motif distinct — régression du bug corrigé le 15/08/2026 (round 172) : un seul échec serait de nouveau tracé par requête, quel que soit le nombre de causes différentes";
            }
        }

        // Round 172 (15/08/2026) : LoyaltyManager — DELETE de nettoyage
        // scopé par id_shop (symétrique à l'UPDATE), fenêtre du récap non
        // tronquée à 60 jours.
        $loySrc172 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php');
        if ($loySrc172 === '') {
            $offenders[] = 'LoyaltyManager.php introuvable (garde-fou round 172)';
        } else {
            $posFail172 = strpos($loySrc172, 'if (!$cartRule->add()) {');
            $failBody172 = $posFail172 !== false ? substr($loySrc172, $posFail172, 1400) : '';
            if ($posFail172 === false || strpos($failBody172, 'AND id_shop = " . $reservationShopId') === false) {
                $offenders[] = "LoyaltyManager::generateVoucher() ne filtre plus le DELETE de nettoyage par id_shop — régression du bug corrigé le 15/08/2026 (round 172) : un échec de CartRule::add() sur une boutique supprimerait de nouveau AUSSI la réservation d'une autre boutique en mode séparé, provoquant l'émission d'un second bon pour un palier déjà récompensé";
            }
            if (strpos($loySrc172, 'min($days, 400)') === false) {
                $offenders[] = "LoyaltyManager::computeRecapWindowDays() ne relève plus le plafond à 400 jours — régression du bug corrigé le 15/08/2026 (round 172) : après une panne cron de plus de 60 jours, les points gagnés au-delà seraient de nouveau tronqués et disparaîtraient définitivement du prochain récap";
            }
        }

        // Round 172 (15/08/2026) : BounceManager::parsePostmark() doit
        // reconnaître RecordType==='Bounce' et classer 'soft' les types
        // Postmark temporaires connus (Transient, Blocked, etc.).
        $bmSrc172 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/BounceManager.php');
        if ($bmSrc172 === '') {
            $offenders[] = 'BounceManager.php introuvable (garde-fou round 172)';
        } else {
            if (strpos($bmSrc172, "(\$p['RecordType'] ?? '') === 'Bounce'") === false) {
                $offenders[] = "BounceManager::parsePostmark() ne reconnaît plus RecordType==='Bounce' — régression du bug corrigé le 15/08/2026 (round 172) : les types Postmark temporaires (Transient/Blocked/Undeliverable/...) seraient de nouveau silencieusement ignorés, aucune adresse à boîte pleine répétée ne serait jamais suivie";
            }
            if (strpos($bmSrc172, 'POSTMARK_SOFT_TYPES') === false) {
                $offenders[] = "BounceManager::parsePostmark() ne classe plus les types Postmark temporaires connus en 'soft' — régression du bug corrigé le 15/08/2026 (round 172) : un échec temporaire (boîte pleine) bloquerait de nouveau définitivement l'adresse comme un hard bounce";
            }
        }

        $cssInlinerSrc173 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CssInliner.php');
        if ($cssInlinerSrc173 === '') {
            $offenders[] = 'CssInliner.php introuvable (garde-fou round 173)';
        } elseif (strpos($cssInlinerSrc173, '$fromInline') === false) {
            $offenders[] = "CssInliner::merge() ne distingue plus l'origine des déclarations (\$fromInline) — régression du bug corrigé le 15/08/2026 (round 173) : une propriété dupliquée au sein d'une même règle (ex. fallback color:#999;color:red) retomberait sur la PREMIÈRE valeur au lieu de la dernière, cascade CSS standard de nouveau violée, rendu divergent entre Apple Mail et Gmail/Outlook";
        }

        $toolsSrc173 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/NeriaTools.php');
        if ($toolsSrc173 === '') {
            $offenders[] = 'NeriaTools.php introuvable (garde-fou round 173)';
        } else {
            if (strpos($toolsSrc173, 'if ($ts === false) {') === false) {
                $offenders[] = "NeriaTools::formatDate() ne distingue plus un échec réel de strtotime() (false) d'un timestamp epoch 0 valide — régression du bug corrigé le 15/08/2026 (round 173) : un timestamp 0 (1970-01-01) serait de nouveau traité à tort comme un échec de parsing et renverrait la chaîne brute";
            }
            if (strpos($toolsSrc173, '$contextLangId = $context->language !== null') === false) {
                $offenders[] = "NeriaTools::displayPrice() accède de nouveau directement à \$context->language->id sans vérifier que \$context->language n'est pas null — régression du bug corrigé le 15/08/2026 (round 173) : warning PHP 8 émis à chaque appel cron/CLI avec \$idLang explicite (contexte langue incomplet), polluant les logs";
            }
            if (strpos($toolsSrc173, 'if ($diff <= 0) {') === false) {
                $offenders[] = "NeriaTools::timeAgo() ne gère plus explicitement une date future (diff négatif) — régression du bug latent corrigé le 15/08/2026 (round 173) : un diff négatif retomberait de nouveau sur 'à l'instant' par accident (via \$diff < 60) plutôt que par un traitement explicite, sans garantie si la méthode est un jour raccrochée";
            }
        }

        $clvSrc174 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ClvManager.php');
        if ($clvSrc174 === '') {
            $offenders[] = 'ClvManager.php introuvable (garde-fou round 174)';
        } else {
            $posRefund174 = strpos($clvSrc174, '// ── 1 requête : remboursements (avoirs) pour TOUS les candidats');
            $refundBlock174 = $posRefund174 !== false ? substr($clvSrc174, $posRefund174, 1800) : '';
            if ($posRefund174 === false || strpos($refundBlock174, "AND o.`id_shop` = ' . \$this->idShop . ' AND o.`valid` = 1") === false) {
                $offenders[] = "ClvManager::getTopCustomers() ne filtre plus refundAgg par o.valid = 1 — régression du bug corrigé le 15/08/2026 (round 174) : un avoir lié à une commande annulée serait de nouveau soustrait à tort du CLV batch d'un client, incohérence avec sa fiche individuelle";
            }
        }

        $voiceSrc174 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/VoiceProfileManager.php');
        if ($voiceSrc174 === '') {
            $offenders[] = 'VoiceProfileManager.php introuvable (garde-fou round 174)';
        } else {
            $posMethod174   = strpos($voiceSrc174, 'private function normalizeWordListInput');
            $methodBody174  = $posMethod174 !== false ? substr($voiceSrc174, $posMethod174, 1200) : '';
            $posKey174      = strpos($methodBody174, '$key = mb_strtolower($word);');
            $posTruncate174 = strpos($methodBody174, 'mb_strlen($word) > self::MAX_WORD_LENGTH) {');
            if ($posMethod174 === false || $posKey174 === false || $posTruncate174 === false || $posKey174 > $posTruncate174) {
                $offenders[] = "VoiceProfileManager::normalizeWordListInput() calcule de nouveau la clé de dédoublonnage APRÈS la troncature à MAX_WORD_LENGTH — régression du bug corrigé le 15/08/2026 (round 174) : deux entrées distinctes de plus de 100 caractères partageant le même préfixe fusionneraient de nouveau silencieusement";
            }
        }

        $lookSrc174 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LookCompletionManager.php');
        if ($lookSrc174 === '') {
            $offenders[] = 'LookCompletionManager.php introuvable (garde-fou round 174)';
        } elseif (strpos($lookSrc174, 'category_shop') === false) {
            $offenders[] = "LookCompletionManager::getCategories() ne filtre plus par boutique (jointure category_shop disparue) — régression du bug corrigé le 15/08/2026 (round 174) : le BO proposerait de nouveau des catégories d'autres boutiques pour créer une règle de complétion de look";
        }

        $fontSrc174 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/FontManager.php');
        if ($fontSrc174 === '') {
            $offenders[] = 'FontManager.php introuvable (garde-fou round 174)';
        } elseif (strpos($fontSrc174, 'mb_strlen($b) <=> mb_strlen($a)') === false) {
            $offenders[] = "FontManager::getFontNameForLang() ne trie plus les noms de police par longueur décroissante avant la recherche — régression du bug corrigé le 15/08/2026 (round 174) : le match redeviendrait dépendant de l'ordre de déclaration de FONT_CATALOG";
        }

        $histSrc175 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/TranslationHistoryManager.php');
        if ($histSrc175 === '') {
            $offenders[] = 'TranslationHistoryManager.php introuvable (garde-fou round 175)';
        } else {
            $posIf175 = strpos($histSrc175, 'if ($acquired !== 1) {');
            $posTry175 = $posIf175 !== false ? strpos($histSrc175, 'try {', $posIf175) : false;
            $ifBlock175 = ($posIf175 !== false && $posTry175 !== false) ? substr($histSrc175, $posIf175, $posTry175 - $posIf175) : '';
            if ($posIf175 === false || $posTry175 === false || strpos($ifBlock175, 'return;') === false) {
                $offenders[] = "TranslationHistoryManager::record() n'exécute plus de return après un échec de GET_LOCK() — régression du bug corrigé le 15/08/2026 (round 175) : l'INSERT + pruneKey() s'exécuteraient de nouveau même sans verrou obtenu, recréant la race condition du round 138 malgré le warning journalisé";
            }
        }

        $ghSrc175 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/GoldenHourManager.php');
        if ($ghSrc175 === '') {
            $offenders[] = 'GoldenHourManager.php introuvable (garde-fou round 175)';
        } elseif (strpos($ghSrc175, 'o.`id_shop`        = s.`id_shop`') === false) {
            $offenders[] = "GoldenHourManager::computeRecommendations() ne filtre plus sa jointure ouverture/envoi par o.id_shop = s.id_shop — régression du bug corrigé le 15/08/2026 (round 175) : une collision de tracking_token entre 2 boutiques d'une même install multi-shop fausserait de nouveau le taux d'ouverture et l'heure optimale recommandée";
        }

        $mcpSrc175 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/MultiClientPreviewManager.php');
        if ($mcpSrc175 === '') {
            $offenders[] = 'MultiClientPreviewManager.php introuvable (garde-fou round 175)';
        } else {
            $posAol175 = strpos($mcpSrc175, 'private function transformAol(string $html): string');
            $aolBody175 = $posAol175 !== false ? substr($mcpSrc175, $posAol175, 900) : '';
            if ($posAol175 === false || strpos($aolBody175, 'stripStyleAndLinkTags($html)') === false) {
                $offenders[] = "MultiClientPreviewManager::transformAol() n'appelle plus stripStyleAndLinkTags() — régression du bug corrigé le 15/08/2026 (round 175) : le libellé AOL promet 'media queries et styles supprimés' mais seule stripMediaQueries() serait de nouveau appelée, laissant les styles non-media intacts dans l'aperçu";
            }
        }

        $calSrc175 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CalendarManager.php');
        if ($calSrc175 === '') {
            $offenders[] = 'CalendarManager.php introuvable (garde-fou round 175)';
        } else {
            foreach (['calculateRamadanStart', 'calculateEidAlFitr', 'calculateEidAlAdha', 'hijriToJdn', 'jdnToDateTime'] as $deadMethod175) {
                if (strpos($calSrc175, "function {$deadMethod175}(") !== false) {
                    $offenders[] = "CalendarManager::{$deadMethod175}() a été réintroduite — cette méthode était du code mort (jamais appelée, calculateEventDate() court-circuite les événements hégiriens vers le NIVEAU 3) supprimé le 15/08/2026 (round 175) ; si elle est réintroduite, vérifier qu'elle est bien appelée quelque part et que son résultat est fiable avant de la garder";
                }
            }
        }

        $churnSrc176 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ChurnScoreManager.php');
        if ($churnSrc176 === '') {
            $offenders[] = 'ChurnScoreManager.php introuvable (garde-fou round 176)';
        } elseif (strpos($churnSrc176, '$rowsPeriods = is_array($rowsPeriods) ? $rowsPeriods : [];') === false) {
            $offenders[] = "ChurnScoreManager::recomputeAll() a de nouveau un early return AVANT le bloc de purge quand \$rowsPeriods est vide — régression du bug corrigé le 15/08/2026 (round 176) : une boutique dormante (ou après une purge RGPD massive de neria_stat) garderait indéfiniment ses anciennes lignes neria_churn_score, y compris des scores à risque élevé, dans getHighRiskCustomers()";
        }

        $erSrc176 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php');
        if ($erSrc176 === '') {
            $offenders[] = 'EmailRenderer.php introuvable (garde-fou round 176)';
        } else {
            $posInject176 = strpos($erSrc176, 'private function injectCustomVars(array &$templateVars): void');
            $injectBody176 = $posInject176 !== false ? substr($erSrc176, $posInject176, 1500) : '';
            if ($posInject176 === false || strpos($injectBody176, 'array_key_exists($rawKey, $templateVars)') === false) {
                $offenders[] = "EmailRenderer::injectCustomVars() n'utilise plus array_key_exists() pour vérifier si une variable est déjà présente — régression du bug corrigé le 15/08/2026 (round 176) : empty() traiterait de nouveau '0'/'' comme absent, écrasant à tort une valeur légitime malgré le docblock qui promet de ne jamais remplacer une valeur déjà présente";
            }
            if (strpos($erSrc176, 'private function voucherRateFromCode(string $code, string $lang, int $idShop): string') === false) {
                $offenders[] = "EmailRenderer::voucherRateFromCode() ne prend plus \$idShop en paramètre — régression du bug corrigé le 15/08/2026 (round 176) : le taux affiché dans {discount} pourrait de nouveau provenir du premier cart_rule trouvé en base, potentiellement celui d'une autre boutique en cas de code de bon dupliqué entre boutiques";
            } elseif (strpos($erSrc176, 'NOT EXISTS') === false) {
                $offenders[] = "EmailRenderer::voucherRateFromCode() ne conserve plus le repli NOT EXISTS sur les cart_rule non restreints — régression : un filtre id_shop strict (INNER JOIN) exclurait à tort la quasi-totalité des bons réels (cart_rule_shop ne contient une ligne que pour les cart_rule explicitement restreints à des boutiques)";
            }
        }

        $licSrc176 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LicenseManager.php');
        if ($licSrc176 === '') {
            $offenders[] = 'LicenseManager.php introuvable (garde-fou round 176)';
        } elseif (strpos($licSrc176, 'GRACE_LAST_CHECK_MAX_DAYS') === false) {
            $offenders[] = "LicenseManager::isWithinGracePeriod() n'a plus de plafond GRACE_LAST_CHECK_MAX_DAYS sur la grâce 'dernière validation réussie' — régression du bug corrigé le 15/08/2026 (round 176) : un client dont le serveur de licences ne répond plus jamais après expiration naturelle enverrait de nouveau indéfiniment";
        }

        $abSrc177 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ABTestManager.php');
        if ($abSrc177 === '') {
            $offenders[] = 'ABTestManager.php introuvable (garde-fou round 177)';
        } elseif (strpos($abSrc177, "SET `abtest_variant` = ''") === false) {
            $offenders[] = "AbTestManager::deleteTests() ne désétiquette plus abtest_variant via une chaîne vide — régression du bug corrigé le 15/08/2026 (round 177) : SET abtest_variant = NULL échoue silencieusement en mode SQL strict (colonne NOT NULL), laissant les anciens événements A/B polluer le calcul de significativité d'un futur test sur ce même template";
        }

        $certSrc177 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php');
        if ($certSrc177 === '') {
            $offenders[] = 'CertificateManager.php introuvable (garde-fou round 177)';
        } else {
            $posSend177 = strpos($certSrc177, '$err = $this->sendCertificateEmail(');
            $sendBody177 = $posSend177 !== false ? substr($certSrc177, $posSend177, 250) : '';
            if ($posSend177 === false || strpos($sendBody177, '$idLangProduct') === false) {
                $offenders[] = "CertificateManager::issue() n'appelle plus sendCertificateEmail() avec \$idLangProduct — régression du bug corrigé le 15/08/2026 (round 177) : l'email pourrait de nouveau être envoyé dans (int) \$customer->id_lang au lieu de la langue réellement résolue pour le PDF, cassant la parité PDF/email";
            }
            $posIssue177 = strpos($certSrc177, 'public function issue(');
            $issueBody177 = $posIssue177 !== false ? substr($certSrc177, $posIssue177, 9000) : '';
            if ($posIssue177 === false || strpos($issueBody177, "GET_LOCK('") === false || strpos($issueBody177, "RELEASE_LOCK('") === false) {
                $offenders[] = "CertificateManager::issue() n'utilise plus de verrou nommé MySQL autour de la résolution du numéro de série — régression du bug corrigé le 15/08/2026 (round 177) : la fenêtre TOCTOU entre serialExists() et l'INSERT redeviendrait exploitable sous forte concurrence";
            }
        }

        $drmSrc177 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/DomainReputationManager.php');
        if ($drmSrc177 === '') {
            $offenders[] = 'DomainReputationManager.php introuvable (garde-fou round 177)';
        } else {
            if (strpos($drmSrc177, '$dnsError = ($raw === false);') === false) {
                $offenders[] = "DomainReputationManager::checkSpf()/checkDmarc() ne distinguent plus une erreur DNS réseau (false) d'un NXDOMAIN légitime ([]) — régression du bug corrigé le 15/08/2026 (round 177) : une panne DNS transitoire serait de nouveau mise en cache 24h comme un domaine confirmé sans protection";
            }
            if (strpos($drmSrc177, "if (\$result === false) {\n                continue;\n            }") === false) {
                $offenders[] = "DomainReputationManager::checkBlacklists() compte de nouveau une requête RBL en échec réseau dans 'checked' — régression du bug corrigé le 15/08/2026 (round 177) : une panne DNS totale accorderait de nouveau les 25 points RBL pleins dans computeScore()";
            }
            $posPtr177 = strpos($drmSrc177, 'private function checkPtr(string $ip, ?float $deadline = null): array');
            $ptrBody177 = $posPtr177 !== false ? substr($drmSrc177, $posPtr177, 2400) : '';
            $posAddr177 = strpos($ptrBody177, '@gethostbyaddr($ip)');
            $posName177 = strpos($ptrBody177, '@gethostbyname($hostname)');
            $betweenCalls177 = ($posAddr177 !== false && $posName177 !== false) ? substr($ptrBody177, $posAddr177, $posName177 - $posAddr177) : '';
            if ($posPtr177 === false || $posAddr177 === false || $posName177 === false || strpos($betweenCalls177, '$deadline !== null && microtime(true) >= $deadline') === false) {
                $offenders[] = "DomainReputationManager::checkPtr() ne revérifie plus le budget DNS avant son second appel bloquant (gethostbyname) — régression du bug corrigé le 15/08/2026 (round 177) : un hostname PTR pointant vers une résolution A lente pourrait de nouveau dépasser largement le budget censé protéger le visiteur front";
            }
        }

        $atSrc177 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/AdminTranslator.php');
        if ($atSrc177 === '') {
            $offenders[] = 'AdminTranslator.php introuvable (garde-fou round 177)';
        } else {
            $posTLang177 = strpos($atSrc177, 'public static function tLang(string $key, string $iso): string');
            $tLangBody177 = $posTLang177 !== false ? substr($atSrc177, $posTLang177, 900) : '';
            if ($posTLang177 === false || strpos($tLangBody177, 'if (!in_array($iso, TranslationEngine::SUPPORTED_LANGS, true)) {') === false) {
                $offenders[] = "AdminTranslator::tLang() ne valide plus \$iso contre TranslationEngine::SUPPORTED_LANGS — régression du bug corrigé le 15/08/2026 (round 177) : une clé orpheline du dictionnaire JSON pourrait de nouveau être retournée silencieusement pour un code langue non supporté";
            }
        }

        $msSrc178 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php');
        if ($msSrc178 === '') {
            $offenders[] = 'ManualSendManager.php introuvable (garde-fou round 178)';
        } elseif (strpos($msSrc178, "(new ConfigManager(\$this->module))->isCooldownEnabled()") === false
            || strpos($msSrc178, 'msg.send_blocked_cooldown') === false
        ) {
            $offenders[] = "ManualSendManager::send() ne revérifie plus explicitement CooldownManager (Mode Silence) avant Mail::Send() — régression du bug corrigé le 16/08/2026 (round 178) : Mail::Send() retourne toujours true même quand le hook bloque réellement l'envoi, un doublon bloqué par le Mode Silence serait de nouveau journalisé comme un succès";
        }

        $qmSrc178 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php');
        if ($qmSrc178 === '') {
            $offenders[] = 'QueueManager.php introuvable (garde-fou round 178)';
        } else {
            $posSingle178 = strpos($qmSrc178, 'private function processSingle(array $row): bool');
            $singleBody178 = $posSingle178 !== false ? substr($qmSrc178, $posSingle178, 4000) : '';
            $hasGuards178 = strpos($singleBody178, "\\BounceManager::isBounced(\$toEmail)") !== false
                && strpos($singleBody178, 'markQueueFailed(') !== false;
            if ($posSingle178 === false || !$hasGuards178) {
                $offenders[] = "QueueManager::processSingle() ne revérifie plus bounce/blacklist/préférences/cooldown avant Mail::Send() — régression du bug corrigé le 16/08/2026 (round 178) : une ligne bloquée silencieusement par le hook serait de nouveau marquée 'sent', et pour les anniversaires neria_behavioral_sent enregistrerait un envoi qui n'a jamais réellement eu lieu";
            }
        }

        $otSrc178 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php');
        if ($otSrc178 === '') {
            $offenders[] = 'OrderTriggersManager.php introuvable (garde-fou round 178)';
        } else {
            if (strpos($otSrc178, 'private function explicitSendBlockReason(') === false) {
                $offenders[] = "OrderTriggersManager::explicitSendBlockReason() a disparu — régression du bug corrigé le 16/08/2026 (round 178) : Mail::Send()===true serait de nouveau traité comme un succès inconditionnel même quand le hook bloque silencieusement l'envoi, laissant checkMilestone() réclamer un palier à vie sans jamais le libérer";
            }
            $posClaim178 = strpos($otSrc178, 'if (!$this->claimMilestone($idCustomer, $count, $idShop)) {');
            $posBlock178 = strpos($otSrc178, "\$this->explicitSendBlockReason('milestone_order'");
            if ($posClaim178 === false || $posBlock178 === false || $posBlock178 > $posClaim178) {
                $offenders[] = "OrderTriggersManager::checkMilestone() n'appelle plus explicitSendBlockReason() AVANT claimMilestone() — régression du bug corrigé le 16/08/2026 (round 178) : un palier légitimement atteint mais bloqué par un garde-fou serait de nouveau réclamé à vie sans jamais être libéré";
            }
        }

        $pmSrc178 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PreferencesManager.php');
        if ($pmSrc178 === '') {
            $offenders[] = 'PreferencesManager.php introuvable (garde-fou round 178)';
        } else {
            $posSave178 = strpos($pmSrc178, 'public function saveByCustomer(int $idCustomer, string $email, array $prefs): void');
            $saveBody178 = $posSave178 !== false ? substr($pmSrc178, $posSave178, 1500) : '';
            if ($posSave178 === false || strpos($saveBody178, "AND `email`       != '") === false) {
                $offenders[] = "PreferencesManager::saveByCustomer() ne nettoie plus les lignes d'un email différent pour un client identifié — régression du bug corrigé le 16/08/2026 (round 178) : un client changeant d'email entre deux visites du centre de préférences accumulerait de nouveau des lignes dupliquées, et getByCustomer() (sans tri déterministe) pourrait retourner une ancienne préférence obsolète";
            }
        }

        $smSrc178 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/StatsManager.php');
        if ($smSrc178 === '') {
            $offenders[] = 'StatsManager.php introuvable (garde-fou round 178)';
        } elseif (substr_count($smSrc178, "watchdog.stats_lock_failed") < 3) {
            $offenders[] = "StatsManager : recordOpen()/recordClick()/recordConversion() ne vérifient plus toutes les trois \$gotLock avant leur section protégée — régression du bug corrigé le 16/08/2026 (round 178) : la protection anti-double-crédit de points fidélité ne protégerait plus rien précisément sous forte contention (échec d'acquisition du verrou)";
        }

        $umSrc178 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php');
        if ($umSrc178 === '') {
            $offenders[] = 'UpsellManager.php introuvable (garde-fou round 178)';
        } elseif (strpos($umSrc178, "od2.id_shop = ' . (int) \$idShop") === false || strpos($umSrc178, "od.id_shop = ' . (int) \$idShop") === false) {
            $offenders[] = "UpsellManager::findByCoPurchase()/findByCategoryBestseller() ne filtrent plus leur classement de popularité par id_shop — régression du bug corrigé le 16/08/2026 (round 178) : un produit populaire uniquement sur une autre boutique pourrait de nouveau être recommandé à tort";
        }

        // Round 179 : audit transversal de fin de série (dernier round
        // systématique avant repassage en mode ponctuel/périodique — voir
        // mémoire feedback_bug_hunt_pause_after_transversal). 4 derniers
        // garde-fous.
        $cehmSrc179 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CustomerEmailHistoryManager.php');
        if ($cehmSrc179 === '') {
            $offenders[] = 'CustomerEmailHistoryManager.php introuvable (garde-fou round 179)';
        } else {
            $posResend179 = strpos($cehmSrc179, 'public function resend(int $idStat, int $idCustomer): array');
            $resendBody179 = $posResend179 !== false ? substr($cehmSrc179, $posResend179, 2000) : '';
            if ($posResend179 === false || strpos($resendBody179, "\\BounceManager::isBounced(\$customer->email)") === false) {
                $offenders[] = "CustomerEmailHistoryManager::resend() ne revérifie plus bounce/blacklist/préférences/cooldown avant Mail::Send() — régression du bug corrigé le 16/08/2026 (round 179) : un renvoi vers un client bloqué serait de nouveau journalisé comme un succès";
            }
        }

        $certSrc179 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php');
        if ($certSrc179 === '') {
            $offenders[] = 'CertificateManager.php introuvable (garde-fou round 179)';
        } else {
            $posSendCert179 = strpos($certSrc179, 'private function sendCertificateEmail(');
            $sendCertBody179 = $posSendCert179 !== false ? substr($certSrc179, $posSendCert179, 4000) : '';
            if ($posSendCert179 === false || strpos($sendCertBody179, "\\BounceManager::isBounced(\$to)") === false) {
                $offenders[] = "CertificateManager::sendCertificateEmail() ne revérifie plus bounce/blacklist/préférences/cooldown avant Mail::Send() — régression du bug corrigé le 16/08/2026 (round 179) : un certificat émis à une adresse bloquée serait de nouveau marqué comme envoyé avec succès";
            }
        }

        $wdSrc179 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WatchdogManager.php');
        if ($wdSrc179 === '') {
            $offenders[] = 'WatchdogManager.php introuvable (garde-fou round 179)';
        } elseif (strpos($wdSrc179, "if (!\$locked) {\n            return;\n        }") === false) {
            $offenders[] = "WatchdogManager::record() ne vérifie plus \$locked avant sa section critique — régression du bug corrigé le 16/08/2026 (round 179) : une rafale de messages identiques pourrait de nouveau créer des lignes de log dupliquées au lieu d'une consolidation";
        }

        $wlSrc179 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php');
        if ($wlSrc179 === '') {
            $offenders[] = 'WaitlistManager.php introuvable (garde-fou round 179)';
        } elseif (strpos($wlSrc179, 'function purgeStaleEntries(int $olderThanDays = 365, ?int $idShop = null): int') === false) {
            $offenders[] = "WaitlistManager::purgeStaleEntries() n'accepte plus de \$idShop optionnel — régression du bug corrigé le 16/08/2026 (round 179) : impossible de nouveau de scoper la purge par boutique, cohérent avec getStats()/getTopProducts() de la même classe";
        }

        $colSrc180 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CollectionManager.php');
        if ($colSrc180 === '') {
            $offenders[] = 'CollectionManager.php introuvable (garde-fou round 180)';
        } elseif (strpos($colSrc180, "\\BounceManager::isBounced(\$customer->email)") === false) {
            $offenders[] = "CollectionManager::processCollection() ne revérifie plus bounce/blacklist/cooldown avant Mail::Send() — régression du bug corrigé le 16/08/2026 (round 180) : un envoi bloqué par le hook serait de nouveau journalisé comme un succès, sans jamais libérer la réservation claimSend()";
        }

        $loySrc180 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php');
        if ($loySrc180 === '') {
            $offenders[] = 'LoyaltyManager.php introuvable (garde-fou round 180)';
        } elseif (strpos($loySrc180, '$sent = (bool) \Mail::Send(') === false) {
            $offenders[] = "LoyaltyManager::sendRecapToCustomer() ne capture plus le retour réel de Mail::Send() — régression du bug corrigé le 16/08/2026 (round 180) : un échec d'envoi serait de nouveau traité comme un succès inconditionnel, posant quand même le throttle mensuel";
        }

        $bcmSrc180 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php');
        if ($bcmSrc180 === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (garde-fou round 180)';
        } else {
            if (strpos($bcmSrc180, "WHERE bs.template = \\'checkout_abandonment\\'\n               AND bs.id_shop = ' . \$idShop") === false) {
                $offenders[] = "BehavioralCronManager::getCheckoutAbandonmentStats() ne filtre plus emails_sent par id_shop — régression du bug corrigé le 16/08/2026 (round 180) : le taux de conversion serait de nouveau faussé en multi-boutique (dénominateur global, numérateur scopé)";
            }
            if (strpos($bcmSrc180, "WHERE template = \\'relationship_anniversary\\' AND id_shop = ' . \$idShop") === false) {
                $offenders[] = "BehavioralCronManager::getRelationshipAnniversaryStats() ne filtre plus emails_sent par id_shop — régression du bug corrigé le 16/08/2026 (round 180)";
            }
        }

        $mrmSrc180 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php');
        if ($mrmSrc180 === '') {
            $offenders[] = 'MonthlyReportManager.php introuvable (garde-fou round 180)';
        } else {
            if (strpos($mrmSrc180, "\$data['month_label'] = \$this->formatMonthLabel(\$data['year'], \$data['month'], \$recipientIso);") === false) {
                $offenders[] = "MonthlyReportManager::deliverReportLocked() ne recalcule plus month_label par destinataire — régression du bug corrigé le 16/08/2026 (round 180) : un destinataire non-francophone recevrait de nouveau le titre du rapport figé dans la langue du contexte global";
            }
            if (strpos($mrmSrc180, "es.`id_shop` = ' . \$this->idShop") === false) {
                $offenders[] = "MonthlyReportManager::deliverReportLocked() ne filtre plus la résolution de langue employé par la boutique du rapport — régression du bug corrigé le 16/08/2026 (round 180)";
            }
        }

        $segSrc181 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SegmentManager.php');
        if ($segSrc181 === '') {
            $offenders[] = 'SegmentManager.php introuvable (garde-fou round 181)';
        } elseif (strpos($segSrc181, 'SELECT COUNT(*) FROM (') === false) {
            $offenders[] = "SegmentManager::recomputeAll() ne recompte plus le nombre réel de clients traités — régression du bug corrigé le 17/08/2026 (round 181) : Affected_Rows() (2 par ligne UPDATE réellement modifiée sur un INSERT...ON DUPLICATE KEY UPDATE) serait de nouveau loggué tel quel dans Watchdog, gonflant le chiffre au-delà du nombre réel de clients";
        }

        $cfgSrc181 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php');
        if ($cfgSrc181 === '') {
            $offenders[] = 'ConfigManager.php introuvable (garde-fou round 181)';
        } else {
            $posToggle181 = strpos($cfgSrc181, 'public function toggleMenuItemVisibility(string $key): void');
            $posSetAll181 = strpos($cfgSrc181, 'public function setAllMenuItemsVisibility(bool $visible): void');
            if ($posToggle181 === false || $posSetAll181 === false) {
                $offenders[] = "toggleMenuItemVisibility()/setAllMenuItemsVisibility() introuvables dans ConfigManager.php — jeu de garde-fous invalide (round 181)";
            } else {
                $toggleBody181 = substr($cfgSrc181, $posToggle181, $posSetAll181 - $posToggle181);
                if (strpos($toggleBody181, "Configuration::updateValue(self::KEY_MENU_HIDDEN_ITEMS, \$encoded, false, null, \$this->idShop)") === false) {
                    $offenders[] = "ConfigManager::toggleMenuItemVisibility()/setAllMenuItemsVisibility() n'écrivent plus dans le même scope shop que get()/set() — régression du bug corrigé le 17/08/2026 (round 181) : un masquage de menu après un 'Réinitialiser' réapparaîtrait de nouveau silencieusement au rechargement suivant, en multi-boutique";
                }
            }
        }

        $bcmSrc181 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php');
        $otmSrc181 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php');
        $loySrc181 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LoyaltyManager.php');
        if ($bcmSrc181 === '' || $otmSrc181 === '' || $loySrc181 === '') {
            $offenders[] = 'BehavioralCronManager.php/OrderTriggersManager.php/LoyaltyManager.php introuvable (garde-fou round 181)';
        } else {
            if (strpos($bcmSrc181, 'min($amount, $config->getVoucherFixedCap())') === false) {
                $offenders[] = "BehavioralCronManager::generateBirthdayVoucher() ne re-clampe plus le montant au plafond de sécurité à la génération — régression du bug corrigé le 17/08/2026 (round 181) : un abaissement ultérieur de NERIA_VOUCHER_FIXED_CAP ne se répercuterait plus sur un montant déjà enregistré au-dessus du nouveau plafond";
            }
            if (strpos($otmSrc181, 'min($amount, $config->getVoucherFixedCap())') === false) {
                $offenders[] = "OrderTriggersManager::generateMilestoneVoucher() ne re-clampe plus le montant au plafond de sécurité à la génération — régression du bug corrigé le 17/08/2026 (round 181)";
            }
            if (strpos($loySrc181, 'min((float) $tier[\'amount\'], (new \ConfigManager($this->module))->getVoucherFixedCap())') === false) {
                $offenders[] = "LoyaltyManager::generateVoucher() ne re-clampe plus le montant fixe au plafond de sécurité à la génération — régression du bug corrigé le 17/08/2026 (round 181)";
            }
        }

        $certSrc182 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/CertificateManager.php');
        if ($certSrc182 === '') {
            $offenders[] = 'CertificateManager.php introuvable (garde-fou round 182)';
        } elseif (strpos($certSrc182, 'public function delete(int $idCertificate): bool') === false) {
            $offenders[] = "CertificateManager::delete() n'a plus une signature bool — régression du bug corrigé le 17/08/2026 (round 182) : un id_certificate inexistant, déjà supprimé, ou d'une autre boutique afficherait de nouveau un succès inconditionnel en BO malgré une suppression sans effet";
        }

        $neriaSrc182 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/neria.php');
        if ($neriaSrc182 === '') {
            $offenders[] = 'neria.php introuvable (garde-fou round 182)';
        } elseif (strpos($neriaSrc182, "if ((new CertificateManager(\$this))->delete(\$idCert)) {") === false) {
            $offenders[] = "neria.php (action cert_delete) ne vérifie plus le retour de CertificateManager::delete() — régression du bug corrigé le 17/08/2026 (round 182)";
        }

        $abtSrc182 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ABTestManager.php');
        if ($abtSrc182 === '') {
            $offenders[] = 'ABTestManager.php introuvable (garde-fou round 182)';
        } elseif (strpos($abtSrc182, "mb_strtolower(trim(\$email))") === false) {
            $offenders[] = "AbTestManager::getVariantForEmail() ne normalise plus la casse de l'email dans sa clé de hash — régression du bug corrigé le 17/08/2026 (round 182) : un même destinataire dont la casse de l'email change entre deux envois basculerait de nouveau de variante A/B";
        }

        $psmSrc182 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PageSpeedManager.php');
        if ($psmSrc182 === '') {
            $offenders[] = 'PageSpeedManager.php introuvable (garde-fou round 182)';
        } elseif (strpos($psmSrc182, '$this->cacheKey(self::CONFIG_TARGET_URL)') === false) {
            $offenders[] = "PageSpeedManager::getTargetUrl() ne lit plus CONFIG_TARGET_URL de façon scopée par boutique — régression du bug corrigé le 17/08/2026 (round 182) : une URL personnalisée d'une boutique s'appliquerait de nouveau à toutes les boutiques de l'installation (fuite de rapport inter-boutiques)";
        } elseif (strpos($neriaSrc182, "PageSpeedManager::CONFIG_TARGET_URL . '_' . (int) \$this->context->shop->id") === false) {
            $offenders[] = "neria.php n'écrit plus PageSpeedManager::CONFIG_TARGET_URL de façon scopée par boutique — régression du bug corrigé le 17/08/2026 (round 182)";
        }

        $clvSrc183 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ClvManager.php');
        if ($clvSrc183 === '') {
            $offenders[] = 'ClvManager.php introuvable (garde-fou round 183)';
        } elseif (substr_count($clvSrc183, "AND `date_add` >= DATE_SUB(NOW(), INTERVAL 30 DAY)") < 2) {
            $offenders[] = "ClvManager::getEngagementRate() (et/ou sa version batch dans getTopCustomers()) ne borne(nt) plus le calcul d'engagement aux 30 derniers jours — régression du bug corrigé le 18/08/2026 (round 183) : un client historiquement engagé mais silencieux depuis des mois afficherait de nouveau un engagement 'high' (lifetime) contredisant le bloc churn affiché à côté (fenêtre récente), gonflant à tort engagement_mult et le classement Top CLV";
        }

        $smSrc184 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/StatsManager.php');
        if ($smSrc184 === '') {
            $offenders[] = 'StatsManager.php introuvable (garde-fou round 184)';
        } elseif (strpos($smSrc184, "'id_order'      => (int) (\$params['templateVars']['{id_order}'] ?? 0),") === false) {
            $offenders[] = "StatsManager::recordSent() ne lit plus \$params['templateVars']['{id_order}'] pour id_order — régression du bug corrigé le 18/08/2026 (round 184) : chaque ligne 'sent' redeviendrait enregistrée avec id_order = 0, rendant CooldownManager::isDuplicate() scopé par commande de nouveau totalement inopérant";
        }

        $wlSrc184 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php');
        if ($wlSrc184 === '') {
            $offenders[] = 'WaitlistManager.php introuvable (garde-fou round 184)';
        } else {
            if (strpos($wlSrc184, "if (!\\Validate::isLoadedObject(\$product) || !\$product->active) continue;") === false) {
                $offenders[] = "WaitlistManager::notifyProductLocked() ne vérifie plus \$product->active — régression du bug corrigé le 18/08/2026 (round 184) : un produit désactivé recevrait de nouveau une notification 'de retour en stock' vers une page indisponible";
            }
            if (strpos($wlSrc184, 'private function safeProductPrice(int $idProduct): float') === false) {
                $offenders[] = "WaitlistManager n'a plus de méthode safeProductPrice() — régression du bug corrigé le 18/08/2026 (round 184) : {product_price} redeviendrait résolu via \$product->price brut, sans taxe ni promo";
            }
        }

        $lcmSrc184 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LookCompletionManager.php');
        if ($lcmSrc184 === '') {
            $offenders[] = 'LookCompletionManager.php introuvable (garde-fou round 184)';
        } else {
            if (strpos($lcmSrc184, "SELECT COALESCE(SUM(quantity), 0) FROM `' . \$this->prefix . 'stock_available`") === false) {
                $offenders[] = "LookCompletionManager::buildProductBlocks() n'utilise plus le SUM(quantity) SQL direct pour vérifier le stock — régression du bug corrigé le 18/08/2026 (round 184) : un produit à déclinaisons serait de nouveau silencieusement écarté des suggestions 'Complétez votre look'";
            }
            if (strpos($lcmSrc184, 'private function safeProductPrice(int $idProduct): float') === false) {
                $offenders[] = "LookCompletionManager n'a plus de méthode safeProductPrice() — régression du bug corrigé le 18/08/2026 (round 184) : le prix affiché redeviendrait \$product->price brut, sans taxe ni promo";
            }
        }

        $umSrc184 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php');
        if ($umSrc184 === '') {
            $offenders[] = 'UpsellManager.php introuvable (garde-fou round 184)';
        } elseif (strpos($umSrc184, 'private function safeProductPrice(int $idProduct, int $idLang, int $idCustomer = 0): float') === false) {
            $offenders[] = "UpsellManager::safeProductPrice() n'accepte plus \$idCustomer — régression du bug corrigé le 18/08/2026 (round 184) : le prix upsell d'un client à tarif négocié (B2B) redeviendrait résolu avec le groupe tarifaire visiteur par défaut";
        }

        $gdprSrc184 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/GdprAuditManager.php');
        if ($gdprSrc184 === '') {
            $offenders[] = 'GdprAuditManager.php introuvable (garde-fou round 184)';
        } elseif (strpos($gdprSrc184, "'table'        => 'neria_abtest_history',") === false) {
            $offenders[] = "neria_abtest_history a disparu du registre RGPD — régression du bug corrigé le 18/08/2026 (round 184) : cette table ne serait de nouveau jamais purgée par la rétention RGPD automatique";
        }

        $pmSrc185 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php');
        if ($pmSrc185 === '') {
            $offenders[] = 'PostmasterManager.php introuvable (garde-fou round 185)';
        } elseif (strpos($pmSrc185, 'Configuration::updateGlobalValue(self::CONFIG_ACCESS_TOKEN,') === false) {
            $offenders[] = "PostmasterManager n'écrit plus CONFIG_ACCESS_TOKEN en global (updateGlobalValue) — régression du bug corrigé le 18/08/2026 (round 185) : une connexion OAuth établie depuis une boutique redeviendrait invisible pour les autres boutiques de l'installation";
        }

        $scSrc185 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php');
        if ($scSrc185 === '') {
            $offenders[] = 'SearchConsoleManager.php introuvable (garde-fou round 185)';
        } elseif (strpos($scSrc185, 'Configuration::updateGlobalValue(self::CONFIG_ACCESS_TOKEN,') === false) {
            $offenders[] = "SearchConsoleManager n'écrit plus CONFIG_ACCESS_TOKEN en global (updateGlobalValue) — régression du bug corrigé le 18/08/2026 (round 185)";
        }

        $smSrc185 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/StatsManager.php');
        if ($smSrc185 === '') {
            $offenders[] = 'StatsManager.php introuvable (garde-fou round 185)';
        } elseif (strpos($smSrc185, 'public function adjustConversionRevenueForOrder(int $idOrder, float $newRevenue): void') === false) {
            $offenders[] = "StatsManager::adjustConversionRevenueForOrder() a disparu — régression du bug corrigé le 18/08/2026 (round 185) : le revenu attribué à une commande ne serait de nouveau jamais ajusté après un remboursement, surestimant durablement le ROI par template/campagne";
        }

        $otmSrc185 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/OrderTriggersManager.php');
        if ($otmSrc185 === '') {
            $offenders[] = 'OrderTriggersManager.php introuvable (garde-fou round 185)';
        } elseif (strpos($otmSrc185, 'adjustConversionRevenueForOrder(') === false) {
            $offenders[] = "OrderTriggersManager::handleRefund() n'appelle plus adjustConversionRevenueForOrder() — régression du bug corrigé le 18/08/2026 (round 185)";
        }

        $seasSrc185 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SeasonalCampaignManager.php');
        if ($seasSrc185 === '') {
            $offenders[] = 'SeasonalCampaignManager.php introuvable (garde-fou round 185)';
        } else {
            $posCount185 = strpos($seasSrc185, 'public function countEligible(array $campaign): int');
            $countBody185 = $posCount185 !== false ? substr($seasSrc185, $posCount185, 800) : '';
            if ($posCount185 === false || strpos($countBody185, "\$campaign['target_segment'] = 'ambassador,loyal';") === false) {
                $offenders[] = "SeasonalCampaignManager::countEligible() n'applique plus l'override gift_mode → target_segment — régression du bug corrigé le 18/08/2026 (round 185) : l'aperçu BO du nombre de destinataires en mode 'idées cadeaux' redeviendrait trompeur (segment configuré au lieu du segment réellement restreint à l'envoi)";
            }
        }

        $neriaSrc186 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/neria.php');
        if ($neriaSrc186 === '') {
            $offenders[] = 'neria.php introuvable (garde-fou round 186)';
        } else {
            $posPs186 = strpos($neriaSrc186, "if (Tools::getValue('neria_action') === 'save_pagespeed_key'");
            $psBody186 = $posPs186 !== false ? substr($neriaSrc186, $posPs186, 3000) : '';
            if ($posPs186 === false || strpos($psBody186, "if (\$key !== '') {") === false) {
                $offenders[] = "neria.php (save_pagespeed_key) n'a plus de garde 'if (\$key !== \"\")' avant d'écraser la clé PageSpeed — régression du bug corrigé le 19/08/2026 (round 186) : un champ vide (affiché après un échec de déchiffrement) effacerait de nouveau définitivement la clé API stockée";
            }
            if ($posPs186 === false || strpos($psBody186, '$pageSpeedMgr->clearError();') === false) {
                $offenders[] = "neria.php (save_pagespeed_key) n'appelle plus PageSpeedManager::clearError() — régression du bug corrigé le 19/08/2026 (round 186)";
            }
            $posSeo186 = strpos($neriaSrc186, "if (Tools::getValue('neria_action') === 'save_seo_config'");
            $seoBody186 = $posSeo186 !== false ? substr($neriaSrc186, $posSeo186, 3200) : '';
            if ($posSeo186 === false
                || strpos($seoBody186, '$semrushDecryptBroken = CryptoManager::lastDecryptFailed();') === false
                || strpos($seoBody186, '$mozAccessDecryptBroken = CryptoManager::lastDecryptFailed();') === false
            ) {
                $offenders[] = "neria.php (save_seo_config) ne revérifie plus CryptoManager::lastDecryptFailed() avant d'écraser semrush_key/moz_access — régression du bug corrigé le 19/08/2026 (round 186)";
            }
            if (strpos($neriaSrc186, "'pagespeed_last_error'  => class_exists('PageSpeedManager') ? (new PageSpeedManager(\$this))->getLastError() : '',") === false) {
                $offenders[] = "neria.php n'affiche plus pagespeed_last_error via PageSpeedManager::getLastError() (scopé par boutique) — régression du bug corrigé le 19/08/2026 (round 186) : la page Stats afficherait de nouveau toujours 'aucune erreur' même en cas de vraie erreur";
            }
        }

        $psmSrc186 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PageSpeedManager.php');
        if ($psmSrc186 === '') {
            $offenders[] = 'PageSpeedManager.php introuvable (garde-fou round 186)';
        } elseif (strpos($psmSrc186, 'public function clearError(): void') === false) {
            $offenders[] = "PageSpeedManager::clearError() a disparu — régression du bug corrigé le 19/08/2026 (round 186)";
        }

        $trackSrc186 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/controllers/front/track.php');
        if ($trackSrc186 === '') {
            $offenders[] = 'controllers/front/track.php introuvable (garde-fou round 186)';
        } else {
            $posRef186 = strpos($trackSrc186, 'if ($ref) {');
            $posUpsell186 = strpos($trackSrc186, '(new UpsellManager($this->module))->recordClick($idUpsell, $idCustomerRef);');
            if ($posRef186 === false || $posUpsell186 === false || $posUpsell186 < $posRef186) {
                $offenders[] = "track.php : structure if (\$ref)/UpsellManager::recordClick() introuvable ou dans le mauvais ordre — jeu de garde-fou invalide (round 186)";
            } else {
                $between186 = substr($trackSrc186, $posRef186 + strlen('if ($ref) {'), $posUpsell186 - ($posRef186 + strlen('if ($ref) {')));
                $depth186 = 0;
                $minDepth186 = 0;
                foreach (str_split($between186) as $ch186) {
                    if ($ch186 === '{') {
                        $depth186++;
                    } elseif ($ch186 === '}') {
                        $depth186--;
                        $minDepth186 = min($minDepth186, $depth186);
                    }
                }
                if ($minDepth186 < 0) {
                    $offenders[] = "track.php : UpsellManager::recordClick() n'est plus imbriqué à l'intérieur de if (\$ref) — régression du bug corrigé le 19/08/2026 (round 186) : un token inconnu/inexistant en base pourrait de nouveau déclencher l'écriture sur ps_neria_upsell sans aucune vérification de signature";
                }
            }
        }

        $mcpmSrc186 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/MultiClientPreviewManager.php');
        if ($mcpmSrc186 === '') {
            $offenders[] = 'MultiClientPreviewManager.php introuvable (garde-fou round 186)';
        } elseif (strpos($mcpmSrc186, '"[^"]*"|') === false) {
            $offenders[] = "MultiClientPreviewManager::addBanner() n'utilise plus la regex respectant les valeurs d'attribut entre guillemets — régression du bug corrigé le 19/08/2026 (round 186) : un '>' littéral dans un attribut du <body> tronquerait de nouveau la balise et casserait l'insertion du bandeau";
        }

        $cfgSrc186 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ConfigManager.php');
        if ($cfgSrc186 === '') {
            $offenders[] = 'ConfigManager.php introuvable (garde-fou round 186)';
        } elseif (strpos($cfgSrc186, '$validFontsForScript = array_keys($fontMgr->getFontsForScript($script));') === false) {
            $offenders[] = "ConfigManager::saveTypographyConfig() ne filtre plus la whitelist de polices par script — régression du bug corrigé le 19/08/2026 (round 186) : un POST direct pourrait de nouveau assigner une police d'un script différent (ex. japonaise pour font_arabic), injectée sans avertissement dans le CSS des emails de ce script";
        }

        // Round 187 (23/08/2026) : UpsellManager::recordClick() doit vérifier
        // id_customer, pas seulement id_upsell.
        $upsellSrc187 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/UpsellManager.php');
        if ($upsellSrc187 === '') {
            $offenders[] = 'UpsellManager.php introuvable (garde-fou round 187)';
        } else {
            $posRc187 = strpos($upsellSrc187, 'public function recordClick(int $idUpsell, int $idCustomer): void');
            $rcBody187 = $posRc187 !== false ? substr($upsellSrc187, $posRc187, 500) : '';
            if ($posRc187 === false || strpos($rcBody187, 'AND id_customer = " . (int) $idCustomer') === false) {
                $offenders[] = "UpsellManager::recordClick() ne filtre plus par id_customer — régression de l'IDOR corrigé le 23/08/2026 (round 187) : id_upsell seul (clé auto-incrémentée séquentielle) ne prouve rien sur l'identité de l'appelant, un destinataire en possession d'un token valide pour sa propre adresse pourrait de nouveau figer clicked_at sur les lignes upsell d'autres clients";
            }
        }

        // Round 187 (23/08/2026) : GdprAuditManager::purgeCustomerData() doit
        // scoper la purge par email de neria_preferences par id_customer AUSSI.
        $gdprSrc187 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/GdprAuditManager.php');
        if ($gdprSrc187 === '') {
            $offenders[] = 'GdprAuditManager.php introuvable (garde-fou round 187)';
        } else {
            $posPref187 = strpos($gdprSrc187, "\$prefTable = _DB_PREFIX_ . 'neria_preferences';");
            $prefBody187 = $posPref187 !== false ? substr($gdprSrc187, $posPref187, 900) : '';
            if ($posPref187 === false || substr_count($prefBody187, "AND `id_customer` = \" . (int) \$idCustomer") < 2) {
                $offenders[] = "GdprAuditManager::purgeCustomerData() ne scope plus la purge de neria_preferences par id_customer — régression du bug corrigé le 23/08/2026 (round 187) : une demande d'effacement RGPD purgerait de nouveau, par simple correspondance d'email, la ligne préférences d'un client totalement différent partageant cet email sur une autre boutique";
            }
        }

        // Round 187 (23/08/2026) : WaitlistManager::notifyProductLocked()
        // doit scoper ses 5 UPDATE par id_product_attribute, pas seulement
        // id_customer + id_product + id_shop.
        $wlSrc187 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WaitlistManager.php');
        if ($wlSrc187 === '') {
            $offenders[] = 'WaitlistManager.php introuvable (garde-fou round 187)';
        } else {
            $posLocked187 = strpos($wlSrc187, 'private function notifyProductLocked(');
            $lockedBody187 = $posLocked187 !== false ? substr($wlSrc187, $posLocked187, 19000) : '';
            if ($posLocked187 === false || substr_count($lockedBody187, 'AND id_product_attribute = {$idProductAttribute}') < 5) {
                $offenders[] = "WaitlistManager::notifyProductLocked() ne scope plus (ou plus assez) ses UPDATE par id_product_attribute — régression du bug corrigé le 23/08/2026 (round 187) : un client inscrit sur plusieurs déclinaisons du même produit perdrait de nouveau silencieusement sa notification de retour en stock pour les déclinaisons non réassorties, le réassort d'UNE déclinaison marquant à tort notified_at sur TOUTES ses inscriptions à ce produit";
            }
        }

        // Round 187 (23/08/2026) : BehavioralCronManager::send() doit résoudre
        // {shop_name} avec $idShop, pas le contexte Configuration global.
        $bcmSrc187 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/BehavioralCronManager.php');
        if ($bcmSrc187 === '') {
            $offenders[] = 'BehavioralCronManager.php introuvable (garde-fou round 187)';
        } elseif (strpos($bcmSrc187, "'{shop_name}'   => \\Configuration::get('PS_SHOP_NAME', null, null, \$idShop),") === false) {
            $offenders[] = "BehavioralCronManager::send() ne résout plus {shop_name} avec \$idShop — régression du bug corrigé le 23/08/2026 (round 187) : un email comportemental (anniversaire, relance panier, etc.) sur une install multi-boutiques afficherait de nouveau le nom d'une boutique différente de celle du client destinataire";
        }

        // Round 188 (23/08/2026) : EmailRenderer::buildCompiledHtml() (2
        // occurrences) doit injecter le bloc neria_content via
        // preg_replace_callback(), pas preg_replace() avec le contenu comme
        // argument remplacement direct.
        $erSrc188 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php');
        if ($erSrc188 === '') {
            $offenders[] = 'EmailRenderer.php introuvable (garde-fou round 188)';
        } else {
            $ccCount188 = substr_count($erSrc188, "preg_replace_callback(\n            '/\\{block\\s+name=[\\'\"]neria_content[\\'\\\"]\\}\\{\\/block\\}/',");
            if ($ccCount188 < 2) {
                $offenders[] = "EmailRenderer::buildCompiledHtml() n'injecte plus (ou plus assez) le bloc neria_content via preg_replace_callback() — régression du bug corrigé le 23/08/2026 (round 188) : preg_replace() interpréterait de nouveau tout '\$' suivi d'un chiffre dans le contenu compilé (ex. un prix \"\$50\") comme une rétro-référence et le supprimerait silencieusement";
            }
        }

        // Round 188 (23/08/2026) : MonthlyReportManager::renderHtml()/
        // resolveRecVars() doivent résoudre PS_CURRENCY_DEFAULT avec
        // $this->idShop explicite, pas via Currency::getDefaultCurrency()
        // (contexte boutique statique non fiable en multi-boutique).
        $mrmSrc188 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/MonthlyReportManager.php');
        if ($mrmSrc188 === '') {
            $offenders[] = 'MonthlyReportManager.php introuvable (garde-fou round 188)';
        } else {
            $curCount188 = substr_count($mrmSrc188, "Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$this->idShop)");
            if ($curCount188 < 2) {
                $offenders[] = "MonthlyReportManager ne résout plus PS_CURRENCY_DEFAULT avec \$this->idShop explicite (trouvé {$curCount188}/2) — régression du bug corrigé le 23/08/2026 (round 188) : le CA du rapport mensuel d'une boutique pourrait de nouveau s'afficher avec la devise d'une autre boutique";
            }
        }

        // Round 188 (23/08/2026) : unsubscribe.php doit appeler
        // PreferencesManager::saveByCustomer() sans condition sur
        // $customerId, pour couvrir aussi les invités (id_customer=0).
        $unsubSrc188 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/controllers/front/unsubscribe.php');
        if ($unsubSrc188 === '') {
            $offenders[] = 'unsubscribe.php introuvable (garde-fou round 188)';
        } else {
            $posPrefBlock188 = strpos($unsubSrc188, "class_exists('PreferencesManager')");
            $posSaveCall188 = $posPrefBlock188 !== false ? strpos($unsubSrc188, '->saveByCustomer(', $posPrefBlock188) : false;
            $between188 = ($posPrefBlock188 !== false && $posSaveCall188 !== false)
                ? substr($unsubSrc188, $posPrefBlock188, $posSaveCall188 - $posPrefBlock188)
                : '';
            if ($posPrefBlock188 === false || $posSaveCall188 === false || strpos($between188, 'if ($customerId > 0)') !== false) {
                $offenders[] = "unsubscribe.php gate de nouveau saveByCustomer() derrière une condition sur \$customerId — régression du bug corrigé le 23/08/2026 (round 188) : un invité (id_customer=0, jamais devenu client PrestaShop) ne verrait de nouveau jamais sa ligne neria_preferences créée lors d'un désabonnement, continuant à recevoir les autres catégories d'emails Neria malgré la confirmation de désabonnement";
            }
        }

        // Round 188 (23/08/2026) : upgrade-1.0.40.php ne doit plus utiliser
        // SHOW TABLES LIKE (plante sous certains couples MySQL/PDO), partout
        // remplacé par information_schema.TABLES.
        $up140Src188 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/upgrade/upgrade-1.0.40.php');
        if ($up140Src188 === '') {
            $offenders[] = 'upgrade-1.0.40.php introuvable (garde-fou round 188)';
        } elseif (strpos($up140Src188, "getValue(\"SHOW TABLES LIKE") !== false) {
            $offenders[] = "upgrade-1.0.40.php utilise de nouveau SHOW TABLES LIKE via Db::getValue() — régression du bug corrigé le 23/08/2026 (round 188) : ce motif plante sous certains couples MySQL/PDO (LIMIT 1 auto-ajouté), pouvant faire échouer/sauter silencieusement le backfill id_shop sur une install multi-boutiques";
        }

        // Round 189 (23/08/2026) : PostmasterManager/SearchConsoleManager::
        // disconnect() doivent vider le cache de TOUTES les boutiques
        // (Shop::getShops()), pas seulement la boutique courante.
        $pmSrc189 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/PostmasterManager.php');
        if ($pmSrc189 === '') {
            $offenders[] = 'PostmasterManager.php introuvable (garde-fou round 189)';
        } elseif (strpos($pmSrc189, 'Shop::getShops(false, null, true)') === false) {
            $offenders[] = "PostmasterManager::disconnect() n'itère plus Shop::getShops() pour vider le cache — régression du bug corrigé le 23/08/2026 (round 189) : sur une install multi-boutiques, se déconnecter depuis une boutique laisserait de nouveau le cache des autres intact jusqu'à expiration du TTL, affichant des données 'en direct' d'une connexion pourtant révoquée globalement";
        }
        $scSrc189 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/SearchConsoleManager.php');
        if ($scSrc189 === '') {
            $offenders[] = 'SearchConsoleManager.php introuvable (garde-fou round 189)';
        } elseif (strpos($scSrc189, 'Shop::getShops(false, null, true)') === false) {
            $offenders[] = "SearchConsoleManager::disconnect() n'itère plus Shop::getShops() pour vider le cache — régression du bug corrigé le 23/08/2026 (round 189) : même risque que PostmasterManager ci-dessus";
        }

        // Round 189 (23/08/2026) : WatchdogManager doit rafraîchir date_add
        // à chaque occurrence consolidée, pas seulement occurrence_count.
        $wdSrc189 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/WatchdogManager.php');
        if ($wdSrc189 === '') {
            $offenders[] = 'WatchdogManager.php introuvable (garde-fou round 189)';
        } elseif (strpos($wdSrc189, "`occurrence_count` = `occurrence_count` + 1, `date_add` = NOW()") === false) {
            $offenders[] = "WatchdogManager ne rafraîchit plus date_add lors de la consolidation d'occurrences — régression du bug corrigé le 23/08/2026 (round 189) : sendImmediateAlert()/sendDailyDigestIfDueLocked() filtrent par date_add, une ligne active resterait de nouveau invisible à ces fenêtres si sa création précède le début de la fenêtre";
        }

        // Round 189 (23/08/2026) : PostmasterManager/SearchConsoleManager::
        // getAuthUrl() doivent vérifier le retour de GET_LOCK() avant
        // d'écrire et avant RELEASE_LOCK().
        if ($pmSrc189 !== '') {
            $posLockPm189 = strpos($pmSrc189, "GET_LOCK('neria_postmaster_oauth_state', 3)");
            $beforePm189 = $posLockPm189 !== false ? substr($pmSrc189, max(0, $posLockPm189 - 40), 40) : '';
            if ($posLockPm189 === false || strpos($beforePm189, '$locked = ') === false) {
                $offenders[] = "PostmasterManager::getAuthUrl() ne vérifie plus le retour de GET_LOCK() — régression du bug corrigé le 23/08/2026 (round 189) : le cycle lecture-modification-écriture du state OAuth s'exécuterait de nouveau sans protection sous contention, et RELEASE_LOCK() pourrait libérer un verrou détenu par un autre processus";
            }
        }
        if ($scSrc189 !== '') {
            $posLockSc189 = strpos($scSrc189, "GET_LOCK('neria_search_console_oauth_state', 3)");
            $beforeSc189 = $posLockSc189 !== false ? substr($scSrc189, max(0, $posLockSc189 - 40), 40) : '';
            if ($posLockSc189 === false || strpos($beforeSc189, '$locked = ') === false) {
                $offenders[] = "SearchConsoleManager::getAuthUrl() ne vérifie plus le retour de GET_LOCK() — régression du bug corrigé le 23/08/2026 (round 189) : même risque que PostmasterManager ci-dessus";
            }
        }

        // Round 189 (23/08/2026) : ABTestManager::getVariantForEmail() doit
        // retourner '' (pas VARIANT_A) quand aucune clé de répartition
        // n'est disponible.
        $abtSrc189 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ABTestManager.php');
        if ($abtSrc189 === '') {
            $offenders[] = 'ABTestManager.php introuvable (garde-fou round 189)';
        } else {
            $posKey189 = strpos($abtSrc189, "if (\$key === '') {");
            $keyBody189 = $posKey189 !== false ? substr($abtSrc189, $posKey189, 80) : '';
            if ($posKey189 === false || strpos($keyBody189, "return '';") === false) {
                $offenders[] = "ABTestManager::getVariantForEmail() ne retourne plus '' quand aucune clé de répartition n'est disponible — régression du bug corrigé le 23/08/2026 (round 189) : ce cas limite serait de nouveau systématiquement assigné à VARIANT_A, gonflant son volume et faussant le calcul du gagnant";
            }
        }

        // Round 190 (23/08/2026) : LookCompletionManager::runDailyCheck()
        // doit revérifier bounce/blacklist/cooldown avant Mail::Send(), avec
        // releaseSendClaim() sur chaque blocage (même piège que
        // CollectionManager corrigé au round 180).
        $lcSrc190 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/LookCompletionManager.php');
        if ($lcSrc190 === '') {
            $offenders[] = 'LookCompletionManager.php introuvable (garde-fou round 190)';
        } else {
            $posMailSend190 = strpos($lcSrc190, '$mailed = \Mail::Send(');
            $posBounce190 = strpos($lcSrc190, "\\BounceManager::isBounced(\$customer->email)");
            $posBlacklist190 = strpos($lcSrc190, "BlacklistManager(\$idShop))->isBlacklisted('complete_your_look'");
            $posCooldown190 = strpos($lcSrc190, "CooldownManager())->isDuplicate(\$customer->email, 'complete_your_look'");
            if ($posMailSend190 === false || $posBounce190 === false || $posBlacklist190 === false || $posCooldown190 === false
                || $posBounce190 >= $posMailSend190 || $posBlacklist190 >= $posMailSend190 || $posCooldown190 >= $posMailSend190
            ) {
                $offenders[] = "LookCompletionManager::runDailyCheck() ne revérifie plus bounce/blacklist/cooldown avant Mail::Send() — régression du bug corrigé le 23/08/2026 (round 190) : un client bloqué (bounce/blacklist/cooldown) serait de nouveau marqué 'envoyé' à tort et exclu à vie de cette notification (clé UNIQUE uq_order), même après la levée du blocage";
            } else {
                $guardsBlock190 = substr($lcSrc190, $posBounce190, $posMailSend190 - $posBounce190);
                if (substr_count($guardsBlock190, 'releaseSendClaim($idOrder, $idCustomer)') !== 3) {
                    $offenders[] = "LookCompletionManager : les 3 garde-fous bounce/blacklist/cooldown ne libèrent plus systématiquement releaseSendClaim() sur blocage — régression du bug corrigé le 23/08/2026 (round 190)";
                }
            }
        }

        // Round 190 (23/08/2026) : QueueManager::processSingle() doit lire
        // {id_order}/{cooldown_scope} depuis $allVars pour le pré-contrôle
        // cooldown, pas row['ref_id'] (identifiant de dédup générique, pas
        // systématiquement un id de commande).
        $qmSrc190 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/QueueManager.php');
        if ($qmSrc190 === '') {
            $offenders[] = 'QueueManager.php introuvable (garde-fou round 190)';
        } elseif (strpos($qmSrc190, "\$cdIdOrder  = (int) (\$allVars['{id_order}'] ?? 0);") === false
            || strpos($qmSrc190, "\$cdRefScope = (string) (\$allVars['{cooldown_scope}'] ?? 0);") !== false
            || strpos($qmSrc190, "\$cdRefScope = (string) (\$allVars['{cooldown_scope}'] ?? '');") === false
        ) {
            $offenders[] = "QueueManager::processSingle() ne lit plus {id_order}/{cooldown_scope} depuis \$allVars pour le pré-contrôle cooldown — régression du bug corrigé le 23/08/2026 (round 190) : row['ref_id'] (année×mois, id_cart, etc. selon le template) redeviendrait utilisé tel quel comme id_order, ne matchant jamais neria_stat, laissant Mail::Send() être appelé et la ligne marquée à tort 'sent' malgré un blocage réel du hook";
        }

        // Round 190 (23/08/2026) : ManualSendManager::getPreferencesGuardStatus()
        // doit résoudre $customer['id_shop'] en priorité sur le contexte BO
        // de l'opérateur.
        $msmSrc190 = $this->readModuleSrc(_PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php');
        if ($msmSrc190 === '') {
            $offenders[] = 'ManualSendManager.php introuvable (garde-fou round 190)';
        } else {
            $posGps190 = strpos($msmSrc190, 'public function getPreferencesGuardStatus(');
            $gpsBody190 = $posGps190 !== false ? substr($msmSrc190, $posGps190, 1300) : '';
            if ($posGps190 === false || strpos($gpsBody190, "\$idShop    = (int) (\$customer['id_shop'] ?? \\Context::getContext()->shop->id);") === false) {
                $offenders[] = "ManualSendManager::getPreferencesGuardStatus() n'utilise plus \$customer['id_shop'] en priorité — régression du bug corrigé le 23/08/2026 (round 190) : le bandeau d'avertissement BO serait de nouveau évalué sur le contexte de l'opérateur au lieu de la boutique réelle du client";
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.known_regressions_error', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.known_regressions_ok')];
    }

    /**
     * Contrôle statique PROSPECTIF, générique — pas la re-détection d'un bug
     * déjà connu (cf. checkKnownRegressionsGuard()), mais la prévention de
     * la FAMILLE de bug trouvée le 2026-07-21 sur le centre de contrôle
     * pour TOUTE future entrée ajoutée à ConfigManager::CONTROL_CENTER_REGISTRY,
     * pas seulement les 6 entrées déjà corrigées. Deux pièges génériques :
     *
     * 1. Clé fantôme : un 'enabled_key' déclaré dans le registre mais jamais
     *    lu ni écrit ailleurs dans le code (comme NERIA_ATTRIBUTION_ENABLED)
     *    — la feature est en réalité toujours active, sans interrupteur réel,
     *    et devrait utiliser 'enabled_key' => null plutôt qu'une fausse clé.
     * 2. Défaut incohérent : un 'enabled_key' dont le getter ConfigManager
     *    correspondant (isXxxEnabled()) déclare un défaut à 1, mais dont la
     *    clé n'est pas semée dans neria.php::setDefaultConfiguration() ET
     *    sans 'default_if_unset' => true dans le registre — sur une install
     *    jamais configurée, Configuration::getGlobalValue() renverrait false
     *    et le centre de contrôle afficherait à tort "Inactif".
     */
    private function checkControlCenterDefaultsConsistency(): array
    {
        if (!class_exists('ConfigManager') || !defined('ConfigManager::CONTROL_CENTER_REGISTRY')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.cc_defaults_ok')];
        }

        $moduleDir  = _PS_MODULE_DIR_ . $this->module->name;
        $configFile = $moduleDir . '/src/ConfigManager.php';
        $mainFile   = $moduleDir . '/' . $this->module->name . '.php';
        $configSrc = $this->readModuleSrc($configFile);
        $mainSrc = $this->readModuleSrc($mainFile);

        if ($configSrc === '' || $mainSrc === '') {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.cc_defaults_ok')];
        }

        // Le reste du module référence souvent une clé de config via une
        // CONSTANTE de classe (ex: BounceManager::CFG_ENABLED) plutôt que la
        // chaîne littérale 'NERIA_BOUNCE_ENABLED' — grep sur tous les
        // fichiers src/*.php pour retrouver, pour une clé donnée, tout nom de
        // constante qui lui est égal, sans quoi la détection "clé fantôme"
        // ci-dessous donnerait des faux positifs sur ces raccourcis courants.
        // Retire uniquement les commentaires (docblocs inclus) avant analyse —
        // sinon une clé morte simplement MENTIONNÉE dans un commentaire
        // l'expliquant (comme ci-dessus pour NERIA_ATTRIBUTION_ENABLED) se
        // compterait à tort comme un usage réel. Les littéraux de chaîne
        // sont conservés : c'est justement ce qu'on cherche à compter.
        $stripComments = static function (string $code): string {
            $out = '';
            foreach (token_get_all($code) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $out .= $token[1];
                } else {
                    $out .= $token;
                }
            }
            return $out;
        };

        $srcFiles = glob($moduleDir . '/src/*.php') ?: [];
        $allSrc   = ['ConfigManager.php' => $stripComments($configSrc), 'neria.php' => $stripComments($mainSrc)];
        foreach ($srcFiles as $f) {
            $base = basename($f);
            if (!isset($allSrc[$base])) {
                $allSrc[$base] = $stripComments(file_get_contents($f) ?: '');
            }
        }
        $wholeModuleSrc = implode("\n", $allSrc);

        // ── Map constante KEY_X => 'NERIA_X' (ConfigManager) ────────────
        $constMap = [];
        if (preg_match_all("/const\\s+(KEY_\\w+)\\s*=\\s*'([^']+)'/", $configSrc, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $constMap[$mm[1]] = $mm[2];
            }
        }

        // ── Map clé de config => défaut déclaré par son getter isXxxEnabled() ──
        $getterDefault = [];
        if (preg_match_all(
            "/return\\s*\\(bool\\)\\s*\\\$this->get\\(self::(KEY_\\w+),\\s*(\\d+)\\)/",
            $configSrc,
            $m2,
            PREG_SET_ORDER
        )) {
            foreach ($m2 as $mm) {
                if (isset($constMap[$mm[1]])) {
                    $getterDefault[$constMap[$mm[1]]] = (int) $mm[2];
                }
            }
        }

        // ── Clés effectivement semées à l'installation (setDefaultConfiguration) ──
        // Deux formes possibles dans le tableau $defaults : littérale
        // ('NERIA_X' => ...) ou concaténée (self::CONFIG_PREFIX . 'X' => ...).
        $seeded = [];
        if (preg_match('/setDefaultConfiguration\(\)[^{]*\{(.*?)foreach\s*\(\s*\$defaults/s', $mainSrc, $blockMatch)) {
            preg_match_all("/'(NERIA_[A-Z0-9_]+)'\\s*=>/", $blockMatch[1], $m3);
            $seeded = $m3[1];
            if (preg_match("/const\\s+CONFIG_PREFIX\\s*=\\s*'([^']+)'/", $mainSrc, $mp)) {
                preg_match_all(
                    "/self::CONFIG_PREFIX\\s*\\.\\s*'([A-Z0-9_]+)'\\s*=>/",
                    $blockMatch[1],
                    $m4
                );
                foreach ($m4[1] as $suffix) {
                    $seeded[] = $mp[1] . $suffix;
                }
            }
        }

        // ── Trouve, pour une clé de config donnée, tout nom de CONSTANTE de
        // classe (dans n'importe quel fichier src/*.php) qui lui est égale,
        // puis vérifie si cette constante est utilisée ailleurs (Xxx::CONST
        // ou self::CONST) — c'est la vraie détection d'usage, la clé brute
        // n'apparaissant parfois qu'une fois, dans sa propre déclaration.
        $isKeyReferencedElsewhere = function (string $key) use ($allSrc, $wholeModuleSrc): bool {
            // Usage direct de la chaîne littérale (Configuration::get('NERIA_X') ...)
            $literalOccurrences = substr_count($wholeModuleSrc, "'{$key}'");
            if ($literalOccurrences >= 2) {
                return true;
            }
            // Usage via une constante de classe qui vaut cette chaîne.
            foreach ($allSrc as $src) {
                if (preg_match_all("/const\\s+(\\w+)\\s*=\\s*'" . preg_quote($key, '/') . "'/", $src, $cm)) {
                    foreach ($cm[1] as $constName) {
                        if (preg_match('/\\w+::' . preg_quote($constName, '/') . '\\b/', $wholeModuleSrc)) {
                            return true;
                        }
                    }
                }
            }
            return false;
        };

        $offenders = [];

        foreach (ConfigManager::CONTROL_CENTER_REGISTRY as $item) {
            $key = $item['enabled_key'] ?? null;
            if ($key === null) {
                continue;
            }

            // 1. Clé fantôme : aucun usage réel trouvé ailleurs dans le module.
            if (!$isKeyReferencedElsewhere($key)) {
                $offenders[] = "{$item['key']} : {$key} n'est référencée nulle part ailleurs dans le module (clé fantôme probable — vérifier si cette feature a un vrai interrupteur, sinon enabled_key => null)";
                continue;
            }

            // 2. Défaut incohérent : getter à 1, jamais semé, pas de fallback déclaré.
            $trueDefault = $getterDefault[$key] ?? null;
            $isSeeded    = in_array($key, $seeded, true);
            $hasFallback = (bool) ($item['default_if_unset'] ?? false);
            if ($trueDefault === 1 && !$isSeeded && !$hasFallback) {
                $offenders[] = "{$item['key']} : {$key} est actif par défaut selon son getter ConfigManager mais n'est ni semé à l'install ni couvert par 'default_if_unset' (afficherait Inactif sur une boutique jamais configurée)";
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.cc_defaults_warning', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.cc_defaults_ok')];
    }

    /**
     * Contrôle statique PROSPECTIF (pas la re-détection d'un bug déjà
     * corrigé comme checkKnownRegressionsGuard(), mais la prévention de deux
     * pièges PrestaShop génériques documentés — n'importe quelle future
     * requête/appel pourrait les réintroduire, sans lien avec un incident
     * précis) :
     *  1. `Db::getRow()` ajoute automatiquement `LIMIT 1` — un `LIMIT`
     *     explicite dans la requête donne `LIMIT 1 LIMIT 1` (erreur SQL
     *     silencieuse en prod, _PS_MODE_DEV_=false).
     *  2. `Product::getPriceStatic()` fait un `die()` non catchable si appelé
     *     sans employé NI panier en contexte (cron/CLI) — tue tout le script
     *     hôte. Le seul point d'appel légitime doit rester protégé par un
     *     panier transitoire (`UpsellManager::safeProductPrice()`).
     */
    private function checkSqlPatternRisks(): array
    {
        $offenders = [];
        $srcDir = _PS_MODULE_DIR_ . $this->module->name . '/src';
        $files = is_dir($srcDir) ? (glob($srcDir . '/*.php') ?: []) : [];

        $priceStaticCallers = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file) ?: '';
            if ($raw === '') {
                continue;
            }
            $base = basename($file);

            // Retire commentaires et littéraux de chaîne AVANT analyse — sinon
            // une simple mention en docblock ("Product::getPriceStatic() fait
            // un die()") ou dans un message d'erreur se compte comme un vrai
            // appel. token_get_all() est fiable ici (pas de regex fragile).
            $codeOnly = '';
            foreach (token_get_all($raw) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                        continue;
                    }
                    $codeOnly .= $token[1];
                } else {
                    $codeOnly .= $token;
                }
            }

            // Piège 1 : getRow(...) contenant un LIMIT explicite dans la requête
            // (recherché dans le code brut car la requête SQL elle-même est un
            // littéral de chaîne, volontairement inclus ici malgré le filtre
            // ci-dessus — on veut justement inspecter SON contenu).
            if (preg_match('/->getRow\s*\(\s*(["\'])(?:(?!\1).)*?\bLIMIT\b(?:(?!\1).)*?\1/is', $raw)) {
                $offenders[] = "{$base} : getRow() avec un LIMIT explicite (Db::getRow() en ajoute déjà un — risque de doublon SQL)";
            }

            // Piège 2 : appel direct à Product::getPriceStatic() hors du wrapper
            // connu — cette fois sur le code réel uniquement (commentaires exclus).
            if (preg_match_all('/\\\\?Product::getPriceStatic\s*\(/', $codeOnly, $m)) {
                $priceStaticCallers[$base] = count($m[0]);
            }
        }

        // Un seul point d'appel légitime toléré : UpsellManager::safeProductPrice().
        foreach ($priceStaticCallers as $file => $count) {
            if ($file !== 'UpsellManager.php' || $count > 1) {
                $offenders[] = "{$file} : appel(le) Product::getPriceStatic() hors du wrapper protégé — risque de die() non catchable en cron/CLI sans employé ni panier";
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.sql_pattern_risks_warning', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.sql_pattern_risks_ok')];
    }

    /**
     * Contrôle statique PROSPECTIF : requête `LIKE '%{$var}%'` (ou
     * `LIKE '%' . $var . '%'`) où $var n'est jamais passé dans un
     * str_replace() échappant les métacaractères LIKE (% et _) avant
     * d'être inséré dans le motif. pSQL() échappe guillemets/backslashes
     * mais PAS la sémantique LIKE — un '_' est un caractère valide dans une
     * adresse email (ex. john_doe@…) et matche n'importe quel caractère
     * unique dans le motif, causant une sur-correspondance.
     *
     * Trouvé en réel le 2026-08-03 dans GdprAuditManager::purgeCustomerData()
     * (purge RGPD de neria_webhook_queue par email dans le payload — pouvait
     * supprimer les données d'un tiers), CollectionManager::searchProducts()
     * et BounceManager::getBounceList()/getBounceCount() (bruit dans la
     * recherche BO). Corrigés en échappant % et _ via str_replace() (ou
     * addcslashes()) avant pSQL().
     *
     * likeVarIsEscaped() suit la chaîne d'affectation sur 2 niveaux
     * d'indirection (ex. $emailSql = pSQL($emailLike) où l'échappement est
     * fait sur $emailLike, pas $emailSql) pour éviter un faux positif sur
     * ce correctif même, et reconnaît aussi bien str_replace() qu'addcslashes().
     *
     * Les deux regex tolèrent un guillemet PHP échappé (\') autour du motif
     * LIKE — la requête SQL est souvent elle-même imbriquée dans une chaîne
     * à guillemets simples (ex. `. \'%' . $var . '%\' .`), ce qui a fait
     * rater ManualSendManager::searchCustomers() à la première version de ce
     * contrôle (trouvé en réel le 2026-08-03, corrigé depuis).
     */
    private function checkUnescapedLikeMetachars(): array
    {
        $offenders = [];
        $srcDir = _PS_MODULE_DIR_ . $this->module->name . '/src';
        $files = is_dir($srcDir) ? (glob($srcDir . '/*.php') ?: []) : [];

        foreach ($files as $file) {
            $base = basename($file);
            // Exclu : ce fichier contient le texte source des regex de
            // détection ci-dessous (docblock + littéraux), qui matche sa
            // propre recherche de motif LIKE — faux positif garanti sur
            // lui-même, comme checkCronLoopMissingTryCatch() s'exclut déjà.
            if ($base === 'HealthCheckManager.php') {
                continue;
            }
            $raw = file_get_contents($file) ?: '';
            if ($raw === '' || stripos($raw, 'LIKE') === false) {
                continue;
            }

            $vars = [];
            if (preg_match_all('/LIKE\s*\\\\?\'%\{?\$(\w+)\}?%\\\\?\'/', $raw, $m1)) {
                $vars = array_merge($vars, $m1[1]);
            }
            if (preg_match_all('/LIKE\s*\\\\?\'%\\\\?\'\s*\.\s*\$(\w+)\s*\.\s*\\\\?\'%\\\\?\'/', $raw, $m2)) {
                $vars = array_merge($vars, $m2[1]);
            }
            if (!$vars) {
                continue;
            }

            foreach (array_unique($vars) as $varName) {
                if (!$this->likeVarIsEscaped($raw, $varName)) {
                    $offenders[] = "{$base} : LIKE '%\${$varName}%' sans échappement des métacaractères % et _ avant pSQL()";
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.unescaped_like_metachars_warning', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.unescaped_like_metachars_ok')];
    }

    /**
     * Contrôle statique PROSPECTIF : tout template réellement envoyé dans le
     * code (via Mail::Send(...) ou le wrapper $this->send(...) de
     * BehavioralCronManager) doit être présent à la fois dans
     * PreferencesManager::TEMPLATE_CAT et StatsManager::$CHART_CATEGORIES —
     * sinon les préférences client ne s'y appliquent jamais (TEMPLATE_CAT
     * absent → isAllowed() retourne toujours true) et/ou son revenu est mal
     * attribué dans les stats par catégorie (bucket "other").
     *
     * Reprend l'esprit de test_16_preferences_template_cat_complete.php
     * (régression ciblée sur BehavioralCronManager uniquement) mais en
     * continu sur TOUT src/*.php et visible dans l'onglet Santé du BO, pas
     * seulement au lancement manuel de la suite de tests — trouvé en réel
     * le 2026-08-03 pour 5 templates (post_purchase_care, order_on_hold,
     * order_partial_shipped, refund_processed, return_received), absents
     * depuis leur création faute d'un tel garde-fou permanent.
     */
    private function checkTemplateCategoryMappingComplete(): array
    {
        $srcDir = _PS_MODULE_DIR_ . $this->module->name . '/src';
        if (!is_dir($srcDir) || !class_exists('PreferencesManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.template_cat_mapping_ok')];
        }

        $sent = [];
        foreach (glob($srcDir . '/*.php') ?: [] as $file) {
            // Exclu : ce fichier contient lui-même le texte source des motifs
            // recherchés ci-dessous (docblock), faux positif garanti sur
            // lui-même — même exclusion que checkUnescapedLikeMetachars().
            if (basename($file) === 'HealthCheckManager.php') {
                continue;
            }
            $raw = file_get_contents($file) ?: '';
            if ($raw === '') {
                continue;
            }
            if (preg_match_all('/Mail::Send\(\s*\$\w+,\s*\'([a-z_0-9]+)\'/', $raw, $m)) {
                $sent = array_merge($sent, $m[1]);
            }
            if (preg_match_all('/->send\(\s*\'([a-z_0-9]+)\'/', $raw, $m)) {
                $sent = array_merge($sent, $m[1]);
            }
        }
        // ManualSendManager::WAVE1_TEMPLATES et ABTestManager::getEligibleTemplates()
        // sont des catalogues de templates sélectionnables DYNAMIQUEMENT par le
        // marchand (envoi manuel BO / test A/B) — le template littéral n'apparaît
        // jamais dans un Mail::Send()/->send() en dur, donc invisible au scan
        // ci-dessus. Trouvé en réel au round 72 (commit 072212b) : vip,
        // private_invitation, voucher, voucher_new présents dans ces deux
        // catalogues mais absents de TEMPLATE_CAT — un client ayant désactivé la
        // catégorie correspondante recevait quand même l'email lors d'un envoi
        // manuel ou d'un test A/B, sans qu'aucun garde-fou ne l'ait détecté avant
        // une chasse manuelle. On les ajoute donc à $sent pour qu'ils passent par
        // le même contrôle de mapping.
        if (class_exists('ManualSendManager') && defined('ManualSendManager::WAVE1_TEMPLATES')) {
            $sent = array_merge($sent, \ManualSendManager::WAVE1_TEMPLATES);
        }
        $abtestFile = $srcDir . '/ABTestManager.php';
        $abtestRaw = $this->readModuleSrc($abtestFile);
        if (preg_match('/\$eligible\s*=\s*\[(.*?)\n\s*\];/s', $abtestRaw, $block)) {
            preg_match_all('/\'([a-z_0-9]+)\'/', $block[1], $mm);
            $sent = array_merge($sent, $mm[1]);
        }

        // Templates système/transactionnels volontairement HORS mapping
        // catégorie, par conception (même famille que les emails PS core
        // order_conf/payment/order_shipped — jamais préférence-gated) :
        // certificate_email (justificatif rattaché à une commande précise,
        // pas une catégorie marketing), neria_fallback (filet de sécurité
        // technique, déjà exempté du Mode Silence via
        // CooldownManager::BYPASS_TEMPLATES), monthly_report (rapport
        // adressé au MARCHAND, pas au client — aucune notion de préférence
        // ni d'attribution de revenu par catégorie n'a de sens ici),
        // newsletter_conf (confirmation double opt-in — transactionnel par
        // nature, listé dans ABTestManager::getEligibleTemplates() mais
        // jamais destiné à être préférence-gaté, comme les trois précédents).
        $sent = array_diff(array_unique($sent), ['certificate_email', 'neria_fallback', 'monthly_report', 'newsletter_conf']);

        // Extraction manifestement cassée (refactor ayant changé les deux
        // motifs ci-dessus) : se taire plutôt que de signaler massivement
        // des faux positifs sur la totalité des templates du module.
        if (count($sent) < 10) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.template_cat_mapping_ok')];
        }

        $prefCat = \PreferencesManager::TEMPLATE_CAT;

        // StatsManager::$CHART_CATEGORIES est privée statique : parsée depuis
        // le code source plutôt que par réflexion, comme les autres contrôles
        // statiques de ce fichier.
        $statsFile = $srcDir . '/StatsManager.php';
        $statsRaw = $this->readModuleSrc($statsFile);
        $statsCats = [];
        if (preg_match('/\$CHART_CATEGORIES\s*=\s*\[(.*?)\n\s*\];/s', $statsRaw, $block)) {
            preg_match_all('/\'([a-z_0-9]+)\'/', $block[1], $mm);
            $statsCats = $mm[1];
        }

        $missingPref  = [];
        $missingStats = [];
        foreach ($sent as $tpl) {
            if (!isset($prefCat[$tpl])) {
                $missingPref[] = $tpl;
            }
            if ($statsCats && !in_array($tpl, $statsCats, true)) {
                $missingStats[] = $tpl;
            }
        }

        $offenders = [];
        if ($missingPref) {
            $offenders[] = 'PreferencesManager::TEMPLATE_CAT : ' . implode(', ', $missingPref);
        }
        if ($missingStats) {
            $offenders[] = 'StatsManager::$CHART_CATEGORIES : ' . implode(', ', $missingStats);
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.template_cat_mapping_warning', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.template_cat_mapping_ok')];
    }

    /**
     * Contrôle statique PROSPECTIF : une colonne `date_add`/`created_at`
     * insérée via l'horloge PHP (date('Y-m-d H:i:s')) plutôt que via NOW()
     * côté SQL. Convention constante ailleurs dans le module
     * (BlacklistManager, BehavioralCronManager, ChurnScoreManager...) :
     * ces colonnes sont TOUJOURS écrites avec NOW() précisément parce
     * qu'elles sont ensuite comparées via NOW()/DATE_SUB(NOW(), ...) côté
     * SQL ailleurs dans le code. Mélanger horloge PHP (écriture) et horloge
     * MySQL (lecture) sur la même colonne casse silencieusement toute
     * fenêtre de temps calculée dessus dès que les deux serveurs ne
     * partagent pas le même fuseau horaire (hébergement mutualisé/managé
     * fréquent).
     *
     * Trouvé en réel le 2026-08-03 dans StatsManager::record() —
     * date_add de neria_stat était stampée en PHP alors que
     * CooldownManager::isDuplicate() (Mode Silence) et
     * StatsManager::detectAnomalies() la comparent via NOW()/DATE_SUB(NOW())
     * ; un décalage d'1h+ entre serveur web et serveur DB faussait
     * silencieusement la fenêtre de cooldown et les alertes d'anomalie.
     * Corrigé en stampant via NOW() à l'insertion.
     *
     * Heuristique par fenêtre glissante (comme checkCronStrictDateEquality) :
     * pour chaque affectation `$var = date('Y-m-d H:i:s')`, on regarde les
     * ~2500 caractères suivants — si ce voisinage contient une colonne
     * `date_add`/`created_at` dans un INSERT ET la variable elle-même
     * (probablement passée en argument de la requête) SANS que NOW()
     * n'apparaisse dans ce même voisinage, on signale un risque de mélange
     * d'horloges. Faux positif possible si $var sert à autre chose dans la
     * fenêtre (ex. log) sans être réellement liée à la colonne — accepté,
     * dans le même esprit que les autres contrôles prospectifs de ce fichier.
     */
    private function checkPhpMysqlClockMismatch(): array
    {
        $offenders = [];
        $srcDir = _PS_MODULE_DIR_ . $this->module->name . '/src';
        $files = is_dir($srcDir) ? (glob($srcDir . '/*.php') ?: []) : [];

        // Inclut aussi neria.php (racine du module) — trouvé en réel le
        // 2026-08-03 avec 8 occurrences (imports/exports CSV traductions,
        // auto-traduction DeepL, restauration d'historique), alors que la
        // version initiale de ce contrôle ne scannait que src/*.php.
        $rootFile = _PS_MODULE_DIR_ . $this->module->name . '/neria.php';
        if (is_file($rootFile)) {
            $files[] = $rootFile;
        }

        foreach ($files as $file) {
            $base = basename($file);
            if ($base === 'HealthCheckManager.php') {
                continue;
            }
            $raw = file_get_contents($file) ?: '';
            if ($raw === '' || stripos($raw, "date('Y-m-d H:i:s')") === false) {
                continue;
            }

            if (!preg_match_all('/\$(\w+)\s*=\s*date\(\s*\'Y-m-d H:i:s\'\s*\)\s*;/', $raw, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[1] as $match) {
                $varName = $match[0];
                $pos     = $match[1];
                $window  = substr($raw, $pos, 2500);

                $hasDateAddColumn = (bool) preg_match('/`(date_add|created_at)`/', $window);
                $hasVarUsage      = (bool) preg_match('/\$' . preg_quote($varName, '/') . '\b/', substr($window, strlen($varName) + 20));
                $hasNow           = strpos($window, 'NOW()') !== false;

                if ($hasDateAddColumn && $hasVarUsage && !$hasNow) {
                    $line = substr_count(substr($raw, 0, $pos), "\n") + 1;
                    $offenders[] = "{$base}:{$line} : \${$varName} = date('Y-m-d H:i:s') utilisé près d'une colonne date_add/created_at sans NOW()";
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.php_mysql_clock_mismatch_warning', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.php_mysql_clock_mismatch_ok')];
    }

    /**
     * Suit la chaîne d'affectation de $varName dans $raw (max 2 niveaux
     * d'indirection) à la recherche d'un échappement des métacaractères LIKE
     * (% et _) via str_replace() ou addcslashes().
     */
    private function likeVarIsEscaped(string $raw, string $varName, int $depth = 0): bool
    {
        if ($depth > 2) {
            return false;
        }
        if (!preg_match_all('/\$' . preg_quote($varName, '/') . '\s*=([^;]*);/s', $raw, $assigns)) {
            return false;
        }
        foreach ($assigns[1] as $expr) {
            if (stripos($expr, 'str_replace') !== false
                && strpos($expr, "'%'") !== false
                && strpos($expr, "'_'") !== false
            ) {
                return true;
            }
            if (stripos($expr, 'addcslashes') !== false
                && strpos($expr, '%') !== false
                && strpos($expr, '_') !== false
            ) {
                return true;
            }
            if (preg_match_all('/\$(\w+)/', $expr, $refs)) {
                foreach (array_unique($refs[1]) as $ref) {
                    if ($ref !== $varName && $this->likeVarIsEscaped($raw, $ref, $depth + 1)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Contrôle statique PROSPECTIF (comme checkSqlPatternRisks()) sur deux
     * pièges i18n/Smarty génériques documentés, réintroductibles par
     * n'importe quel futur template ou correction de traduction :
     *  1. `{neria_admin key='...'|modificateur}` / `{neria_trad key='...'
     *     |modificateur}` — en Smarty, un modificateur après un paramètre
     *     NOMMÉ s'applique à la VALEUR du paramètre, pas à la sortie de la
     *     fonction (piège trouvé le 2026-07-04, 76 occurrences corrigées via
     *     un paramètre `esc=` dédié, cf. feedback_smarty_modifier_binding).
     *  2. Une clé de langue orpheline dans data/translations.json — un
     *     ancien code de langue avec tiret (ex. "pt-br", "zh-tw") au lieu du
     *     code court normalisé ("br", "tw") — jamais lue par
     *     TranslationEngine, contient parfois une traduction correcte
     *     abandonnée pendant que la vraie clé a dérivé (cf.
     *     feedback_orphan_language_keys, 2 occurrences trouvées sur deux
     *     chantiers de revue indépendants).
     */
    private function checkI18nPatternRisks(): array
    {
        $offenders = [];

        $tplDir = _PS_MODULE_DIR_ . $this->module->name . '/views/templates';
        $tplFiles = $this->globRecursive($tplDir, '.tpl');
        $mailsDir = _PS_MODULE_DIR_ . $this->module->name . '/mails';
        $mailFiles = $this->globRecursive($mailsDir, '.html');

        foreach (array_merge($tplFiles, $mailFiles) as $file) {
            $src = file_get_contents($file) ?: '';
            if ($src === '') {
                continue;
            }
            // Repère {neria_admin ...|xxx} ou {neria_trad ...|xxx} : un
            // modificateur de type SORTIE (échappement/formatage) à
            // l'intérieur de la même balise Smarty, après un paramètre
            // nommé — signe du piège de liaison modificateur/paramètre
            // exact du bug du 2026-07-04. Liste noire volontairement
            // étroite (pas une liste blanche) : |default et |@count après
            // un paramètre nommé sont des usages légitimes et courants
            // ("clé de repli si vide", "calcule un nombre à passer en
            // paramètre") — ce sont des modificateurs de PARAMÈTRE par
            // nature, pas de sortie, donc jamais le piège documenté.
            $outputModifiers = 'escape|replace|nl2br|strip_tags|truncate|upper|lower|ucfirst|capitalize|string_format|wordwrap|indent';
            if (preg_match('/\{(?:neria_admin|neria_trad)\s+[^{}]*\|\s*(?:' . $outputModifiers . ')\b[^{}]*\}/', $src)) {
                $offenders[] = basename($file) . ' : utilise un modificateur de sortie Smarty (escape/replace/...) après un paramètre nommé ({neria_admin/neria_trad ...|xxx}) — s\'applique au paramètre, pas à la sortie ; utiliser esc=\'...\' à la place';
            }
        }

        $jsonPath = _PS_MODULE_DIR_ . $this->module->name . '/data/translations.json';
        $jsonSrc = $this->readModuleSrc($jsonPath);
        if ($jsonSrc !== '' && preg_match_all('/"[a-z]{2}-[a-z]{2}"\s*:/', $jsonSrc, $m)) {
            $offenders[] = 'data/translations.json : ' . count($m[0]) . ' clé(s) de langue orpheline(s) détectée(s) (ancien code avec tiret, ex. "pt-br"/"zh-tw" — jamais lu par TranslationEngine)';
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.i18n_pattern_risks_warning', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.i18n_pattern_risks_ok')];
    }

    /**
     * Contrôle statique GÉNÉRIQUE (contrairement à checkKnownRegressionsGuard,
     * ciblé sur 3 lignes précises déjà corrigées) : scanne src/*.php à la
     * recherche de texte français codé en dur dans un littéral de chaîne,
     * hors commentaires, qui n'est pas passé par TranslationEngine::get()/
     * AdminTranslator::t()/tVars() sur la même ligne — le motif exact du bug
     * du 2026-07-29 (signature/pied de page du certificat PDF restés en
     * français dans les autres langues). Liste de mots volontairement
     * étroite (texte destiné au client, pas des termes techniques/clés de
     * config) pour limiter les faux positifs.
     */
    private function checkHardcodedFrenchText(): array
    {
        $offenders = [];
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $srcDir    = $moduleDir . '/src';
        $files     = is_dir($srcDir) ? (glob($srcDir . '/*.php') ?: []) : [];

        // Fichiers volontairement exclus : français légitime et attendu, pas
        // un texte client codé en dur au lieu d'une traduction.
        //  - ConfigManager : table de salutations horaires indexée PAR LANGUE
        //    (la clé 'fr' y est une VALEUR légitime, pas un oubli de trad).
        //  - DeliverabilityScorer : dictionnaire de mots-déclencheurs anti-spam
        //    EN FRANÇAIS par construction (détecte du spam dans du texte FR).
        //  - EmailRenderer : jeu de données FICTIVES pour l'aperçu BO (le
        //    marchand voit toujours un exemple, jamais envoyé à un client).
        //  - NeriaTools : libellés internes de la liste des templates côté BO
        //    (interface d'administration, pas du contenu client).
        //  - HealthCheckManager : ce fichier lui-même — checkKnownRegressionsGuard()
        //    contient délibérément les anciennes chaînes françaises en dur
        //    comme motif de non-régression, pas une régression réelle.
        $excludedFiles = [
            'ConfigManager.php', 'DeliverabilityScorer.php', 'EmailRenderer.php',
            'NeriaTools.php', 'HealthCheckManager.php',
        ];
        $files = array_filter($files, static fn($f) => !in_array(basename($f), $excludedFiles, true));

        $frenchPhrases = [
            'veuillez', 'merci de', 'cher client', 'chère cliente', 'bonjour',
            'cordialement', 'félicitations', 'certificat officiel',
            'signature officielle', 'émis par', 'scannez ce', 'télécharg',
            'ce document est', 'votre commande', 'code de réduction',
            'numéro de commande', 'à bientôt', "n'hésitez pas",
        ];
        $wordsAlt = implode('|', array_map(static fn($w) => preg_quote($w, '/'), $frenchPhrases));
        // Chaîne littérale (simple ou double quotes) contenant une des
        // expressions ci-dessus.
        $stringPattern = '/[\'"][^\'"]*(?:' . $wordsAlt . ')[^\'"]*[\'"]/iu';
        // Un des appelants sûrs présents plus tôt sur la même ligne neutralise
        // le signalement (texte = clé source de traduction, pas la sortie).
        $safeCallerPattern = '/(?:AdminTranslator::t(?:Vars)?|TranslationEngine::get|->get\(\s*[\'"][a-z_]+[\'"]\s*,)\s*\(/i';

        foreach ($files as $file) {
            $rawFull = file_get_contents($file) ?: '';
            if ($rawFull === '') {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', $rawFull);
            $inBlockComment = false;
            foreach ($lines as $i => $line) {
                $code = $line;
                if ($inBlockComment) {
                    $end = strpos($code, '*/');
                    if ($end === false) {
                        continue;
                    }
                    $code = substr($code, $end + 2);
                    $inBlockComment = false;
                }
                $blockStart = strpos($code, '/*');
                if ($blockStart !== false && strpos($code, '*/', $blockStart) === false) {
                    $code = substr($code, 0, $blockStart);
                    $inBlockComment = true;
                }
                $lineCommentPos = strpos($code, '//');
                if ($lineCommentPos !== false) {
                    $code = substr($code, 0, $lineCommentPos);
                }

                if (trim($code) === '' || !preg_match($stringPattern, $code)) {
                    continue;
                }
                if (preg_match($safeCallerPattern, $code)) {
                    continue;
                }

                $offenders[] = basename($file) . ':' . ($i + 1);
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.hardcoded_french_text_warning', [
                    'count' => count($offenders),
                    'list'  => implode(' | ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.hardcoded_french_text_ok')];
    }

    /**
     * Contrôle statique PROSPECTIF : détecte tout appel à
     * Link::getPageLink()/getModuleLink() dans src/*.php sans idLang
     * explicite (6e/5e argument). Sans lui, PrestaShop retombe silencieusement
     * sur la langue du CONTEXTE APPELANT (souvent le français du BO/cron),
     * pas la langue réelle de l'email envoyé — piège trouvé et corrigé 12
     * fois dans 8 fichiers différents le 2026-07-09 (cf.
     * project_idlang_bug_pattern_audit). getWebhookUrl() (BounceManager) est
     * volontairement exclu : URL système sans notion de langue.
     */
    private function checkMissingIdLangInLinks(): array
    {
        $offenders = [];
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $srcDir = $moduleDir . '/src';
        $files = is_dir($srcDir) ? (glob($srcDir . '/*.php') ?: []) : [];
        // Le fichier principal du module (racine, hors src/) contient aussi
        // des appels getPageLink/getModuleLink — angle mort trouvé le
        // 2026-07-20 : getUnsubscribeUrl() y était affecté sans que ce
        // contrôle, scopé à src/ jusque-là, ne le détecte.
        $mainFile = $moduleDir . '/' . $this->module->name . '.php';
        if (is_file($mainFile)) {
            $files[] = $mainFile;
        }

        foreach ($files as $file) {
            $base = basename($file);
            if ($base === 'BounceManager.php') {
                continue;
            }
            $rawFull = file_get_contents($file) ?: '';
            if ($rawFull === '') {
                continue;
            }

            // Exception explicite et documentée : un lien front-office rendu
            // pour le visiteur EN COURS (pas un email à un destinataire
            // distinct) veut légitimement la langue ambiante du contexte —
            // le marqueur "idLang volontairement omis" juste au-dessus de
            // l'appel neutralise la ligne suivante avant analyse.
            $rawFull = preg_replace_callback(
                '/idLang volontairement omis(?:[^\n]*\n\s*\/\/[^\n]*)*[^\n]*\n((?:\s*\/\/[^\n]*\n)*)(\s*)([^\n]*(?:getPageLink|getModuleLink)[^\n]*)\n/u',
                static function ($m) {
                    return "idLang volontairement omis (exclu du contrôle)\n" . $m[1] . $m[2] . "/* neutralisé par exception documentée */\n";
                },
                $rawFull
            );

            // Retire uniquement les commentaires (pas les littéraux de chaîne,
            // nécessaires pour repérer les parenthèses/virgules d'arguments) —
            // sinon un commentaire mentionnant "->getPageLink(...)" en exemple
            // (comme celui juste au-dessus) se compte comme un vrai appel.
            $raw = '';
            foreach (token_get_all($rawFull) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $raw .= is_array($token) ? $token[1] : $token;
            }

            // Repère ->getPageLink(...) / ->getModuleLink(...) et compte les
            // arguments de l'appel (jusqu'à la parenthèse fermante correspondante,
            // en respectant l'imbrication de parenthèses internes).
            if (!preg_match_all('/->(getPageLink|getModuleLink)\s*\(/', $raw, $calls, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($calls[0] as $idx => $call) {
                $methodName = $calls[1][$idx][0];
                $start = $call[1] + strlen($call[0]);
                $depth = 1;
                $end = $start;
                $len = strlen($raw);
                while ($end < $len && $depth > 0) {
                    if ($raw[$end] === '(') {
                        ++$depth;
                    } elseif ($raw[$end] === ')') {
                        --$depth;
                    }
                    ++$end;
                }
                $argsStr = substr($raw, $start, $end - $start - 1);
                // Découpage naïf sur les virgules de premier niveau (suffisant
                // ici : les arguments de ces méthodes sont des scalaires/variables
                // simples, pas des appels imbriqués complexes).
                $depth = 0;
                $argCount = trim($argsStr) === '' ? 0 : 1;
                for ($i = 0, $l = strlen($argsStr); $i < $l; ++$i) {
                    $c = $argsStr[$i];
                    if ($c === '(' || $c === '[') {
                        ++$depth;
                    } elseif ($c === ')' || $c === ']') {
                        --$depth;
                    } elseif ($c === ',' && $depth === 0) {
                        ++$argCount;
                    }
                }
                // getPageLink($controller, $ssl, $idLang, ...) : idLang = 3e arg.
                // getModuleLink($module, $controller, $params, $ssl, $idLang, ...) : idLang = 5e arg.
                $minArgsForIdLang = $methodName === 'getPageLink' ? 3 : 5;
                if ($argCount < $minArgsForIdLang) {
                    $offenders[$base] = ($offenders[$base] ?? 0) + 1;
                }
            }
        }

        if ($offenders) {
            $list = [];
            foreach ($offenders as $file => $count) {
                $list[] = "{$file} ({$count})";
            }
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.idlang_missing_warning', ['list' => implode(' | ', $list)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.idlang_missing_ok')];
    }

    /**
     * Contrôle statique : la version du module doit être identique dans
     * neria.php (const VERSION), config.xml (<version>) ET les 19 fichiers
     * docs/strings/*.js (notice multilingue, chacun porte son propre
     * `version: "X.Y.Z"`) — sinon Module::needUpgrade() peut se baser sur
     * une valeur désynchronisée et ignorer un upgrade réellement dû (cf.
     * feedback_module_upgrade_scripts), ou la notice livrée avec le module
     * affiche un numéro de version périmé.
     *
     * Généralisé à une LISTE de fichiers porteurs de version (pas une simple
     * comparaison à deux) le 2026-07-31 : après avoir corrigé le désync
     * neria.php/config.xml, les 19 fichiers docs/strings/*.js se sont
     * révélés être exactement le même bug, non couvert par la version
     * précédente de ce contrôle qui ne comparait que neria.php et config.xml.
     */
    private function checkModuleVersionFilesSync(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;

        $mainFile = $moduleDir . '/' . $this->module->name . '.php';
        $mainSrc = $this->readModuleSrc($mainFile);
        $codeVersion = null;
        if ($mainSrc !== '' && preg_match('/const\s+VERSION\s*=\s*[\'"]([\d.]+)[\'"]/', $mainSrc, $m)) {
            $codeVersion = $m[1];
        }
        if ($codeVersion === null) {
            return ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::t('health.version_files_sync_unreadable')];
        }

        // Chaque entrée : chemin relatif au module + regex capturant le numéro
        // de version (1er groupe). Ajouter ici tout nouveau fichier qui embarque
        // sa propre copie du numéro de version du module.
        $versionFiles = [
            'config.xml' => '/<version>(?:<!\[CDATA\[)?([\d.]+)/',
        ];
        foreach (glob($moduleDir . '/docs/strings/*.js') ?: [] as $stringsFile) {
            $versionFiles['docs/strings/' . basename($stringsFile)] = '/version:\s*"([\d.]+)"/';
        }

        $mismatched = [];
        $unreadable = 0;
        foreach ($versionFiles as $relPath => $pattern) {
            $fullPath = $moduleDir . '/' . $relPath;
            $src = $this->readModuleSrc($fullPath);
            if ($src === '' || !preg_match($pattern, $src, $m)) {
                $unreadable++;
                continue;
            }
            if ($m[1] !== $codeVersion) {
                $mismatched[] = $relPath . ' (' . $m[1] . ')';
            }
        }

        if ($mismatched) {
            // ERROR (et non WARNING) : trouvé en réel le 2026-07-31 — une version
            // bumpée dans neria.php sans toucher config.xml (commit du 2026-07-26)
            // est restée désynchronisée 5 jours sans être vue, le WARNING partant
            // seulement dans le digest quotidien groupé. config.xml est le fichier
            // que PrestaShop Addons valide au moment de la soumission du zip —
            // une désync doit être remontée immédiatement, pas noyée dans un digest.
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.version_files_sync_warning', [
                    'code' => $codeVersion,
                    'xml'  => implode(', ', $mismatched),
                ]),
            ];
        }

        if ($unreadable === count($versionFiles)) {
            return ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::t('health.version_files_sync_unreadable')];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.version_files_sync_ok', ['version' => $codeVersion])];
    }

    /**
     * Vérifie que chaque clé de config listée dans setDefaultConfiguration()
     * existe réellement en base sur CETTE installation. Une clé absente
     * (Configuration::get() renvoie false) signale qu'une feature a été
     * ajoutée au tableau des défauts sans script d'upgrade correspondant
     * pour les installations déjà existantes — ce contrôle n'existait pas
     * lorsque ce cas de figure s'est produit pour de vrai avec les clés
     * NERIA_LICENSE_* (corrigé par upgrade-1.0.31.php).
     */
    private function checkConfigDefaultsSeeded(): array
    {
        $mainFile = _PS_MODULE_DIR_ . $this->module->name . '/' . $this->module->name . '.php';
        $src = $this->readModuleSrc($mainFile);

        if (!preg_match('/\$defaults\s*=\s*\[(.*?)\n\s*\];/s', $src, $m)) {
            return ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::t('health.config_defaults_seeded_unreadable')];
        }

        $lines = explode("\n", $m[1]);
        $missing = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=>') === false) {
                continue;
            }
            $keyExpr = trim(substr($line, 0, strpos($line, '=>')));
            $key = null;

            if (preg_match('/^self::CONFIG_PREFIX\s*\.\s*\'([^\']+)\'$/', $keyExpr, $km)) {
                // CONFIG_PREFIX est une constante propre au module ('NERIA_'),
                // stable et sans risque à supposer ici plutôt que d'ajouter
                // un eval ou une reflection pour la résoudre dynamiquement.
                $key = 'NERIA_' . $km[1];
            } elseif (preg_match('/^\'([^\']+)\'$/', $keyExpr, $km)) {
                $key = $km[1];
            } elseif (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)$/', $keyExpr, $km)) {
                $const = $km[1] . '::' . $km[2];
                if (defined($const)) {
                    $key = (string) constant($const);
                }
            }

            if ($key !== null && \Configuration::get($key) === false) {
                $missing[] = $key;
            }
        }

        if ($missing) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.config_defaults_seeded_warning', [
                    'list' => implode(', ', $missing),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.config_defaults_seeded_ok')];
    }

    /**
     * Vérifie qu'un script upgrade-{VERSION}.php existe pour la version
     * actuelle du module — sinon toute clé/table ajoutée dans cette version
     * ne sera jamais appliquée aux installations déjà existantes (seul
     * setDefaultConfiguration()/install.sql en bénéficient, jamais rejoués
     * après l'installation initiale).
     */
    private function checkUpgradeScriptExistsForVersion(): array
    {
        $upgradeDir = _PS_MODULE_DIR_ . $this->module->name . '/upgrade';
        if (!is_dir($upgradeDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.upgrade_version_file_ok')];
        }

        $version = (string) $this->module->version;
        $expectedFile = $upgradeDir . '/upgrade-' . $version . '.php';

        if (!is_file($expectedFile)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.upgrade_version_file_warning', ['version' => $version]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.upgrade_version_file_ok')];
    }

    /**
     * Scan statique (aucun appel shell, uniquement file_get_contents +
     * regex — cohérent avec la leçon déjà tirée pour l'appel shell dans le scan
     * de code manuel) de tous les fichiers PHP du module, hors tests/, à
     * la recherche de deux classes de bugs déjà trouvées en réel :
     *  - vérification SSL désactivée sur un appel curl (2 occurrences trouvées le
     *    2026-07-23 sur les appels DeepL, malgré un audit sécurité
     *    antérieur qui pensait avoir traité la seule occurrence connue) ;
     *  - fonctions dangereuses (eval, system, l'appel shell d'exécution
     *    différée, passthru, exec, popen, proc_open, create_function),
     *    dont un appel shell trouvé le même jour dans le bouton BO de
     *    tests de régression.
     * Exécuté automatiquement (buildAllChecks), pas seulement via le
     * scan de code manuel — pour être détecté sans action du marchand.
     */
    private function checkSecurityPatternScan(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        $sslPattern = '/CURLOPT_SSL_VERIFYPEER\s*=>\s*false/';
        $dangerousPattern = '/\b(eval|system|shell_exec|passthru|exec|popen|proc_open|create_function)\s*\(/';

        foreach ($files as $file) {
            $content = file_get_contents($file) ?: '';
            // _PS_MODULE_DIR_ mélange '\' et '/' sur Windows (concaténation de
            // _PS_ROOT_DIR_ en backslash + '/modules/' littéral) — normaliser
            // avant comparaison, sinon str_replace ne matche rien et le chemin
            // relatif affiché reste l'absolu complet, illisible dans le rapport.
            $relative = ltrim(str_replace(str_replace('\\', '/', $moduleDir), '', str_replace('\\', '/', $file)), '/');

            if (preg_match($sslPattern, $content)) {
                $offenders[] = $relative . ' (SSL désactivé)';
            }
            if (preg_match($dangerousPattern, $content, $dm)) {
                $offenders[] = $relative . ' (' . $dm[1] . '())';
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.security_pattern_scan_error', [
                    'list' => implode(', ', $offenders),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.security_pattern_scan_ok')];
    }

    /**
     * Scan statique de neria.php : pour chaque dispatch d'action
     * `if (Tools::getValue('neria_action') === '...')` ou
     * `if (in_array(Tools::getValue('neria_action'), [...], true))`
     * dont le bloc effectue une vraie écriture (Configuration::updateGlobalValue,
     * ->insert(, ->update(, ->delete(, régénération de jeton via
     * bin2hex(random_bytes(), vérifie que la condition exige bien
     * $_SERVER['REQUEST_METHOD'] === 'POST'.
     *
     * Trouvé en réel le 2026-07-30 : 108 actions d'écriture du BO étaient
     * déclenchables via un simple lien GET (CSRF-via-lien) — corrigées
     * d'un coup après une revue manuelle à 3 agents. Ce contrôle existe
     * pour qu'une future action ajoutée sans le même réflexe soit détectée
     * automatiquement plutôt que de dépendre d'une nouvelle revue complète.
     *
     * Analyse par appariement de parenthèses/accolades (pas de simple
     * regex ligne à ligne, sous peine de rater les blocs `in_array(...)`
     * multi-lignes ou de mal apparier une accolade imbriquée). Volontairement
     * permissif : un dispatch dont le déclencheur est une variable
     * intermédiaire (ex: `$mpEarlyAction = Tools::getValue(...)` puis un
     * `if` séparé plus loin, cas des actions multipreview_submit_ et
     * multipreview_poll_) n'est pas couvert — pour ne jamais signaler à tort un bloc correctement gardé
     * ailleurs, au prix de laisser passer ce cas plus rare.
     */
    private function checkDestructiveActionsRequirePost(): array
    {
        $file = _PS_MODULE_DIR_ . $this->module->name . '/neria.php';
        if (!is_file($file)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.destructive_actions_post_ok')];
        }

        $content = file_get_contents($file) ?: '';
        $len     = strlen($content);

        $writePattern = '/Configuration::updateGlobalValue\(|->insert\(|->update\(|->delete\(|bin2hex\(random_bytes\(/';

        $offenders = [];
        $searchFrom = 0;

        while (($pos = strpos($content, "Tools::getValue('neria_action')", $searchFrom)) !== false) {
            $searchFrom = $pos + 1;

            $ifPos = strrpos(substr($content, 0, $pos), 'if (');
            if ($ifPos === false || $pos - $ifPos > 60) {
                continue; // pas un dispatch d'action direct — hors scope volontaire
            }

            // Apparie la parenthèse fermante de la condition du if
            $condStart = $ifPos + 3;
            $depth = 0;
            $condEnd = null;
            for ($i = $condStart; $i < $len; $i++) {
                if ($content[$i] === '(') {
                    $depth++;
                } elseif ($content[$i] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $condEnd = $i;
                        break;
                    }
                }
            }
            if ($condEnd === null || $pos < $condStart || $pos > $condEnd) {
                continue; // paren non apparié ou occurrence hors de cette condition
            }
            $condition = substr($content, $condStart, $condEnd - $condStart + 1);

            // Apparie l'accolade du bloc pour en lire le contenu
            $bracePos = strpos($content, '{', $condEnd);
            if ($bracePos === false || $bracePos - $condEnd > 5) {
                continue;
            }
            $depth = 0;
            $blockEnd = null;
            for ($i = $bracePos; $i < $len; $i++) {
                if ($content[$i] === '{') {
                    $depth++;
                } elseif ($content[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $blockEnd = $i;
                        break;
                    }
                }
            }
            if ($blockEnd === null) {
                continue;
            }
            $block = substr($content, $bracePos, $blockEnd - $bracePos + 1);

            if (!preg_match($writePattern, $block)) {
                continue; // bloc en lecture seule — pas concerné
            }
            if (str_contains($condition, 'REQUEST_METHOD')) {
                continue; // déjà gardé
            }

            preg_match_all("/'([a-z_0-9]+)'/i", $condition, $m);
            $names = array_slice(array_unique($m[1] ?? []), 0, 3);
            $line  = substr_count(substr($content, 0, $ifPos), "\n") + 1;
            $offenders[] = (implode('/', $names) ?: '?') . ' (L' . $line . ')';
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.destructive_actions_post_error', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.destructive_actions_post_ok')];
    }

    /**
     * Scan statique de src/*.php : date('d/m/Y'...) ou ->format('d/m/Y'...)
     * codé en dur dans un email envoyé au client, au lieu de passer par
     * NeriaTools::formatDate() (localisée par langue destinataire).
     *
     * Trouvé en réel le 2026-07-31 dans BehavioralCronManager.php et
     * CertificateManager.php : un client japonais ou anglais recevait une
     * date au format français quel que soit son destinataire. Exclut
     * NeriaTools.php lui-même (où formatDate() est définie et où le format
     * 'd/m/Y' est un repli légitime, pas un bug).
     */
    private function checkHardcodedDateFormat(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        foreach ($files as $file) {
            $base = basename($file);
            // NeriaTools.php : y définit formatDate() (repli 'd/m/Y' légitime).
            // HealthCheckManager.php : le docblock de CE contrôle cite
            // littéralement le motif recherché à titre d'exemple — auto-match.
            if ($base === 'NeriaTools.php' || $base === 'HealthCheckManager.php') {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            // _PS_MODULE_DIR_ mélange '\' et '/' sur Windows (concaténation de
            // _PS_ROOT_DIR_ en backslash + '/modules/' littéral) — normaliser
            // avant comparaison, sinon str_replace ne matche rien et le chemin
            // relatif affiché reste l'absolu complet, illisible dans le rapport.
            $relative = ltrim(str_replace(str_replace('\\', '/', $moduleDir), '', str_replace('\\', '/', $file)), '/');

            if (preg_match_all('/\bdate\s*\(\s*[\'"]d\/m\/Y|->format\s*\(\s*[\'"]d\/m\/Y/', $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.hardcoded_date_format_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.hardcoded_date_format_ok')];
    }

    /**
     * Scan statique de mails/themes/**\/*.html : text-align:left / float:left
     * codé en dur en style inline, au lieu de {$neria_text_align}/{$neria_dir}
     * — casse la mise en page pour l'arabe (RTL), invisible tant que
     * personne n'inspecte spécifiquement le rendu arabe.
     *
     * Trouvé en réel le 2026-07-31 sur 4 templates (collection_completion,
     * complete_your_look, ghost_cart, waitlist_available) : titre forcé à
     * gauche malgré un corps d'email en RTL. Volontairement permissif :
     * ne signale que text-align/float, pas tout usage de "left" (ex.
     * "margin-left" sur un élément décoratif symétrique n'est pas concerné).
     */
    private function checkRtlHardcodedAlignment(): array
    {
        $mailsDir = _PS_MODULE_DIR_ . $this->module->name . '/mails/themes';
        if (!is_dir($mailsDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.rtl_hardcoded_align_ok')];
        }

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($mailsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'html') {
                continue;
            }
            $path = $fileInfo->getPathname();
            $content = file_get_contents($path) ?: '';
            $relative = ltrim(str_replace(str_replace('\\', '/', $mailsDir), '', str_replace('\\', '/', $path)), '/');

            if (preg_match_all('/(?:text-align|float)\s*:\s*left\b/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.rtl_hardcoded_align_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.rtl_hardcoded_align_ok')];
    }

    /**
     * Scan statique de src/*.php : appels à NeriaTools::displayPrice() sans
     * le 3e argument optionnel $idLang, dans les gestionnaires de cron
     * comportemental — le contexte de langue ambiant (Context::getContext()
     * ->language) y est celui de la dernière requête web/admin, pas
     * forcément celui du client destinataire de l'email.
     *
     * Trouvé en réel le 2026-07-31 dans 5 endroits (BehavioralCronManager,
     * CollectionManager, LookCompletionManager, WaitlistManager,
     * OrderTriggersManager), corrigés en ajoutant $idLang partout.
     * Volontairement scopé aux fichiers *CronManager.php et aux managers
     * connus pour construire des emails hors requête HTTP directe — un appel
     * à displayPrice() dans le flux front-office classique (où le contexte
     * langue est déjà correct) n'a pas besoin de ce 3e argument, d'où une
     * vérification par appariement de parenthèses plutôt qu'un blocage
     * générique sur toute occurrence du nom de la méthode.
     */
    private function checkDisplayPriceMissingLang(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        foreach ($files as $file) {
            $base = basename($file);
            // NeriaTools.php : le code de displayPrice() lui-même. HealthCheckManager.php :
            // le docblock/code de CE contrôle cite littéralement 'NeriaTools::displayPrice('
            // à titre d'exemple/motif recherché — auto-match sinon.
            if ($base === 'NeriaTools.php' || $base === 'HealthCheckManager.php') {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            // _PS_MODULE_DIR_ mélange '\' et '/' sur Windows (concaténation de
            // _PS_ROOT_DIR_ en backslash + '/modules/' littéral) — normaliser
            // avant comparaison, sinon str_replace ne matche rien et le chemin
            // relatif affiché reste l'absolu complet, illisible dans le rapport.
            $relative = ltrim(str_replace(str_replace('\\', '/', $moduleDir), '', str_replace('\\', '/', $file)), '/');
            $len = strlen($content);

            $searchFrom = 0;
            while (($pos = strpos($content, 'NeriaTools::displayPrice(', $searchFrom)) !== false) {
                $callStart = $pos + strlen('NeriaTools::displayPrice');
                $searchFrom = $pos + 1;

                // Apparie la parenthèse fermante de l'appel
                $depth = 0;
                $argsEnd = null;
                for ($i = $callStart; $i < $len; $i++) {
                    if ($content[$i] === '(') {
                        $depth++;
                    } elseif ($content[$i] === ')') {
                        $depth--;
                        if ($depth === 0) {
                            $argsEnd = $i;
                            break;
                        }
                    }
                }
                if ($argsEnd === null) {
                    continue;
                }
                $args = substr($content, $callStart + 1, $argsEnd - $callStart - 1);

                // Compte les virgules au niveau 0 de profondeur (hors parenthèses
                // imbriquées d'un éventuel appel de fonction en argument)
                $argDepth = 0;
                $commaCount = 0;
                for ($i = 0, $al = strlen($args); $i < $al; $i++) {
                    if ($args[$i] === '(') {
                        $argDepth++;
                    } elseif ($args[$i] === ')') {
                        $argDepth--;
                    } elseif ($args[$i] === ',' && $argDepth === 0) {
                        $commaCount++;
                    }
                }

                if ($commaCount < 2 && trim($args) !== '') {
                    $line = substr_count(substr($content, 0, $pos), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.display_price_missing_lang_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.display_price_missing_lang_ok')];
    }

    /**
     * Scan statique de src/*.php : number_format($x, 2, ',', ...) codé en
     * dur — sépare décimales par une virgule quelle que soit la langue du
     * destinataire de l'email, au lieu de NeriaTools::displayPrice()/
     * NumberFormatter (locale-aware).
     *
     * Trouvé en réel le 2026-08-01, 3 occurrences indépendantes dans le même
     * round de bug-hunting (EmailRenderer::voucherRateFromCode, UpsellManager
     * ::enrich, LoyaltyManager tier reward) : un même défaut réintroduit à
     * chaque nouvelle formule de montant écrite à la main plutôt qu'en
     * passant par le helper localisé. Ne signale QUE le 3e argument littéral
     * ',' (séparateur décimal) — pas le 4e (séparateur de milliers), qui a
     * des usages légitimes non liés à la langue (ex. regroupement visuel).
     */
    private function checkHardcodedDecimalFormat(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        foreach ($files as $file) {
            $base = basename($file);
            // NeriaTools.php : son propre dernier repli sans intl (légitime,
            // documenté). HealthCheckManager.php : ce docblock/code cite le
            // motif recherché à titre d'exemple — auto-match sinon.
            if ($base === 'NeriaTools.php' || $base === 'HealthCheckManager.php') {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            $relative = ltrim(str_replace(str_replace('\\', '/', $moduleDir), '', str_replace('\\', '/', $file)), '/');

            if (preg_match_all('/number_format\s*\([^,]+,\s*\d+\s*,\s*[\'"],[\'"]/', $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.hardcoded_decimal_format_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.hardcoded_decimal_format_ok')];
    }

    /**
     * Scan statique de src/*.php : str_replace(array_keys($x), array_values($x), ...)
     * — substitution de variables par tableaux parallèles, qui enchaîne les
     * remplacements SÉQUENTIELLEMENT sur le résultat déjà transformé. Si la
     * valeur d'UNE variable contient littéralement le texte "{autre_clé}"
     * (champ libre BO — nom de marque, slogan, texte personnalisé — sans
     * validation contre ce motif), ce texte injecté se fait à son tour
     * remplacer selon l'ordre d'itération du tableau, corrompant
     * silencieusement le texte affiché au client.
     *
     * Trouvé en réel le 2026-08-01 dans TranslationEngine::resolveVariables()
     * (corrigé) ET 5 fois dans EmailRenderer.php (corrigées dans la même
     * session) — le moteur de compilation le plus critique du module.
     * Corrigé partout en remplaçant par strtr($text, $array), qui effectue
     * un seul passage simultané sans jamais rescanner une portion déjà
     * substituée.
     */
    private function checkChainedStrReplace(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        foreach ($files as $file) {
            $base = basename($file);
            // HealthCheckManager.php : ce docblock cite littéralement le
            // motif recherché à titre d'exemple — auto-match sinon.
            if ($base === 'HealthCheckManager.php') {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            $relative = ltrim(str_replace(str_replace('\\', '/', $moduleDir), '', str_replace('\\', '/', $file)), '/');

            if (preg_match_all('/str_replace\s*\(\s*array_keys\s*\(/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.chained_str_replace_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.chained_str_replace_ok')];
    }

    /**
     * Scan statique : requête SQL sur la table `customer` avec une condition
     * sur `email`, sans filtre `id_shop` dans la même requête. Motif trouvé
     * et corrigé de façon récurrente (unsubscribe.php, preferences.php,
     * neria.php centre de préférences, neria.php devis B2B,
     * EmailRenderer::resolveCustomerId(), le 01/08/2026) : en multiboutique
     * sans partage de comptes, la même adresse email peut correspondre à
     * des lignes client distinctes par boutique — sans ce filtre,
     * `ORDER BY id_customer DESC` peut résoudre le client d'une AUTRE
     * boutique (contournement RGPD, mauvais rattachement de devis/commande,
     * mauvaise détection de langue).
     *
     * Heuristique volontairement prudente (fenêtre de caractères autour du
     * mot "email", pas un vrai parseur SQL) : signale un candidat à vérifier
     * manuellement, pas une certitude. Exclut le motif
     * Shop::addSqlRestriction()/$shopRestriction, idiome natif PS déjà
     * correct pour ce même besoin (ManualSendManager::findCustomer()).
     */
    private function checkCustomerEmailLookupMissingShop(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        foreach ($files as $file) {
            $base = basename($file);
            if ($base === 'HealthCheckManager.php') {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            $relative = ltrim(str_replace(str_replace('\\', '/', $moduleDir), '', str_replace('\\', '/', $file)), '/');

            if (!preg_match_all(
                '/customer`([\s\S]{0,200}?)\bemail\b[^=\n]{0,15}=([\s\S]{0,200})/i',
                $content,
                $m,
                PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            foreach ($m[0] as $i => $fullMatch) {
                $combined = $m[1][$i][0] . $m[2][$i][0];
                if (stripos($combined, 'id_shop') !== false) {
                    continue;
                }
                if (stripos($combined, 'shopRestriction') !== false || stripos($combined, 'addSqlRestriction') !== false) {
                    continue;
                }
                $line = substr_count(substr($content, 0, $fullMatch[1]), "\n") + 1;
                $offenders[] = $relative . ':' . $line;
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.customer_email_shop_scope_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.customer_email_shop_scope_ok')];
    }

    /**
     * Scan statique : occurrences de Currency::getDefaultCurrency(), qui
     * ignore toujours la devise réelle de la boutique du destinataire au
     * profit de la devise par défaut GLOBALE. Motif trouvé et corrigé 3 fois
     * à l'identique (CollectionManager, LookCompletionManager,
     * BehavioralCronManager ghost_cart le 01/08/2026) dans des prix affichés
     * à un CLIENT — mais un usage légitime existe aussi (MonthlyReportManager,
     * CA agrégé du rapport envoyé au MARCHAND, où la devise de la boutique
     * est justement la bonne référence).
     *
     * Volontairement non tranché automatiquement (impossible à distinguer de
     * façon fiable par un scan statique — contexte client vs contexte BO) :
     * liste chaque occurrence pour relecture manuelle plutôt qu'un verdict
     * "bug"/"pas bug". Le volume est faible (5 occurrences dans tout le
     * module au 01/08/2026), donc cette relecture reste rapide à chaque
     * exécution — c'est ce qui rend ce contrôle praticable malgré l'absence
     * de verdict automatique.
     */
    private function checkDefaultCurrencyUsage(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name . '/src';
        if (!is_dir($moduleDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.default_currency_usage_ok')];
        }
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        foreach ($files as $file) {
            // Ce fichier cite littéralement le motif recherché dans son
            // propre docblock (à titre d'exemple) — auto-match sinon.
            if (basename($file) === 'HealthCheckManager.php') {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            $relative = ltrim(str_replace(str_replace('\\', '/', _PS_MODULE_DIR_ . $this->module->name), '', str_replace('\\', '/', $file)), '/');

            if (preg_match_all('/Currency::getDefaultCurrency\s*\(\s*\)/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.default_currency_usage_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.default_currency_usage_ok')];
    }

    /**
     * Scan statique de upgrade/*.php : déclarations UNIQUE KEY dont la
     * liste de colonnes ne contient pas id_shop. Motif trouvé et corrigé
     * 2 fois à l'identique (neria_behavioral_sent en upgrade-1.0.29.php, puis
     * neria_queue en upgrade-1.0.36.php le 01/08/2026 — la contrainte
     * corrigée en 1.0.29 a été réintroduite sans id_shop dans un script
     * ultérieur) : sur une install multi-boutiques avec clients partagés,
     * une clé d'unicité sans id_shop bloque à tort l'INSERT IGNORE d'une
     * seconde boutique pour le même client/référence — un email jamais
     * envoyé, sans erreur ni log.
     *
     * Volontairement non tranché automatiquement : plusieurs clés
     * existantes n'ont légitimement pas besoin d'id_shop (ex. uq_order sur
     * id_order, déjà unique par nature puisqu'une commande n'appartient
     * qu'à une boutique) — liste chaque occurrence pour relecture manuelle,
     * comme checkDefaultCurrencyUsage() ci-dessus.
     */
    private function checkUpgradeUniqueKeyShopScope(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name . '/upgrade';
        if (!is_dir($moduleDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.upgrade_unique_key_shop_ok')];
        }

        $offenders = [];
        foreach (glob($moduleDir . '/upgrade-*.php') ?: [] as $file) {
            $content = file_get_contents($file) ?: '';
            $relative = 'upgrade/' . basename($file);

            if (preg_match_all('/UNIQUE\s+KEY\s+`?[a-z0-9_]*`?\s*\(([^)]*)\)/i', $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as $i => $colsMatch) {
                    if (stripos($colsMatch[0], 'id_shop') !== false) {
                        continue;
                    }
                    $line = substr_count(substr($content, 0, $m[0][$i][1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.upgrade_unique_key_shop_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.upgrade_unique_key_shop_ok')];
    }

    /**
     * Scan statique de views/templates/**\/*.tpl : $smarty.server.REQUEST_URI
     * utilisé sans |escape (ni |json_encode, sûr dans un contexte JS). Motif
     * trouvé le 01/08/2026 dans 18 fichiers / 100 occurrences d'un coup :
     * la grande majorité des formulaires/liens BO du module réinjectaient
     * l'URL courante (query string comprise) dans un attribut HTML sans
     * échappement, alors qu'une minorité de fichiers l'échappait déjà
     * correctement — incohérence, pas un oubli isolé. Risque XSS réfléchie
     * si un admin clique un lien forgé vers une page du module contenant un
     * payload dans la query string.
     */
    private function checkTplRequestUriEscape(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name . '/views/templates';
        if (!is_dir($moduleDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.tpl_request_uri_escape_ok')];
        }

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($moduleDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'tpl') {
                continue;
            }
            $file = $fileInfo->getPathname();
            $content = file_get_contents($file) ?: '';
            $relative = ltrim(str_replace(str_replace('\\', '/', _PS_MODULE_DIR_ . $this->module->name), '', str_replace('\\', '/', $file)), '/');

            if (preg_match_all('/\$smarty\.server\.REQUEST_URI[^}]*\}/', $content, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $match) {
                    if (stripos($match[0], '|escape') !== false || stripos($match[0], '|json_encode') !== false) {
                        continue;
                    }
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.tpl_request_uri_escape_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.tpl_request_uri_escape_ok')];
    }

    /**
     * Scan statique de src/*.php : requête SQL comparant une date de
     * déclenchement de cron à CURDATE()/DATE_ADD()/DATE_SUB() par ÉGALITÉ
     * STRICTE (`DATE(col) = DATE(...)`) plutôt qu'une plage. Si le cron ne
     * tourne pas exactement le jour visé (panne serveur, maintenance), la
     * fenêtre est ratée et ne se représente jamais — contrairement à une
     * comparaison `<=`/`BETWEEN`, qui permet un rattrapage au prochain
     * passage sans risque de doublon grâce à la déduplication déjà en place
     * (neria_behavioral_sent / flags sent_*) sur chacun de ces crons.
     *
     * Trouvé en réel le 02/08/2026 : 6 occurrences dans BehavioralCronManager
     * (first_anniversary, reorder_reminder, loyalty_reward_expiry,
     * post_purchase_care/review) et les relances de devis B2B — toutes
     * corrigées (commits af86c15 et suivant). Regex testée sur le vrai
     * codebase avant intégration : zéro faux positif (contrairement à une
     * précédente tentative de contrôle générique sur Mail::Send() dans une
     * boucle, abandonnée pour 207 faux positifs) — ce motif précis ne
     * matche que le vrai pattern de bug.
     */
    private function checkCronStrictDateEquality(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);
        $offenders = [];

        foreach ($files as $file) {
            $content = file_get_contents($file) ?: '';
            // (?&lt;!SUM\() : exclut SUM(DATE(col) = CURDATE()) — un motif
            // légitime de comptage d'affichage BO ("X envoyés aujourd'hui"),
            // recalculé à chaque chargement de page, pas une condition de
            // déclenchement de cron (faux positif réel trouvé le
            // 02/08/2026 dans neria.php, onglet Automatisations).
            // Le groupe interne ([^()]*(\([^()]*\))?[^()]*) tolère UN niveau
            // de parenthèses imbriquées dans l'argument de DATE(...) — ex.
            // DATE(MIN(o.date_add)) — sans quoi ce cas précis (bug réel
            // first_anniversary) échappait totalement à la détection.
            if (preg_match_all(
                '/(?<!SUM\()DATE\(([^()]*(\([^()]*\))?[^()]*)\)\s*=\s*(CURDATE\(\)|DATE\(DATE_ADD|DATE\(DATE_SUB)/',
                $content,
                $m,
                PREG_OFFSET_CAPTURE
            )) {
                $relative = str_replace(_PS_MODULE_DIR_ . $this->module->name . '/', '', str_replace('\\', '/', $file));
                foreach ($m[0] as $match) {
                    $pos = $match[1];
                    // Exclut aussi COUNT(*)/SUM( plus en amont dans la même
                    // requête (ex. "SELECT COUNT(*) FROM ... WHERE ... AND
                    // DATE(date_add) = CURDATE()") — un compteur "combien
                    // aujourd'hui" pour un dashboard/health check, pas une
                    // condition de déclenchement de cron (2e faux positif
                    // réel trouvé le 02/08/2026, cette fois dans
                    // HealthCheckManager lui-même — checkSendVolumeSpike).
                    $before = substr($content, max(0, $pos - 300), min(300, $pos));
                    if (stripos($before, 'COUNT(*)') !== false || stripos($before, 'SUM(') !== false) {
                        continue;
                    }
                    $line = substr_count(substr($content, 0, $pos), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.cron_strict_date_equality_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.cron_strict_date_equality_ok')];
    }

    /**
     * Scan statique de src/*CronManager.php et src/*Manager.php : méthodes
     * contenant une boucle foreach() qui appelle $this->send(...) (envoi
     * d'email comportemental) SANS aucun bloc try/catch dans le corps de la
     * méthode entière — une exception sur UN enregistrement (deadlock MySQL,
     * etc.) fait alors remonter l'exception hors de la méthode, empêchant
     * silencieusement le traitement des lignes suivantes du même lot.
     *
     * Trouvé en réel le 2026-08-01 dans BehavioralCronManager (relances
     * devis/remboursement/durée de vie produit) ; corrigé en ajoutant un
     * try/catch par itération. Volontairement prudent (moins précis que
     * checkHardcodedDecimalFormat) : signale seulement l'ABSENCE TOTALE de
     * "try {" dans toute la méthode contenant le foreach, pas la structure
     * exacte de la protection — un try/catch positionné ailleurs dans la
     * méthode (ex. autour d'un bloc englobant plus large) ne sera donc pas
     * signalé à tort, au prix de rater un try/catch mal placé (silence
     * préféré au bruit, cf. le canari dynamique abandonné pour 21 faux
     * positifs).
     */
    private function checkCronLoopMissingTryCatch(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name . '/src';
        if (!is_dir($moduleDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.cron_loop_try_catch_ok')];
        }
        $files = glob($moduleDir . '/*Manager.php') ?: [];

        $offenders = [];
        foreach ($files as $file) {
            $base = basename($file);
            if ($base === 'HealthCheckManager.php') {
                continue;
            }
            $content = file_get_contents($file) ?: '';
            $relative = 'src/' . $base;

            // Découpe approximative par méthode (private/protected/public function ... { ... })
            if (!preg_match_all('/(?:private|protected|public)\s+function\s+(\w+)\s*\([^)]*\)(?:\s*:\s*[\?\\\\\w]+)?\s*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $starts = $m[0];
            $count = count($starts);
            for ($i = 0; $i < $count; $i++) {
                $bodyStart = $starts[$i][1] + strlen($starts[$i][0]);
                // Appariement d'accolades pour trouver la fin réelle de la méthode
                $depth = 1;
                $bodyEnd = null;
                for ($p = $bodyStart, $len = strlen($content); $p < $len; $p++) {
                    if ($content[$p] === '{') {
                        $depth++;
                    } elseif ($content[$p] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $bodyEnd = $p;
                            break;
                        }
                    }
                }
                if ($bodyEnd === null) {
                    continue;
                }
                $body = substr($content, $bodyStart, $bodyEnd - $bodyStart);

                $hasLoopSend = preg_match('/foreach\s*\([^)]*\)[\s\S]{0,400}?\$this->send\s*\(/', $body) === 1;
                $hasTryCatch = strpos($body, 'try {') !== false || strpos($body, 'try{') !== false;

                if ($hasLoopSend && !$hasTryCatch) {
                    $line = substr_count(substr($content, 0, $starts[$i][1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line . ' (' . $m[1][$i][0] . ')';
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.cron_loop_try_catch_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.cron_loop_try_catch_ok')];
    }

    /**
     * Scan statique des .tpl : interpolation Smarty {$var} à l'intérieur
     * d'une chaîne JavaScript à guillemets simples (souvent pour construire
     * un id DOM, ex. document.getElementById('trad_field_{$key}')) sans le
     * modificateur |escape:'javascript'. Une valeur contenant un guillemet
     * simple casse alors la totalité du bloc <script> de la page BO.
     *
     * Trouvé en réel le 2026-07-31 : 12 occurrences dans translations.tpl
     * (variable {$key}, techniquement développeur-contrôlée aujourd'hui,
     * mais le motif est le vrai risque à surveiller pour tout futur ajout).
     * Volontairement scopé au motif getElementById/getElementsBy — pas une
     * vérification générale de tout {$var} en contexte JS, pour rester
     * permissif et ne pas noyer un vrai signal dans du bruit.
     */
    private function checkTplJsEscapeMissing(): array
    {
        $root = rtrim($this->module->getLocalPath(), '/');
        $tplDir = $root . '/views/templates/admin';
        if (!is_dir($tplDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.tpl_js_escape_ok')];
        }

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tplDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'tpl') {
                continue;
            }
            $path = $fileInfo->getPathname();
            $content = file_get_contents($path) ?: '';
            $relative = ltrim(str_replace(str_replace('\\', '/', $tplDir), '', str_replace('\\', '/', $path)), '/');

            if (preg_match_all(
                "/getElement(?:ById|sByClassName|sByName)\\(\\s*'[^']*\\{\\\$[a-zA-Z_][a-zA-Z0-9_]*\\}[^']*'/",
                $content,
                $m,
                PREG_OFFSET_CAPTURE
            )) {
                foreach ($m[0] as $match) {
                    if (strpos($match[0], "escape:'javascript'") !== false || strpos($match[0], 'escape:"javascript"') !== false) {
                        continue;
                    }
                    $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.tpl_js_escape_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.tpl_js_escape_ok')];
    }

    /**
     * Scan statique de src/*.php : appel à imap_open() sans imap_timeout()
     * posé avant dans le même fichier. imap_open() n'a aucun délai par
     * défaut — sans ce réglage préalable, un serveur IMAP lent/injoignable
     * bloque le handshake TCP/SSL indéfiniment (souvent 60-120s+ selon l'OS),
     * gelant tout le worker PHP-FPM, que ce soit un cron ou un bouton BO
     * synchrone (« Tester la connexion »).
     *
     * Trouvé en réel le 2026-07-31 dans BounceManager.php (les 2 seuls
     * appels imap_open() du module), corrigé en ajoutant imap_timeout()
     * avant chaque appel. Ce contrôle existe pour qu'une future intégration
     * IMAP/POP3 ne retombe pas dans le même piège.
     */
    private function checkImapTimeoutMissing(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        foreach ($files as $file) {
            $base = basename($file);
            // HealthCheckManager.php : le code/docblock de CE contrôle cite
            // littéralement 'imap_open(' et 'imap_timeout(' comme motifs
            // recherchés — auto-match sinon.
            if ($base === 'HealthCheckManager.php') {
                continue;
            }
            $rawContent = file_get_contents($file) ?: '';
            if (strpos($rawContent, 'imap_open(') === false) {
                continue;
            }
            // Neutralise les commentaires (le nom de la fonction y est parfois
            // cité en toutes lettres pour expliquer le correctif, comme dans
            // BounceManager::applyImapTimeouts() lui-même) en conservant les
            // retours à la ligne, pour que les numéros de ligne restent exacts.
            $content = preg_replace_callback('#/\*.*?\*/#s', function ($m) {
                return preg_replace('/[^\n]/', ' ', $m[0]);
            }, $rawContent);
            $content = preg_replace_callback('#//[^\n]*#', function ($m) {
                return str_repeat(' ', strlen($m[0]));
            }, $content);
            if (strpos($content, 'imap_open(') === false) {
                continue; // ne restait que des mentions en commentaire
            }
            $relative = ltrim(str_replace(str_replace('\\', '/', $moduleDir), '', str_replace('\\', '/', $file)), '/');

            if (strpos($content, 'imap_timeout(') === false) {
                // Aucun imap_timeout() nulle part dans le fichier — chaque
                // appel imap_open() qu'il contient est concerné.
                $searchFrom = 0;
                while (($pos = strpos($content, 'imap_open(', $searchFrom)) !== false) {
                    $searchFrom = $pos + 1;
                    $line = substr_count(substr($content, 0, $pos), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
                continue;
            }

            // imap_timeout() existe dans le fichier : vérifie qu'il apparaît
            // bien AVANT chaque imap_open() (un réglage global en fin de
            // fichier ou après l'appel ne protège pas cet appel-là).
            $firstTimeoutPos = strpos($content, 'imap_timeout(');
            $searchFrom = 0;
            while (($pos = strpos($content, 'imap_open(', $searchFrom)) !== false) {
                $searchFrom = $pos + 1;
                if ($pos < $firstTimeoutPos) {
                    $line = substr_count(substr($content, 0, $pos), "\n") + 1;
                    $offenders[] = $relative . ':' . $line;
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.imap_timeout_missing_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.imap_timeout_missing_ok')];
    }

    /**
     * Scan statique de src/*.php : une classe qui définit sa propre constante
     * CONFIG_LAST_ERROR (motif d'intégration OAuth du type Postmaster/Search
     * Console — canal d'erreur lu par checkOAuthFreshness()) doit aussi
     * écrire dans ce canal depuis sa méthode refreshAccessToken() en cas
     * d'échec, pas seulement journaliser dans le Watchdog.
     *
     * Trouvé en réel le 2026-07-31 : PostmasterManager/SearchConsoleManager
     * ne le faisaient pas — un rafraîchissement OAuth échoué (accès révoqué
     * par le marchand côté Google) restait invisible du statut BO lu par
     * checkOAuthFreshness(), qui continuait d'afficher "connecté" alors que
     * les données ne se rafraîchissaient plus silencieusement depuis des
     * jours. Corrigé sur les 2 fichiers concernés ; ce contrôle existe pour
     * qu'une future intégration OAuth du même moule ne retombe pas dans le
     * même piège.
     *
     * Volontairement scopé aux classes qui définissent CONFIG_LAST_ERROR
     * (le motif déjà établi dans ce module pour ce type d'intégration), pas
     * une vérification générale de tout code OAuth — pour rester permissif
     * et ne pas signaler à tort une intégration construite différemment.
     */
    private function checkOAuthRefreshErrorSurfaced(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = $this->collectModulePhpFiles($moduleDir);

        $offenders = [];
        foreach ($files as $file) {
            $content = file_get_contents($file) ?: '';
            if (strpos($content, 'CONFIG_LAST_ERROR') === false
                || !preg_match('/function\s+refreshAccessToken\s*\(/', $content, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $relative = ltrim(str_replace(str_replace('\\', '/', $moduleDir), '', str_replace('\\', '/', $file)), '/');

            // Isole le corps de refreshAccessToken() par appariement d'accolades
            $bracePos = strpos($content, '{', $m[0][1]);
            if ($bracePos === false) {
                continue;
            }
            $depth = 0;
            $bodyEnd = null;
            for ($i = $bracePos, $len = strlen($content); $i < $len; $i++) {
                if ($content[$i] === '{') {
                    $depth++;
                } elseif ($content[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $bodyEnd = $i;
                        break;
                    }
                }
            }
            if ($bodyEnd === null) {
                continue;
            }
            $body = substr($content, $bracePos, $bodyEnd - $bracePos + 1);

            if (strpos($body, 'CONFIG_LAST_ERROR') === false) {
                $line = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
                $offenders[] = $relative . ':' . $line;
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.oauth_refresh_error_warning', [
                    'n'    => count($offenders),
                    'list' => implode(', ', array_slice($offenders, 0, 15)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.oauth_refresh_error_ok')];
    }

    /**
     * Détecte les clés de data/admin_translations.json qui n'apparaissent
     * plus littéralement nulle part dans le code (.php) ni les templates
     * (.tpl) du module — reste d'une feature retirée sans nettoyage du
     * dictionnaire, comme les 6 clés help.regression_* trouvées et
     * retirées manuellement le 2026-07-23 avant ce contrôle.
     *
     * Volontairement permissif : une clé compte comme "utilisée" dès
     * qu'elle apparaît N'IMPORTE OÙ dans le code sous forme de chaîne
     * littérale (y compris dans un tableau de mapping Smarty comme
     * {assign var='_checks' value=[...]}), pas seulement dans un appel
     * direct AdminTranslator::t()/tVars() ou {neria_admin key=...} — ceci
     * pour ne jamais signaler à tort une clé réellement utilisée via une
     * clé dynamique/concaténée (ex: 'history.' . $alert['key'] dans
     * neria.php), au prix de laisser passer un très petit nombre de
     * vrais orphelins si leur nom ressemblait par coïncidence à un
     * fragment de code non lié — risque jugé acceptable face au risque
     * inverse (suppression d'une clé encore utilisée).
     */
    private function checkOrphanedAdminTranslationKeys(): array
    {
        $root = rtrim($this->module->getLocalPath(), '/');
        $jsonPath = $root . '/data/admin_translations.json';

        if (!is_file($jsonPath)) {
            return ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::t('health.orphaned_admin_trad_keys_unreadable')];
        }

        $dict = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($dict)) {
            return ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::t('health.orphaned_admin_trad_keys_unreadable')];
        }

        $files = array_merge(
            $this->collectModulePhpFiles($root),
            $this->globRecursive($root . '/views', '.tpl')
        );

        $haystack = '';
        foreach ($files as $file) {
            $haystack .= file_get_contents($file) ?: '';
            $haystack .= "\n";
        }

        $orphaned = [];
        foreach (array_keys($dict) as $key) {
            if (strpos($haystack, "'" . $key . "'") === false && strpos($haystack, '"' . $key . '"') === false) {
                $orphaned[] = $key;
            }
        }

        if ($orphaned) {
            $count = count($orphaned);
            $sample = implode(', ', array_slice($orphaned, 0, 8));
            if ($count > 8) {
                $sample .= '… (' . ($count - 8) . ' autres)';
            }
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.orphaned_admin_trad_keys_warning', [
                    'count' => $count,
                    'sample' => $sample,
                ]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.orphaned_admin_trad_keys_ok', ['count' => count($dict)]),
        ];
    }

    /**
     * Liste tous les .php du module, hors tests/ (scripts de test dédiés,
     * jamais exécutés en production) et upgrade/ n'est PAS exclu — un
     * pattern dangereux dans un script d'upgrade s'exécute bel et bien
     * chez le marchand.
     */
    private function collectModulePhpFiles(string $moduleDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($moduleDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'php') {
                continue;
            }
            $path = $fileInfo->getPathname();
            if (strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) !== false) {
                continue;
            }
            // .claude/ : dossier interne de l'outillage Claude Code (worktrees
            // d'agents, etc.), jamais livré (.gitignore) mais parfois présent
            // physiquement dans une copie locale — jamais du code du module.
            if (strpos($path, DIRECTORY_SEPARATOR . '.claude' . DIRECTORY_SEPARATOR) !== false) {
                continue;
            }
            // modules/neria/Neria/... : trouvé en réel le 2026-07-31, une copie
            // complète et périmée du dépôt imbriquée par erreur dans une
            // synchronisation précédente (604 Mo sur Laragon/F:) — jamais dans
            // le dépôt source. Aucun sous-dossier réel du module ne s'appelle
            // "Neria" à la racine (src/, docs/, views/, mails/, sql/...), donc
            // cette exclusion ne peut pas masquer du vrai code.
            if (strpos($path, DIRECTORY_SEPARATOR . 'Neria' . DIRECTORY_SEPARATOR) !== false) {
                continue;
            }
            $files[] = $path;
        }
        return $files;
    }

    /**
     * Contrôle statique : les 4 dictionnaires de traduction indépendants du
     * module (translations.json, admin_translations.json,
     * template_labels_i18n.json, academy/{lang}.json) doivent tous couvrir
     * l'intégralité de TranslationEngine::SUPPORTED_LANGS. Compare l'UNION
     * des langues présentes dans tout le fichier (pas clé par clé — d'autres
     * contrôles couvrent déjà les clés isolées manquantes) pour détecter
     * spécifiquement un bloc de langue entier oublié dans un dictionnaire —
     * exactement le bug du 2026-07-11 (chantier en-GB : translations.json
     * traité mais admin_translations.json resté sans aucun bloc "gb", BO
     * d'un marchand UK resté en anglais américain malgré des emails
     * corrects). Cf. feedback_check_all_translation_dictionaries.
     */
    private function checkTranslationDictionaryCoverage(): array
    {
        $expected = TranslationEngine::SUPPORTED_LANGS;
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $offenders = [];

        $collectUnionDepth2 = function (string $path) {
            $data = is_file($path) ? json_decode(file_get_contents($path) ?: '', true) : null;
            $langs = [];
            if (is_array($data)) {
                foreach ($data as $entry) {
                    if (is_array($entry)) {
                        foreach (array_keys($entry) as $k) {
                            $langs[$k] = true;
                        }
                    }
                }
            }
            return array_keys($langs);
        };

        $dictionaries = [
            'translations.json' => $collectUnionDepth2($moduleDir . '/data/translations.json'),
            'admin_translations.json' => (function () use ($moduleDir) {
                $data = json_decode(file_get_contents($moduleDir . '/data/admin_translations.json') ?: '', true);
                $langs = [];
                if (is_array($data)) {
                    foreach ($data as $entry) {
                        if (is_array($entry)) {
                            foreach (array_keys($entry) as $k) {
                                $langs[$k] = true;
                            }
                        }
                    }
                }
                return array_keys($langs);
            })(),
            'template_labels_i18n.json' => $collectUnionDepth2($moduleDir . '/data/template_labels_i18n.json'),
        ];

        foreach ($dictionaries as $file => $langsFound) {
            $missing = array_diff($expected, $langsFound);
            if ($missing) {
                $offenders[] = "{$file} : " . implode(', ', $missing);
            }
        }

        $academyMissing = [];
        foreach ($expected as $lang) {
            if (!is_file($moduleDir . "/data/academy/{$lang}.json")) {
                $academyMissing[] = $lang;
            }
        }
        if ($academyMissing) {
            $offenders[] = 'academy/ : ' . implode(', ', $academyMissing);
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.translation_dict_coverage_warning', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.translation_dict_coverage_ok')];
    }

    /**
     * Contrôle statique : les clés history_info / guest_tracking_info /
     * tracking_info de translations.json doivent contenir un lien cliquable
     * (<a href>), pas du texte brut — sinon le client ne peut pas suivre sa
     * commande depuis l'email. Cf. feedback_clickable_links (validé lors du
     * test du template cheque le 2026-06-07).
     */
    private function checkClickableTrackingLinks(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $path = $moduleDir . '/data/translations.json';
        $data = is_file($path) ? json_decode(file_get_contents($path) ?: '', true) : null;
        $offenders = [];

        $watchedKeys = ['history_info', 'guest_tracking_info', 'tracking_info'];

        if (is_array($data)) {
            foreach ($data as $template => $byLang) {
                if (!is_array($byLang)) {
                    continue;
                }
                foreach ($byLang as $lang => $keys) {
                    if (!is_array($keys)) {
                        continue;
                    }
                    foreach ($watchedKeys as $wk) {
                        if (isset($keys[$wk]) && is_string($keys[$wk]) && $keys[$wk] !== '' && stripos($keys[$wk], '<a href') === false) {
                            $offenders[] = "{$template}/{$lang}/{$wk}";
                        }
                    }
                }
            }
        }

        if ($offenders) {
            $sample = array_slice($offenders, 0, 8);
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.clickable_tracking_links_warning', [
                    'count' => count($offenders),
                    'sample' => implode(', ', $sample),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.clickable_tracking_links_ok')];
    }

    /**
     * Contrôle statique : aucune trace d'outil de dev (Mailpit, "Tester
     * maintenant"...) ne doit rester dans les templates/JS livrés — un
     * module premium vendu 199€ ne doit jamais exposer ces éléments à un
     * marchand. Cf. feedback_cleanup_dev_tools.
     */
    private function checkDevToolResidue(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $offenders = [];

        $files = array_merge(
            $this->globRecursive($moduleDir . '/views/templates', '.tpl'),
            $this->globRecursive($moduleDir . '/views/js', '.js')
        );

        foreach ($files as $file) {
            $src = file_get_contents($file) ?: '';
            if ($src === '') {
                continue;
            }
            if (stripos($src, 'mailpit') !== false) {
                $offenders[] = basename($file) . ' (mailpit)';
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.dev_tool_residue_warning', ['list' => implode(', ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.dev_tool_residue_ok')];
    }

    /**
     * Contrôle statique : détecte l'usage de neriaConfig.adminUrl /
     * neriaConfig.moduleName dans les templates BO — cette variable est
     * injectée par data-URI (HooksManager::onDisplayBackOfficeHeader()) et
     * souvent bloquée par le CSP du back-office, laissant un bouton "qui ne
     * fait rien" sans erreur visible (exception synchrone avant le fetch).
     * Cf. feedback_bo_ajax_neriaconfig_csp — pattern recommandé : construire
     * l'URL depuis window.location.href.
     */
    private function checkFragileNeriaConfigUsage(): array
    {
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $offenders = [];

        $files = $this->globRecursive($moduleDir . '/views/templates', '.tpl');

        foreach ($files as $file) {
            $src = file_get_contents($file) ?: '';
            if ($src === '') {
                continue;
            }
            if (preg_match('/neriaConfig\s*\.\s*(adminUrl|moduleName)/', $src)) {
                $offenders[] = basename($file);
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.fragile_neriaconfig_warning', ['list' => implode(', ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.fragile_neriaconfig_ok')];
    }

    /**
     * Contrôle statique : détecte toute clé BRUTE (sans accolades) écrite
     * dans $templateVars/$params['templateVars'] par EmailRenderer.php —
     * en dehors d'une liste blanche étroite de clés légitimement "pontées"
     * vers le système {$neria_xxx} par compileNeriaTemplate(). Une clé brute
     * non consommée atteint intacte le Swift_Plugins_DecoratorPlugin du
     * cœur PrestaShop dans Mail::send(), qui fait un str_replace() BRUT sur
     * tout le corps de l'email compilé — un mot générique comme "neria_lang"
     * corrompt alors toute occurrence coïncidente ailleurs dans l'email (le
     * bug réel : {unsubscribe_url} contenant "...&neria_lang=ar" devenait
     * "...&ar=ar", cf. injectDesignVars() supprimé le 2026-07-20).
     */
    private function checkBareTemplateVarKeys(): array
    {
        $offenders = [];
        $moduleDir = _PS_MODULE_DIR_ . $this->module->name;
        $files = glob($moduleDir . '/src/*.php') ?: [];
        $mainFile = $moduleDir . '/' . $this->module->name . '.php';
        if (is_file($mainFile)) {
            $files[] = $mainFile;
        }

        // Clés bien pontées vers {$neria_xxx} dans compileNeriaTemplate(), OU
        // consommées directement en brut par les blocs conditionnels
        // {if isset($x) && $x}...{/if} de mails/themes/neria_global/layout.html
        // (neria_has_social, neria_has_signature) — seules exceptions tolérées
        // à la règle "clé brute interdite".
        $allowlist = [
            'neria_tracking_pixel', 'neria_tracking_token', 'neria_social_links',
            'neria_signature_url', 'neria_signature_name', 'neria_signature_title',
            'neria_has_social', 'neria_has_signature',
        ];

        foreach ($files as $file) {
            $base = basename($file);
            $raw = file_get_contents($file) ?: '';
            if ($raw === '') {
                continue;
            }

            // Retire commentaires/docblocks avant analyse (même piège que
            // checkSqlPatternRisks : un exemple en commentaire se compte sinon
            // comme un vrai offender).
            $codeOnly = '';
            foreach (token_get_all($raw) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $codeOnly .= is_array($token) ? $token[1] : $token;
            }

            // Écritures directes : $templateVars['xxx'] = ... / $params['templateVars']['xxx'] = ...
            if (preg_match_all(
                '/\$(?:templateVars|params\[[\'"]templateVars[\'"]\])\[[\'"]([a-z][a-z0-9_]*)[\'"]\]\s*=(?!=)/',
                $codeOnly,
                $m
            )) {
                foreach (array_unique($m[1]) as $key) {
                    if (!in_array($key, $allowlist, true)) {
                        $offenders[] = "{$base} : \$templateVars['{$key}'] (écriture directe)";
                    }
                }
            }

            // Fusion : $templateVars = array_merge($templateVars, [ 'xxx' => ..., ... ])
            if (preg_match_all('/array_merge\s*\(\s*\$templateVars\s*,\s*\[(.*?)\]\s*\)/s', $codeOnly, $blocks)) {
                foreach ($blocks[1] as $block) {
                    if (preg_match_all('/[\'"]([a-z][a-z0-9_]*)[\'"]\s*=>/', $block, $bm)) {
                        foreach (array_unique($bm[1]) as $key) {
                            if (!in_array($key, $allowlist, true)) {
                                $offenders[] = "{$base} : '{$key}' (array_merge dans \$templateVars)";
                            }
                        }
                    }
                }
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.bare_template_var_keys_warning', ['list' => implode(' | ', $offenders)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.bare_template_var_keys_ok')];
    }

    /**
     * Contrôle statique dédié aux templates .txt (le bug {products_txt} du
     * 2026-07-14 n'a été trouvé que parce qu'un rapport de test externe a
     * ouvert la version texte d'un email — aucun contrôle n'inspectait ce
     * fichier avant). Pour chaque placeholder {xxx} présent dans un .txt,
     * vérifie qu'il est bien "connu" : soit couvert par les données factices
     * de l'aperçu (EmailRenderer::buildPreviewFakes(), le référentiel le plus
     * complet des variables gérées), soit assigné littéralement quelque part
     * dans le code source (variables injectées dynamiquement par la logique
     * métier — upsell, fidélité, bon de réduction... — qui n'apparaissent pas
     * dans l'aperçu générique mais existent bien dans le code réel).
     * Ne scanne QUE les .txt (le HTML est déjà couvert par render_canary +
     * residual_vars_recent sur les vrais envois).
     */
    private function checkTxtPlaceholderCoverage(): array
    {
        if (!class_exists('EmailRenderer')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.txt_coverage_ok')];
        }

        $coreDir = _PS_MODULE_DIR_ . $this->module->name . '/mails/themes/neria_global/core';
        $txtFiles = glob($coreDir . '/*.txt') ?: [];
        if (empty($txtFiles)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.txt_coverage_ok')];
        }

        // Référentiel 1 : toutes les variables connues de l'aperçu.
        $renderer = new \EmailRenderer($this->module);
        $refClass = new \ReflectionClass($renderer);
        $fakesMethod = $refClass->getMethod('buildPreviewFakes');
        $fakesMethod->setAccessible(true);
        $knownVars = [];
        try {
            $knownVars = array_keys($fakesMethod->invoke($renderer, '', 'fr'));
        } catch (\Throwable $e) {
            // Non bloquant — se rabat sur le seul référentiel 2 ci-dessous.
        }

        // Référentiel 2 : toute variable '{xxx}' assignée littéralement dans
        // le code source (couvre les variables injectées dynamiquement par la
        // logique métier, absentes de l'aperçu générique par nature).
        $sourceVars = [];
        foreach (glob(_PS_MODULE_DIR_ . $this->module->name . '/src/*.php') ?: [] as $srcFile) {
            $content = file_get_contents($srcFile) ?: '';
            if (preg_match_all('/\'(\{[a-z][a-z0-9_]*\})\'/i', $content, $m)) {
                $sourceVars = array_merge($sourceVars, $m[1]);
            }
        }
        $sourceVars = array_unique($sourceVars);

        // Mots-clés de contrôle Smarty pouvant apparaître seuls sous forme
        // "{mot}" (ex. {else} dans un bloc {if}...{else}...{/if}) — ne sont
        // pas des variables de contenu, à exclure du scan.
        static $smartyKeywords = ['else', 'if', 'foreach', 'capture', 'block', 'literal'];

        // Templates qui remplacent un email NATIF PrestaShop — cœur (cf.
        // mails/en/*.txt) OU module natif bundlé (ps_emailalerts : new_order,
        // return_slip, productcoverage... — confirmé le 2026-07-14 via les
        // traductions BO PrestaShop 9, "E-mails des modules"). Leurs variables
        // (dont les variantes _txt) sont fournies par ce code natif tiers,
        // jamais par Neria — les scanner ici produirait un faux positif
        // systématique, par nature (les variables n'existent délibérément
        // pas côté Neria).
        static $nativeOverrides = [
            'account', 'backoffice_order', 'bankwire', 'contact', 'contact_form',
            'credit_slip', 'download_product', 'employee_password', 'forward_msg',
            'guest_to_customer', 'import', 'in_transit', 'log_alert', 'newsletter',
            'order_canceled', 'order_changed', 'order_conf', 'order_customer_comment',
            'order_return_state', 'outofstock', 'password', 'password_query',
            // ps_emailalerts (module natif) :
            'new_order', 'return_slip', 'productcoverage', 'customer_qty',
            'payment', 'payment_error', 'preparation', 'productoutofstock', 'refund',
            'reply_msg', 'shipped', 'cheque', 'voucher', 'voucher_new',
        ];

        $offenders = [];
        foreach ($txtFiles as $txtFile) {
            $template = basename($txtFile, '.txt');
            if (in_array($template, $nativeOverrides, true)) {
                continue;
            }
            $content  = file_get_contents($txtFile) ?: '';
            if (!preg_match_all('/\{[a-z][a-z0-9_]*\}/i', $content, $m)) {
                continue;
            }
            foreach (array_unique($m[0]) as $placeholder) {
                $bare = strtolower(trim($placeholder, '{}'));
                if (in_array($bare, $smartyKeywords, true)) {
                    continue;
                }
                if (!in_array($placeholder, $knownVars, true) && !in_array($placeholder, $sourceVars, true)) {
                    $offenders[] = $template . ' : ' . $placeholder;
                }
            }
        }

        if ($offenders) {
            $count  = count($offenders);
            $sample = implode(', ', array_slice($offenders, 0, 6));
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.txt_coverage_warning', ['count' => $count, 'sample' => $sample]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.txt_coverage_ok')];
    }

    /**
     * Réservations de bons de réduction orphelines — BehavioralCronManager::
     * generateBirthdayVoucher() / OrderTriggersManager::generateMilestoneVoucher()
     * réservent une ligne (id_cart_rule=0) avant de créer le vrai CartRule, pour
     * l'anti-doublon. Si CartRule::add() échoue, le code libère la réservation ;
     * mais un crash PHP entre les deux (rare mais possible) laisserait la ligne
     * bloquée à id_cart_rule=0 pour toujours — ce client ne recevrait plus
     * jamais son bon pour ce palier/cette année. Auto-réparation : au-delà de
     * 24h, une réservation à id_cart_rule=0 est forcément un échec, jamais un
     * envoi en cours (le cycle complet dure quelques secondes) — supprimable
     * sans risque pour débloquer une nouvelle tentative au prochain cron.
     */
    private function checkOrphanedVoucherReservations(): array
    {
        $db     = \Db::getInstance();
        $tables = ['neria_birthday_voucher', 'neria_milestone_voucher'];
        $fixed  = 0;

        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $count = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . $table . '`
                 WHERE `id_cart_rule` = 0 AND `created_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            );
            if ($count > 0) {
                $db->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . $table . '`
                     WHERE `id_cart_rule` = 0 AND `created_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)'
                );
                $fixed += $count;
            }
        }

        // neria_loyalty_rewards suit le même pattern réserve/CartRule/update, mais
        // sa colonne de date s'appelle `sent_at` (et non `created_at`) — traitée
        // séparément pour cette raison, avec la même logique de nettoyage 24h.
        if ($this->tableExists('neria_loyalty_rewards')) {
            $count = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_loyalty_rewards`
                 WHERE `id_cart_rule` = 0 AND `sent_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            );
            if ($count > 0) {
                $db->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'neria_loyalty_rewards`
                     WHERE `id_cart_rule` = 0 AND `sent_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR)'
                );
                $fixed += $count;
            }
        }

        if ($fixed > 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.orphaned_vouchers_fixed', ['count' => $fixed]),
                'auto_fixed' => true,
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.orphaned_vouchers_ok')];
    }

    /**
     * neria_waitlist::notifyProduct() pose claim_started_at avant l'envoi et
     * ne pose notified_at qu'après confirmation réelle — distinction ajoutée
     * en 1.0.26 précisément pour permettre ce nettoyage sans ambiguïté. Un
     * crash entre les deux laisse claim_started_at posé sans notified_at ;
     * au-delà d'1h (l'envoi d'un seul email prend quelques secondes), c'est
     * forcément un échec, jamais un envoi encore en cours. On libère le
     * claim (claim_started_at = NULL) pour permettre un nouvel essai au
     * prochain retour en stock — jamais de suppression ni de manipulation
     * de notified_at ici, donc aucun risque de redéclencher un envoi déjà
     * réussi.
     */
    private function checkOrphanedWaitlistClaims(): array
    {
        if (!$this->tableExists('neria_waitlist')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.orphaned_waitlist_claims_ok')];
        }

        $db    = \Db::getInstance();
        $count = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_waitlist`
             WHERE `notified_at` IS NULL
               AND `claim_started_at` IS NOT NULL
               AND `claim_started_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );

        if ($count > 0) {
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . 'neria_waitlist`
                 SET `claim_started_at` = NULL
                 WHERE `notified_at` IS NULL
                   AND `claim_started_at` IS NOT NULL
                   AND `claim_started_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.orphaned_waitlist_claims_fixed', ['count' => $count]),
                'auto_fixed' => true,
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.orphaned_waitlist_claims_ok')];
    }

    private function tableExists(string $table): bool
    {
        $row = \Db::getInstance()->executeS(
            "SHOW TABLES LIKE '" . pSQL(_DB_PREFIX_ . $table) . "'"
        );
        return !empty($row);
    }

    /**
     * Clé de chiffrement AES-256-GCM (CryptoManager) — si NERIA_ENCRYPTION_KEY
     * disparaissait ou se corrompait (mauvaise migration, erreur de config),
     * TOUTES les données chiffrées en base deviendraient illisibles d'un coup
     * (variables de rendu snapshot, secrets webhook...). Test réel d'aller-
     * retour chiffrement/déchiffrement, pas seulement une vérification de
     * présence — une clé présente mais corrompue casserait aussi le décryptage.
     */
    private function checkCryptoKeyHealth(): array
    {
        if (!class_exists('CryptoManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.crypto_key_ok')];
        }

        $key = (string) \Configuration::get(\CryptoManager::CONFIG_KEY);
        if ($key === '') {
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::t('health.crypto_key_missing')];
        }

        try {
            $probe     = 'neria-health-check-' . bin2hex(random_bytes(8));
            $encrypted = \CryptoManager::encrypt($probe);
            $decrypted = \CryptoManager::decrypt($encrypted);
        } catch (\Throwable $e) {
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::tVars('health.crypto_key_error', ['error' => $e->getMessage()])];
        }

        if ($decrypted !== $probe) {
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::t('health.crypto_key_broken')];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.crypto_key_ok')];
    }

    /**
     * Chaque template email doit avoir SES DEUX fichiers (.html ET .txt) —
     * une désynchronisation silencieuse est possible si un template est ajouté
     * ou modifié à moitié (ex. un .txt supprimé par erreur, ou jamais créé
     * pour un nouveau template). Sans le .txt, le client dont le lecteur mail
     * n'affiche que le texte brut recevrait un email vide.
     */
    private function checkHtmlTxtPairs(): array
    {
        $coreDir = _PS_MODULE_DIR_ . $this->module->name . '/mails/themes/neria_global/core';
        $htmlFiles = array_map(fn($f) => basename($f, '.html'), glob($coreDir . '/*.html') ?: []);
        $txtFiles  = array_map(fn($f) => basename($f, '.txt'), glob($coreDir . '/*.txt') ?: []);

        $missingTxt  = array_diff($htmlFiles, $txtFiles);
        $missingHtml = array_diff($txtFiles, $htmlFiles);
        $offenders   = array_merge(
            array_map(fn($t) => $t . ' (.txt manquant)', $missingTxt),
            array_map(fn($t) => $t . ' (.html manquant)', $missingHtml)
        );

        if ($offenders) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.html_txt_pairs_warning', [
                    'count' => count($offenders),
                    'list'  => implode(', ', $offenders),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.html_txt_pairs_ok', ['count' => count($htmlFiles)])];
    }

    /**
     * Résidus de placeholders {xxx} percent-encodés (%7Bxxx%7D) dans les liens
     * — si un placeholder non résolu se retrouve dans un attribut href/src au
     * moment du passage par CssInliner (DOMDocument), il est encodé et devient
     * invisible au filet de sécurité qui ne cherche que des accolades brutes.
     * Garde-fou de régression : rejoue la compilation réelle (même chemin
     * qu'un vrai envoi) pour chaque template avec des données factices
     * réalistes, et vérifie qu'aucun %7B/%7b ne subsiste dans le résultat.
     */
    private function checkEncodedResidualLinks(): array
    {
        if (!class_exists('EmailRenderer') || !class_exists('NeriaTools')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.encoded_links_ok')];
        }

        $renderer  = new \EmailRenderer($this->module);
        $refClass  = new \ReflectionClass($renderer);
        $fakes     = $refClass->getMethod('buildPreviewFakes');
        $fakes->setAccessible(true);
        $compile   = $refClass->getMethod('compileNeriaTemplate');
        $compile->setAccessible(true);

        $templates = array_keys(\NeriaTools::getTemplateLabels());
        $offenders = [];

        foreach ($templates as $tpl) {
            try {
                $demoVars = $fakes->invoke($renderer, $tpl, 'fr');
                // $suppressResidualLog = true : ce test utilise des données de démo
                // génériques (buildPreviewFakes), pas un vrai envoi client — sans ce
                // flag, chaque exécution du diagnostic pollue le journal Watchdog
                // réel avec de fausses alertes "variable manquante" sur presque
                // tous les templates (variables métier dynamiques absentes des
                // fakes par construction). Les vrais envois (ManualSendManager,
                // crons, hooks PS) n'utilisent jamais ce flag et continuent de
                // logger normalement.
                $outFile  = $compile->invoke($renderer, $tpl, 'fr', 'fr', $demoVars, true);
                if ($outFile && is_file($outFile)) {
                    $content = file_get_contents($outFile) ?: '';
                    if (stripos($content, '%7b') !== false) {
                        $offenders[] = $tpl;
                    }
                }
            } catch (\Throwable $e) {
                // Non bloquant — un échec de compilation isolé est déjà
                // couvert par render_canary, pas la responsabilité de ce
                // contrôle.
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.encoded_links_error', [
                    'count' => count($offenders),
                    'list'  => implode(', ', array_slice($offenders, 0, 8)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.encoded_links_ok')];
    }

    /**
     * Round 150 : fuite de balises HTML brutes dans la version .txt — le
     * bug corrigé le 09/08/2026 (history_info/guest_tracking_info/
     * tracking_info contiennent un lien HTML complet <a href="...">...</a>
     * pensé pour le rendu HTML, jamais nettoyé côté pipeline texte). Le
     * garde-fou statique de checkKnownRegressionsGuard() ne vérifie que le
     * CODE de ce correctif précis — il ne détecterait pas la MÊME classe de
     * bug réapparaissant via une future clé de traduction contenant du
     * HTML, sur un template qui n'existe pas encore.
     *
     * Contrôle PROSPECTIF (même mécanisme que checkEncodedResidualLinks()
     * juste au-dessus — rejoue la compilation réelle de CHAQUE template
     * avec des données factices) : vérifie qu'aucun fichier .txt produit ne
     * contient de balise HTML structurelle (<a, <img, <p, <br, <div, <span,
     * <strong, <table...), quelle qu'en soit l'origine (traduction, variable
     * de texte libre, futur bug d'échappement).
     */
    private function checkTxtRawHtmlLeak(): array
    {
        if (!class_exists('EmailRenderer') || !class_exists('NeriaTools')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.txt_raw_html_ok')];
        }

        $renderer  = new \EmailRenderer($this->module);
        $refClass  = new \ReflectionClass($renderer);
        $fakes     = $refClass->getMethod('buildPreviewFakes');
        $fakes->setAccessible(true);
        $compile   = $refClass->getMethod('compileNeriaTemplate');
        $compile->setAccessible(true);

        $templates = array_keys(\NeriaTools::getTemplateLabels());
        $offenders = [];

        foreach ($templates as $tpl) {
            try {
                $demoVars = $fakes->invoke($renderer, $tpl, 'fr');
                $outFile  = $compile->invoke($renderer, $tpl, 'fr', 'fr', $demoVars, true);
                if (!$outFile || !is_file($outFile)) {
                    continue;
                }
                $txtFile = preg_replace('/\.html$/', '.txt', $outFile);
                if (!is_file($txtFile)) {
                    continue;
                }
                $content = file_get_contents($txtFile) ?: '';
                if (preg_match('/<(a|img|p|br|div|span|strong|table|em|b|ul|li)\b[^>]*>/i', $content)) {
                    $offenders[] = $tpl;
                }
            } catch (\Throwable $e) {
                // Non bloquant — un échec de compilation isolé est déjà
                // couvert par render_canary, pas la responsabilité de ce
                // contrôle.
            }
        }

        if ($offenders) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.txt_raw_html_error', [
                    'count' => count($offenders),
                    'list'  => implode(', ', array_slice($offenders, 0, 8)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.txt_raw_html_ok')];
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

        // Clés volontairement vides pour certaines langues (pas un oubli) —
        // ex. gdpr.local_law_note n'a de contenu que pour les langues où le
        // libellé de l'onglet RGPD a été localisé (ja/ko/zh/tw/ru/tr/ar/en),
        // cf. gdpr.tpl. Sans cette exclusion, ce contrôle générique les
        // signale à tort comme des trous de traduction.
        static $intentionallyEmptyKeys = ['gdpr.local_law_note'];

        $gaps = [];
        foreach ($langs as $lang) {
            $missing = 0;
            foreach ($dict as $key => $translations) {
                if (in_array($key, $intentionallyEmptyKeys, true)) {
                    continue;
                }
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
     * #8 — Compatibilité API List-Unsubscribe (Swift_Message OU Symfony Mime)
     *
     * PS8 (legacy) passe un Swift_Message à actionMailAlterMessageBeforeSend ;
     * PS9 passe un Symfony\Component\Mime\Email. Les deux exposent bien
     * getHeaders()/getTo(), MAIS avec une forme de retour différente pour
     * getTo() : Swift_Message retourne un tableau associatif [email => nom],
     * Symfony\Mime\Email retourne un tableau numérique d'objets Address —
     * une différence de FORME, pas de présence de méthode, qui ne se
     * détecte pas par un simple class_exists()/hasMethod(). Ce check ne
     * vérifiait auparavant que la classe Swift_Message, donnant un WARNING
     * systématique et non informatif sur PS9 (où elle n'existe jamais)
     * même quand hookActionMailAlterMessageBeforeSend() gère correctement
     * les deux formes (corrigé le 2026-07-18, vérifié en réel sur PS9).
     */
    private function checkListUnsubscribeApi(): array
    {
        $messageClass = class_exists('Symfony\\Component\\Mime\\Email')
            ? 'Symfony\\Component\\Mime\\Email'
            : (class_exists('Swift_Message') ? 'Swift_Message' : null);

        if ($messageClass === null) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::t('health.swift_missing'),
            ];
        }

        try {
            $ref     = new \ReflectionClass($messageClass);
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

        // monthly_report a son propre rendu HTML autonome, totalement
        // indépendant de layout.html/core/*.html (cf. MonthlyReportManager::
        // renderHtml + EmailRenderer::renderPreviewHtml) — contrairement à
        // log_alert/neria_fallback (autres templates "internes") qui, eux,
        // fusionnent bien leur core/*.html via compileNeriaTemplate.
        $noCoreFileNeeded = ['monthly_report'];

        foreach (array_keys($templates) as $tpl) {
            if (in_array($tpl, $noCoreFileNeeded, true)) {
                continue;
            }
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
     * #65 — Palier de fidélisation milestone_order : deux angles.
     * 1. Statique : OrderTriggersManager::MILESTONE_ORDINALS doit couvrir
     *    CHAQUE combinaison (palier × langue) — sinon {milestone_count}
     *    retombe silencieusement sur le nombre brut (repli volontaire côté
     *    code pour ne jamais envoyer de variable vide, mais qui doit rester
     *    visible ici plutôt que passer inaperçu si MILESTONES est étendu un
     *    jour sans mettre à jour la table).
     * 2. Récent : erreurs/avertissements réels des 7 derniers jours sur ce
     *    déclencheur précis (échec d'envoi, exception pendant le calcul).
     */
    private function checkMilestoneOrderHealth(): array
    {
        if (!class_exists('OrderTriggersManager') || !class_exists('TranslationEngine')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.milestone_order_ok')];
        }

        $milestones = \OrderTriggersManager::MILESTONES;
        $ordinals   = \OrderTriggersManager::MILESTONE_ORDINALS;
        $langs      = \TranslationEngine::SUPPORTED_LANGS;

        $missingCombos = [];
        foreach ($langs as $lang) {
            foreach ($milestones as $milestone) {
                if (!isset($ordinals[$lang][$milestone])) {
                    $missingCombos[] = $lang . ':' . $milestone;
                }
            }
        }

        if (!empty($missingCombos)) {
            $count  = count($missingCombos);
            $sample = implode(', ', array_slice($missingCombos, 0, 8));
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.milestone_order_ordinals_missing', ['count' => $count, 'sample' => $sample]),
            ];
        }

        $recentIssues = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_log`
             WHERE `id_shop` = {$this->idShop}
               AND `template` = 'milestone_order' AND `class` = 'OrderTriggers'
               AND `level` IN ('error', 'warning')
               AND `date_add` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        if ($recentIssues > 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.milestone_order_recent_issues', ['count' => $recentIssues]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::t('health.milestone_order_ok'),
        ];
    }

    /**
     * #67 — Variables personnalisées (marchand) utilisées par au moins un
     * template mais jamais renseignées en BO — {maison_name}, {founder_name},
     * {return_deadline_days}, etc. Un template qui référence une de ces
     * variables sans qu'elle soit remplie affiche un texte tronqué/vide
     * (ex. "Sous  jours" sans le nombre) — cf. l'audit du 2026-07-12 qui a
     * confirmé injectCustomVars() correctement câblée, mais aucun contrôle
     * ne vérifiait jusqu'ici que le marchand ait bien REMPLI ces champs.
     *
     * Ne signale que les variables réellement UTILISÉES par au moins un
     * template (translations.json) — jamais celles inutilisées, pour ne
     * pas réclamer une donnée dont aucun email n'a besoin.
     */
    private function checkCustomVarsCompleteness(): array
    {
        if (!class_exists('ConfigManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.custom_vars_ok')];
        }

        $jsonPath = rtrim($this->module->getLocalPath(), '/') . '/data/translations.json';
        if (!is_file($jsonPath)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.custom_vars_ok')];
        }

        $dict = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($dict)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.custom_vars_ok')];
        }

        // Variables réellement référencées par au moins un template, toutes
        // langues confondues (une valeur suffit à prouver l'usage).
        $usedKeys = [];
        foreach ($dict as $block) {
            if (!is_array($block)) {
                continue;
            }
            foreach ($block as $vals) {
                if (!is_array($vals)) {
                    continue;
                }
                foreach ($vals as $val) {
                    if (!is_string($val)) {
                        continue;
                    }
                    foreach (\ConfigManager::CUSTOM_VARIABLE_KEYS as $key) {
                        if (isset($usedKeys[$key])) {
                            continue;
                        }
                        if (strpos($val, '{' . $key . '}') !== false
                            || strpos($val, '{' . $key . '_html}') !== false
                            || strpos($val, '{' . $key . '_txt}') !== false
                        ) {
                            $usedKeys[$key] = true;
                        }
                    }
                }
            }
        }

        if (empty($usedKeys)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.custom_vars_ok')];
        }

        // Valeurs actuellement renseignées par le marchand
        $filled = [];
        $rows = $this->db->executeS(
            'SELECT `variable_key`, `variable_value` FROM `' . _DB_PREFIX_ . 'neria_custom_variable`
             WHERE `id_shop` = ' . $this->idShop
        );
        foreach ((array) $rows as $row) {
            if (trim((string) $row['variable_value']) !== '') {
                $filled[$row['variable_key']] = true;
            }
        }

        $missing = [];
        foreach (array_keys($usedKeys) as $key) {
            if (!isset($filled[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.custom_vars_missing', [
                    'count' => count($missing),
                    'list'  => implode(', ', $missing),
                ]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.custom_vars_ok_count', ['count' => count($usedKeys)]),
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

        // Import partiel (panne réseau/timeout à mi-parcours) : des lignes
        // entières peuvent manquer sans être "vides" — le contrôle ci-dessus
        // ne le détecte pas puisqu'il ne regarde que les lignes existantes.
        // On compare donc le nombre total de triplets (template, langue, clé)
        // déclarés dans le JSON au nombre réel de lignes non personnalisées en base.
        $expectedTotal = 0;
        foreach ($trad as $block) {
            if (!is_array($block)) {
                continue;
            }
            foreach ($block as $lang => $keys) {
                if (is_array($keys)) {
                    $expectedTotal += count($keys);
                }
            }
        }

        $actualTotal = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_translation` WHERE `is_custom` = 0"
        );

        $importGap = $expectedTotal - $actualTotal;
        // Tolérance : quelques lignes d'écart peuvent venir de clés is_custom=1
        // (remplacées par le marchand) — seul un vrai trou (>1%) indique un
        // import réellement interrompu.
        $importIncomplete = $expectedTotal > 0 && $importGap > max(20, (int) ($expectedTotal * 0.01));

        if (empty($missing) && $dbMissing === 0 && !$importIncomplete) {
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
        if ($importIncomplete) {
            $detail .= AdminTranslator::tVars('health.trad_keys_import_incomplete', [
                'expected' => $expectedTotal, 'actual' => $actualTotal, 'gap' => $importGap,
            ]);
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
            $allMissing = array_merge($missingCore, $missingSecondary);
            $registered = [];
            foreach ($allMissing as $hookName) {
                if ($this->module->registerHook($hookName)) {
                    $registered[] = $hookName;
                }
            }
            $stillMissing = array_diff($allMissing, $registered);

            if (empty($stillMissing)) {
                return [
                    'status'     => self::STATUS_WARNING,
                    'detail'     => AdminTranslator::tVars('health.hooks_fixed', [
                        'count' => count($registered),
                        'hooks' => implode(', ', $registered),
                    ]),
                    'auto_fixed' => true,
                ];
            }

            $stillMissingCore      = array_intersect($stillMissing, $missingCore);
            $stillMissingSecondary = array_intersect($stillMissing, $missingSecondary);
            $parts = [];
            if ($stillMissingCore) {
                $parts[] = AdminTranslator::tVars('health.hooks_core_missing', [
                    'count' => count($stillMissingCore),
                    'hooks' => implode(', ', $stillMissingCore),
                ]);
            }
            if ($stillMissingSecondary) {
                $parts[] = AdminTranslator::tVars('health.hooks_secondary_missing', [
                    'count' => count($stillMissingSecondary),
                    'hooks' => implode(', ', $stillMissingSecondary),
                ]);
            }

            return [
                'status'  => $stillMissingCore ? self::STATUS_ERROR : self::STATUS_WARNING,
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
            '1.0.21' => ['type' => 'translation_template', 'name' => 'certificate_email'],
            '1.0.22' => ['type' => 'translation_lang', 'name' => 'gb'],
            '1.0.23' => ['type' => 'table',  'name' => 'neria_birthday_voucher'],
            '1.0.24' => ['type' => 'table',  'name' => 'neria_milestone_voucher'],
            '1.0.25' => ['type' => 'index_column', 'table' => 'neria_preferences', 'index' => 'uq_shop_customer_email_cat', 'name' => 'email'],
            '1.0.26' => ['type' => 'column', 'table' => 'neria_waitlist', 'name' => 'claim_started_at'],
            '1.0.27' => ['type' => 'index_column', 'table' => 'neria_stat', 'index' => 'idx_shop_template_event', 'name' => 'date_add'],
            // uq_customer_product_shop (créé en 1.0.28) a été remplacé par
            // uq_customer_product_attr_shop en 1.0.40 (ajout de la variante
            // à la clé unique) — on vérifie donc l'index final, pas celui
            // d'origine qui n'existe plus sur une install à jour.
            '1.0.28' => ['type' => 'index',  'table' => 'neria_waitlist', 'name' => 'uq_customer_product_attr_shop'],
            '1.0.29' => ['type' => 'column', 'table' => 'neria_loyalty_points', 'name' => 'id_shop'],
            '1.0.30' => ['type' => 'config', 'name' => 'NERIA_CERT_ENABLED'],
            '1.0.31' => ['type' => 'config_exists', 'name' => 'NERIA_LICENSE_KEY'],
            '1.0.32' => ['type' => 'config', 'name' => 'NERIA_BIRTHDAY_ENABLED'],
            '1.0.33' => ['type' => 'index',  'table' => 'neria_stat', 'name' => 'idx_shop_event_date'],
            '1.0.34' => ['type' => 'index',  'table' => 'neria_stat', 'name' => 'idx_shop_date'],
            '1.0.35' => ['type' => 'column', 'table' => 'neria_upsell', 'name' => 'id_shop'],
            '1.0.36' => ['type' => 'index',  'table' => 'neria_queue', 'name' => 'uq_customer_template_ref_shop'],
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

            case 'translation_template':
                return (bool) $this->db->getValue(
                    "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_translation`
                     WHERE `template` = '" . pSQL($rule['name']) . "'"
                );

            case 'translation_lang':
                return (bool) $this->db->getValue(
                    "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_translation`
                     WHERE `lang` = '" . pSQL($rule['name']) . "'"
                );

            case 'index':
                return (bool) $this->db->getValue(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . _DB_PREFIX_ . $rule['table'] . "'
                       AND INDEX_NAME = '" . pSQL($rule['name']) . "'"
                );

            case 'index_column':
                // Vérifie qu'une colonne fait bien partie d'un index nommé —
                // distinct de 'index' (existence seule) : un index peut exister
                // sous le même nom avant/après un upgrade qui ne fait qu'y
                // AJOUTER une colonne (ex: 1.0.27), auquel cas seule cette
                // vérification plus précise prouve que l'upgrade a eu son effet.
                return (bool) $this->db->getValue(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . _DB_PREFIX_ . $rule['table'] . "'
                       AND INDEX_NAME = '" . pSQL($rule['index']) . "'
                       AND COLUMN_NAME = '" . pSQL($rule['name']) . "'"
                );

            case 'config_exists':
                // Distinct de 'config' (qui exige une valeur non vide) : certaines
                // clés semées par un upgrade ont légitimement '' comme valeur par
                // défaut (ex: NERIA_LICENSE_KEY tant que le marchand n'a pas encore
                // activé sa licence) — seule la présence de la ligne en base prouve
                // que l'upgrade a tourné, pas son contenu.
                return \Configuration::get($rule['name']) !== false;

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
     * Contrôle proactif — Templates soudainement silencieux
     * checkCronsHealth() surveille l'exécution des CRONS eux-mêmes, mais un
     * cron qui tourne toujours peut envoyer 0 email pour UN template précis
     * si son hook métier est cassé (ex. le hook fantôme trouvé le 2026-06-16
     * sur l'historique email client) — le cron "réussit" silencieusement
     * sans rien envoyer. Compare, par template, le volume envoyé sur les 30
     * derniers jours à celui des 60 jours précédents : un template avec un
     * historique d'envois réguliers qui tombe à zéro signale un hook ou un
     * déclencheur cassé, pas un manque de trafic (le seuil minimum de 5
     * envois sur la période de référence exclut les templates rares/manuels).
     */
    private function checkTemplateStaleness(): array
    {
        $table = _DB_PREFIX_ . 'neria_stat';

        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '{$table}'"
        );
        if (!$exists) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.template_staleness_ok')];
        }

        $rows = $this->db->executeS(
            "SELECT `template`,
                    SUM(CASE WHEN `date_add` > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent,
                    SUM(CASE WHEN `date_add` <= DATE_SUB(NOW(), INTERVAL 30 DAY)
                              AND `date_add` > DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS baseline
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop} AND `event_type` = 'sent'
             GROUP BY `template`"
        );

        $silent = [];
        foreach (($rows ?: []) as $row) {
            $baseline = (int) $row['baseline'];
            $recent   = (int) $row['recent'];
            if ($baseline >= 5 && $recent === 0) {
                $silent[] = $row['template'];
            }
        }

        if ($silent) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.template_staleness_warning', [
                    'count'     => count($silent),
                    'templates' => implode(', ', array_slice($silent, 0, 5)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.template_staleness_ok')];
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
        $lastRejected = (string) \Configuration::getGlobalValue('NERIA_CRON_LAST_REJECTED');
        $rejectedAgeHours = $lastRejected !== '' ? (time() - strtotime($lastRejected)) / 3600 : null;
        $cronUrl = \Tools::getShopDomainSsl(true) . __PS_BASE_URI__
            . 'index.php?fc=module&module=neria&controller=cron&token='
            . urlencode((string) \Configuration::getGlobalValue('NERIA_CRON_TOKEN'));

        // Round 186 : un rejet récent (jeton invalide) plus frais que le
        // dernier battement de cœur réussi prouve que le cron ATTEINT bien
        // le serveur — ce n'est donc pas un souci d'hébergement, mais un
        // jeton périmé (typiquement après une réinstallation du module).
        // Diagnostic sans ambiguïté possible dans ce cas précis.
        if ($rejectedAgeHours !== null && $rejectedAgeHours <= 26
            && ($lastHit === null || strtotime($lastRejected) > strtotime($lastHit))
        ) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.active_cron_rejected', [
                    'lastRejected' => $lastRejected,
                    'cronUrl' => $cronUrl,
                ]),
            ];
        }

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
                'detail' => AdminTranslator::tVars('health.active_cron_stale', [
                    'days' => round($ageHours / 24, 1),
                    'lastHit' => $lastHit,
                    'cronUrl' => $cronUrl,
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.active_cron_ok', ['lastHit' => $lastHit])];
    }

    /**
     * Round 186 : le scan lourd known_regressions_guard() ne tourne QUE via
     * le vrai cron serveur (jeton) ou le bouton "Diagnostic complet" du BO
     * — jamais via hookDisplayHeader (round 141, coût prohibitif pour un
     * visiteur). C'est donc le seul mécanisme de Neria sans aucun filet de
     * secours passif : un cron externe cassé (jeton périmé, hébergeur en
     * panne) l'arrête complètement et silencieusement, contrairement aux
     * autres tâches de fond qui continuent de tourner au fil du trafic
     * front-office. Ce contrôle dédié le rend visible.
     */
    private function checkKnownRegressionsGuardFreshness(): array
    {
        $lastRun = (string) \Configuration::getGlobalValue(self::KRG_LAST_RUN);

        if ($lastRun === '') {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.krg_freshness_pending')];
        }

        $ageHours = (time() - strtotime($lastRun)) / 3600;
        if ($ageHours <= self::KRG_STALE_THRESHOLD_HOURS) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::tVars('health.krg_freshness_ok', ['lastRun' => $lastRun])];
        }

        $lastRejected = (string) \Configuration::getGlobalValue('NERIA_CRON_LAST_REJECTED');
        $rejectedAgeHours = $lastRejected !== '' ? (time() - strtotime($lastRejected)) / 3600 : null;

        // Rejet récent et plus récent que la dernière exécution réelle du
        // scan lourd : cause la plus probable identifiée avec certitude.
        if ($rejectedAgeHours !== null && $rejectedAgeHours <= self::KRG_STALE_THRESHOLD_HOURS
            && strtotime($lastRejected) > strtotime($lastRun)
        ) {
            $cronUrl = \Tools::getShopDomainSsl(true) . __PS_BASE_URI__
                . 'index.php?fc=module&module=neria&controller=cron&token='
                . urlencode((string) \Configuration::getGlobalValue('NERIA_CRON_TOKEN'));

            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.krg_freshness_stale_rejected', [
                    'days' => round($ageHours / 24, 1),
                    'lastRun' => $lastRun,
                    'cronUrl' => $cronUrl,
                ]),
            ];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.krg_freshness_stale', [
                'days' => round($ageHours / 24, 1),
                'lastRun' => $lastRun,
            ]),
        ];
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

        // Auto-réparation : force le traitement immédiat de la file plutôt que
        // d'attendre le prochain passage du cron (jusqu'à 24h via le filet de
        // sécurité hookDisplayHeader). QueueManager::processQueue() est déjà
        // ce que ce filet appelle automatiquement — on l'invoque juste plus tôt.
        $processed = 0;
        if (class_exists('QueueManager')) {
            try {
                $processed = (new \QueueManager($this->module))->processQueue();
            } catch (\Throwable $e) {
                // best-effort — on retombe sur le rapport d'erreur ci-dessous
            }
        }

        $stillBlocked = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `send_at` < DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND `status` = 'pending'"
        );

        if ($stillBlocked === 0) {
            return [
                'status'     => self::STATUS_WARNING,
                'detail'     => AdminTranslator::tVars('health.queue_blocked_fixed', ['count' => $processed]),
                'auto_fixed' => true,
            ];
        }

        return [
            'status' => self::STATUS_ERROR,
            'detail' => AdminTranslator::tVars('health.queue_blocked_critical', ['blocked' => $stillBlocked]),
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
             WHERE `id_shop`   = {$this->idShop}
               AND `status`    = '" . pSQL(WebhookManager::STATUS_FAILED) . "'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );

        if ($failed === 0) {
            $pending = (int) $this->db->getValue(
                "SELECT COUNT(*) FROM `{$table}` WHERE `id_shop` = {$this->idShop} AND `status` = 'pending'"
            );
            return [
                'status' => self::STATUS_OK,
                'detail' => AdminTranslator::tVars('health.webhooks_ok', ['pending' => $pending]),
            ];
        }

        // Auto-réparation : remet les webhooks en échec en file (statut
        // 'pending', compteur de tentatives à 0) et force le traitement
        // immédiat, plutôt que de les laisser en échec permanent jusqu'à
        // une action manuelle du marchand.
        $failedIds = $this->db->executeS(
            "SELECT id_webhook FROM `{$table}`
             WHERE `id_shop`   = {$this->idShop}
               AND `status`    = '" . pSQL(\WebhookManager::STATUS_FAILED) . "'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        ) ?: [];

        $webhookMgr = new \WebhookManager($this->module);
        $requeued = 0;
        foreach ($failedIds as $row) {
            if ($webhookMgr->retryOne((int) $row['id_webhook'])) {
                $requeued++;
            }
        }
        if ($requeued > 0) {
            try {
                $webhookMgr->processQueue();
            } catch (\Throwable $e) {
                // best-effort — le statut 'pending' reste correct, un futur
                // passage du cron retentera l'envoi
            }
        }

        $stillFailed = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `id_shop`   = {$this->idShop}
               AND `status`    = '" . pSQL(\WebhookManager::STATUS_FAILED) . "'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );

        if ($stillFailed === 0) {
            return [
                'status'     => self::STATUS_WARNING,
                'detail'     => AdminTranslator::tVars('health.webhooks_fixed', ['count' => $requeued]),
                'auto_fixed' => true,
            ];
        }

        return [
            'status' => self::STATUS_ERROR,
            'detail' => AdminTranslator::tVars('health.webhooks_failed', ['failed' => $stillFailed]),
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
             WHERE `id_shop`    = {$this->idShop}
               AND `is_active`  = 1
               AND `date_add`  < DATE_SUB(NOW(), INTERVAL 30 DAY)"
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
             WHERE `id_shop`    = {$this->idShop}
               AND `event_type` = 'sent'
               AND DATE(`date_add`) = CURDATE()"
        );

        if ($today === 0) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.send_volume_none_today')];
        }

        $avgRow = $this->db->getValue(
            "SELECT AVG(daily_count) FROM (
                SELECT COUNT(*) AS daily_count
                FROM `{$table}`
                WHERE `id_shop`    = {$this->idShop}
                  AND `event_type` = 'sent'
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
            $recreated = $this->recreateMissingTables($missing);
            $stillMissing = array_diff($missing, $recreated);

            if (empty($stillMissing)) {
                return [
                    'status'     => self::STATUS_WARNING,
                    'detail'     => AdminTranslator::tVars('health.db_tables_fixed', [
                        'count' => count($recreated),
                        'list'  => implode(', ', $recreated),
                    ]),
                    'auto_fixed' => true,
                ];
            }

            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.db_tables_missing', [
                    'count' => count($stillMissing),
                    'list'  => implode(', ', $stillMissing),
                ]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.db_tables_ok', ['count' => count($expected)]),
        ];
    }

    /**
     * Recrée UNIQUEMENT les instructions `CREATE TABLE IF NOT EXISTS` des
     * tables manquantes, extraites de sql/install.sql — jamais les `INSERT`
     * du même fichier (certains sont des `INSERT INTO` bruts, sans
     * `IGNORE`, sur des tables comme `neria_custom_variable` : les rejouer
     * dupliquerait ou écraserait la config déjà personnalisée du marchand).
     *
     * @param array $missingTables Noms de tables (sans préfixe) à recréer
     * @return array Noms de tables effectivement recréées
     */
    private function recreateMissingTables(array $missingTables): array
    {
        $sqlFile = _PS_MODULE_DIR_ . $this->module->name . '/sql/install.sql';
        if (!is_file($sqlFile)) {
            return [];
        }

        $content = file_get_contents($sqlFile);
        if ($content === false) {
            return [];
        }

        $recreated = [];
        foreach ($missingTables as $table) {
            // Capture le bloc CREATE TABLE IF NOT EXISTS `PREFIX_table` ( ... ) ENGINE=... ; en entier
            $pattern = '/CREATE TABLE IF NOT EXISTS `PREFIX_' . preg_quote($table, '/') . '`.*?ENGINE=InnoDB[^;]*;/is';
            if (!preg_match($pattern, $content, $m)) {
                continue;
            }
            $statement = str_replace('PREFIX_', _DB_PREFIX_, $m[0]);
            if (\Db::getInstance()->execute($statement)) {
                $recreated[] = $table;
            }
        }

        return $recreated;
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

        // Clients en attente non notifiés depuis plus de 48h, dont le stock
        // réellement disponible (SUM sur TOUTES les lignes stock_available,
        // aucun filtre id_product_attribute) est positif — même correctif
        // que WaitlistManager::notifyProduct() (voir son commentaire
        // détaillé) : id_product_attribute = 0 ne teste que la combinaison
        // "sans déclinaison", presque toujours à quantity = 0 pour un
        // produit géré par déclinaisons. Un SUM(quantity) SQL direct est
        // utilisé plutôt que StockAvailable::getQuantityAvailableByProduct()
        // — cette API core convertit id_product_attribute=null en 0
        // (classes/stock/StockAvailable.php), donc NE somme PAS les
        // déclinaisons contrairement à ce qu'un correctif précédent
        // supposait ; ce garde-fou, censé justement rattraper un oubli de
        // notification, restait alors bloqué en permanence sur "OK" pour
        // tout produit à déclinaisons.
        $candidates = $this->db->executeS(
            "SELECT DISTINCT w.id_product, w.id_shop FROM `{$table}` w
             WHERE w.id_shop = {$this->idShop}
               AND w.notified_at IS NULL
               AND w.registered_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        ) ?: [];

        $backlogProducts = [];
        foreach ($candidates as $row) {
            // Round 167 : même correctif que WaitlistManager::notifyProduct() —
            // en mode "stock partagé" entre boutiques d'un groupe, la
            // quantité réelle est stockée sur UNE SEULE ligne id_shop=0/
            // id_shop_group=X, jamais sur id_shop=$row['id_shop']. Sans
            // cette bascule, ce garde-fou ne détectait JAMAIS de backlog
            // pour une boutique en stock partagé (le SUM renvoyait toujours
            // 0), restant bloqué sur "OK" alors que des inscrits attendaient
            // réellement un produit disponible.
            $rowShop = new \Shop((int) $row['id_shop']);
            if ((bool) $rowShop->getGroup()->share_stock) {
                $stockWhere = " AND id_shop = 0 AND id_shop_group = " . (int) $rowShop->id_shop_group;
            } else {
                $stockWhere = " AND id_shop = " . (int) $row['id_shop'] . " AND id_shop_group = 0";
            }
            $qty = (int) $this->db->getValue(
                "SELECT COALESCE(SUM(quantity), 0) FROM `" . _DB_PREFIX_ . "stock_available`
                 WHERE id_product = " . (int) $row['id_product'] . $stockWhere
            );
            if ($qty > 0) {
                $backlogProducts[] = $row;
            }
        }

        if (empty($backlogProducts)) {
            $total = (int) $this->db->getValue("SELECT COUNT(*) FROM `{$table}` WHERE id_shop = {$this->idShop} AND notified_at IS NULL");
            return [
                'status' => self::STATUS_OK,
                'detail' => AdminTranslator::tVars('health.waitlist_ok', ['total' => $total]),
            ];
        }

        // Auto-réparation : notifie immédiatement les clients en attente sur
        // ces produits, plutôt que d'attendre le prochain passage du cron
        // qui aurait dû le faire — WaitlistManager::notifyProduct() est déjà
        // la méthode utilisée en conditions normales, on l'invoque juste ici.
        $notified = 0;
        $waitlistMgr = new \WaitlistManager($this->module);
        foreach ($backlogProducts as $row) {
            try {
                $notified += $waitlistMgr->notifyProduct((int) $row['id_product'], (int) $row['id_shop']);
            } catch (\Throwable $e) {
                // best-effort — on continue avec les autres produits
            }
        }

        return [
            'status'     => self::STATUS_WARNING,
            'detail'     => AdminTranslator::tVars('health.waitlist_fixed', ['count' => $notified, 'products' => count($backlogProducts)]),
            'auto_fixed' => true,
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
             WHERE `id_shop` = {$this->idShop} AND `event_type` = 'sent' AND DATE(`date_add`) = CURDATE()"
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
            WHERE id_shop = ' . $this->idShop . '
              AND event_type = \'open\'
              AND date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        if ($opens === 0) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.click_rate_no_opens')];
        }

        $clicks = (int) $db->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_stat`
            WHERE id_shop = ' . $this->idShop . '
              AND event_type = \'click\'
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
            WHERE id_shop = ' . $this->idShop . '
              AND event_type = \'sent\'
              AND date_add >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');

        if ($sent < 100) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.unsub_spike_insufficient')];
        }

        $unsubs = (int) $db->getValue('
            SELECT COUNT(DISTINCT id_customer) FROM `' . _DB_PREFIX_ . 'neria_preferences`
            WHERE id_shop = ' . $this->idShop . '
              AND subscribed = 0
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
            WHERE id_shop = ' . $this->idShop . '
              AND status = \'pending\'
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

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            // Réinitialise à vide plutôt que de laisser un JSON corrompu en
            // place : tous les emails repartent avec l'expéditeur par défaut
            // de la boutique (comportement identique au cas "non configuré"),
            // sans perte de données puisque la valeur était déjà inexploitable.
            \Configuration::updateValue('NERIA_SENDERS_JSON', '');
            return [
                'status'     => self::STATUS_WARNING,
                'detail'     => AdminTranslator::t('health.multisender_fixed'),
                'auto_fixed' => true,
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
            // MonthlyReportManager::getRecipients() se replie sur PS_SHOP_EMAIL
            // quand aucun destinataire n'est explicitement configuré — l'envoi
            // réel n'est donc pas silencieusement perdu tant que cette valeur
            // existe. Avant ce correctif, ce contrôle affichait un WARNING
            // laissant croire à un envoi cassé/perdu, alors que le rapport
            // partait bien vers l'email de la boutique.
            $shopEmail = trim((string) \Configuration::get('PS_SHOP_EMAIL'));
            if ($shopEmail !== '' && filter_var($shopEmail, FILTER_VALIDATE_EMAIL)) {
                return [
                    'status' => self::STATUS_OK,
                    'detail' => AdminTranslator::tVars('health.monthly_report_fallback', ['recipient' => $shopEmail]),
                ];
            }

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

        // GROUP BY id_customer, id_shop en mode séparé (comme
        // LoyaltyManager::getCustomerStats()/getGlobalStats()/getTopCustomers()) :
        // sans ce filtre, un solde négatif sur UNE boutique pouvait être
        // masqué (faux négatif) par un solde positif sur une autre, ou
        // inversement rendre impossible d'identifier quelle boutique est
        // réellement corrompue quand NERIA_LOYALTY_CROSS_SHOP_ENABLED est
        // désactivé (gestion par boutique).
        $crossShop = class_exists('ConfigManager') && (new \ConfigManager($this->module))->isLoyaltyCrossShopEnabled();
        $groupBy   = $crossShop ? '`id_customer`' : '`id_customer`, `id_shop`';
        $negative  = (int) $db->getValue('
            SELECT COUNT(*) FROM (
                SELECT SUM(`points`) AS total
                FROM `' . _DB_PREFIX_ . 'neria_loyalty_points`
                GROUP BY ' . $groupBy . '
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
            'SELECT MAX(`computed_at`) FROM `' . _DB_PREFIX_ . 'neria_customer_segment` WHERE `id_shop` = ' . $this->idShop
        );

        $needsRecompute = !$lastRun || (time() - strtotime($lastRun)) / 3600 > 48;

        if ($needsRecompute) {
            try {
                $updated = (new \SegmentManager($this->module))->recomputeAll();
                return [
                    'status'     => self::STATUS_WARNING,
                    'detail'     => AdminTranslator::tVars('health.segment_fixed', ['count' => $updated]),
                    'auto_fixed' => true,
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => self::STATUS_ERROR,
                    'detail' => AdminTranslator::tVars('health.segment_critical', [
                        'ageH'    => $lastRun ? round((time() - strtotime($lastRun)) / 3600, 1) : 'N/A',
                        'lastRun' => $lastRun ?: '—',
                    ]),
                ];
            }
        }

        $ageH = round((time() - strtotime($lastRun)) / 3600, 1);

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
            WHERE id_shop = ' . $this->idShop . '
        ');

        $lastCalc = $count > 0 ? $db->getValue('
            SELECT MAX(computed_at) FROM `' . _DB_PREFIX_ . 'neria_churn_score`
            WHERE id_shop = ' . $this->idShop . '
        ') : null;

        $ageH = $lastCalc ? round((time() - strtotime($lastCalc)) / 3600, 1) : null;

        if ($count === 0 || $ageH > 72) {
            if (!class_exists('ChurnScoreManager')) {
                return $count === 0
                    ? ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::t('health.clv_no_scores')]
                    : ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::tVars('health.clv_stale', ['ageH' => $ageH, 'count' => $count])];
            }
            try {
                $updated = (new \ChurnScoreManager($this->module))->recomputeAll();
                return [
                    'status'     => self::STATUS_WARNING,
                    'detail'     => AdminTranslator::tVars('health.clv_fixed', ['count' => $updated]),
                    'auto_fixed' => true,
                ];
            } catch (\Throwable $e) {
                return $count === 0
                    ? ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::t('health.clv_no_scores')]
                    : ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::tVars('health.clv_stale', ['ageH' => $ageH, 'count' => $count])];
            }
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
            // Auto-réparation : force l'envoi des relances en retard tout de
            // suite plutôt que d'attendre le prochain passage du cron complet.
            try {
                (new \BehavioralCronManager($this->module))->sendQuoteExpiryReminders();
            } catch (\Throwable $e) {
                return [
                    'status' => self::STATUS_WARNING,
                    'detail' => AdminTranslator::tVars('health.quote_stuck', ['stuck' => $stuck]),
                ];
            }

            $stillStuck = (int) \Db::getInstance()->getValue('
                SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_quote`
                WHERE status = \'active\'
                  AND sent_48h = 0
                  AND date_add < DATE_SUB(NOW(), INTERVAL 7 DAY)
            ');

            if ($stillStuck === 0) {
                return [
                    'status'     => self::STATUS_WARNING,
                    'detail'     => AdminTranslator::tVars('health.quote_fixed', ['count' => $stuck]),
                    'auto_fixed' => true,
                ];
            }

            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.quote_stuck', ['stuck' => $stillStuck]),
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
              AND table_name = \'' . _DB_PREFIX_ . 'neria_seasonal_campaign\'
        ');

        if (!$tableExists) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.campaign_no_table')];
        }

        $campaigns = $db->executeS('
            SELECT id_campaign, name, target_segment
            FROM `' . _DB_PREFIX_ . 'neria_seasonal_campaign`
            WHERE is_active = 1
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
                    $emptySegments[] = AdminTranslator::tVars('health.campaign_empty_item', ['name' => $c['name'], 'segment' => $seg]);
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
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
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
     * Volontairement SANS vérification de syntaxe PHP via un appel shell
     * ("php -l") : tout appel système est scruté par le
     * validateur PrestaShop Addons comme surface de risque potentielle,
     * même sécurisé — et ne fonctionne de toute façon pas sur la plupart
     * des hébergements mutualisés. La syntaxe se vérifie en local avant
     * chaque déploiement (déjà fait manuellement pendant le développement).
     */
    public function runCodeDiagnostic(): array
    {
        $results = [
            'admin_trad_usage'  => $this->checkAdminTranslationKeyUsage(),
            'trad_key_usage'    => $this->checkTradKeyUsage(),
            'class_references'  => $this->checkClassReferencesIntegrity(),
            'orphaned_admin_trad_keys' => $this->checkOrphanedAdminTranslationKeys(),
        ];

        $this->logResultsToWatchdog($results);

        return $results;
    }

    /**
     * "Canari" de rendu — appelé une fois par jour depuis le cron
     * (cf. Neria::runBackgroundJobs, jamais sur chaque visiteur front,
     * throttlé via CONFIG_RENDER_CANARY_LAST_RUN). Rend CHAQUE template en
     * mode aperçu (données fictives de buildPreviewFakes, aucune donnée
     * client réelle) et capture tout warning/notice/deprecated PHP déclenché
     * pendant la compilation.
     *
     * Classe d'erreurs différente de orphan_placeholders/trad_key_usage :
     * ceux-ci détectent des VARIABLES manquantes (statique, sans exécuter
     * le code) ; le canari détecte des bugs de CODE qui se déclenchent
     * seulement à l'exécution (accès à un index de tableau non défini,
     * appel de méthode sur null…) et qui ne laissent souvent aucune trace
     * visible dans le HTML compilé — donc invisibles aux deux autres
     * contrôles, comme le warning "Undefined array key" repéré dans les
     * logs PHP lors d'un test manuel du 2026-07-12.
     */
    public function runRenderCanary(): void
    {
        if (!class_exists('EmailRenderer') || !class_exists('NeriaTools')) {
            return;
        }

        $renderer  = new \EmailRenderer($this->module);
        $templates = array_keys(\NeriaTools::getTemplateLabels());

        $warnFindings  = []; // template => [issue strings] (warning/notice/deprecated)
        $fatalFindings = []; // template => exception message (rendu cassé)

        static $levelLabels = [
            E_WARNING       => 'Warning',
            E_NOTICE        => 'Notice',
            E_DEPRECATED    => 'Deprecated',
            E_USER_WARNING  => 'Warning',
            E_USER_NOTICE   => 'Notice',
            E_USER_DEPRECATED => 'Deprecated',
            E_STRICT        => 'Strict',
        ];

        foreach ($templates as $tpl) {
            $issues = [];
            $prevHandler = set_error_handler(static function ($errno, $errstr, $errfile) use (&$issues, $levelLabels) {
                // Ignore tout ce qui ne vient pas du code Neria lui-même
                // (dépréciations du cœur PrestaShop/vendor, hors périmètre).
                if (stripos((string) $errfile, 'neria') === false) {
                    return false;
                }
                $label    = $levelLabels[$errno] ?? ('PHP#' . $errno);
                $issues[] = $label . ': ' . $errstr;
                return true;
            });

            try {
                $renderer->renderPreviewHtml($tpl, 'fr', [], false);
            } catch (\Throwable $e) {
                $fatalFindings[$tpl] = get_class($e) . ' — ' . $e->getMessage();
            } finally {
                set_error_handler($prevHandler);
            }

            if (!empty($issues)) {
                $warnFindings[$tpl] = array_unique($issues);
            }
        }

        if (!empty($fatalFindings)) {
            $count  = count($fatalFindings);
            $sample = [];
            $i = 0;
            foreach ($fatalFindings as $tpl => $msg) {
                $sample[] = $tpl . ' (' . $msg . ')';
                if (++$i >= 3) {
                    break;
                }
            }
            $this->watchdog->error(
                \WatchdogManager::i18nMsg('watchdog.render_canary_fatal', [
                    'count'  => $count,
                    'sample' => implode(' | ', $sample),
                ]),
                '',
                'RenderCanary'
            );
        }

        if (!empty($warnFindings)) {
            $count  = count($warnFindings);
            $sample = [];
            $i = 0;
            foreach ($warnFindings as $tpl => $issues) {
                $sample[] = $tpl . ' (' . $issues[0] . ')';
                if (++$i >= 5) {
                    break;
                }
            }
            $this->watchdog->warning(
                \WatchdogManager::i18nMsg('watchdog.render_canary_warning', [
                    'count'  => $count,
                    'sample' => implode(', ', $sample),
                ]),
                '',
                'RenderCanary'
            );
        }

        $this->watchdog->cronHeartbeat('render_canary', 'ok', count($templates));
    }

    /**
     * Lance le canari de rendu si le throttle quotidien est écoulé —
     * appelé depuis Neria::runBackgroundJobs(), jamais directement sur du
     * trafic front sans throttle (125 rendus complets par appel : coûteux,
     * volontairement absent des 64 contrôles automatiques légers).
     */
    public function runRenderCanaryIfDue(): void
    {
        $last = (int) \Configuration::get(self::CONFIG_RENDER_CANARY_LAST_RUN);
        if ($last && (time() - $last) < self::RENDER_CANARY_THROTTLE_SECONDS) {
            return;
        }
        \Configuration::updateValue(self::CONFIG_RENDER_CANARY_LAST_RUN, time());
        $this->runRenderCanary();
    }

    /**
     * #64 — Résultats récents du canari de rendu (fenêtre 48h, plus large
     * que le throttle 24h pour tolérer un cron occasionnellement en retard
     * sans faux positif de type "jamais exécuté").
     */
    private function checkRenderCanaryRecent(): array
    {
        $rows = $this->db->executeS(
            'SELECT `level`, COUNT(*) AS n FROM `' . _DB_PREFIX_ . 'neria_log`
             WHERE `class` = \'RenderCanary\'
               AND `date_add` >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
             GROUP BY `level`'
        );

        if (empty($rows)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.render_canary_ok')];
        }

        $hasError = false;
        $total    = 0;
        foreach ((array) $rows as $row) {
            $total += (int) $row['n'];
            if ($row['level'] === 'error') {
                $hasError = true;
            }
        }

        return [
            'status' => $hasError ? self::STATUS_ERROR : self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.render_canary_recent_warning', ['count' => $total]),
        ];
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
        // Clés utilisées dont AU MOINS UNE langue supportée a une valeur vide
        // (clé présente, donc invisible à la vérification d'existence
        // ci-dessus, mais le marchand voit le texte de repli — souvent
        // l'anglais — dans son BO au lieu de sa propre langue). C'est le
        // trou exact qui a laissé passer gdpr.local_law_note vide dans 11
        // langues sur 19 (dont le français) jusqu'à un audit manuel.
        $emptyLangs = [];
        $supportedLangs = class_exists('TranslationEngine') ? \TranslationEngine::SUPPORTED_LANGS : [];
        foreach ($usedKeys as $key => $inFiles) {
            if (!array_key_exists($key, $dict)) {
                $missing[$key] = $inFiles[0];
                continue;
            }
            $entry = $dict[$key];
            if (!is_array($entry)) {
                continue;
            }
            $emptyForKey = [];
            foreach ($supportedLangs as $lang) {
                if (!isset($entry[$lang]) || trim((string) $entry[$lang]) === '') {
                    $emptyForKey[] = $lang;
                }
            }
            if ($emptyForKey) {
                $emptyLangs[$key] = $emptyForKey;
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

        if ($emptyLangs) {
            $count  = count($emptyLangs);
            $sample = [];
            $i = 0;
            foreach ($emptyLangs as $key => $langs) {
                $sample[] = "{$key} (" . implode(',', $langs) . ')';
                if (++$i >= 5) {
                    break;
                }
            }
            $sampleStr = implode(', ', $sample) . ($count > 5 ? '… (' . ($count - 5) . ' autres)' : '');
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.admin_trad_empty_langs', ['count' => $count, 'sample' => $sampleStr]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.admin_trad_usage_ok', ['count' => count($usedKeys)]),
        ];
    }

    /**
     * Scanne les templates email (layout.html + core/*.html) à la recherche
     * de clés {neria_trad key='...'} littérales absentes de
     * data/translations.json — le marchand recevrait alors un email avec
     * un bout de texte manquant chez le client.
     *
     * Résolution strictement scopée par template (TranslationEngine::get()
     * ne tombe jamais sur le bloc d'un AUTRE template) : une clé de
     * core/{tpl}.html doit exister dans translations.json[{tpl}], et une
     * clé de layout.html (partagé par tous les envois) doit exister dans
     * TOUS les blocs, sinon elle casserait le rendu de n'importe quel
     * template ne la possédant pas.
     */
    private function checkTradKeyUsage(): array
    {
        $root       = rtrim($this->module->getLocalPath(), '/');
        $jsonPath   = $root . '/data/translations.json';
        $coreDir    = $root . '/mails/themes/neria_global/core';
        $layoutPath = $root . '/mails/themes/neria_global/layout.html';

        if (!is_file($jsonPath)) {
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::t('health.trad_key_usage_json_missing')];
        }

        $dict = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($dict)) {
            return ['status' => self::STATUS_ERROR, 'detail' => AdminTranslator::t('health.trad_key_usage_json_missing')];
        }

        $extractKeys = static function (string $content): array {
            $content = preg_replace('/\{\*.*?\*\}/s', '', $content) ?? $content;
            $keys = [];
            if (preg_match_all('/neria_trad\s+key=([\'"])([a-zA-Z0-9_.\-]+)\1/', $content, $m)) {
                $keys = array_unique($m[2]);
            }
            return $keys;
        };

        $keyExistsInBlock = static function ($block, string $key): bool {
            if (!is_array($block)) {
                return false;
            }
            foreach ($block as $vals) {
                if (is_array($vals) && array_key_exists($key, $vals)) {
                    return true;
                }
            }
            return false;
        };

        // Reproduit la vraie logique de résolution de TranslationEngine::get() :
        // bloc du template en premier, puis repli sur le bloc partagé "_global"
        // (footer, etc.) — sans ce repli, on obtiendrait un faux positif sur
        // TOUTES les clés de layout.html partagées via _global.
        $globalBlock = $dict['_global'] ?? null;
        $keyResolvable = function ($block, string $key) use ($keyExistsInBlock, $globalBlock): bool {
            return $keyExistsInBlock($block, $key) || $keyExistsInBlock($globalBlock, $key);
        };

        $missing    = [];
        // Même trou que checkAdminTranslationKeyUsage() : une clé peut exister
        // dans le dictionnaire (donc ne jamais remonter dans $missing) tout en
        // étant vide pour une ou plusieurs langues supportées — le client
        // recevrait alors un email avec un repli (souvent l'anglais) au lieu
        // du texte dans sa propre langue.
        $supportedLangs = class_exists('TranslationEngine') ? \TranslationEngine::SUPPORTED_LANGS : [];
        $emptyLangs = [];
        $checkEmpty = function (string $refKey, $block) use ($supportedLangs, $globalBlock, &$emptyLangs) {
            $emptyForKey = [];
            foreach ($supportedLangs as $lang) {
                $key = explode(':', $refKey, 2)[1] ?? $refKey;
                $val = $block[$lang][$key] ?? $globalBlock[$lang][$key] ?? null;
                if ($val === null || trim((string) $val) === '') {
                    $emptyForKey[] = $lang;
                }
            }
            if ($emptyForKey) {
                $emptyLangs[$refKey] = $emptyForKey;
            }
        };

        // Clés spécifiques à chaque template
        foreach ($this->globRecursive($coreDir, '.html') as $file) {
            $tpl  = basename($file, '.html');
            $keys = $extractKeys((string) file_get_contents($file));
            foreach ($keys as $key) {
                $block = $dict[$tpl] ?? null;
                if (!$keyResolvable($block, $key)) {
                    $missing[$tpl . ':' . $key] = $tpl;
                    continue;
                }
                $checkEmpty($tpl . ':' . $key, $block);
            }
        }

        // Clés de layout.html : résolues via _global (partagé par tous les
        // envois) ou, à défaut, doivent être dupliquées dans chaque bloc.
        if (is_file($layoutPath)) {
            foreach ($extractKeys((string) file_get_contents($layoutPath)) as $key) {
                if ($keyExistsInBlock($globalBlock, $key)) {
                    continue;
                }
                $missingIn = [];
                foreach ($dict as $tpl => $block) {
                    if ($tpl !== '_global' && !$keyExistsInBlock($block, $key)) {
                        $missingIn[] = $tpl;
                    }
                }
                if (!empty($missingIn)) {
                    $missing['layout:' . $key] = 'layout (' . count($missingIn) . ' templates)';
                    continue;
                }
                $checkEmpty('_global:' . $key, $globalBlock);
            }
        }

        if ($missing) {
            $count     = count($missing);
            $sample    = array_slice(array_keys($missing), 0, 5);
            $sampleStr = implode(', ', $sample) . ($count > 5 ? '… (' . ($count - 5) . ' autres)' : '');
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.trad_key_usage_missing', ['count' => $count, 'sample' => $sampleStr]),
            ];
        }

        if ($emptyLangs) {
            $count     = count($emptyLangs);
            $sample    = [];
            $i = 0;
            foreach ($emptyLangs as $refKey => $langs) {
                $sample[] = "{$refKey} (" . implode(',', $langs) . ')';
                if (++$i >= 5) {
                    break;
                }
            }
            $sampleStr = implode(', ', $sample) . ($count > 5 ? '… (' . ($count - 5) . ' autres)' : '');
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.trad_key_usage_empty_langs', ['count' => $count, 'sample' => $sampleStr]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::t('health.trad_key_usage_ok'),
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
     * #53 — Fichiers compilés périmés pour un template blacklisté
     * add_blacklist() est censé supprimer mails/{lang}/{template}.html|.txt
     * pour empêcher Mail::Send() de continuer à réutiliser l'ancien rendu
     * Neria (signature, design...) malgré la désactivation. Si un upgrade
     * ou une restauration de sauvegarde a laissé un fichier compilé pour un
     * template pourtant blacklisté, le client continue de le recevoir en
     * silence. Détecté le 2026-07-07 en conditions réelles (Mailpit).
     */
    private function checkBlacklistStaleFiles(): array
    {
        if (!class_exists('BlacklistManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.blacklist_stale_ok')];
        }

        $rows = $this->db->executeS(
            'SELECT `template`, `lang` FROM `' . _DB_PREFIX_ . 'neria_blacklist`'
        );
        if (empty($rows)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.blacklist_stale_ok')];
        }

        // `mails/{lang}/{template}` n'est pas un template source : c'est la
        // sortie COMPILÉE du vrai pipeline d'envoi (EmailRenderer::
        // compileNeriaTemplate(), régénérée à chaque envoi réel). Ce fichier
        // était auparavant supprimé automatiquement (@unlink) dès qu'une
        // règle de blacklist existait — mais CalendarManager::
        // sendCalendarEmail() lit directement ce fichier comme condition
        // d'envoi (file_exists avant Mail::Send) pour les occasions
        // calendaires, sans jamais se reconstruire tout seul entre deux
        // envois annuels. Une suppression pouvait donc bloquer
        // silencieusement un envoi calendaire pendant des mois, sans
        // qu'aucune régénération naturelle ne survienne, y compris après
        // que le marchand ait retiré la règle de blacklist.
        // Correctif adopté : sendCalendarEmail() vérifie désormais
        // explicitement BlacklistManager::isBlacklisted() lui-même — la
        // présence du fichier compilé n'a donc plus besoin d'être
        // manipulée pour faire respecter la blacklist. Ce contrôle reste
        // purement informatif (aucune suppression automatique).
        $mailsDir  = rtrim($this->module->getLocalPath(), '/') . '/mails/';
        $offenders = [];

        foreach ((array) $rows as $row) {
            $tpl   = (string) $row['template'];
            $langs = $row['lang'] !== '' ? [(string) $row['lang']] : \TranslationEngine::SUPPORTED_LANGS;
            foreach ($langs as $lang) {
                foreach (['.html', '.txt'] as $ext) {
                    $path = $mailsDir . $lang . '/' . $tpl . $ext;
                    if (is_file($path)) {
                        $offenders[] = $tpl . ' (' . $lang . ')';
                    }
                }
            }
        }

        if ($offenders) {
            $offenders = array_unique($offenders);
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.blacklist_stale_info', [
                    'count' => count($offenders),
                    'list'  => implode(', ', array_slice($offenders, 0, 8)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.blacklist_stale_ok')];
    }

    /**
     * #54 — Alertes récentes de variables manquantes (filet de sécurité)
     * EmailRenderer::compileNeriaTemplate() journalise un WARNING
     * ('watchdog.residual_vars_stripped') chaque fois qu'une variable de
     * contenu attendue par un template n'a pas été fournie par l'appelant
     * (champ manuel oublié, intégration tierce incomplète...). Le filet de
     * sécurité protège déjà le client d'un email cassé, mais le marchand
     * doit être informé pour corriger la source plutôt que de compter sur
     * ce filet indéfiniment.
     */
    private function checkRecentResidualVarsWarnings(): array
    {
        $rows = $this->db->executeS(
            'SELECT `template`, `message`, `date_add` FROM `' . _DB_PREFIX_ . 'neria_log`
             WHERE `class` = \'EmailRenderer\'
               AND `message` LIKE \'%residual_vars_stripped%\'
               AND `date_add` >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );

        if (empty($rows)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.residual_recent_ok')];
        }

        // La détection SYSTÉMIQUE (ci-dessous) utilise une fenêtre plus courte
        // (24h, alignée sur le rythme des autres seuils du module — cron_health,
        // throttle du render_canary) que le signal informatif isolé (7 jours,
        // plus bas) : un vrai bug de code produit des occurrences en continu,
        // 24h suffit largement à le détecter. Une fenêtre de 7 jours ferait
        // crier au bug pendant une semaine après chaque correction, sur du
        // bruit historique déjà résolu — piège rencontré en pratique le
        // 2026-07-12 avec {preferences_url} (fix commit b7871d3, 08h17) :
        // 251 occurrences du 11/07 (avant fix) encore dans la fenêtre de 7j,
        // et même une fenêtre de 48h les incluait encore (32-36h d'écart) —
        // seule une fenêtre de 24h les exclut correctement, confirmé par un
        // second contrôle direct (0 occurrence dans les 24h suivant le fix).
        $recentRows = array_values(array_filter((array) $rows, function ($row) {
            return isset($row['date_add']) && strtotime($row['date_add']) >= strtotime('-24 hours');
        }));

        // Distingue un défaut de câblage SYSTÉMIQUE (une variable jamais injectée
        // nulle part, donc absente sur la quasi-totalité des templates — ex. le
        // bug {preferences_url} du 2026-07-12, 125/125 templates identiquement
        // touchés) d'une variable ponctuellement absente sur un template isolé.
        // Le premier cas est un vrai bug de code à corriger, pas du bruit —
        // il doit passer en erreur pour déclencher l'alerte immédiate.
        $varTemplates      = [];
        $templatesAffected = [];

        foreach ((array) $rows as $row) {
            $template = (string) $row['template'];
            $templatesAffected[$template] = true;

            if (preg_match('/"vars":"([^"]*)"/', (string) $row['message'], $m)) {
                foreach (explode(', ', $m[1]) as $var) {
                    $var = trim($var);
                    if ($var !== '') {
                        $varTemplates[$var][$template] = true;
                    }
                }
            }
        }

        // Recalcul dédié à la détection systémique, sur la fenêtre courte
        // (48h) uniquement — voir commentaire plus haut.
        $recentVarTemplates      = [];
        $recentTemplatesAffected = [];

        foreach ($recentRows as $row) {
            $template = (string) $row['template'];
            $recentTemplatesAffected[$template] = true;

            if (preg_match('/"vars":"([^"]*)"/', (string) $row['message'], $m)) {
                foreach (explode(', ', $m[1]) as $var) {
                    $var = trim($var);
                    if ($var !== '') {
                        $recentVarTemplates[$var][$template] = true;
                    }
                }
            }
        }

        // {custom_message}/{custom_message_txt} sont volontairement optionnels
        // (vides hors envoi manuel avec message personnalisé, cf. injectCustomMessage)
        // — leur absence quasi-systématique est normale, pas un bug de câblage.
        $knownOptional = ['{custom_message}', '{custom_message_txt}'];

        $totalTemplates    = count($recentTemplatesAffected);
        $systemicThreshold = max(10, (int) ceil($totalTemplates * 0.5));

        $systemicVars = [];
        foreach ($recentVarTemplates as $var => $tpls) {
            if (in_array($var, $knownOptional, true)) {
                continue;
            }
            if (count($tpls) >= $systemicThreshold) {
                $systemicVars[$var] = count($tpls);
            }
        }

        if (!empty($systemicVars)) {
            arsort($systemicVars);
            $list = [];
            foreach ($systemicVars as $var => $n) {
                $list[] = $var . ' (' . $n . ')';
            }
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.residual_systemic_error', [
                    'list' => implode(', ', $list),
                ]),
            ];
        }

        $templates = array_keys($templatesAffected);
        $total     = count($rows);

        return [
            'status' => self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.residual_recent_warning', [
                'count' => $total,
                'list'  => implode(', ', array_slice($templates, 0, 8)),
            ]),
        ];
    }

    /**
     * #55 — Signature / réseaux sociaux absents des envois RÉCENTS
     * Ne juge que les fichiers compilés modifiés dans les 7 derniers jours
     * (mtime), jamais l'état figé du disque en général : un fichier compilé
     * reflète le DERNIER envoi de son template, donc juger un fichier ancien
     * produirait un faux signal sur un template simplement peu envoyé — piège
     * déjà rencontré et écarté pour le contrôle blacklist_stale_files. Ici,
     * si aucun fichier n'a été régénéré récemment, le contrôle ne peut rien
     * affirmer et renvoie OK plutôt que de spéculer.
     */
    private function checkSignatureSocialRenderedRecently(): array
    {
        if (!class_exists('ConfigManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.sig_social_recent_ok')];
        }

        $config = new \ConfigManager($this->module);
        $checkSignature = $config->isSignatureEnabled() && $this->db->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'neria_signature` WHERE `is_active` = 1 AND `id_shop` = ' . $this->idShop
        );
        $socialLinks  = method_exists($config, 'getSocialLinks') ? $config->getSocialLinks() : [];
        $checkSocial  = !empty($socialLinks);

        if (!$checkSignature && !$checkSocial) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.sig_social_recent_na')];
        }

        $sigFile = '';
        if ($checkSignature) {
            $sigRow = $this->db->getRow(
                'SELECT `image_path` FROM `' . _DB_PREFIX_ . 'neria_signature` WHERE `is_active` = 1 AND `id_shop` = ' . $this->idShop
            );
            $sigFile = $sigRow ? basename((string) $sigRow['image_path']) : '';
        }

        $mailsDir  = rtrim($this->module->getLocalPath(), '/') . '/mails/';
        $cutoff    = time() - (7 * 86400);
        $checked   = 0;
        $missingSig    = [];
        $missingSocial = [];

        foreach (\TranslationEngine::SUPPORTED_LANGS as $lang) {
            $langDir = $mailsDir . $lang . '/';
            if (!is_dir($langDir)) {
                continue;
            }
            foreach (glob($langDir . '*.html') ?: [] as $file) {
                if (filemtime($file) < $cutoff) {
                    continue; // fichier pas régénéré récemment — pas de jugement possible
                }
                $checked++;
                $content = (string) file_get_contents($file);
                $label   = $lang . '/' . basename($file);

                if ($checkSignature && $sigFile !== '' && strpos($content, $sigFile) === false) {
                    $missingSig[] = $label;
                }
                if ($checkSocial && strpos($content, 'neria-social') === false && !$this->contentHasAnySocialLink($content, $socialLinks)) {
                    $missingSocial[] = $label;
                }
            }
        }

        if ($checked === 0) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.sig_social_recent_none')];
        }

        $issues = [];
        if ($missingSig) {
            $issues[] = AdminTranslator::tVars('health.sig_social_recent_sig_part', [
                'count' => count($missingSig),
                'list'  => implode(', ', array_slice($missingSig, 0, 5)),
            ]);
        }
        if ($missingSocial) {
            $issues[] = AdminTranslator::tVars('health.sig_social_recent_social_part', [
                'count' => count($missingSocial),
                'list'  => implode(', ', array_slice($missingSocial, 0, 5)),
            ]);
        }

        if ($issues) {
            return ['status' => self::STATUS_WARNING, 'detail' => implode(' ', $issues)];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.sig_social_recent_ok_count', ['count' => $checked]),
        ];
    }

    /**
     * #56 — Actions du BO sans bannière de confirmation
     * Analyse statique de neria.php : pour chaque bloc
     * `Tools::getValue('neria_action') === 'xxx'`, vérifie qu'il assigne bien
     * neria_success/neria_error (directement, via un wrapper connu comme
     * assignQuoteMsg(), ou via une clé construite dynamiquement du type
     * "…neria_" . $var . "…"). Reproduit par le code ce que le test exhaustif
     * du 2026-07-07 a dû faire manuellement (cliquer chaque action et lire
     * la réponse HTML) pour détecter 19+ toggles muets et plusieurs actions
     * CRUD sans retour visible. Les actions AJAX/JSON, de contenu brut
     * (aperçu, téléchargement) ou de redirection OAuth externe sont exclues
     * car elles n'affichent jamais de bannière par nature.
     */
    private function checkActionBannerCoverage(): array
    {
        $file = _PS_MODULE_DIR_ . 'neria/neria.php';
        if (!is_file($file)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.banner_coverage_ok')];
        }
        $source = (string) file_get_contents($file);

        // Actions volontairement silencieuses (AJAX/JSON, contenu brut, redirection
        // OAuth externe, délégation à une méthode privée qui gère sa propre bannière) —
        // établi lors du test exhaustif du 2026-07-07 (Groupes A à F).
        $silentByDesign = [
            'preview', 'preview_signature', 'preview_manual', 'multipreview_render',
            'customer_autocomplete', 'check_send_duplicate', 'check_anniversary_guard',
            'upsell_preview', 'cert_download', 'gdpr_pdf', 'run_bounce_check',
            'deliverability_score', 'health_pixel_test', 'test_imap_connection',
            'connect_postmaster', 'connect_searchconsole', 'watchdog_refresh',
            'dismiss_design_wizard', 'process_queue_now', 'send_report_now',
            'run_full_diagnostic', 'run_code_diagnostic', 'send_test', 'search_customers',
            'load_translations',
        ];

        preg_match_all(
            "/Tools::getValue\('neria_action'\)\s*===\s*'([a-z_0-9]+)'/",
            $source,
            $m,
            PREG_OFFSET_CAPTURE
        );

        $noBanner = [];
        foreach ($m[1] as [$name, $offset]) {
            if (in_array($name, $silentByDesign, true) || in_array($name, $noBanner, true)) {
                continue;
            }
            $braceStart = strpos($source, '{', $offset);
            if ($braceStart === false) {
                continue;
            }
            $depth = 0;
            $blockEnd = null;
            $len = strlen($source);
            for ($i = $braceStart; $i < $len; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $blockEnd = $i;
                        break;
                    }
                }
            }
            if ($blockEnd === null) {
                continue;
            }
            $block = substr($source, $braceStart, $blockEnd - $braceStart);
            $hasBanner = strpos($block, 'neria_success') !== false
                || strpos($block, 'neria_error') !== false
                || strpos($block, 'assignQuoteMsg(') !== false
                || preg_match("/neria_'\s*\.\s*\\\$/", $block) === 1;
            if (!$hasBanner) {
                $noBanner[] = $name;
            }
        }

        if ($noBanner) {
            $count = count($noBanner);
            $list  = implode(', ', array_slice($noBanner, 0, 8));
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.banner_coverage_warning', ['count' => $count, 'list' => $list]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.banner_coverage_ok_count', ['count' => count($m[1])]),
        ];
    }

    /**
     * Vérifie qu'au moins un lien social configuré apparaît littéralement
     * dans le contenu compilé (contournement si le style du bloc change).
     */
    private function contentHasAnySocialLink(string $content, array $socialLinks): bool
    {
        foreach ($socialLinks as $url) {
            if ($url !== '' && strpos($content, (string) $url) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * #63 — Placeholders orphelins : variables {xxx} présentes dans les
     * templates (layout + .html + .txt) mais jamais mentionnées nulle part
     * dans le code PHP du module, donc jamais injectées lors d'un envoi réel.
     *
     * Contrôle statique proactif — détecte ce type de bug de câblage (ex.
     * {preferences_url}, cf. commit b7871d3) AVANT même qu'un seul email
     * ne parte, sans attendre l'accumulation de logs residual_vars_stripped.
     *
     * Filet volontairement large côté PHP : une variable citée ne serait-ce
     * qu'une fois comme littéral '{xxx}' n'importe où dans src/ ou
     * controllers/ est considérée "câblée", pour éviter les faux positifs
     * sur les variables injectées par concaténation dynamique.
     */
    private function checkOrphanPlaceholders(): array
    {
        $mailsDir = _PS_MODULE_DIR_ . 'neria/mails/themes/neria_global';
        if (!is_dir($mailsDir)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.orphan_vars_ok')];
        }

        $templateFiles = array_merge(
            $this->globRecursive($mailsDir, '.html'),
            $this->globRecursive($mailsDir, '.txt')
        );

        $usedVars = [];
        foreach ($templateFiles as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $content, $m)) {
                foreach ($m[1] as $var) {
                    $usedVars[$var] = true;
                }
            }
        }

        if (empty($usedVars)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.orphan_vars_ok')];
        }

        $phpFiles = array_merge(
            $this->globRecursive(_PS_MODULE_DIR_ . 'neria/src', '.php'),
            $this->globRecursive(_PS_MODULE_DIR_ . 'neria/controllers', '.php')
        );
        $neriaRoot = _PS_MODULE_DIR_ . 'neria/neria.php';
        if (is_file($neriaRoot)) {
            $phpFiles[] = $neriaRoot;
        }

        $wiredVars = [];
        foreach ($phpFiles as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $content, $m)) {
                foreach ($m[1] as $var) {
                    $wiredVars[$var] = true;
                }
            }
        }

        // Exclusion connue : les variantes "_txt" sont générées dynamiquement
        // à partir de leur équivalent HTML par concaténation de chaîne
        // ('{' . $key . '_txt}', cf. EmailRenderer::compileEmail) — jamais
        // écrites en toutes lettres dans le code source, donc invisibles à ce
        // grep statique bien qu'elles soient réellement câblées à l'exécution.
        $orphans = array_values(array_filter(
            array_diff(array_keys($usedVars), array_keys($wiredVars)),
            static fn ($v) => !str_ends_with($v, '_txt')
        ));
        sort($orphans);

        if (!empty($orphans)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.orphan_vars_warning', [
                    'count' => count($orphans),
                    'list'  => implode(', ', array_map(static fn ($v) => '{' . $v . '}', array_slice($orphans, 0, 10))),
                ]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.orphan_vars_ok_count', ['count' => count($usedVars)]),
        ];
    }

    /**
     * Fraîcheur des scores de churn (risque de désabonnement) et de propension
     * (probabilité d'achat). Même logique que checkSegmentFreshness/checkClvFreshness :
     * si le cron comportemental plante avant d'appeler recomputeAll()/recalculateAll(),
     * les scores restent figés et affichent des alertes trompeuses sur les fiches client.
     */
    private function checkChurnPropensityFreshness(): array
    {
        $db = \Db::getInstance();

        $issues = [];

        if (class_exists('ChurnScoreManager')) {
            // Le recalcul quotidien peut légitimement ne mettre à jour
            // aucune ligne (boutique trop jeune : aucun client n'a encore
            // 30 jours d'historique passé à comparer, cf. ChurnScoreManager::
            // recomputeAll()) — dans ce cas computed_at des lignes
            // existantes ne bouge jamais alors que le cron tourne bien.
            // NERIA_CHURN_LAST_RUN, lui, est écrit à chaque exécution
            // quel que soit le nombre de lignes touchées : c'est le seul
            // repère fiable pour distinguer "rien à recalculer" d'un cron
            // réellement en échec.
            // Pas de repère du tout = cron jamais encore passé (install
            // récente) : rien à signaler, pas plus que l'ancien comportement
            // qui se taisait tant qu'aucune ligne n'existait.
            $lastRun = \Configuration::get('NERIA_CHURN_LAST_RUN', null, null, $this->idShop);
            if ($lastRun) {
                $ageChurnH = (time() - strtotime($lastRun)) / 3600;
                if ($ageChurnH > 48) {
                    $issues[] = AdminTranslator::tVars('health.churn_stale', ['ageH' => round($ageChurnH, 1)]);
                }
            }
        }

        if (class_exists('PropensityScoreManager')) {
            // Même raisonnement que pour NERIA_CHURN_LAST_RUN ci-dessus :
            // recalculateAll() peut légitimement ne toucher aucune ligne
            // (aucun client à commande valide pour l'instant) sans que le
            // cron ait échoué — NERIA_PROPENSITY_LAST_RUN est écrit à
            // chaque exécution, contrairement à date_upd des lignes.
            $lastPropRun = \Configuration::get('NERIA_PROPENSITY_LAST_RUN', null, null, $this->idShop);
            if ($lastPropRun) {
                $agePropH = (time() - strtotime($lastPropRun)) / 3600;
                if ($agePropH > 48) {
                    $issues[] = AdminTranslator::tVars('health.propensity_stale', ['ageH' => round($agePropH, 1)]);
                }
            }
        }

        if (!empty($issues)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => implode(' / ', $issues),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.churn_propensity_ok')];
    }

    /**
     * Vérifie que les règles actives "Collection" et "Complétez votre look"
     * référencent encore des produits existants et actifs. Un produit
     * supprimé/désactivé après la création de la règle la rend silencieusement
     * inopérante (elle reste affichée "active" en BO mais ne matche plus rien).
     */
    private function checkCollectionLookRulesProductValidity(): array
    {
        $db = \Db::getInstance();
        $broken = [];

        if ($this->tableExists('neria_collection')) {
            $rows = $db->executeS(
                'SELECT `id_neria_collection`, `name`, `product_ids` FROM `' . _DB_PREFIX_ . 'neria_collection` WHERE `active` = 1'
            );
            foreach ((array) $rows as $row) {
                $ids = json_decode((string) $row['product_ids'], true);
                if (!is_array($ids) || empty($ids)) {
                    continue;
                }
                $validCount = (int) $db->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`
                     WHERE `id_product` IN (' . implode(',', array_map('intval', $ids)) . ') AND `active` = 1'
                );
                if ($validCount < count($ids)) {
                    $broken[] = $row['name'] . ' (' . $validCount . '/' . count($ids) . ')';
                }
            }
        }

        if ($this->tableExists('neria_look_rule')) {
            $rows = $db->executeS(
                'SELECT `id_neria_look_rule`, `id_category`, `product_ids` FROM `' . _DB_PREFIX_ . 'neria_look_rule` WHERE `active` = 1'
            );
            foreach ((array) $rows as $row) {
                $ids = json_decode((string) $row['product_ids'], true);
                if (!is_array($ids) || empty($ids)) {
                    continue;
                }
                $validCount = (int) $db->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`
                     WHERE `id_product` IN (' . implode(',', array_map('intval', $ids)) . ') AND `active` = 1'
                );
                if ($validCount < count($ids)) {
                    $broken[] = 'look_rule#' . $row['id_neria_look_rule'] . ' (cat.' . $row['id_category'] . ', ' . $validCount . '/' . count($ids) . ')';
                }
            }
        }

        if (!empty($broken)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.collection_look_broken', [
                    'count' => count($broken),
                    'list'  => implode(', ', array_slice($broken, 0, 10)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.collection_look_ok')];
    }

    /**
     * Taux d'échec de la file d'attente comportementale (neria_queue) sur 30 jours.
     * Un email en 'failed' après MAX_ATTEMPTS n'est plus jamais retenté et
     * disparaît silencieusement — contrairement aux 'pending' déjà surveillés
     * par checkQueueBlocked/checkQueueOverflow.
     */
    private function checkQueueFailedRate(): array
    {
        if (!$this->tableExists('neria_queue')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.queue_failed_disabled')];
        }

        $db = \Db::getInstance();

        $sent30d = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_queue`
             WHERE `id_shop` = ' . $this->idShop . '
               AND `status` = \'sent\' AND `sent_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        // Round 118 : filtré sur send_at, pas created_at — même correctif
        // que QueueManager::getStats(). created_at est la date de MISE EN
        // FILE (un envoi peut être programmé des semaines à l'avance via
        // scheduleManual()), pas celle de l'échec réel ; sans colonne
        // "failed_at" dédiée, send_at (figé sur la dernière tentative
        // planifiée par markFailedOrRetry() juste avant l'échec final) en
        // est le meilleur proxy disponible. Avec created_at, une campagne
        // programmée à l'avance et en échec restait invisible dans
        // failed30d au moment de l'échec réel, masquant un vrai incident de
        // délivrabilité (statut OK affiché alors que le taux réel dépassait
        // les seuils WARNING/ERROR).
        $failed30d = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_queue`
             WHERE `id_shop` = ' . $this->idShop . '
               AND `status` = \'failed\' AND `send_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );

        $total = $sent30d + $failed30d;
        if ($total === 0) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.queue_failed_no_data')];
        }

        $rate = $failed30d / $total * 100;

        if ($rate > 30) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.queue_failed_critical', [
                    'rate' => round($rate, 1), 'failed' => $failed30d, 'total' => $total,
                ]),
            ];
        }

        if ($rate > 10) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.queue_failed_warning', [
                    'rate' => round($rate, 1), 'failed' => $failed30d, 'total' => $total,
                ]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.queue_failed_ok', ['rate' => round($rate, 1), 'total' => $total]),
        ];
    }

    /**
     * Vérifie que les configurations JSON critiques (paliers fidélité,
     * expéditeurs par langue) sont décodables. Un JSON corrompu (saisie BO
     * tronquée) fait retomber silencieusement le code sur des valeurs par
     * défaut — le marchand croit ses réglages personnalisés appliqués alors
     * que non, sans aucun signal.
     */
    private function checkJsonConfigIntegrity(): array
    {
        $broken = [];

        $tiersJson = (string) \Configuration::get('NERIA_LOYALTY_TIERS');
        if ($tiersJson !== '') {
            $decoded = json_decode($tiersJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $broken[] = 'NERIA_LOYALTY_TIERS';
            }
        }

        $sendersJson = (string) \Configuration::get('NERIA_SENDERS_JSON');
        if ($sendersJson !== '') {
            $decoded = json_decode($sendersJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $broken[] = 'NERIA_SENDERS_JSON';
            }
        }

        if (!empty($broken)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.json_config_broken', ['list' => implode(', ', $broken)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.json_config_ok')];
    }

    /**
     * Si OpenSSL/la clé de chiffrement est indisponible sur l'hébergement,
     * GdprAuditManager::auditEncryption() reste silencieusement à "0 problème"
     * même si des milliers de rendered_vars sont en clair — le score RGPD
     * affiche A/B alors que rien n'est réellement chiffré.
     */
    private function checkCryptoUnavailableWithPlainData(): array
    {
        $opensslOk = class_exists('CryptoManager') && \CryptoManager::isAvailable();

        if ($opensslOk) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.crypto_unavailable_ok')];
        }

        $plain = (int) \Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_stat`
             WHERE `id_shop` = {$this->idShop}
               AND `rendered_vars` IS NOT NULL AND `rendered_vars` != ''"
        );

        if ($plain > 0) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.crypto_unavailable_plain_data', ['count' => $plain]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.crypto_unavailable_ok')];
    }

    /**
     * Un test A/B actif doit toujours avoir exactement ses 2 variantes (A+B).
     * Si l'une est supprimée/désactivée séparément (bug ou manip BO directe),
     * le BO affiche juste "durée inconnue" sans jamais signaler l'incohérence.
     */
    private function checkAbtestVariantPairIntegrity(): array
    {
        if (!$this->tableExists('neria_abtest')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.abtest_pair_disabled')];
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT `id_shop`, `template`, COUNT(*) AS cnt
             FROM `' . _DB_PREFIX_ . 'neria_abtest`
             WHERE `is_active` = 1
             GROUP BY `id_shop`, `template`
             HAVING COUNT(*) <> 2'
        );

        if (!empty($rows)) {
            $list = implode(', ', array_map(
                static fn ($r) => $r['template'] . ' (' . $r['cnt'] . ' variante(s))',
                (array) $rows
            ));
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.abtest_pair_broken', ['list' => $list]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.abtest_pair_ok')];
    }

    /**
     * Un CartRule::add() peut renvoyer true alors que le bon créé est en
     * réalité inutilisable (contrainte DB tardive, code dupliqué) — le bon
     * de palier de commandes garde alors un id_cart_rule "valide" mais mort.
     */
    private function checkMilestoneVoucherCartRuleValidity(): array
    {
        if (!$this->tableExists('neria_milestone_voucher')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.milestone_voucher_cartrule_disabled')];
        }

        $db = \Db::getInstance();
        $rows = $db->executeS(
            'SELECT `id_voucher`, `id_cart_rule` FROM `' . _DB_PREFIX_ . 'neria_milestone_voucher`
             WHERE `id_cart_rule` > 0
             AND `created_at` >= DATE_SUB(NOW(), INTERVAL 90 DAY)'
        );

        $dead = [];
        foreach ((array) $rows as $row) {
            $exists = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_rule` WHERE `id_cart_rule` = ' . (int) $row['id_cart_rule'] . ' AND `active` = 1'
            );
            if ($exists === 0) {
                $dead[] = $row['id_voucher'] . ' (cart_rule#' . $row['id_cart_rule'] . ')';
            }
        }

        if (!empty($dead)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.milestone_voucher_cartrule_broken', [
                    'count' => count($dead), 'list' => implode(', ', array_slice($dead, 0, 10)),
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.milestone_voucher_cartrule_ok')];
    }

    /**
     * CssInliner::inline() avale silencieusement toute exception DOMDocument
     * et renvoie le HTML non inliné — l'email part "avec succès" mais peut
     * être illisible sur Gmail/Orange/Yahoo. Le compteur Configuration
     * (incrémenté par CssInliner) est repris ici puis remis à zéro.
     */
    private function checkCssInlinerSilentFailures(): array
    {
        // Round 160 : clé scopée par boutique (idShop), cohérent avec
        // l'écriture désormais scopée dans CssInliner::inline() —
        // auparavant la clé globale non suffixée n'était plus jamais
        // relue par cette méthode.
        $key   = 'NERIA_CSS_INLINE_FAILURES_' . $this->idShop;
        $count = (int) \Configuration::get($key);

        if ($count > 0) {
            \Configuration::updateValue($key, 0);
            return [
                'status'     => self::STATUS_WARNING,
                'detail'     => AdminTranslator::tVars('health.css_inliner_failures_warning', ['count' => $count]),
                'auto_fixed' => true,
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.css_inliner_failures_ok')];
    }

    /**
     * checkCryptoKeyHealth() ne prouve que la clé courante peut chiffrer/
     * déchiffrer une valeur FRAÎCHE — pas que les secrets DÉJÀ STOCKÉS
     * (mot de passe IMAP bounce, secrets webhook/OAuth, clé DeepL) restent
     * déchiffrables avec elle. Si la clé a été régénérée/écrasée (restauration
     * DB partielle, manipulation manuelle), CryptoManager::decrypt() renvoie
     * silencieusement '' pour chaque secret — le marchand voit "configuration
     * incomplète" et croit n'avoir jamais rempli le champ.
     */
    private function checkStoredSecretsDecryptable(): array
    {
        if (!class_exists('CryptoManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.stored_secrets_ok')];
        }

        $broken = [];
        foreach (\CryptoManager::SENSITIVE_CONFIG_KEYS as $key) {
            $stored = (string) \Configuration::get($key);
            if ($stored === '' || !\CryptoManager::isEncrypted($stored)) {
                continue;
            }
            $decrypted = \CryptoManager::decrypt($stored);
            if ($decrypted === '') {
                $broken[] = $key;
            }
        }

        if (!empty($broken)) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.stored_secrets_broken', ['list' => implode(', ', $broken)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.stored_secrets_ok')];
    }

    /**
     * checkStoredSecretsDecryptable() ne couvre que les ~15 clés de
     * CryptoManager::SENSITIVE_CONFIG_KEYS — pas le volume, bien plus
     * important, de snapshots chiffrés dans `neria_stat.rendered_vars`
     * (un par email envoyé, lu par l'historique client/audit RGPD/stats).
     * Trouvé en réel le 2026-07-30 : CryptoManager::decrypt() renvoyait ''
     * en silence sur tout échec (clé rotée/corrompue, tag GCM invalide) —
     * indiscernable d'un snapshot simplement absent. Un journal a été
     * ajouté dans decrypt() lui-même, mais ce contrôle vérifie activement
     * un échantillon de données RÉELLEMENT stockées (pas une simple sonde
     * fraîche comme checkCryptoKeyHealth) pour détecter le problème avant
     * qu'un marchand ne le découvre par hasard dans l'historique client.
     */
    private function checkStatsSnapshotDecryptable(): array
    {
        if (!class_exists('CryptoManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.stats_snapshot_ok')];
        }

        $table = _DB_PREFIX_ . 'neria_stat';
        $rows = $this->db->executeS(
            'SELECT `rendered_vars` FROM `' . $table . '`
             WHERE `rendered_vars` IS NOT NULL AND `rendered_vars` != \'\'
             ORDER BY `id_stat` DESC LIMIT 20'
        );
        if (!is_array($rows) || empty($rows)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.stats_snapshot_ok')];
        }

        $checked = 0;
        $broken  = 0;
        foreach ($rows as $row) {
            $value = (string) ($row['rendered_vars'] ?? '');
            if (!\CryptoManager::isEncrypted($value)) {
                continue; // donnée pré-chiffrement, non concernée
            }
            $checked++;
            if (\CryptoManager::decrypt($value) === '') {
                $broken++;
            }
        }

        if ($checked === 0) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.stats_snapshot_ok')];
        }

        if ($broken > 0) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::tVars('health.stats_snapshot_broken', [
                    'broken' => $broken, 'checked' => $checked,
                ]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.stats_snapshot_ok')];
    }

    /**
     * CalendarManager::loadCalendarDates() fait un array_merge($builtIn, $data)
     * — pas de fusion profonde. Si data/calendar.json contient une entrée mal
     * formée pour une clé déjà connue (ex. 'eid' sans 'dates' suite à une
     * édition manuelle ratée), elle remplace ENTIÈREMENT les dates de secours
     * intégrées pour cette occasion, sans qu'aucun log ne signale la perte —
     * le seul symptôme visible arrive bien plus tard, sans lien évident.
     */
    private function checkCalendarJsonIntegrity(): array
    {
        if (!class_exists('CalendarManager')) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.calendar_json_ok')];
        }

        $jsonPath = rtrim($this->module->getLocalPath(), '/') . '/data/calendar.json';
        if (!is_file($jsonPath)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.calendar_json_ok')];
        }

        $raw = trim((string) file_get_contents($jsonPath));
        if ($raw === '') {
            // Fichier vide = jamais utilisé par le marchand, pas corrompu —
            // CalendarManager l'ignore silencieusement et garde les dates
            // intégrées, sans aucun dommage. Ne pas confondre avec un JSON
            // réellement invalide (contenu non vide mais mal formé).
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.calendar_json_ok')];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['status' => self::STATUS_WARNING, 'detail' => AdminTranslator::t('health.calendar_json_invalid')];
        }

        $r = new \ReflectionClass('CalendarManager');
        $method = $r->getMethod('getBuiltInDates');
        $method->setAccessible(true);
        $builtIn = $method->invoke($r->newInstanceWithoutConstructor());

        $broken = [];
        foreach ($data as $key => $entry) {
            if (!isset($builtIn[$key]) || !is_array($entry)) {
                continue;
            }
            $hasRecurring = isset($entry['recurring']['month'], $entry['recurring']['day']);
            $hasDates     = isset($entry['dates']) && is_array($entry['dates']) && !empty($entry['dates']);
            if (!$hasRecurring && !$hasDates) {
                $broken[] = (string) $key;
            }
        }

        if (!empty($broken)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.calendar_json_broken', ['list' => implode(', ', $broken)]),
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.calendar_json_ok')];
    }

    /**
     * Checklist de première installation — 2026-07-21.
     *
     * Suite au passage de Certificat/Upsell/Fidélité en actifs par défaut
     * (v1.0.30), un marchand qui n'a jamais ouvert le module peut se
     * retrouver à envoyer de vrais emails avec un contenu 100% générique
     * (certificat en français neutre, paliers de fidélité par défaut sans
     * rapport avec ses marges réelles) sans même le savoir. Ce contrôle
     * n'est PAS un garde-fou de régression (il ne détecte pas un bug de
     * code) : c'est un rappel informatif qui ne dégrade jamais le score
     * de santé en continu — il s'efface de lui-même dès que le marchand
     * personnalise le réglage concerné, OU après la fenêtre d'onboarding
     * de 30 jours (au-delà, on cesse de le rappeler pour ne pas polluer
     * un diagnostic par ailleurs propre sur une install ancienne qui a
     * délibérément gardé les valeurs par défaut).
     */
    private function checkFirstInstallChecklist(): array
    {
        $installedAt = (string) \Configuration::get('NERIA_INSTALLED_AT');
        $daysOld     = $installedAt ? (time() - (int) strtotime($installedAt)) / 86400 : 9999;

        if ($daysOld > 30) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.first_install_past_window')];
        }

        $pending = [];

        if ((bool) \Configuration::get('NERIA_CERT_ENABLED')
            && class_exists('CertificateManager')
            && \Configuration::get(\CertificateManager::CFG_TITLE) === false
            && \Configuration::get(\CertificateManager::CFG_SUBTITLE) === false
            && \Configuration::get(\CertificateManager::CFG_BODY) === false
        ) {
            $pending[] = AdminTranslator::t('health.first_install_cert_generic');
        }

        if ((bool) \Configuration::get('NERIA_LOYALTY_ENABLED')
            && class_exists('LoyaltyManager')
            && \Configuration::get(\LoyaltyManager::CONFIG_TIERS) === false
        ) {
            $pending[] = AdminTranslator::t('health.first_install_loyalty_default_tiers');
        }

        if (empty($pending)) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.first_install_all_customized')];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.first_install_pending', ['list' => implode(' | ', $pending)]),
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

    /**
     * #68 — Tous les crons email désactivés simultanément
     * Si le marchand désactive par erreur tous les toggles depuis l'onglet
     * Automatisations, plus aucun email comportemental n'est envoyé et
     * aucun autre mécanisme ne le signale.
     */
    private function checkAllEmailCronsDisabled(): array
    {
        $emailCronKeys = [
            'NERIA_BIRTHDAY_ENABLED', 'NERIA_FIRST_ANNIVERSARY_ENABLED',
            'NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED', 'NERIA_REORDER_ENABLED',
            'NERIA_WIN_BACK_ENABLED', 'NERIA_REWARD_EXPIRY_ENABLED',
            'NERIA_WISHLIST_ENABLED', 'NERIA_ABANDONED_CART_ENABLED',
            'NERIA_CHECKOUT_ABANDONMENT_ENABLED', 'NERIA_POST_PURCHASE_ENABLED',
            'NERIA_SHIPPED_DELAY_ENABLED', 'NERIA_GHOST_CART_ENABLED',
            'NERIA_QUOTE_REMINDERS_ENABLED', 'NERIA_REFUND_RECONCILIATION_ENABLED',
            'NERIA_LIFESPAN_ENABLED', 'NERIA_COLLECTION_COMPLETION_ENABLED',
            'NERIA_LOOK_COMPLETION_ENABLED', 'NERIA_PURCHASE_WINDOW_ENABLED',
        ];

        $activeCount = 0;
        foreach ($emailCronKeys as $key) {
            if ((bool) \Configuration::getGlobalValue($key)) {
                $activeCount++;
            }
        }

        if ($activeCount === 0) {
            return [
                'status' => self::STATUS_ERROR,
                'detail' => AdminTranslator::t('health.all_email_crons_disabled_error'),
            ];
        }

        $total = count($emailCronKeys);
        if ($activeCount < (int) round($total * 0.5)) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.all_email_crons_disabled_warning', [
                    'active' => $activeCount,
                    'total'  => $total,
                ]),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'detail' => AdminTranslator::tVars('health.all_email_crons_disabled_ok', [
                'active' => $activeCount,
                'total'  => $total,
            ]),
        ];
    }

    /**
     * #69 — Silence comportemental anormal
     * Si le cron tourne (CRON_LAST_BEHAVIORAL récent) mais qu'aucun email
     * comportemental n'a été envoyé depuis 7 jours alors que la boutique a
     * des commandes récentes, c'est le signe d'un problème de configuration
     * ou d'éligibilité systématiquement nulle (base de clients trop petite,
     * conditions trop restrictives, cooldown trop long…).
     * On ne déclenche le WARNING que si la boutique a ≥10 clients actifs
     * pour éviter les faux positifs sur les boutiques vides ou en test.
     */
    private function checkBehavioralSilence(): array
    {
        $db     = \Db::getInstance();
        $prefix = _DB_PREFIX_;

        // Le cron est-il passé récemment (7 derniers jours) ?
        $lastRun = (string) \Configuration::getGlobalValue(self::CRON_LAST_BEHAVIORAL);
        if (!$lastRun || (time() - (int) strtotime($lastRun)) > 7 * 86400) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.behavioral_silence_cron_not_run')];
        }

        // Combien d'emails comportementaux dans les 7 derniers jours ?
        $sent7d = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . $prefix . 'neria_behavioral_sent`
             WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );

        if ($sent7d > 0) {
            return [
                'status' => self::STATUS_OK,
                'detail' => AdminTranslator::tVars('health.behavioral_silence_ok', ['count' => $sent7d]),
            ];
        }

        // 0 envoi — vérifier si la boutique a suffisamment de clients pour que ce soit anormal
        $activeCustomers = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . $prefix . 'customer` WHERE active = 1 AND deleted = 0'
        );

        if ($activeCustomers < 10) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.behavioral_silence_few_customers')];
        }

        // Vérifier si au moins un cron email est actif
        $atLeastOneActive = (bool) \Configuration::getGlobalValue('NERIA_BIRTHDAY_ENABLED')
            || (bool) \Configuration::getGlobalValue('NERIA_REORDER_ENABLED')
            || (bool) \Configuration::getGlobalValue('NERIA_WIN_BACK_ENABLED')
            || (bool) \Configuration::getGlobalValue('NERIA_ABANDONED_CART_ENABLED');

        if (!$atLeastOneActive) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.behavioral_silence_all_off')];
        }

        return [
            'status' => self::STATUS_WARNING,
            'detail' => AdminTranslator::tVars('health.behavioral_silence_warning', ['customers' => $activeCustomers]),
        ];
    }
}
