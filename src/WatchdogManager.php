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
        $table       = _DB_PREFIX_ . self::TABLE;
        $contextSql  = !empty($context)
            ? "'" . pSQL(json_encode($context, JSON_UNESCAPED_UNICODE)) . "'"
            : 'NULL';

        $this->db->execute(sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `level`, `template`, `class`, `message`, `context`, `date_add`)
             VALUES (%d, '%s', '%s', '%s', '%s', %s, '%s')",
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
     * À appeler une fois par jour depuis HooksManager::onDisplayHeader().
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
}
