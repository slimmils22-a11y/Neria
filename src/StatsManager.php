<?php
/**
 * NERIA â€” StatsManager
 *
 * Gestion complÃ¨te des statistiques d'emails :
 * â€” Enregistrement des envois, ouvertures et clics
 * â€” Calcul des taux d'ouverture et de clic
 * â€” Rapports par template, langue et pays
 * â€” Nettoyage des donnÃ©es anciennes
 * â€” Anonymisation RGPD des IPs
 *
 * Flux de tracking :
 * 1. EmailRenderer gÃ©nÃ¨re un token unique par email
 * 2. Un pixel 1x1 est injectÃ© dans le HTML de l'email
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
    private ?\WatchdogManager $watchdog = null;
    private ?\WebhookManager $webhookMgr = null;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    private function webhook(): \WebhookManager
    {
        if ($this->webhookMgr === null) {
            $this->webhookMgr = new \WebhookManager($this->module);
        }
        return $this->webhookMgr;
    }

    // ============================================================
    // ENREGISTREMENT DES EVENEMENTS
    // ============================================================

    public function recordSent(array $params): void
    {
        $idCustomer = (int) ($params['idCustomer'] ?? 0);

        // Certains envois (newsletter, désinscription...) ne sont pas
        // déclenchés par un client connecté : id_customer arrive à 0 même si
        // un compte existe avec cette adresse. On le retrouve par email pour
        // que l'historique (fiche client) reste fiable.
        if ($idCustomer === 0) {
            $idCustomer = $this->resolveCustomerIdByEmail($params['to'] ?? '');
        }

        $this->record(
            $params['neria_template'] ?? '',
            $params['neria_lang']     ?? '',
            $params['neria_token']    ?? '',
            self::EVENT_SENT,
            [
                'id_customer'   => $idCustomer,
                'id_order'      => (int) ($params['idOrder']       ?? 0),
                'abtest'        => $params['neria_variant']        ?? '',
                'rendered_vars' => $this->buildSnapshot($params['templateVars'] ?? []),
            ]
        );

        $this->webhook()->trigger('email_sent', [
            'template'       => $params['neria_template'] ?? '',
            'lang'           => $params['neria_lang']     ?? '',
            'customer_id'    => $idCustomer,
            'tracking_token' => $params['neria_token']    ?? '',
        ]);
    }

    /**
     * Retrouve l'id_customer correspondant à une adresse email, pour les
     * envois qui n'ont pas de client connecté en contexte (ex. newsletter).
     */
    private function resolveCustomerIdByEmail($to): int
    {
        if (is_array($to)) {
            $to = reset($to) ?: '';
        }
        $to = trim((string) $to);
        if ($to === '' || !\Validate::isEmail($to)) {
            return 0;
        }

        $id = (int) \Customer::customerExists($to, true);
        return $id > 0 ? $id : 0;
    }

    /**
     * Capture un instantané léger des variables "humaines" du template au
     * moment de l'envoi (montant, n° commande, prénom...), pas le HTML rendu
     * en entier. Permet de reconstruire un aperçu/renvoi fidèle aux données
     * d'origine plus tard, sans le coût de stockage d'un snapshot HTML complet.
     * Les blocs internes Neria ({neria_*}) et les valeurs trop longues (gros
     * blocs HTML déjà mis en forme, type tableau produits) sont exclus.
     */
    private function buildSnapshot(array $templateVars): string
    {
        $snapshot = [];
        foreach ($templateVars as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $clean = trim((string) $key, '{}');
            if ($clean === '' || str_starts_with($clean, 'neria_')) {
                continue;
            }
            $value = (string) $value;
            if (strlen($value) > 500) {
                continue;
            }
            $snapshot[$clean] = $value;
        }

        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        // Garde-fou : ne jamais dépasser ~4 Ko même si beaucoup de petites variables
        if ($json !== false && strlen($json) > 4096) {
            $json = substr($json, 0, 4093) . '"}';
        }

        return $json !== false ? $json : '{}';
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

        $isMpp = $this->detectMpp(
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $sent['date_add']
        );

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
                'is_mpp'       => $isMpp ? 1 : 0,
            ]
        );

        if (!$isMpp) {
            $this->webhook()->trigger('email_opened', [
                'template'       => $sent['template'],
                'lang'           => $sent['lang'],
                'customer_id'    => (int) $sent['id_customer'],
                'tracking_token' => $token,
            ]);
        }
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

        $renderedVars = $extra['rendered_vars'] ?? null;
        if ($renderedVars !== null && class_exists('CryptoManager')) {
            $encrypted = \CryptoManager::encrypt($renderedVars);
            if ($encrypted === $renderedVars && \CryptoManager::isAvailable()) {
                // encrypt() a retourné la valeur d'origine → clé absente ou erreur openssl
                $this->watchdog()->warning(
                    'Chiffrement échoué pour rendered_vars (événement "' . $event . '", template "' . $template . '") — les données sont stockées en clair. Vérifiez que NERIA_ENCRYPTION_KEY est définie dans ps_configuration.',
                    '', 'CryptoManager'
                );
            }
            $renderedVars = $encrypted;
        }

        $revenue = isset($extra['revenue']) ? (float) $extra['revenue'] : 0.0;
        $isMpp   = isset($extra['is_mpp'])  ? (int)   $extra['is_mpp']  : 0;

        $sql = sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `template`, `lang`, `country_code`,
                 `id_customer`, `id_order`, `tracking_token`,
                 `event_type`, `is_mpp`, `abtest_variant`, `rendered_vars`,
                 `revenue`, `ip_address`, `user_agent`, `date_add`)
             VALUES (%d, '%s', '%s', '%s', %d, %d, '%s', '%s', %d, '%s', %s, %.2f, '%s', '%s', '%s')",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($lang),
            pSQL($extra['country_code'] ?? $this->resolveCountryCode()),
            (int) ($extra['id_customer'] ?? 0),
            (int) ($extra['id_order']    ?? 0),
            pSQL($token),
            pSQL($event),
            $isMpp,
            pSQL($extra['abtest'] ?? ''),
            $renderedVars !== null ? "'" . pSQL($renderedVars) . "'" : 'NULL',
            $revenue,
            pSQL($this->anonymizeIp($this->getClientIp())),
            pSQL(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)),
            $now
        );

        $ok = $this->db->execute($sql);
        if (!$ok) {
            $this->watchdog()->warning(
                sprintf(
                    'Impossible d\'enregistrer l\'événement "%s" en base de données : %s. Les statistiques de tracking peuvent être incomplètes. Vérifiez que la table ps_neria_stat existe et est accessible.',
                    $event,
                    $this->db->getMsgError()
                ),
                '', 'StatsManager'
            );
        }

        // Attribution de points fidélité (non-bloquant, uniquement si l'INSERT a réussi)
        if ($ok && class_exists('LoyaltyManager') && \Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')) {
            $idCustomer = (int) ($extra['id_customer'] ?? 0);
            $idStat     = (int) $this->db->Insert_ID();
            if ($idCustomer > 0 && $idStat > 0
                && in_array($event, ['open', 'click', 'conversion'], true)
            ) {
                try {
                    (new \LoyaltyManager($this->module))->awardPoints($idCustomer, $idStat, $event);
                } catch (\Throwable $e) {
                    // Non-bloquant : la fidélité ne doit jamais bloquer le tracking
                }
            }
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
                    COUNT(CASE WHEN `event_type` = 'sent'                     THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0   THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 1   THEN 1 END) AS mpp_open,
                    COUNT(CASE WHEN `event_type` = 'click'                    THEN 1 END) AS total_click
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
            $sent  = (int) $row['total_sent'];
            $opens = (int) $row['total_open'];
            $row['rate_open']  = $sent  > 0 ? round(((int) $row['total_open']  / $sent)  * 100, 1) : 0;
            $row['rate_click'] = $sent  > 0 ? round(((int) $row['total_click'] / $sent)  * 100, 1) : 0;
            $row['ctor']       = $opens > 0 ? round(((int) $row['total_click'] / $opens) * 100, 1) : 0;
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
                    COUNT(CASE WHEN `event_type` = 'sent'                     THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0   THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click'                    THEN 1 END) AS total_click
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
                    COUNT(CASE WHEN `event_type` = 'sent'                     THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0   THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click'                    THEN 1 END) AS total_click
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
                    COUNT(CASE WHEN `event_type` = 'sent'                     THEN 1 END) AS sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0   THEN 1 END) AS open,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 1   THEN 1 END) AS mpp,
                    COUNT(CASE WHEN `event_type` = 'click'                    THEN 1 END) AS click
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
                    COUNT(CASE WHEN `event_type` = 'sent'                     THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0   THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 1   THEN 1 END) AS mpp_open,
                    COUNT(CASE WHEN `event_type` = 'click'                    THEN 1 END) AS total_click,
                    COUNT(CASE WHEN `event_type` = 'conversion'               THEN 1 END) AS total_conversion,
                    COUNT(DISTINCT `template`)                                             AS active_templates,
                    COUNT(DISTINCT `lang`)                                                 AS active_langs,
                    COUNT(DISTINCT `country_code`)                                         AS active_countries
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
            'mpp_open'         => (int) $row['mpp_open'],
            'total_click'      => (int) $row['total_click'],
            'total_conversion' => (int) $row['total_conversion'],
            'active_templates' => (int) $row['active_templates'],
            'active_langs'     => (int) $row['active_langs'],
            'active_countries' => (int) $row['active_countries'],
            'rate_open'        => $sent > 0
                ? round(((int) $row['total_open']  / $sent) * 100, 1) : 0,
            'rate_click'       => $sent > 0
                ? round(((int) $row['total_click'] / $sent) * 100, 1) : 0,
            'ctor'             => (int) $row['total_open'] > 0
                ? round(((int) $row['total_click'] / (int) $row['total_open']) * 100, 1) : 0,
            'period_days'      => $days,
        ];
    }

    const SIG_MIN_SAMPLE = 100;

    public function getABTestReport(string $template, int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        $sql = "SELECT
                    `abtest_variant` AS variant,
                    COUNT(CASE WHEN `event_type` = 'sent'                     THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0   THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click'                    THEN 1 END) AS total_click
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

        $result['significance'] = ($result['A'] !== null && $result['B'] !== null)
            ? $this->computeSignificance($result['A'], $result['B'])
            : $this->emptySignificance();

        $this->logSignificanceIfNew($template, $result['significance']);

        return $result;
    }

    private function emptySignificance(): array
    {
        $empty = ['confidence' => 0, 'winner' => null, 'sufficient' => false, 'z' => 0.0];
        return [
            'open'           => $empty,
            'click'          => $empty,
            'overall_winner' => null,
            'significant'    => false,
            'min_sample'     => self::SIG_MIN_SAMPLE,
            'sent_a'         => 0,
            'sent_b'         => 0,
        ];
    }

    private function logSignificanceIfNew(string $template, array $sig): void
    {
        $conf   = max($sig['open']['confidence'] ?? 0, $sig['click']['confidence'] ?? 0);
        $winner = $sig['overall_winner'] ?? null;

        if ($conf < 95 || !$winner) {
            return;
        }

        $cfgKey = 'NERIA_SIG_LOGGED_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $template));
        $logged = (int) \Configuration::get($cfgKey);

        if ($logged >= $conf) {
            return;
        }

        \Configuration::updateValue($cfgKey, $conf);

        (new WatchdogManager($this->module))->info(
            "A/B [{$template}] significativité {$conf}% atteinte — gagnant : variante {$winner}",
            $template, 'StatsManager'
        );

        $this->webhook()->trigger('ab_winner', [
            'template'   => $template,
            'winner'     => $winner,
            'confidence' => $conf,
        ]);
    }

    public function computeSignificance(array $a, array $b): array
    {
        $min    = self::SIG_MIN_SAMPLE;
        $sentA  = $a['total_sent'];
        $sentB  = $b['total_sent'];
        $base   = $this->emptySignificance();
        $base['sent_a'] = $sentA;
        $base['sent_b'] = $sentB;

        if ($sentA < $min || $sentB < $min) {
            return $base;
        }

        $open  = $this->zTestProportions($a['total_open'],  $sentA, $b['total_open'],  $sentB);
        $click = $this->zTestProportions($a['total_click'], $sentA, $b['total_click'], $sentB);

        $overall = $click['winner'] ?? $open['winner'];

        return [
            'open'           => $open,
            'click'          => $click,
            'overall_winner' => $overall,
            'significant'    => $open['confidence'] >= 95 || $click['confidence'] >= 95,
            'min_sample'     => $min,
            'sent_a'         => $sentA,
            'sent_b'         => $sentB,
        ];
    }

    private function zTestProportions(int $x1, int $n1, int $x2, int $n2): array
    {
        $out = ['confidence' => 0, 'winner' => null, 'sufficient' => true, 'z' => 0.0];

        if ($n1 === 0 || $n2 === 0) {
            $out['sufficient'] = false;
            return $out;
        }

        $p1    = $x1 / $n1;
        $p2    = $x2 / $n2;
        $total = $n1 + $n2;
        $pPool = ($x1 + $x2) / $total;

        if ($pPool <= 0.0 || $pPool >= 1.0) {
            return $out;
        }

        $se = sqrt($pPool * (1 - $pPool) * (1 / $n1 + 1 / $n2));

        if ($se < 1e-10) {
            return $out;
        }

        $z    = ($p1 - $p2) / $se;
        $absZ = abs($z);

        $out['z'] = round($z, 3);

        if ($absZ >= 2.576)     { $out['confidence'] = 99; }
        elseif ($absZ >= 1.960) { $out['confidence'] = 95; }
        elseif ($absZ >= 1.645) { $out['confidence'] = 90; }

        if ($out['confidence'] >= 90) {
            $out['winner'] = $z > 0 ? 'A' : 'B';
        }

        return $out;
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
                $age = isset($data['computed_at'])
                    ? (time() - strtotime($data['computed_at']))
                    : PHP_INT_MAX;
                // Cache valide pendant 5 minutes max
                if ($age < 300) {
                    return $data;
                }
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

        $this->watchdog()->info(
            sprintf('Nettoyage statistiques : %d entrées supprimées (antérieures à %d jours).', $deleted, $days),
            '', 'StatsManager'
        );

        return $deleted;
    }

    /**
     * Retourne template + lang associés à un token (pour track.php et cookie).
     */
    public function getRefDataByToken(string $token): ?array
    {
        return $this->getSentByToken($token);
    }

    /**
     * Enregistre une conversion (commande payée) attribuée à un email Neria.
     * Stocke l'id_order et le montant dans rendered_vars (JSON) pour éviter
     * toute migration de schéma.
     */
    public function recordConversion(string $token, int $idOrder, float $amount): void
    {
        if ($this->eventExists($token, self::EVENT_CONVERSION)) {
            return; // déjà attribuée
        }

        $sent = $this->getSentByToken($token);
        if (!$sent) {
            return;
        }

        $this->record(
            $sent['template'],
            $sent['lang'],
            $token,
            self::EVENT_CONVERSION,
            [
                'id_customer'   => (int) $sent['id_customer'],
                'id_order'      => $idOrder,
                'country_code'  => $sent['country_code'],
                'abtest'        => $sent['abtest_variant'],
                'revenue'       => $amount,
            ]
        );

        $this->webhook()->trigger('conversion', [
            'template'       => $sent['template'],
            'lang'           => $sent['lang'],
            'customer_id'    => (int) $sent['id_customer'],
            'order_id'       => $idOrder,
            'revenue'        => $amount,
            'tracking_token' => $token,
        ]);
    }

    /**
     * Agrège les statistiques de revenus sur les N derniers jours.
     *
     * @param int $days
     * @return array{
     *   total_revenue: float,
     *   total_orders: int,
     *   avg_order: float,
     *   by_template: array<string, array{revenue: float, orders: int}>
     * }
     */
    public function getRevenueStats(int $days = 90): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = pSQL(date('Y-m-d', strtotime("-{$days} days")));

        // MySQL 5.7+ : JSON_EXTRACT sur un champ TEXT
        $rows = $this->db->executeS(
            "SELECT
                `template`,
                COUNT(*)        AS orders,
                SUM(`revenue`)  AS revenue
             FROM `{$table}`
             WHERE `event_type` = '" . self::EVENT_CONVERSION . "'
               AND `id_shop`    = {$this->idShop}
               AND `date_add`   >= '{$dateFrom}'
             GROUP BY `template`
             ORDER BY revenue DESC"
        );

        $totalRevenue = 0.0;
        $totalOrders  = 0;
        $byTemplate   = [];

        foreach ((is_array($rows) ? $rows : []) as $r) {
            $rev = (float) $r['revenue'];
            $ord = (int)   $r['orders'];
            $totalRevenue += $rev;
            $totalOrders  += $ord;
            $byTemplate[$r['template']] = ['revenue' => $rev, 'orders' => $ord];
        }

        return [
            'total_revenue' => round($totalRevenue, 2),
            'total_orders'  => $totalOrders,
            'avg_order'     => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0.0,
            'by_template'   => $byTemplate,
        ];
    }

    private static $CHART_CATEGORIES = [
        'cart'    => ['abandoned_cart_1','abandoned_cart_2','abandoned_cart_3',
                      'checkout_abandonment','ghost_cart'],
        'post'    => ['post_purchase_review','complete_your_look','collection_completion',
                      'product_lifespan_reminder','refund_reconciliation_1',
                      'refund_reconciliation_2','refund_reconciliation_3',
                      'waitlist_available','wishlist_reminder','back_in_stock'],
        'loyalty' => ['loyalty_tier_upgrade','loyalty_recap','loyalty_reward_expiry',
                      'milestone_order','referral_invitation'],
        'behav'   => ['birthday','relationship_anniversary','win_back',
                      'reorder_reminder','vip_invitation','private_sale','first_anniversary'],
        'season'  => ['christmas','valentine','halloween','eid','ramadan',
                      'diwali','lunar_new_year','nowruz','black_friday','new_year',
                      'hanukkah','fathers_day','mothers_day','grandparents_day',
                      'end_of_year_gift','early_access','exclusive_preview'],
        'b2b'     => ['quote_expiry_48h','quote_expiry_day','quote_extension_offer'],
    ];

    public function getRevenueDailyByCategory(int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = pSQL(date('Y-m-d', strtotime("-{$days} days")));

        $rows = $this->db->executeS(
            "SELECT DATE(`date_add`) AS `d`, `template`, SUM(`revenue`) AS `rev`
             FROM `{$table}`
             WHERE `event_type` = '" . self::EVENT_CONVERSION . "'
               AND `id_shop`    = {$this->idShop}
               AND `date_add`  >= '{$dateFrom}'
               AND `revenue`   >  0
             GROUP BY DATE(`date_add`), `template`
             ORDER BY `d` ASC"
        );

        $dates = [];
        for ($i = $days; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime("-{$i} days"));
        }

        $tplTocat = [];
        foreach (self::$CHART_CATEGORIES as $cat => $tpls) {
            foreach ($tpls as $tpl) {
                $tplTocat[$tpl] = $cat;
            }
        }

        $cats   = array_keys(self::$CHART_CATEGORIES);
        $cats[] = 'other';
        $series = [];
        foreach ($cats as $cat) {
            $series[$cat] = array_fill_keys($dates, 0.0);
        }
        $total = array_fill_keys($dates, 0.0);

        foreach ((is_array($rows) ? $rows : []) as $r) {
            $d   = $r['d'];
            $rev = (float) $r['rev'];
            $cat = $tplTocat[$r['template']] ?? 'other';
            if (isset($series[$cat][$d])) {
                $series[$cat][$d] += $rev;
            }
            if (isset($total[$d])) {
                $total[$d] += $rev;
            }
        }

        $out = ['dates' => $dates, 'series' => [], 'total' => []];
        foreach ($series as $cat => $byDate) {
            $out['series'][$cat] = array_values(array_map(fn($v) => round($v, 2), $byDate));
        }
        $out['total'] = array_values(array_map(fn($v) => round($v, 2), $total));

        return $out;
    }

    // ============================================================
    // UTILITAIRES PRIVES
    // ============================================================

    private function getSentByToken(string $token): ?array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $row = $this->db->getRow(
            "SELECT `template`, `lang`, `country_code`,
                    `id_customer`, `id_order`, `abtest_variant`, `date_add`
             FROM `{$table}`
             WHERE `tracking_token` = '" . pSQL($token) . "'
               AND `event_type`     = '" . self::EVENT_SENT . "'"
        );

        return $row ?: null;
    }

    /**
     * Détecte une ouverture Apple Mail Privacy Protection (MPP).
     *
     * Apple Mail (iOS 15+/macOS Monterey+) précharge automatiquement les pixels
     * de tracking via ses serveurs proxy, avant que l'utilisateur ouvre l'email.
     *
     * Trois signaux, du plus au moins fiable :
     *  1. UA contient un pattern Apple Mail/proxy connu → MPP certain
     *  2. Délai < 3 secondes après l'envoi → humainement impossible
     *  3. UA Safari pur (WebKit sans Chrome/Firefox/Edge) + délai < 15s → probable MPP
     */
    private function detectMpp(string $ua, string $sentDateAdd): bool
    {
        // Signal 1 : UA explicitement Apple Mail ou proxy Apple connu
        foreach ([
            'com.apple.mail', 'AppleExchangeWebServices', 'Applebot',
            'Apple Mail', 'AppleMailSecurity', 'Apple-PubSub',
        ] as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                return true;
            }
        }

        $sentTs  = (int) strtotime($sentDateAdd);
        $elapsed = $sentTs > 0 ? (time() - $sentTs) : PHP_INT_MAX;

        // Signal 2 : délai < 3 secondes (humanement impossible)
        if ($elapsed < 3) {
            return true;
        }

        // Signal 3 : Safari/WebKit pur + < 15 secondes après l'envoi
        $isSafariOnly = stripos($ua, 'AppleWebKit') !== false
            && stripos($ua, 'Chrome')  === false
            && stripos($ua, 'Firefox') === false
            && stripos($ua, 'Edg/')    === false
            && stripos($ua, 'OPR/')    === false;

        if ($isSafariOnly && $elapsed < 15) {
            return true;
        }

        return false;
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
            'mpp_open'         => 0,
            'total_click'      => 0,
            'total_conversion' => 0,
            'active_templates' => 0,
            'active_langs'     => 0,
            'active_countries' => 0,
            'rate_open'        => 0,
            'rate_click'       => 0,
            'ctor'             => 0,
            'period_days'      => 0,
        ];
    }
}