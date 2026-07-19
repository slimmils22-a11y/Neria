<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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
        // Garde-fou : ne jamais dépasser ~4 Ko même si beaucoup de petites variables.
        // On retire des clés (au lieu de tronquer la chaîne encodée) pour ne jamais
        // produire un JSON invalide qui casserait le renvoi/aperçu d'email.
        while ($json !== false && strlen($json) > 4096 && !empty($snapshot)) {
            array_pop($snapshot);
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
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

        // Chaque clic (même sur des liens différents du même email) crée un
        // événement pour les statistiques — mais un client qui recharge ou
        // reclique plusieurs fois sur son propre lien ne doit gagner des
        // points de fidélité qu'une seule fois par email envoyé, sinon le
        // programme de fidélité est trivialement exploitable (clics répétés
        // → points illimités → paliers de réduction obtenus gratuitement).
        //
        // eventExists() + record() forment un check-then-act non atomique :
        // deux requêtes de clic quasi simultanées (pré-fetch du client mail +
        // clic réel, double-tap mobile) peuvent toutes deux lire "aucun clic
        // existant" avant que l'une des deux n'ait inséré sa ligne, et donc
        // toutes deux créditer des points. GET_LOCK sérialise la décision
        // par token pour empêcher ce double crédit.
        $lockKey  = 'neria_click_' . md5($token);
        $gotLock  = (bool) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockKey) . "', 2)");
        try {
            $awardPoints = !$this->eventExists($token, self::EVENT_CLICK);

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
                ],
                $awardPoints
            );
        } finally {
            if ($gotLock) {
                $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockKey) . "')");
            }
        }
    }

    private function record(
        string $template,
        string $lang,
        string $token,
        string $event,
        array  $extra = [],
        bool   $awardPoints = true
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
                    \WatchdogManager::i18nMsg('watchdog.stats_encryption_failed', ['event' => $event, 'template' => $template]),
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
                \WatchdogManager::i18nMsg('watchdog.stats_insert_failed', ['event' => $event, 'error' => $this->db->getMsgError()]),
                '', 'StatsManager'
            );
        }

        // Attribution de points fidélité (non-bloquant, uniquement si l'INSERT a réussi)
        if ($ok && $awardPoints && class_exists('LoyaltyManager') && \Configuration::getGlobalValue('NERIA_LOYALTY_ENABLED')) {
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

        $dateClause = $days < 9999 ? "AND `date_add` >= '{$dateFrom}'" : '';

        $sql = "SELECT
                    `abtest_variant` AS variant,
                    COUNT(CASE WHEN `event_type` = 'sent'                          THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0        THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click'                         THEN 1 END) AS total_click,
                    SUM(CASE WHEN `event_type`   = 'conversion' THEN `revenue` ELSE 0 END) AS total_revenue
                FROM `{$table}`
                WHERE `id_shop`        = {$this->idShop}
                  AND `template`       = '" . pSQL($template) . "'
                  AND `abtest_variant` IN ('A', 'B')
                  {$dateClause}
                GROUP BY `abtest_variant`";

        $rows   = $this->db->executeS($sql) ?: [];
        $result = ['A' => null, 'B' => null];

        foreach ($rows as $row) {
            $sent    = (int) $row['total_sent'];
            $revenue = round((float) ($row['total_revenue'] ?? 0), 2);
            $variant = $row['variant'];
            $result[$variant] = [
                'total_sent'       => $sent,
                'total_open'       => (int) $row['total_open'],
                'total_click'      => (int) $row['total_click'],
                'total_revenue'    => $revenue,
                'revenue_per_100'  => $sent > 0 ? round($revenue / $sent * 100, 2) : 0,
                'rate_open'        => $sent > 0
                    ? round(((int) $row['total_open']  / $sent) * 100, 1) : 0,
                'rate_click'       => $sent > 0
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
            WatchdogManager::i18nMsg('watchdog.abtest_significance_reached', ['template' => $template, 'conf' => $conf, 'winner' => $winner]),
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

        // Le gagnant global doit venir de la métrique dont la confiance est
        // la plus élevée, pas systématiquement du clic : `$click['winner'] ??
        // $open['winner']` privilégiait aveuglément le clic dès qu'il
        // atteignait 90%, même quand l'ouverture était bien plus
        // significative (jusqu'à 99%) et désignait l'autre variante.
        if ($click['winner'] !== null && $open['winner'] !== null) {
            $overall = $click['confidence'] >= $open['confidence'] ? $click['winner'] : $open['winner'];
        } else {
            $overall = $click['winner'] ?? $open['winner'];
        }

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
                // Cache valide pendant 30 minutes max (5 min en dev, relevé
                // pour la prod — le cron prend le relai pour les mises à jour
                // fréquentes, pas besoin de recalculer à chaque visite BO).
                if ($age < 1800) {
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
            \WatchdogManager::i18nMsg('watchdog.stats_cleanup', ['n' => $deleted, 'days' => $days]),
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

    // ============================================================
    // NOUVEAUX INDICATEURS — STATISTIQUES AVANCÉES
    // ============================================================

    /**
     * Tendances KPIs — semaine courante vs semaine précédente.
     * Retourne pour chaque métrique : current, previous, delta (%)
     */
    public function getKpiTrends(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        $prefTable = _DB_PREFIX_ . 'neria_preferences';

        $raw = [];
        foreach (['current' => 0, 'previous' => 7] as $period => $offset) {
            $from = pSQL(date('Y-m-d', strtotime('-' . ($offset + 7) . ' days')));
            $to   = pSQL(date('Y-m-d', strtotime('-' . $offset . ' days')));
            $row  = $this->db->getRow("
                SELECT
                    COUNT(CASE WHEN event_type = 'sent'              THEN 1 END) AS sent,
                    COUNT(CASE WHEN event_type = 'open' AND is_mpp=0 THEN 1 END) AS opens,
                    COUNT(CASE WHEN event_type = 'click'             THEN 1 END) AS clicks
                FROM `{$table}`
                WHERE id_shop     = {$this->idShop}
                  AND DATE(date_add) >= '{$from}'
                  AND DATE(date_add) <  '{$to}'
            ");
            $raw[$period] = $row ?: ['sent' => 0, 'opens' => 0, 'clicks' => 0];

            // Neria n'a pas d'événement 'unsubscribed' dans neria_stat — le vrai
            // désabonnement est stocké dans neria_preferences (subscribed=0),
            // horodaté sur date_upd. On compte les nouveaux "subscribed=0" de la période.
            $raw[$period]['unsubs'] = (int) $this->db->getValue("
                SELECT COUNT(*) FROM `{$prefTable}`
                WHERE id_shop     = {$this->idShop}
                  AND subscribed  = 0
                  AND DATE(date_upd) >= '{$from}'
                  AND DATE(date_upd) <  '{$to}'
            ");
        }

        // Revenus attribués — depuis neria_stat event_type='conversion'
        $statTable = _DB_PREFIX_ . self::TABLE;
        foreach (['current' => 0, 'previous' => 7] as $period => $offset) {
            $from = pSQL(date('Y-m-d', strtotime('-' . ($offset + 7) . ' days')));
            $to   = pSQL(date('Y-m-d', strtotime('-' . $offset . ' days')));
            $rev  = (float) $this->db->getValue(
                "SELECT COALESCE(SUM(revenue), 0) FROM `{$statTable}`
                 WHERE event_type = 'conversion'
                   AND id_shop    = {$this->idShop}
                   AND DATE(date_add) >= '{$from}'
                   AND DATE(date_add) <  '{$to}'"
            );
            $raw[$period]['revenue'] = $rev;
        }

        $result = [];
        foreach (['sent', 'opens', 'clicks', 'unsubs', 'revenue'] as $key) {
            $cur   = (float) ($raw['current'][$key]  ?? 0);
            $prev  = (float) ($raw['previous'][$key] ?? 0);
            $delta = $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;
            $isGood = $key === 'unsubs' ? ($delta !== null && $delta < 0) : ($delta !== null && $delta > 0);
            $result[$key] = [
                'current'  => $key === 'revenue' ? round($cur, 2) : (int) $cur,
                'previous' => $key === 'revenue' ? round($prev, 2) : (int) $prev,
                'delta'    => $delta,
                'good'     => $delta === null ? null : $isGood,
            ];
        }

        // Taux d'ouverture et de clic
        $sentCur  = max(1, (float) ($raw['current']['sent']  ?? 1));
        $sentPrev = max(1, (float) ($raw['previous']['sent'] ?? 1));
        foreach (['open_rate', 'click_rate'] as $rk) {
            $base  = $rk === 'open_rate' ? 'opens' : 'clicks';
            $cur   = round((float) ($raw['current'][$base]  ?? 0) / $sentCur  * 100, 1);
            $prev  = round((float) ($raw['previous'][$base] ?? 0) / $sentPrev * 100, 1);
            $delta = $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;
            $result[$rk] = [
                'current'  => $cur,
                'previous' => $prev,
                'delta'    => $delta,
                'good'     => $delta === null ? null : $delta > 0,
            ];
        }

        return $result;
    }

    /**
     * Graphique d'engagement email — envois / ouvertures / clics par jour.
     */
    public function getEngagementDailyChart(int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = pSQL(date('Y-m-d', strtotime("-{$days} days")));

        $rows = $this->db->executeS("
            SELECT DATE(date_add) AS d,
                   COUNT(CASE WHEN event_type = 'sent'              THEN 1 END) AS sent,
                   COUNT(CASE WHEN event_type = 'open' AND is_mpp=0 THEN 1 END) AS opens,
                   COUNT(CASE WHEN event_type = 'click'             THEN 1 END) AS clicks
            FROM `{$table}`
            WHERE id_shop  = {$this->idShop}
              AND date_add >= '{$dateFrom}'
            GROUP BY DATE(date_add)
            ORDER BY d ASC
        ");

        $dates = [];
        for ($i = $days; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime("-{$i} days"));
        }

        $byDate = [];
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $byDate[$r['d']] = $r;
        }

        $sent = $opens = $clicks = [];
        foreach ($dates as $d) {
            $r        = $byDate[$d] ?? null;
            $sent[]   = $r ? (int) $r['sent']   : 0;
            $opens[]  = $r ? (int) $r['opens']  : 0;
            $clicks[] = $r ? (int) $r['clicks'] : 0;
        }

        return ['dates' => $dates, 'sent' => $sent, 'opens' => $opens, 'clicks' => $clicks];
    }

    /**
     * Heatmap des ouvertures — grille WEEKDAY×HOUR (0=lun, 6=dim).
     */
    public function getOpenHeatmap(int $days = 90): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = pSQL(date('Y-m-d', strtotime("-{$days} days")));

        $rows = $this->db->executeS("
            SELECT WEEKDAY(date_add) AS dow, HOUR(date_add) AS h, COUNT(*) AS cnt
            FROM `{$table}`
            WHERE event_type = 'open'
              AND is_mpp     = 0
              AND id_shop    = {$this->idShop}
              AND date_add  >= '{$dateFrom}'
            GROUP BY WEEKDAY(date_add), HOUR(date_add)
        ");

        $grid = [];
        for ($d = 0; $d < 7; $d++) {
            $grid[$d] = array_fill(0, 24, 0);
        }

        $max = 0;
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $cnt = (int) $r['cnt'];
            $grid[(int) $r['dow']][(int) $r['h']] = $cnt;
            if ($cnt > $max) {
                $max = $cnt;
            }
        }

        return ['grid' => $grid, 'max' => $max, 'days' => $days];
    }

    /**
     * Top 10 templates par taux d'ouverture ou de clic (30 derniers jours).
     */
    public function getTopTemplatesByMetric(string $metric = 'rate_open', int $limit = 10): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = pSQL(date('Y-m-d', strtotime('-30 days')));

        // MySQL interdit de référencer l'alias d'une fonction d'agrégat dans
        // une expression arithmétique d'ORDER BY (erreur 1247 "reference to
        // group function") — on répète donc l'expression COUNT() complète
        // plutôt que l'alias.
        $sentExpr = "COUNT(CASE WHEN event_type = 'sent' THEN 1 END)";
        $orderBy = $metric === 'rate_click'
            ? "COUNT(CASE WHEN event_type = 'click' THEN 1 END) / NULLIF({$sentExpr}, 0) DESC"
            : "COUNT(CASE WHEN event_type = 'open' AND is_mpp=0 THEN 1 END) / NULLIF({$sentExpr}, 0) DESC";

        $rows = $this->db->executeS("
            SELECT template,
                   COUNT(CASE WHEN event_type = 'sent'              THEN 1 END) AS sent,
                   COUNT(CASE WHEN event_type = 'open' AND is_mpp=0 THEN 1 END) AS opens,
                   COUNT(CASE WHEN event_type = 'click'             THEN 1 END) AS clicks
            FROM `{$table}`
            WHERE id_shop  = {$this->idShop}
              AND date_add >= '{$dateFrom}'
            GROUP BY template
            HAVING sent >= 5
            ORDER BY {$orderBy}
            LIMIT " . (int) $limit
        );

        return array_map(function ($r) {
            $sent   = (int) $r['sent'];
            $opens  = (int) $r['opens'];
            $clicks = (int) $r['clicks'];
            return [
                'template'   => $r['template'],
                'sent'       => $sent,
                'opens'      => $opens,
                'clicks'     => $clicks,
                'rate_open'  => $sent > 0 ? round($opens  / $sent * 100, 1) : 0.0,
                'rate_click' => $sent > 0 ? round($clicks / $sent * 100, 1) : 0.0,
            ];
        }, is_array($rows) ? $rows : []);
    }

    /**
     * Top templates par revenus attribués (30 derniers jours) — via neria_stat conversions.
     */
    public function getTopTemplatesByRevenue(int $limit = 10): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        $dateFrom = pSQL(date('Y-m-d', strtotime('-30 days')));

        $rows = $this->db->executeS("
            SELECT template, COUNT(*) AS orders, SUM(revenue) AS revenue
            FROM `{$table}`
            WHERE event_type = 'conversion'
              AND id_shop    = {$this->idShop}
              AND revenue    > 0
              AND date_add  >= '{$dateFrom}'
            GROUP BY template
            ORDER BY revenue DESC
            LIMIT " . (int) $limit
        );

        return array_map(fn($r) => [
            'template' => $r['template'],
            'orders'   => (int) $r['orders'],
            'revenue'  => round((float) $r['revenue'], 2),
        ], is_array($rows) ? $rows : []);
    }

    /**
     * Comparatif mois M vs mois M-1.
     */
    public function getMonthlyComparison(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;

        // "Mois à date" : compare les N premiers jours du mois en cours aux
        // N premiers jours du mois précédent (même quantième), pas au mois
        // précédent en entier — sinon un mois en cours partiel (ex. le 16
        // juillet) était systématiquement comparé à un mois complet (juin
        // entier), affichant une "chute" fictive d'activité à volume
        // constant, tant que le mois n'est pas terminé.
        $dayOfMonth       = (int) date('j');
        $prevMonthLastDay = (int) date('t', strtotime('first day of last month'));
        $prevEndDay       = min($dayOfMonth, $prevMonthLastDay);

        $periods = [
            'current'  => [date('Y-m-01'), date('Y-m-d')],
            'previous' => [
                date('Y-m-01', strtotime('first day of last month')),
                date('Y-m-', strtotime('first day of last month')) . str_pad((string) $prevEndDay, 2, '0', STR_PAD_LEFT),
            ],
        ];

        $prefTable = _DB_PREFIX_ . 'neria_preferences';

        $data = [];
        foreach ($periods as $label => [$from, $to]) {
            $from = pSQL($from);
            $to   = pSQL($to);
            $row  = $this->db->getRow("
                SELECT
                    COUNT(CASE WHEN event_type = 'sent'              THEN 1 END) AS sent,
                    COUNT(CASE WHEN event_type = 'open' AND is_mpp=0 THEN 1 END) AS opens,
                    COUNT(CASE WHEN event_type = 'click'             THEN 1 END) AS clicks
                FROM `{$table}`
                WHERE id_shop      = {$this->idShop}
                  AND DATE(date_add) >= '{$from}'
                  AND DATE(date_add) <= '{$to}'
            ");
            $sent   = (int) ($row['sent']  ?? 0);
            $opens  = (int) ($row['opens'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            // Voir getKpiTrends() : le désabonnement réel vit dans neria_preferences,
            // pas dans neria_stat (aucun événement 'unsubscribed' n'y est jamais écrit).
            $unsubs = (int) $this->db->getValue("
                SELECT COUNT(*) FROM `{$prefTable}`
                WHERE id_shop     = {$this->idShop}
                  AND subscribed  = 0
                  AND DATE(date_upd) >= '{$from}'
                  AND DATE(date_upd) <= '{$to}'
            ");
            $data[$label] = [
                'sent'       => $sent,
                'opens'      => $opens,
                'clicks'     => $clicks,
                'unsubs'     => $unsubs,
                'rate_open'  => $sent > 0 ? round($opens  / $sent * 100, 1) : 0.0,
                'rate_click' => $sent > 0 ? round($clicks / $sent * 100, 1) : 0.0,
            ];
        }

        // Revenus attribués — depuis neria_stat event_type='conversion'
        $statTable = _DB_PREFIX_ . self::TABLE;
        foreach ($periods as $label => [$from, $to]) {
            $from = pSQL($from);
            $to   = pSQL($to);
            $data[$label]['revenue'] = round((float) $this->db->getValue(
                "SELECT COALESCE(SUM(revenue), 0) FROM `{$statTable}`
                 WHERE event_type = 'conversion'
                   AND id_shop    = {$this->idShop}
                   AND revenue    > 0
                   AND DATE(date_add) >= '{$from}'
                   AND DATE(date_add) <= '{$to}'"
            ), 2);
        }

        // Deltas
        foreach (['sent', 'opens', 'clicks', 'unsubs', 'rate_open', 'rate_click', 'revenue'] as $key) {
            $cur   = (float) ($data['current'][$key]  ?? 0);
            $prev  = (float) ($data['previous'][$key] ?? 0);
            $data['delta'][$key] = $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;
        }

        $data['labels'] = [
            'current'  => $this->formatMonthLabel(new \DateTime('first day of this month')),
            'previous' => $this->formatMonthLabel(new \DateTime('first day of last month')),
        ];

        return $data;
    }

    /**
     * Formate un mois/année localisé pour l'affichage BO ("juillet 2026").
     * N'utilise plus strftime() — dépréciée en PHP 8.1, supprimée en PHP 9,
     * incompatible avec la cible de compatibilité du module (PS8 → PS9).
     * Utilise IntlDateFormatter (langue de l'employé connecté) si disponible,
     * sinon un repli numérique stable quel que soit l'environnement serveur.
     */
    private function formatMonthLabel(\DateTime $date): string
    {
        if (class_exists('\IntlDateFormatter')) {
            $employee = \Context::getContext()->employee;
            $isoLang  = ($employee && $employee->id)
                ? (\Language::getIsoById((int) $employee->id_lang) ?: 'fr')
                : 'fr';
            try {
                $fmt = new \IntlDateFormatter(
                    $isoLang,
                    \IntlDateFormatter::LONG,
                    \IntlDateFormatter::NONE,
                    null,
                    null,
                    'MMMM yyyy'
                );
                $label = $fmt->format($date);
                if (is_string($label) && $label !== '') {
                    return $label;
                }
            } catch (\Throwable $e) {
                // repli silencieux
            }
        }

        return $date->format('m/Y');
    }

    /**
     * Score santé global — compte les résultats du dernier diagnostic.
     * Retourne [ok, warning, error, total, score_pct]
     */
    public function getHealthScore(): array
    {
        $raw = \Configuration::get(\HealthCheckManager::CONFIG_RESULTS);
        if (!$raw) {
            return ['ok' => 0, 'warning' => 0, 'error' => 0, 'total' => 0, 'score_pct' => 0];
        }

        $results = json_decode($raw, true);
        if (!is_array($results)) {
            return ['ok' => 0, 'warning' => 0, 'error' => 0, 'total' => 0, 'score_pct' => 0];
        }

        $ok = $warn = $err = 0;
        foreach ($results as $check) {
            $status = $check['status'] ?? 'ok';
            if ($status === 'error') {
                $err++;
            } elseif ($status === 'warning') {
                $warn++;
            } else {
                $ok++;
            }
        }

        $total = $ok + $warn + $err;
        return [
            'ok'        => $ok,
            'warning'   => $warn,
            'error'     => $err,
            'total'     => $total,
            'score_pct' => $total > 0 ? (int) round($ok / $total * 100) : 100,
        ];
    }

    // ============================================================
    // FEATURE 3 — DÉTECTION D'ANOMALIES MÉTRIQUES
    // ============================================================

    /**
     * Compare les taux d'ouverture/clic cette semaine vs la semaine précédente.
     * Retourne la liste des templates avec une chute > 20 %.
     */
    public function detectAnomalies(): array
    {
        $table = _DB_PREFIX_ . 'neria_stat';

        $templates = $this->db->executeS(
            "SELECT DISTINCT `template` FROM `{$table}`
             WHERE `event_type` = 'sent'
               AND `id_shop`    = {$this->idShop}
               AND `date_add`  > DATE_SUB(NOW(), INTERVAL 14 DAY)"
        );

        if (empty($templates)) {
            return [];
        }

        $anomalies = [];
        foreach ($templates as $tpl) {
            $template = $tpl['template'];
            $thisWeek = $this->getTemplateWeekRates($template, 7, 0);
            $lastWeek = $this->getTemplateWeekRates($template, 14, 7);

            if (!$thisWeek || !$lastWeek || $lastWeek['sent'] < 10 || $thisWeek['sent'] < 5) {
                continue;
            }

            $openDrop  = $lastWeek['open_rate']  > 0
                ? round(($lastWeek['open_rate']  - $thisWeek['open_rate'])  / $lastWeek['open_rate']  * 100, 1)
                : 0.0;
            $clickDrop = $lastWeek['click_rate'] > 0
                ? round(($lastWeek['click_rate'] - $thisWeek['click_rate']) / $lastWeek['click_rate'] * 100, 1)
                : 0.0;

            if ($openDrop >= 20 || $clickDrop >= 20) {
                $anomalies[] = [
                    'template'   => $template,
                    'open_drop'  => $openDrop,
                    'click_drop' => $clickDrop,
                    'this_week'  => $thisWeek,
                    'last_week'  => $lastWeek,
                ];
            }
        }

        return $anomalies;
    }

    private function getTemplateWeekRates(string $template, int $daysBack, int $daysOffset): ?array
    {
        $t = _DB_PREFIX_ . 'neria_stat';

        $sent = (int) $this->db->getValue(sprintf(
            "SELECT COUNT(*) FROM `{$t}`
             WHERE `template` = '%s' AND `event_type` = 'sent'
               AND `id_shop`   = %d
               AND `date_add` > DATE_SUB(NOW(), INTERVAL %d DAY)
               AND `date_add` <= DATE_SUB(NOW(), INTERVAL %d DAY)",
            pSQL($template), $this->idShop, $daysBack, $daysOffset
        ));

        if ($sent === 0) {
            return null;
        }

        // COUNT(*) événement (pas COUNT(DISTINCT id_customer)) pour rester
        // comparable au taux affiché partout ailleurs dans ce fichier
        // (getGlobalReport, getKpis...) — et exclusion des pré-chargements
        // Apple Mail Privacy Protection (is_mpp), comme ces mêmes méthodes.
        $opens = (int) $this->db->getValue(sprintf(
            "SELECT COUNT(*) FROM `{$t}`
             WHERE `template` = '%s' AND `event_type` = 'open' AND `is_mpp` = 0
               AND `id_shop`   = %d
               AND `date_add` > DATE_SUB(NOW(), INTERVAL %d DAY)
               AND `date_add` <= DATE_SUB(NOW(), INTERVAL %d DAY)",
            pSQL($template), $this->idShop, $daysBack, $daysOffset
        ));

        $clicks = (int) $this->db->getValue(sprintf(
            "SELECT COUNT(*) FROM `{$t}`
             WHERE `template` = '%s' AND `event_type` = 'click'
               AND `id_shop`   = %d
               AND `date_add` > DATE_SUB(NOW(), INTERVAL %d DAY)
               AND `date_add` <= DATE_SUB(NOW(), INTERVAL %d DAY)",
            pSQL($template), $this->idShop, $daysBack, $daysOffset
        ));

        return [
            'sent'       => $sent,
            'open_rate'  => round($opens  / $sent * 100, 1),
            'click_rate' => round($clicks / $sent * 100, 1),
        ];
    }
}