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
     * Lance tous les contrôles automatiques si 24 h se sont écoulées.
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
            'class_override'       => $this->checkClassOverride(),
            'smarty_compile_check' => $this->checkSmartyCompileCheck(),
            'upgrade_script_safety' => $this->checkUpgradeScriptSafety(),
            'known_regressions_guard' => $this->checkKnownRegressionsGuard(),
            'txt_placeholder_coverage' => $this->checkTxtPlaceholderCoverage(),
            'orphaned_voucher_reservations' => $this->checkOrphanedVoucherReservations(),
            'encoded_residual_links' => $this->checkEncodedResidualLinks(),
            'crypto_key_health' => $this->checkCryptoKeyHealth(),
            'html_txt_pairs' => $this->checkHtmlTxtPairs(),
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
            'template_staleness'   => $this->checkTemplateStaleness(),
            // ── Régression rendu (issus du test exhaustif 2026-07-07) ──
            'blacklist_stale_files'  => $this->checkBlacklistStaleFiles(),
            'residual_vars_recent'   => $this->checkRecentResidualVarsWarnings(),
            'sig_social_recent'      => $this->checkSignatureSocialRenderedRecently(),
            'action_banner_coverage' => $this->checkActionBannerCoverage(),
            'orphan_placeholders'    => $this->checkOrphanPlaceholders(),
            'render_canary_recent'   => $this->checkRenderCanaryRecent(),
            'milestone_order_health' => $this->checkMilestoneOrderHealth(),
            'custom_vars_completeness' => $this->checkCustomVarsCompleteness(),
            // ── Ajouts 2026-07-16 (scan de couverture Watchdog) ────────
            'churn_propensity_freshness'  => $this->checkChurnPropensityFreshness(),
            'collection_look_products'    => $this->checkCollectionLookRulesProductValidity(),
            'queue_failed_rate'           => $this->checkQueueFailedRate(),
            // ── Ajouts 2026-07-16 (2e passage de scan Watchdog) ────────
            'json_config_integrity'       => $this->checkJsonConfigIntegrity(),
            'crypto_unavailable_plain'    => $this->checkCryptoUnavailableWithPlainData(),
            'abtest_variant_pair'         => $this->checkAbtestVariantPairIntegrity(),
            'milestone_voucher_cartrule'  => $this->checkMilestoneVoucherCartRuleValidity(),
            'css_inliner_failures'        => $this->checkCssInlinerSilentFailures(),
            // ── Ajouts 2026-07-16 (3e passage de scan Watchdog) ────────
            'stored_secrets_decryptable'  => $this->checkStoredSecretsDecryptable(),
            'calendar_json_integrity'     => $this->checkCalendarJsonIntegrity(),
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
            $neriaClassFiles = array_map(
                fn (string $p): string => basename($p),
                glob(__DIR__ . '/*.php') ?: []
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
     * Garde-fou statique contre 3 régressions précises trouvées le 2026-07-14
     * via un rapport de test externe (Claude Cowork) — un canari dynamique
     * s'est révélé trop bruyant (variables métier légitimement absentes des
     * données factices d'aperçu, cf. mémoire), donc contrôle ciblé sur le
     * code source plutôt qu'un scan générique de résidus :
     *  1. La boucle de substitution d'EmailRenderer ne doit plus exclure les
     *     valeurs vides ($value !== '') — sinon {subject}/{custom_message}
     *     redeviennent invisibles dès qu'ils sont légitimement vides (le cas
     *     le plus courant).
     *  2. {products_txt} doit rester fourni par sendAbandonedCarts().
     *  3. ManualSendManager doit continuer à interroger ps_log pour la vraie
     *     cause d'échec plutôt que d'afficher le message générique masquant.
     */
    private function checkKnownRegressionsGuard(): array
    {
        $offenders = [];

        $rendererFile = _PS_MODULE_DIR_ . $this->module->name . '/src/EmailRenderer.php';
        $rendererSrc  = is_file($rendererFile) ? (file_get_contents($rendererFile) ?: '') : '';
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
        $cronSrc  = is_file($cronFile) ? (file_get_contents($cronFile) ?: '') : '';
        if ($cronSrc === '' || strpos($cronSrc, '{products_txt}') === false) {
            $offenders[] = 'BehavioralCronManager : {products_txt} n\'est plus fourni sur les paniers abandonnés';
        }

        $manualFile = _PS_MODULE_DIR_ . $this->module->name . '/src/ManualSendManager.php';
        $manualSrc  = is_file($manualFile) ? (file_get_contents($manualFile) ?: '') : '';
        if ($manualSrc === '' || strpos($manualSrc, "FROM `' . _DB_PREFIX_ . 'log`") === false) {
            $offenders[] = 'ManualSendManager : ne recherche plus la vraie cause d\'échec dans ps_log';
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

        if ($fixed > 0) {
            return [
                'status' => self::STATUS_WARNING,
                'detail' => AdminTranslator::tVars('health.orphaned_vouchers_fixed', ['count' => $fixed]),
                'auto_fixed' => true,
            ];
        }

        return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.orphaned_vouchers_ok')];
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

        // Clients en attente non notifiés depuis plus de 48h,
        // dont le produit est actuellement en stock (quantity > 0)
        $backlogProducts = $this->db->executeS(
            "SELECT DISTINCT w.id_product, w.id_shop FROM `{$table}` w
             JOIN `" . _DB_PREFIX_ . "stock_available` s
                  ON s.id_product = w.id_product AND s.id_product_attribute = 0
             WHERE w.id_shop = {$this->idShop}
               AND w.notified_at IS NULL
               AND w.registered_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
               AND s.quantity > 0"
        ) ?: [];

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
        ');

        $lastCalc = $count > 0 ? $db->getValue('
            SELECT MAX(computed_at) FROM `' . _DB_PREFIX_ . 'neria_churn_score`
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
            'trad_key_usage'    => $this->checkTradKeyUsage(),
            'class_references'  => $this->checkClassReferencesIntegrity(),
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

        $missing = [];

        // Clés spécifiques à chaque template
        foreach ($this->globRecursive($coreDir, '.html') as $file) {
            $tpl  = basename($file, '.html');
            $keys = $extractKeys((string) file_get_contents($file));
            foreach ($keys as $key) {
                if (!$keyResolvable($dict[$tpl] ?? null, $key)) {
                    $missing[$tpl . ':' . $key] = $tpl;
                }
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
                }
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
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'neria_signature` WHERE `is_active` = 1'
        );
        $socialLinks  = method_exists($config, 'getSocialLinks') ? $config->getSocialLinks() : [];
        $checkSocial  = !empty($socialLinks);

        if (!$checkSignature && !$checkSocial) {
            return ['status' => self::STATUS_OK, 'detail' => AdminTranslator::t('health.sig_social_recent_na')];
        }

        $sigFile = '';
        if ($checkSignature) {
            $sigRow = $this->db->getRow(
                'SELECT `image_path` FROM `' . _DB_PREFIX_ . 'neria_signature` WHERE `is_active` = 1'
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
            $countChurn = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_churn_score` WHERE `id_shop` = ' . $this->idShop
            );
            if ($countChurn > 0) {
                $lastChurn = $db->getValue(
                    'SELECT MAX(`computed_at`) FROM `' . _DB_PREFIX_ . 'neria_churn_score` WHERE `id_shop` = ' . $this->idShop
                );
                $ageChurnH = $lastChurn ? (time() - strtotime($lastChurn)) / 3600 : 9999;
                if ($ageChurnH > 48) {
                    $issues[] = AdminTranslator::tVars('health.churn_stale', ['ageH' => round($ageChurnH, 1)]);
                }
            }
        }

        if (class_exists('PropensityScoreManager')) {
            $countProp = (int) $db->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_propensity_score` WHERE `id_shop` = ' . $this->idShop
            );
            if ($countProp > 0) {
                $lastProp = $db->getValue(
                    'SELECT MAX(`date_upd`) FROM `' . _DB_PREFIX_ . 'neria_propensity_score` WHERE `id_shop` = ' . $this->idShop
                );
                $agePropH = $lastProp ? (time() - strtotime($lastProp)) / 3600 : 9999;
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
        $failed30d = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neria_queue`
             WHERE `id_shop` = ' . $this->idShop . '
               AND `status` = \'failed\' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
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
        $count = (int) \Configuration::get('NERIA_CSS_INLINE_FAILURES');

        if ($count > 0) {
            \Configuration::updateValue('NERIA_CSS_INLINE_FAILURES', 0);
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
