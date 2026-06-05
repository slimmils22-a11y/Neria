<?php
/**
 * NERIA — StatsManager
 *
 * Gestion complète des statistiques d'emails :
 * — Enregistrement des envois, ouvertures et clics
 * — Calcul des taux d'ouverture et de clic
 * — Rapports par template, langue et pays
 * — Nettoyage des données anciennes
 * — Anonymisation RGPD des IPs
 *
 * Flux de tracking :
 * 1. EmailRenderer génère un token unique par email
 * 2. Un pixel 1x1 est injecté dans le HTML de l'email
 * 3. Quand le client ouvre l'email, le pixel charge
 *    PrestaShop appelle le front controller "track"
 *    StatsManager enregistre l'evenement "open"
 * 4. Les liens cliquables contiennent aussi le token
 *    StatsManager enregistre l'evenement "click"
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class StatsManager
{
    const TABLE = 'neria_stat';

    const EVENT_SENT       = 'sent';
    const EVENT_OPEN       = 'open';
    const EVENT_CLICK      = 'click';
    const EVENT_CONVERSION = 'conversion';

    const DEFAULT_RETENTION_DAYS = 365;

    private Neria $module;
    private \Db $db;
    private int $idShop;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    // ============================================================
    // ENREGISTREMENT DES EVENEMENTS
    // ============================================================

    public function recordSent(array $params): void
    {
        $this->record(
            $params['neria_template'] ?? '',
            $params['neria_lang']     ?? '',
            $params['neria_token']    ?? '',
            self::EVENT_SENT,
            [
                'id_customer' => (int) ($params['idCustomer']    ?? 0),
                'id_order'    => (int) ($params['idOrder']       ?? 0),
                'abtest'      => $params['neria_variant']        ?? '',
            ]
        );
    }

    public function recordOpen(string $token): void
    {
        $sent = $this->getSentByToken($token);
        if (!$sent) {
            return;
        }

        if ($this->eventExists($token, self::EVENT_OPEN)) {
            return;
        }

        $this->record(
            $sent['template'],
            $sent['lang'],
            $token,
            self::EVENT_OPEN,
            [
                'id_customer'  => (int) $sent['id_customer'],
                'id_order'     => (int) $sent['id_order'],
                'country_code' => $sent['country_code'],
                'abtest'       => $sent['abtest_variant'],
            ]
        );
    }

    public function recordClick(string $token, string $url = ''): void
    {
        $sent = $this->getSentByToken($token);
        if (!$sent) {
            return;
        }

        $this->record(
            $sent['template'],
            $sent['lang'],
            $token,
            self::EVENT_CLICK,
            [
                'id_customer'  => (int) $sent['id_customer'],
                'id_order'     => (int) $sent['id_order'],
                'country_code' => $sent['country_code'],
                'abtest'       => $sent['abtest_variant'],
            ]
        );
    }

    private function record(
        string $template,
        string $lang,
        string $token,
        string $event,
        array  $extra = []
    ): void {
        if (empty($template) || empty($token)) {
            return;
        }

        $table = _DB_PREFIX_ . self::TABLE;
        $now   = date('Y-m-d H:i:s');

        $sql = sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `template`, `lang`, `country_code`,
                 `id_customer`, `id_order`, `tracking_token`,
                 `event_type`, `abtest_variant`,
                 `ip_address`, `user_agent`, `date_add`)
             VALUES (%d, '%s', '%s', '%s', %d, %d, '%s', '%s', '%s', '%s', '%s', '%s')",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($lang),
            pSQL($extra['country_code'] ?? $this->resolveCountryCode()),
            (int) ($extra['id_customer'] ?? 0),
            (int) ($extra['id_order']    ?? 0),
            pSQL($token),
            pSQL($event),
            pSQL($extra['abtest'] ?? ''),
            pSQL($this->anonymizeIp($this->getClientIp())),
            pSQL(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)),
            $now
        );

        if (!$this->db->execute($sql)) {
            $this->module->log(
                'StatsManager: erreur enregistrement [' . $event . '] : '
                . $this->db->getMsgError(),
                2
            );
        }
    }

    // ============================================================
    // RAPPORTS
    // ============================================================

    public function getGlobalReport(int $days = 30, string $lang = ''): array
    {
        $table      = _DB_PREFIX_ . self::TABLE;
        $dateFrom   = date('Y-m-d', strtotime("-{$days} days"));
        $langFilter = $lang ? "AND `lang` = '" . pSQL($lang) . "'" : '';

        $sql = "SELECT
                    `template`,
                    COUNT(CASE WHEN `event_type` = 'sent'  THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open'  THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click' THEN 1 END) AS total_click
                FROM `{$table}`
                WHERE `id_shop`  = {$this->idShop}
                  AND `date_add` >= '{$dateFrom}'
                  {$langFilter}
                GROUP BY `template`
                ORDER BY `total_sent` DESC";

        $rows = $this->db->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $sent = (int) $row['total_sent'];
            $row['rate_open']  = $sent > 0
                ? round(((int) $row['total_open']  / $sent) * 100, 1) : 0;
            $row['rate_click'] = $sent > 0
                ? round(((int) $row['total_click'] / $sent) * 100, 1) : 0;
        }

        return $rows;
    }

    public function getReportByLang(int $days = 30, string $template = ''): array
    {
        $table          = _DB_PREFIX_ . self::TABLE;
        $dateFrom       = date('Y-m-d', strtotime("-{$days} days"));
        $templateFilter = $template
            ? "AND `template` = '" . pSQL($template) . "'"
            : '';

        $sql = "SELECT
                    `lang`,
                    COUNT(CASE WHEN `event_type` = 'sent'  THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open'  THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click' THEN 1 END) AS total_click
                FROM `{$table}`
                WHERE `id_shop`  = {$this->idShop}
                  AND `date_add` >= '{$dateFrom}'
                  {$templateFilter}
                GROUP BY `lang`
                ORDER BY `total_sent` DESC";

        $rows = $this->db->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $sent = (int) $row['total_sent'];
            $row['rate_open']  = $sent > 0
                ? round(((int) $row['total_open']  / $sent) * 100, 1) : 0;
            $row['rate_click'] = $sent > 0
                ? round(((int) $row['total_click'] / $sent) * 100, 1) : 0;
        }

        return $rows;
    }

    public function getReportByCountry(int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        $sql = "SELECT
                    `country_code`,
                    COUNT(CASE WHEN `event_type` = 'sent'  THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open'  THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click' THEN 1 END) AS total_click
                FROM `{$table}`
                WHERE `id_shop`      = {$this->idShop}
                  AND `date_add`     >= '{$dateFrom}'
                  AND `country_code` != ''
                GROUP BY `country_code`
                ORDER BY `total_sent` DESC
                LIMIT 30";

        $rows = $this->db->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $sent = (int) $row['total_sent'];
            $row['rate_open']  = $sent > 0
                ? round(((int) $row['total_open']  / $sent) * 100, 1) : 0;
            $row['rate_click'] = $sent > 0
                ? round(((int) $row['total_click'] / $sent) * 100, 1) : 0;
        }

        return $rows;
    }

    public function getDailyEvolution(int $days = 30, string $template = ''): array
    {
        $table          = _DB_PREFIX_ . self::TABLE;
        $dateFrom       = date('Y-m-d', strtotime("-{$days} days"));
        $templateFilter = $template
            ? "AND `template` = '" . pSQL($template) . "'"
            : '';

        $sql = "SELECT
                    DATE(`date_add`) AS `date`,
                    COUNT(CASE WHEN `event_type` = 'sent'  THEN 1 END) AS sent,
                    COUNT(CASE WHEN `event_type` = 'open'  THEN 1 END) AS open,
                    COUNT(CASE WHEN `event_type` = 'click' THEN 1 END) AS click
                FROM `{$table}`
                WHERE `id_shop`  = {$this->idShop}
                  AND `date_add` >= '{$dateFrom}'
                  {$templateFilter}
                GROUP BY DATE(`date_add`)
                ORDER BY `date` ASC";

        return $this->db->executeS($sql) ?: [];
    }

    public function getKpis(int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        $sql = "SELECT
                    COUNT(CASE WHEN `event_type` = 'sent'       THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open'       THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click'      THEN 1 END) AS total_click,
                    COUNT(CASE WHEN `event_type` = 'conversion' THEN 1 END) AS total_conversion,
                    COUNT(DISTINCT `template`)                               AS active_templates,
                    COUNT(DISTINCT `lang`)                                   AS active_langs,
                    COUNT(DISTINCT `country_code`)                           AS active_countries
                FROM `{$table}`
                WHERE `id_shop`  = {$this->idShop}
                  AND `date_add` >= '{$dateFrom}'";

        $row = $this->db->getRow($sql);
        if (!$row) {
            return $this->getEmptyKpis();
        }

        $sent = (int) $row['total_sent'];

        return [
            'total_sent'       => $sent,
            'total_open'       => (int) $row['total_open'],
            'total_click'      => (int) $row['total_click'],
            'total_conversion' => (int) $row['total_conversion'],
            'active_templates' => (int) $row['active_templates'],
            'active_langs'     => (int) $row['active_langs'],
            'active_countries' => (int) $row['active_countries'],
            'rate_open'        => $sent > 0
                ? round(((int) $row['total_open']  / $sent) * 100, 1) : 0,
            'rate_click'       => $sent > 0
                ? round(((int) $row['total_click'] / $sent) * 100, 1) : 0,
            'period_days'      => $days,
        ];
    }

    public function getABTestReport(string $template, int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        $sql = "SELECT
                    `abtest_variant` AS variant,
                    COUNT(CASE WHEN `event_type` = 'sent'  THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open'  THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click' THEN 1 END) AS total_click
                FROM `{$table}`
                WHERE `id_shop`        = {$this->idShop}
                  AND `template`       = '" . pSQL($template) . "'
                  AND `abtest_variant` IN ('A', 'B')
                  AND `date_add`       >= '{$dateFrom}'
                GROUP BY `abtest_variant`";

        $rows   = $this->db->executeS($sql) ?: [];
        $result = ['A' => null, 'B' => null];

        foreach ($rows as $row) {
            $sent    = (int) $row['total_sent'];
            $variant = $row['variant'];
            $result[$variant] = [
                'total_sent'  => $sent,
                'total_open'  => (int) $row['total_open'],
                'total_click' => (int) $row['total_click'],
                'rate_open'   => $sent > 0
                    ? round(((int) $row['total_open']  / $sent) * 100, 1) : 0,
                'rate_click'  => $sent > 0
                    ? round(((int) $row['total_click'] / $sent) * 100, 1) : 0,
            ];
        }

        return $result;
    }

    public function computeReports(): void
    {
        $reports = [
            'global_30'     => $this->getGlobalReport(30),
            'by_lang_30'    => $this->getReportByLang(30),
            'by_country_30' => $this->getReportByCountry(30),
            'kpis_30'       => $this->getKpis(30),
            'kpis_7'        => $this->getKpis(7),
            'computed_at'   => date('Y-m-d H:i:s'),
        ];

        \Configuration::updateValue(
            'NERIA_STATS_CACHE',
            json_encode($reports, JSON_UNESCAPED_UNICODE)
        );
    }

    public function getCachedReports(): array
    {
        $cached = \Configuration::get('NERIA_STATS_CACHE');

        if ($cached) {
            $data = json_decode($cached, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        $this->computeReports();

        return [
            'global_30'     => $this->getGlobalReport(30),
            'by_lang_30'    => $this->getReportByLang(30),
            'by_country_30' => $this->getReportByCountry(30),
            'kpis_30'       => $this->getKpis(30),
            'kpis_7'        => $this->getKpis(7),
            'computed_at'   => date('Y-m-d H:i:s'),
        ];
    }

    // ============================================================
    // MAINTENANCE
    // ============================================================

    public function cleanup(int $days = self::DEFAULT_RETENTION_DAYS): int
    {
        $table     = _DB_PREFIX_ . self::TABLE;
        $dateLimit = date('Y-m-d', strtotime("-{$days} days"));

        $this->db->execute(
            "DELETE FROM `{$table}`
             WHERE `id_shop`  = {$this->idShop}
               AND `date_add` < '{$dateLimit}'"
        );

        $deleted = (int) $this->db->Affected_Rows();

        $this->module->log(
            "StatsManager::cleanup : {$deleted} lignes supprimees (>{$days} jours)",
            1
        );

        return $deleted;
    }

    // ============================================================
    // UTILITAIRES PRIVES
    // ============================================================

    private function getSentByToken(string $token): ?array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $row = $this->db->getRow(
            "SELECT `template`, `lang`, `country_code`,
                    `id_customer`, `id_order`, `abtest_variant`
             FROM `{$table}`
             WHERE `tracking_token` = '" . pSQL($token) . "'
               AND `event_type`     = '" . self::EVENT_SENT . "'
             LIMIT 1"
        );

        return $row ?: null;
    }

    private function eventExists(string $token, string $event): bool
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $count = (int) $this->db->getValue(
            "SELECT COUNT(*)
             FROM `{$table}`
             WHERE `tracking_token` = '" . pSQL($token) . "'
               AND `event_type`     = '" . pSQL($event) . "'"
        );

        return $count > 0;
    }

    private function resolveCountryCode(): string
    {
        $context = \Context::getContext();

        if ($context->customer && $context->customer->id) {
            $idCountry = (int) (\Address::getCountryAndState(
                (int) $context->customer->id_address_delivery
            )['id_country'] ?? 0);

            if ($idCountry) {
                return \Country::getIsoById($idCountry) ?: '';
            }
        }

        return '';
    }

    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }

    private function anonymizeIp(string $ip): string
    {
        if (empty($ip)) {
            return '';
        }

        if (strpos($ip, ':') !== false) {
            $parts = explode(':', $ip);
            $parts = array_slice($parts, 0, 4);
            return implode(':', $parts) . '::';
        }

        $parts    = explode('.', $ip);
        $parts[3] = '0';
        return implode('.', $parts);
    }

    private function getEmptyKpis(): array
    {
        return [
            'total_sent'       => 0,
            'total_open'       => 0,
            'total_click'      => 0,
            'total_conversion' => 0,
            'active_templates' => 0,
            'active_langs'     => 0,
            'active_countries' => 0,
            'rate_open'        => 0,
            'rate_click'       => 0,
            'period_days'      => 0,
        ];
    }
}