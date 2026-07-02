<?php
/**
 * NERIA — WatchdogManager
 *
 * Système de surveillance et journal des erreurs du module.
 * Enregistre tous les événements dans ps_neria_log.
 * Accessible depuis l'onglet Aide du back-office.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class WatchdogManager
{
    const TABLE             = 'neria_log';
    const MAX_LOGS          = 500;
    const DEFAULT_RETENTION = 30; // jours

    const LEVEL_INFO     = 'info';
    const LEVEL_WARNING  = 'warning';
    const LEVEL_ERROR    = 'error';
    const LEVEL_CRITICAL = 'critical';

    // ── Monitoring des crons ───────────────────────────────────────
    const TABLE_CRON  = 'neria_cron_health';
    const KNOWN_CRONS = [
        'behavioral' => ['label' => 'Cron comportemental',    'threshold_hours' => 25],
        'calendar'   => ['label' => 'Cron calendaire',        'threshold_hours' => 25],
        'webhook'    => ['label' => 'Queue webhook',           'threshold_hours' => 2],
    ];

    // ── Alertes email ──────────────────────────────────────────────
    const CFG_ALERT_EMAIL     = 'NERIA_ALERT_EMAIL';
    const CFG_ALERT_IMMEDIATE = 'NERIA_ALERT_IMMEDIATE_ENABLED';
    const CFG_ALERT_DIGEST    = 'NERIA_ALERT_DIGEST_ENABLED';
    const CFG_ALERT_LAST_SENT = 'NERIA_ALERT_LAST_SENT';
    const CFG_DIGEST_LAST     = 'NERIA_DIGEST_LAST_SENT';
    const ALERT_THROTTLE      = 3600; // 1 alerte max par heure

    private Neria $module;
    private \Db   $db;
    private int   $idShop;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    // ============================================================
    // ENREGISTREMENT
    // ============================================================

    /**
     * Encode a translation key + vars so the watchdog can translate at display
     * time instead of at log time. Pass the result as the $message argument.
     * Format stored: ::i18n::{"k":"watchdog.foo","v":{"month":"..."}}
     */
    public static function i18nMsg(string $key, array $vars = []): string
    {
        return '::i18n::' . json_encode(['k' => $key, 'v' => $vars], JSON_UNESCAPED_UNICODE);
    }

    public function info(string $message, string $template = '', string $class = '', array $context = []): void
    {
        $this->record(self::LEVEL_INFO, $message, $template, $class, $context);
    }

    public function warning(string $message, string $template = '', string $class = '', array $context = []): void
    {
        $this->record(self::LEVEL_WARNING, $message, $template, $class, $context);
    }

    public function error(string $message, string $template = '', string $class = '', array $context = []): void
    {
        $this->record(self::LEVEL_ERROR, $message, $template, $class, $context);
        \PrestaShopLogger::addLog('[Neria] ' . $message, 3, null, 'Neria', 0, true);
        $this->sendImmediateAlert(self::LEVEL_ERROR, $message, $template, $class);
    }

    public function critical(string $message, string $template = '', string $class = '', array $context = []): void
    {
        $this->record(self::LEVEL_CRITICAL, $message, $template, $class, $context);
        \PrestaShopLogger::addLog('[Neria CRITICAL] ' . $message, 4, null, 'Neria', 0, true);
        $this->sendImmediateAlert(self::LEVEL_CRITICAL, $message, $template, $class);
    }

    private function record(
        string $level,
        string $message,
        string $template,
        string $class,
        array  $context
    ): void {
        $table      = _DB_PREFIX_ . self::TABLE;
        $contextSql = !empty($context)
            ? "'" . pSQL(json_encode($context, JSON_UNESCAPED_UNICODE)) . "'"
            : 'NULL';

        // Consolidation : même message+class dans la dernière heure → incrémenter au lieu d'insérer
        $existing = (int) $this->db->getValue(sprintf(
            "SELECT `id_log` FROM `%s`
             WHERE `id_shop` = %d AND `level` = '%s' AND `class` = '%s'
               AND `message` = '%s'
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY `date_add` DESC",
            $table,
            $this->idShop,
            pSQL($level),
            pSQL($class),
            pSQL($message)
        ));

        if ($existing > 0) {
            $this->db->execute(
                "UPDATE `{$table}` SET `occurrence_count` = `occurrence_count` + 1
                 WHERE `id_log` = {$existing}"
            );
            return;
        }

        $this->db->execute(sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `level`, `template`, `class`, `message`, `context`, `occurrence_count`, `date_add`)
             VALUES (%d, '%s', '%s', '%s', '%s', %s, 1, '%s')",
            $table,
            $this->idShop,
            pSQL($level),
            pSQL($template),
            pSQL($class),
            pSQL($message),
            $contextSql,
            date('Y-m-d H:i:s')
        ));

        $this->pruneOldLogs();
    }

    // ============================================================
    // LECTURE — BACK-OFFICE
    // ============================================================

    public function getLogs(
        int    $limit    = 100,
        string $level    = '',
        string $template = ''
    ): array {
        $table       = _DB_PREFIX_ . self::TABLE;
        $levelFilter = $level    ? "AND `level` = '" . pSQL($level) . "'"       : '';
        $tplFilter   = $template ? "AND `template` = '" . pSQL($template) . "'" : '';

        $rows = $this->db->executeS(
            "SELECT *
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
               {$levelFilter}
               {$tplFilter}
             ORDER BY `date_add` DESC
             LIMIT {$limit}"
        );

        return is_array($rows) ? $rows : [];
    }

    public function getCountByLevel(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT `level`, COUNT(*) AS total
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
             GROUP BY `level`"
        );

        $counts = [
            'info'     => 0,
            'warning'  => 0,
            'error'    => 0,
            'critical' => 0,
        ];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $counts[$row['level']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function getTemplatesWithErrors(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(
            "SELECT DISTINCT `template`
             FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `template` != ''
               AND `level`    IN ('warning', 'error', 'critical')
             ORDER BY `template` ASC"
        );

        return is_array($rows) ? array_column($rows, 'template') : [];
    }

    // ============================================================
    // ALERTES EMAIL
    // ============================================================

    /**
     * Alerte immédiate sur ERROR/CRITICAL — throttlée à 1/heure.
     * Utilise mail() natif pour fonctionner même si PS Mail::Send est cassé.
     */
    private function sendImmediateAlert(string $level, string $message, string $template, string $class): void
    {
        if (!\Configuration::getGlobalValue(self::CFG_ALERT_IMMEDIATE)) {
            return;
        }

        $lastSent = (int) \Configuration::getGlobalValue(self::CFG_ALERT_LAST_SENT);
        if ((time() - $lastSent) < self::ALERT_THROTTLE) {
            return;
        }

        $email = $this->getAlertEmail();
        if ($email === '') {
            return;
        }

        \Configuration::updateGlobalValue(self::CFG_ALERT_LAST_SENT, time());

        $shopName   = (string) \Configuration::get('PS_SHOP_NAME');
        $shopDomain = \Tools::getShopDomainSsl(true);
        $levelUpper = strtoupper($level);
        $color      = $level === self::LEVEL_CRITICAL ? '#7a0000' : '#a32d2d';
        $subject    = '[Neria] Alerte ' . $levelUpper . ' — ' . $shopName;

        $emergencyToken = (string) \Configuration::getGlobalValue('NERIA_EMERGENCY_TOKEN');
        $emergencyUrl   = $emergencyToken
            ? $shopDomain . \__PS_BASE_URI__ . 'modules/neria/neria-emergency.php?token=' . urlencode($emergencyToken)
            : '';

        $cleanMsg = htmlspecialchars(strip_tags(str_replace(['::i18n::', '{"k":"', '"}'], '', $message)));

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;background:#f5f5f5;margin:0;padding:20px;">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0;">'
            . '<div style="background:' . $color . ';padding:20px 24px;">'
            . '<p style="color:#fff;margin:0;font-size:11px;letter-spacing:.1em;text-transform:uppercase;">Neria · ' . htmlspecialchars($shopName) . '</p>'
            . '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">Alerte ' . $levelUpper . '</h1>'
            . '</div>'
            . '<div style="padding:24px;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">'
            . '<tr><td style="padding:8px;color:#888;width:90px;vertical-align:top;">Niveau</td>'
            . '<td style="padding:8px;font-weight:700;color:' . $color . ';">' . $levelUpper . '</td></tr>'
            . '<tr style="background:#fafafa;"><td style="padding:8px;color:#888;vertical-align:top;">Classe</td>'
            . '<td style="padding:8px;">' . htmlspecialchars($class ?: '—') . '</td></tr>'
            . '<tr><td style="padding:8px;color:#888;vertical-align:top;">Template</td>'
            . '<td style="padding:8px;">' . htmlspecialchars($template ?: '—') . '</td></tr>'
            . '<tr style="background:#fafafa;"><td style="padding:8px;color:#888;vertical-align:top;">Message</td>'
            . '<td style="padding:8px;line-height:1.5;">' . $cleanMsg . '</td></tr>'
            . '<tr><td style="padding:8px;color:#888;">Date</td>'
            . '<td style="padding:8px;">' . date('d/m/Y H:i:s') . '</td></tr>'
            . '</table>'
            . ($emergencyUrl
                ? '<a href="' . htmlspecialchars($emergencyUrl) . '" style="display:inline-block;background:#b38b59;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">Ouvrir le journal d\'urgence</a>'
                : '')
            . '<p style="margin-top:20px;font-size:11px;color:#aaa;">Vous recevez cet email car les alertes Neria sont activées. Pour les désactiver : Neria → Aide → Alertes email.</p>'
            . '</div></div></body></html>';

        $fromEmail = (string) \Configuration::get('PS_SHOP_EMAIL') ?: 'noreply@' . parse_url($shopDomain, PHP_URL_HOST);
        $headers   = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                   . "From: Neria <" . $fromEmail . ">\r\n"
                   . "X-Mailer: Neria-WatchdogAlert/1.0\r\n";

        @mail($email, $subject, $body, $headers);
    }

    /**
     * Digest quotidien — résumé des WARNING/ERROR des 24 dernières heures.
     * Appelé quotidiennement depuis neria.php (hookDisplayHeader, throttlé).
     */
    public function sendDailyDigestIfDue(): void
    {
        if (!\Configuration::getGlobalValue(self::CFG_ALERT_DIGEST)) {
            return;
        }

        $lastDigest = (int) \Configuration::getGlobalValue(self::CFG_DIGEST_LAST);
        if ((time() - $lastDigest) < 86400) {
            return;
        }

        $table = _DB_PREFIX_ . self::TABLE;
        $rows  = $this->db->executeS(
            "SELECT `level`, `class`, `template`, `message`, `date_add`
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
               AND `level` IN ('warning','error','critical')
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY `date_add` DESC
             LIMIT 50"
        );

        if (empty($rows)) {
            \Configuration::updateGlobalValue(self::CFG_DIGEST_LAST, time());
            return;
        }

        $email = $this->getAlertEmail();
        if ($email === '') {
            return;
        }

        \Configuration::updateGlobalValue(self::CFG_DIGEST_LAST, time());

        $shopName   = (string) \Configuration::get('PS_SHOP_NAME');
        $shopDomain = \Tools::getShopDomainSsl(true);
        $counts     = ['warning' => 0, 'error' => 0, 'critical' => 0];
        foreach ($rows as $r) {
            if (isset($counts[$r['level']])) {
                $counts[$r['level']]++;
            }
        }

        $subject = '[Neria] Digest quotidien — ' . count($rows) . ' événement(s) — ' . $shopName;

        $emergencyToken = (string) \Configuration::getGlobalValue('NERIA_EMERGENCY_TOKEN');
        $emergencyUrl   = $emergencyToken
            ? $shopDomain . \__PS_BASE_URI__ . 'modules/neria/neria-emergency.php?token=' . urlencode($emergencyToken)
            : '';

        $rows_html = '';
        foreach ($rows as $r) {
            $color  = $r['level'] === 'critical' ? '#7a0000' : ($r['level'] === 'error' ? '#a32d2d' : '#ba7517');
            $cleanM = htmlspecialchars(strip_tags(str_replace(['::i18n::', '{"k":"', '"}'], '', $r['message'])));
            $rows_html .= '<tr>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #f0f0f0;white-space:nowrap;font-size:11px;color:#888;">' . htmlspecialchars(substr($r['date_add'], 0, 16)) . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #f0f0f0;"><span style="background:' . $color . ';color:#fff;padding:2px 6px;border-radius:3px;font-size:10px;font-weight:700;">' . strtoupper($r['level']) . '</span></td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #f0f0f0;font-size:12px;color:#555;">' . htmlspecialchars($r['class'] ?: '—') . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid #f0f0f0;font-size:12px;max-width:300px;">' . $cleanM . '</td>'
                . '</tr>';
        }

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;background:#f5f5f5;margin:0;padding:20px;">'
            . '<div style="max-width:700px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0;">'
            . '<div style="background:#1a1a2e;padding:20px 24px;">'
            . '<p style="color:#b38b59;margin:0;font-size:11px;letter-spacing:.1em;text-transform:uppercase;">Neria · ' . htmlspecialchars($shopName) . '</p>'
            . '<h1 style="color:#fff;margin:8px 0 0;font-size:18px;">Digest quotidien Watchdog</h1>'
            . '<p style="color:#aaa;margin:6px 0 0;font-size:12px;">' . date('d/m/Y') . ' — Dernières 24h</p>'
            . '</div>'
            . '<div style="padding:20px 24px;">'
            . '<div style="display:flex;gap:16px;margin-bottom:20px;">'
            . '<div style="flex:1;background:#fff8e6;border:1px solid #ffe082;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#ba7517;">' . $counts['warning'] . '</div><div style="font-size:11px;color:#888;margin-top:4px;">WARNING</div></div>'
            . '<div style="flex:1;background:#ffebee;border:1px solid #ffcdd2;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#a32d2d;">' . $counts['error'] . '</div><div style="font-size:11px;color:#888;margin-top:4px;">ERROR</div></div>'
            . '<div style="flex:1;background:#f8f0f0;border:1px solid #f5c0c0;border-radius:6px;padding:12px;text-align:center;"><div style="font-size:24px;font-weight:700;color:#7a0000;">' . $counts['critical'] . '</div><div style="font-size:11px;color:#888;margin-top:4px;">CRITICAL</div></div>'
            . '</div>'
            . '<div style="overflow-x:auto;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
            . '<thead><tr style="background:#f5f5f5;">'
            . '<th style="padding:8px 10px;text-align:left;font-size:11px;color:#555;font-weight:600;">Date</th>'
            . '<th style="padding:8px 10px;text-align:left;font-size:11px;color:#555;font-weight:600;">Niveau</th>'
            . '<th style="padding:8px 10px;text-align:left;font-size:11px;color:#555;font-weight:600;">Classe</th>'
            . '<th style="padding:8px 10px;text-align:left;font-size:11px;color:#555;font-weight:600;">Message</th>'
            . '</tr></thead><tbody>' . $rows_html . '</tbody></table>'
            . '</div>'
            . ($emergencyUrl
                ? '<div style="margin-top:20px;"><a href="' . htmlspecialchars($emergencyUrl) . '" style="display:inline-block;background:#b38b59;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">Ouvrir le journal d\'urgence</a></div>'
                : '')
            . '<p style="margin-top:20px;font-size:11px;color:#aaa;">Digest Neria — envoyé automatiquement chaque jour si des événements WARNING/ERROR ont eu lieu. Pour désactiver : Neria → Aide → Alertes email.</p>'
            . '</div></div></body></html>';

        $fromEmail = (string) \Configuration::get('PS_SHOP_EMAIL') ?: 'noreply@' . parse_url($shopDomain, PHP_URL_HOST);
        $headers   = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                   . "From: Neria <" . $fromEmail . ">\r\n"
                   . "X-Mailer: Neria-WatchdogDigest/1.0\r\n";

        @mail($email, $subject, $body, $headers);
    }

    private function getAlertEmail(): string
    {
        $email = (string) \Configuration::getGlobalValue(self::CFG_ALERT_EMAIL);
        if ($email !== '' && \Validate::isEmail($email)) {
            return $email;
        }
        $shopEmail = (string) \Configuration::get('PS_SHOP_EMAIL');
        return \Validate::isEmail($shopEmail) ? $shopEmail : '';
    }

    // ============================================================
    // MAINTENANCE
    // ============================================================

    public function clearLogs(): bool
    {
        $table = _DB_PREFIX_ . self::TABLE;
        return $this->db->execute(
            "DELETE FROM `{$table}` WHERE `id_shop` = {$this->idShop}"
        ) !== false;
    }

    private function pruneOldLogs(): void
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $this->db->execute(
            "DELETE FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `date_add` < DATE_SUB(NOW(), INTERVAL " . self::DEFAULT_RETENTION . " DAY)"
        );

        $count = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `id_shop` = {$this->idShop}"
        );

        if ($count > self::MAX_LOGS) {
            $toDelete = $count - self::MAX_LOGS;
            $this->db->execute(
                "DELETE FROM `{$table}`
                 WHERE `id_shop` = {$this->idShop}
                 ORDER BY `date_add` ASC
                 LIMIT {$toDelete}"
            );
        }
    }

    // ============================================================
    // FEATURE 1 — MONITORING DES CRONS
    // ============================================================

    /**
     * Enregistre l'exécution d'un cron. Appeler en fin de tâche.
     * $status : 'ok' | 'warning' | 'error'
     * $count  : nombre d'éléments traités (emails envoyés, lignes nettoyées…)
     */
    public function cronHeartbeat(string $cronKey, string $status = 'ok', int $count = 0): void
    {
        $table = _DB_PREFIX_ . self::TABLE_CRON;
        $this->db->execute(sprintf(
            "INSERT INTO `%s` (`id_shop`, `cron_key`, `last_run`, `last_status`, `last_count`)
             VALUES (%d, '%s', '%s', '%s', %d)
             ON DUPLICATE KEY UPDATE
               `last_run`    = VALUES(`last_run`),
               `last_status` = VALUES(`last_status`),
               `last_count`  = VALUES(`last_count`)",
            $table,
            $this->idShop,
            pSQL($cronKey),
            date('Y-m-d H:i:s'),
            pSQL($status),
            $count
        ));
    }

    /**
     * Retourne l'état de chaque cron connu.
     * Indique si le cron est en retard (> seuil par cron).
     */
    public function getCronHealth(): array
    {
        $table = _DB_PREFIX_ . self::TABLE_CRON;
        $rows  = $this->db->executeS(
            "SELECT * FROM `{$table}` WHERE `id_shop` = {$this->idShop}"
        );

        $byKey = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $byKey[$r['cron_key']] = $r;
            }
        }

        $result = [];
        foreach (self::KNOWN_CRONS as $key => $cfg) {
            $row       = $byKey[$key] ?? null;
            $lastRun   = $row ? $row['last_run']    : null;
            $lastStatus= $row ? $row['last_status'] : 'unknown';
            $lastCount = $row ? (int) $row['last_count'] : 0;
            $threshold = ($cfg['threshold_hours'] ?? 25) * 3600;
            $age       = $lastRun ? (time() - strtotime($lastRun)) : null;
            $isLate    = ($age === null || $age > $threshold);

            $result[$key] = [
                'label'       => $cfg['label'],
                'last_run'    => $lastRun,
                'last_count'  => $lastCount,
                'last_status' => $lastStatus,
                'age_minutes' => $age !== null ? (int) round($age / 60) : null,
                'is_late'     => $isLate,
                'status'      => $isLate ? 'late' : ($lastStatus === 'error' ? 'error' : 'ok'),
            ];
        }

        return $result;
    }

    // ============================================================
    // FEATURE 2 — MONITORING DE LA QUEUE
    // ============================================================

    /**
     * Retourne l'état de la file d'attente ps_neria_queue.
     * Détecte les emails bloqués (pending > 2h) et les échecs.
     */
    public function getQueueHealth(): array
    {
        $table  = _DB_PREFIX_ . 'neria_queue';
        $exists = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
        );

        if (!$exists) {
            return ['exists' => false, 'status' => 'ok', 'stuck' => 0, 'failed' => 0, 'total_pending' => 0];
        }

        $stuck = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `status` = 'pending'
               AND `send_at` < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
        );

        $failed = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'failed'"
        );

        $totalPending = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'pending'"
        );

        return [
            'exists'        => true,
            'stuck'         => $stuck,
            'failed'        => $failed,
            'total_pending' => $totalPending,
            'status'        => ($stuck > 0 || $failed > 5) ? 'warning' : 'ok',
        ];
    }

    // ============================================================
    // FEATURE 5 — SCORE DE SANTÉ GLOBAL WATCHDOG
    // ============================================================

    /**
     * Score 0-100 basé sur : erreurs récentes, crons en retard, queue bloquée.
     * Distinct de StatsManager::getHealthScore() qui mesure les contrôles de diagnostic.
     */
    public function getWatchdogHealthScore(): array
    {
        $table  = _DB_PREFIX_ . self::TABLE;
        $score  = 100;
        $issues = [];

        // ── Événements watchdog (24h) ─────────────────────────────
        $recent = $this->db->executeS(
            "SELECT `level`, COUNT(*) AS cnt
             FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND `level`    IN ('warning','error','critical')
             GROUP BY `level`"
        );
        $rc = ['warning' => 0, 'error' => 0, 'critical' => 0];
        if (is_array($recent)) {
            foreach ($recent as $r) {
                $rc[$r['level']] = (int) $r['cnt'];
            }
        }
        $score -= min(15, $rc['warning']  * 2);
        $score -= min(30, $rc['error']    * 5);
        $score -= min(40, $rc['critical'] * 10);
        if ($rc['error'] > 0 || $rc['critical'] > 0) {
            $tot = $rc['error'] + $rc['critical'];
            $issues[] = $tot . ' erreur(s)/critique(s) dans les 24 dernières heures';
        }
        if ($rc['warning'] > 0) {
            $issues[] = $rc['warning'] . ' avertissement(s) dans les 24 dernières heures';
        }

        // ── Crons en retard ───────────────────────────────────────
        $crons  = $this->getCronHealth();
        $lateCt = 0;
        foreach ($crons as $c) {
            if ($c['is_late']) {
                $lateCt++;
            }
        }
        if ($lateCt > 0) {
            $score   -= min(25, $lateCt * 10);
            $issues[] = $lateCt . ' cron(s) en retard (pas d\'exécution depuis > seuil)';
        }

        // ── Queue bloquée ─────────────────────────────────────────
        $queue = $this->getQueueHealth();
        if (!empty($queue['stuck']) && $queue['stuck'] > 0) {
            $score   -= 10;
            $issues[] = $queue['stuck'] . ' email(s) bloqué(s) dans la file d\'attente';
        }
        if (!empty($queue['failed']) && $queue['failed'] > 5) {
            $score   -= 5;
            $issues[] = $queue['failed'] . ' email(s) en échec dans la file';
        }

        $score = max(0, $score);

        if ($score >= 90) {
            $status = 'excellent';
            $color  = '#16a34a';
            $label  = 'Excellent';
        } elseif ($score >= 70) {
            $status = 'good';
            $color  = '#65a30d';
            $label  = 'Bon';
        } elseif ($score >= 50) {
            $status = 'warning';
            $color  = '#d97706';
            $label  = 'Attention';
        } else {
            $status = 'critical';
            $color  = '#dc2626';
            $label  = 'Critique';
        }

        return [
            'score'   => $score,
            'status'  => $status,
            'color'   => $color,
            'label'   => $label,
            'issues'  => $issues,
            'crons'   => $crons,
            'queue'   => $queue,
            'rc_24h'  => $rc,
        ];
    }
}
