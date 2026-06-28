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
}
