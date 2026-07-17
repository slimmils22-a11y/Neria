<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — SegmentManager
 *
 * Segmentation automatique des clients selon leur comportement email.
 *
 * Segments (par priorité décroissante) :
 *   ambassador — ouvre tout, clique souvent, converti 2+ fois
 *   loyal      — ouvre régulièrement, au moins 1 conversion
 *   warm       — ouvre parfois, dernier open < 90 j
 *   dormant    — a ouvert par le passé, dernier open > 90 j
 *   ghost      — n'a jamais ouvert un seul email
 *
 * Recalcul quotidien via BehavioralCronManager::run().
 * Données source : ps_neria_stat (ouvertures, clics, conversions).
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SegmentManager
{
    const TABLE = 'neria_customer_segment';

    const AMBASSADOR = 'ambassador';
    const LOYAL      = 'loyal';
    const WARM       = 'warm';
    const DORMANT    = 'dormant';
    const GHOST      = 'ghost';

    // Seuils de classification
    const AMBASSADOR_MIN_OPENS       = 5;
    const AMBASSADOR_MIN_CONVERSIONS = 2;
    const AMBASSADOR_MIN_OPEN_RATE   = 0.50;
    const LOYAL_MIN_OPENS            = 2;
    const LOYAL_MIN_CONVERSIONS      = 1;
    const DORMANT_THRESHOLD_DAYS     = 90;

    // Templates recommandés par segment (suggestion dans le formulaire de campagne)
    const RECOMMENDED_TEMPLATES = [
        self::AMBASSADOR => 'vip',
        self::LOYAL      => 'early_access',
        self::WARM       => 'personal_shopper_intro',
        self::DORMANT    => 'win_back',
        self::GHOST      => 'win_back',
    ];

    // Templates disponibles pour les campagnes segment
    const CAMPAIGN_TEMPLATES = [
        'win_back', 'vip', 'early_access', 'private_sale', 'private_invitation',
        'exclusive_preview', 'end_of_year_gift', 'personal_shopper_intro',
        'concierge_followup', 'loyalty_reward_expiry',
    ];

    private \Neria $module;
    private \Db    $db;
    private int    $idShop;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(\Neria $module)
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

    // ============================================================
    // RECALCUL
    // ============================================================

    /**
     * Recalcule et persiste les segments de tous les clients actifs.
     * Un seul INSERT … ON DUPLICATE KEY UPDATE en base.
     *
     * @return int Nombre de lignes insérées/mises à jour
     */
    public function recomputeAll(): int
    {
        $stat  = _DB_PREFIX_ . 'neria_stat';
        $table = _DB_PREFIX_ . self::TABLE;
        $shop  = $this->idShop;

        $aOpen  = self::AMBASSADOR_MIN_OPENS;
        $aConv  = self::AMBASSADOR_MIN_CONVERSIONS;
        $aRate  = self::AMBASSADOR_MIN_OPEN_RATE;
        $lOpen  = self::LOYAL_MIN_OPENS;
        $lConv  = self::LOYAL_MIN_CONVERSIONS;
        $dDays  = self::DORMANT_THRESHOLD_DAYS;

        $sql = "
            INSERT INTO `{$table}`
                (`id_shop`, `id_customer`, `segment`,
                 `total_sent`, `total_opens`, `total_clicks`, `total_conversions`,
                 `last_open`, `last_conversion`, `computed_at`)
            SELECT
                {$shop}                    AS id_shop,
                m.id_customer,
                CASE
                    WHEN m.total_opens >= {$aOpen}
                     AND m.total_conv  >= {$aConv}
                     AND (m.total_opens / NULLIF(m.total_sent, 0)) >= {$aRate}
                        THEN 'ambassador'
                    WHEN m.total_opens >= {$lOpen}
                     AND m.total_conv  >= {$lConv}
                        THEN 'loyal'
                    WHEN m.total_opens >= 1
                     AND m.last_open   >= DATE_SUB(NOW(), INTERVAL {$dDays} DAY)
                        THEN 'warm'
                    WHEN m.total_opens >= 1
                        THEN 'dormant'
                    ELSE 'ghost'
                END                        AS segment,
                m.total_sent,
                m.total_opens,
                m.total_clicks,
                m.total_conv,
                m.last_open,
                m.last_conv,
                NOW()
            FROM (
                SELECT
                    id_customer,
                    SUM(event_type = 'sent')        AS total_sent,
                    SUM(event_type = 'open')        AS total_opens,
                    SUM(event_type = 'click')       AS total_clicks,
                    SUM(event_type = 'conversion')  AS total_conv,
                    MAX(CASE WHEN event_type = 'open'       THEN date_add END) AS last_open,
                    MAX(CASE WHEN event_type = 'conversion' THEN date_add END) AS last_conv
                FROM `{$stat}`
                WHERE id_shop = {$shop} AND id_customer > 0
                GROUP BY id_customer
            ) m
            ON DUPLICATE KEY UPDATE
                `segment`           = VALUES(`segment`),
                `total_sent`        = VALUES(`total_sent`),
                `total_opens`       = VALUES(`total_opens`),
                `total_clicks`      = VALUES(`total_clicks`),
                `total_conversions` = VALUES(`total_conversions`),
                `last_open`         = VALUES(`last_open`),
                `last_conversion`   = VALUES(`last_conversion`),
                `computed_at`       = VALUES(`computed_at`)
        ";

        $this->db->execute($sql);
        $affected = (int) $this->db->Affected_Rows();

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.segment_recomputed', ['n' => $affected]),
            '', 'SegmentManager'
        );

        return $affected;
    }

    // ============================================================
    // LECTURE
    // ============================================================

    /**
     * Nombre de clients par segment.
     *
     * @return array<string,int> Ex: ['ambassador'=>3, 'loyal'=>12, ...]
     */
    public function getSegmentCounts(): array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $rows  = $this->db->executeS(
            "SELECT `segment`, COUNT(*) AS cnt
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
             GROUP BY `segment`"
        );

        $counts = array_fill_keys(self::getAllSegments(), 0);
        foreach ((array) $rows as $row) {
            if (isset($counts[$row['segment']])) {
                $counts[$row['segment']] = (int) $row['cnt'];
            }
        }
        return $counts;
    }

    /**
     * Liste des clients dans un segment, triés par activité décroissante.
     */
    /**
     * @param array $filters Clés optionnelles : slot, id_lang, id_country
     */
    public function getCustomersBySegment(
        string $segment,
        int    $limit   = 50,
        int    $offset  = 0,
        array  $filters = []
    ): array {
        $table   = _DB_PREFIX_ . self::TABLE;
        $cTable  = _DB_PREFIX_ . 'customer';
        $chTable = _DB_PREFIX_ . 'neria_churn_score';
        $aTable  = _DB_PREFIX_ . 'address';
        $coTable = _DB_PREFIX_ . 'country';
        $lTable  = _DB_PREFIX_ . 'lang';

        $extraWhere = '';
        if (!empty($filters['slot'])) {
            $extraWhere .= " AND ch.preferred_slot = '" . pSQL($filters['slot']) . "'";
        }
        if (!empty($filters['lang_iso'])) {
            $extraWhere .= " AND l.iso_code = '" . pSQL($filters['lang_iso']) . "'";
        }
        if (!empty($filters['id_country'])) {
            $extraWhere .= " AND addr.id_country = " . (int) $filters['id_country'];
        }

        $rows = $this->db->executeS(sprintf(
            "SELECT s.id_customer, s.total_sent, s.total_opens, s.total_clicks,
                    s.total_conversions, s.last_open, s.last_conversion,
                    c.firstname, c.lastname, c.email,
                    ch.score AS churn_score, ch.preferred_slot,
                    l.iso_code AS lang_code,
                    co.iso_code AS country_code
             FROM `%s` s
             INNER JOIN `%s` c ON c.id_customer = s.id_customer
             LEFT JOIN `%s` ch ON ch.id_customer = s.id_customer AND ch.id_shop = s.id_shop
             LEFT JOIN `%s` l ON l.id_lang = c.id_lang
             LEFT JOIN `%s` addr ON addr.id_address = (
                 SELECT id_address FROM `%s` a2
                 WHERE a2.id_customer = c.id_customer AND a2.deleted = 0
                 ORDER BY a2.date_add DESC LIMIT 1
             )
             LEFT JOIN `%s` co ON co.id_country = addr.id_country
             WHERE s.id_shop = %d AND s.segment = '%s'
               AND c.active = 1 AND c.deleted = 0
               %s
             ORDER BY s.total_opens DESC, s.last_open DESC
             LIMIT %d OFFSET %d",
            $table, $cTable, $chTable, $lTable, $aTable, $aTable, $coTable,
            $this->idShop,
            pSQL($segment),
            $extraWhere,
            $limit, $offset
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Segment d'un client donné (pour bloc fiche client).
     */
    public function getCustomerSegment(int $idCustomer): ?array
    {
        $table = _DB_PREFIX_ . self::TABLE;
        $row   = $this->db->getRow(sprintf(
            "SELECT * FROM `%s`
             WHERE `id_shop` = %d AND `id_customer` = %d",
            $table, $this->idShop, $idCustomer
        ));

        return $row ?: null;
    }

    // ============================================================
    // CAMPAGNE SEGMENT
    // ============================================================

    /**
     * Envoie un template email à tous les clients d'un segment.
     * Passe par Mail::Send → actionEmailSendBefore → EmailRenderer.
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    /**
     * @param array $filters Clés optionnelles : slot, id_lang, id_country
     * @return array{sent:int, failed:int, skipped:int}
     */
    /**
     * Contrôle à blanc avant tout envoi de masse : vérifie le template,
     * le segment (non vide) et la présence d'au moins un fichier de
     * template dans une langue réellement utilisée par les destinataires —
     * sans envoyer un seul email. Permet au marchand (ou au BO) de détecter
     * un problème AVANT de lancer une campagne à des centaines de clients.
     */
    public function preflightCheck(string $segment, string $template, array $filters = []): array
    {
        $issues = [];
        $blockingCount = 0;

        if (!in_array($template, self::CAMPAIGN_TEMPLATES, true)) {
            $issues[] = \AdminTranslator::tVars('msg.segment_template_not_allowed', ['template' => $template]);
            $blockingCount++;
        }

        $customers = $this->getCustomersBySegment($segment, 500, 0, $filters);
        $recipientCount = count($customers);

        if ($recipientCount === 0) {
            $issues[] = \AdminTranslator::tVars('msg.segment_no_recipients', ['segment' => $segment]);
            $blockingCount++;
        }

        $missingLangFiles = [];
        if (!$issues && $recipientCount > 0) {
            $langsUsed = [];
            foreach ($customers as $c) {
                // getCustomersBySegment() ne sélectionne pas id_lang, seulement
                // lang_code (iso) — lire c['id_lang'] ici renvoyait toujours 0
                // et faisait retomber TOUS les clients sur PS_LANG_DEFAULT,
                // rendant ce contrôle aveugle aux langues réellement utilisées.
                $langCode = (string) ($c['lang_code'] ?? '') ?: (\Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT')) ?: 'fr');
                $langsUsed[$langCode] = true;
            }
            foreach (array_keys($langsUsed) as $langCode) {
                $templateFile = _PS_MODULE_DIR_ . 'neria/mails/' . $langCode . '/' . $template . '.html';
                if (!file_exists($templateFile)) {
                    $missingLangFiles[] = $langCode;
                }
            }
            if ($missingLangFiles) {
                // Non bloquant : ces destinataires sont simplement ignorés, l'envoi
                // continue pour les autres — ne pas incrémenter $blockingCount ici.
                $issues[] = \AdminTranslator::tVars('msg.segment_missing_lang_files', ['langs' => implode(', ', $missingLangFiles)]);
            }
        }

        return [
            'ok'               => empty($issues) || (count($issues) === 1 && $missingLangFiles),
            'blocking'         => $blockingCount > 0,
            'recipient_count'  => $recipientCount,
            'issues'           => $issues,
        ];
    }

    public function sendToSegment(string $segment, string $template, array $filters = []): array
    {
        $preflight = $this->preflightCheck($segment, $template, $filters);
        if ($preflight['blocking']) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.segment_campaign_cancelled', ['segment' => $segment, 'template' => $template, 'issues' => implode(' ', $preflight['issues'])]),
                $template, 'SegmentManager'
            );
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'preflight_failed', 'preflight' => $preflight];
        }

        $customers = $this->getCustomersBySegment($segment, 500, 0, $filters);
        $sent = 0; $failed = 0; $skipped = 0;
        $failureSamples = [];

        foreach ($customers as $c) {
            $customer = new \Customer((int) $c['id_customer']);
            $idLang   = $customer->id
                ? ((int) $customer->id_lang ?: (int) \Configuration::get('PS_LANG_DEFAULT'))
                : (int) \Configuration::get('PS_LANG_DEFAULT');

            $langCode     = \Language::getIsoById($idLang) ?: 'fr';
            $templateFile = _PS_MODULE_DIR_ . 'neria/mails/' . $langCode . '/' . $template . '.html';

            if (!file_exists($templateFile)) {
                $skipped++;
                continue;
            }

            $vars = [
                '{firstname}'   => $c['firstname'],
                '{lastname}'    => $c['lastname'],
                '{shop_name}'   => \Configuration::get('PS_SHOP_NAME'),
                '{history_url}' => \Context::getContext()->link->getPageLink('history', true, $idLang),
                '{shop_url}'    => \Tools::getShopDomainSsl(true),
            ];

            $toName = trim($c['firstname'] . ' ' . $c['lastname']) ?: null;

            try {
                $ok = \Mail::Send(
                    $idLang, $template, '', $vars,
                    $c['email'], $toName,
                    null, null, null, null,
                    _PS_MODULE_DIR_ . 'neria/mails/',
                    false,
                    $this->idShop
                );
                $ok ? $sent++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                // Sans ceci, un "48 échecs" sur 500 clients ne donnait aucun
                // moyen de diagnostiquer la cause réelle. Échantillon limité
                // (5 messages distincts) pour ne pas gonfler le log.
                if (count($failureSamples) < 5 && !in_array($e->getMessage(), $failureSamples, true)) {
                    $failureSamples[] = $e->getMessage();
                }
            }
        }

        $filterParts = [];
        if (!empty($filters['slot']))       { $filterParts[] = 'slot=' . $filters['slot']; }
        if (!empty($filters['lang_iso']))   { $filterParts[] = 'lang=' . $filters['lang_iso']; }
        if (!empty($filters['id_country'])) { $filterParts[] = 'country=' . $filters['id_country']; }
        $filterStr = $filterParts ? ' [filters: ' . implode(', ', $filterParts) . ']' : '';

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.segment_campaign_summary', [
                'segment' => $segment,
                'template' => $template,
                'filters' => $filterStr,
                'sent'    => $sent,
                'failed'  => $failed,
                'skipped' => $skipped,
            ]),
            $template, 'SegmentManager'
        );

        if (!empty($failureSamples)) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.segment_campaign_failure_samples', [
                    'segment' => $segment, 'template' => $template,
                    'samples' => implode(' | ', $failureSamples),
                ]),
                $template, 'SegmentManager'
            );
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    // ============================================================
    // HELPERS
    // ============================================================

    public static function getAllSegments(): array
    {
        return [self::AMBASSADOR, self::LOYAL, self::WARM, self::DORMANT, self::GHOST];
    }
}
