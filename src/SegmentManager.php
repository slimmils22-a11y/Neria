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
    // Délai de grâce avant de classer un client 0-ouverture en 'ghost' —
    // laisse le temps réaliste d'ouvrir un premier email avant de le
    // considérer comme un vrai désengagement (cf. commentaire recomputeAll()).
    const NEW_CUSTOMER_GRACE_DAYS    = 14;

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

    /**
     * Round 200 : reproduit OrderTriggersManager::explicitSendBlockReason()
     * (bounce/blacklist/cooldown) pour que sendToSegment() ne compte plus en
     * "envoyé" un email silencieusement bloqué par le hook
     * actionEmailSendBefore — Mail::Send() renvoie toujours true dans ce cas.
     * Pas de scope par commande ici (campagne segment, pas de $idOrder).
     */
    private function explicitSendBlockReason(string $template, string $email, int $idLang, int $idCustomer = 0): ?string
    {
        // Round 286 : getCustomersBySegment() ne filtre active=1/deleted=0
        // QU'AU MOMENT du SELECT initial (jusqu'à 500 destinataires, chargés
        // en mémoire PHP en une fois) — un envoi SMTP réel prend ~150-300ms
        // par destinataire, donc un lot de 500 peut s'étaler sur 1 à 2
        // minutes. Un client désactivé en BO ou ayant exercé son droit à
        // l'effacement RGPD (purgeCustomerData(), round 278 — la
        // suppression/effacement natif PrestaShop met bien deleted=1 sur la
        // ligne ps_customer) PENDANT ce laps de temps continuait de recevoir
        // la campagne aux itérations suivantes, avec des données déjà
        // périmées en RAM (prénom/nom, éventuellement déjà purgés côté
        // Neria). Relecture fraîche et légère (indexée sur la clé primaire),
        // pas de cache — cohérent avec le reste de cette méthode qui
        // interroge déjà bounce/blacklist/cooldown à chaque itération.
        if ($idCustomer > 0) {
            $row = $this->db->getRow(
                'SELECT `active`, `deleted` FROM `' . _DB_PREFIX_ . 'customer`
                 WHERE `id_customer` = ' . $idCustomer,
                false
            );
            if (!$row || (int) $row['active'] !== 1 || (int) $row['deleted'] !== 0) {
                return 'customer_inactive';
            }
        }

        if (class_exists('BounceManager') && \BounceManager::isBounced($email)) {
            return 'bounce';
        }

        if (class_exists('BlacklistManager')) {
            $langIso = class_exists('TranslationEngine')
                ? (new \TranslationEngine($this->module))->langFromId($idLang)
                : (string) (\Language::getIsoById($idLang) ?: '');
            if ((new \BlacklistManager($this->idShop))->isBlacklisted($template, $langIso)) {
                return 'blacklist';
            }
        }

        if (class_exists('ConfigManager') && class_exists('CooldownManager')
            && (new \ConfigManager($this->module))->isCooldownEnabled()
        ) {
            $cdMinutes = (new \ConfigManager($this->module))->getCooldownMinutes();
            // sendToSegment() ne transmet ni {id_order} ni {cooldown_scope}
            // dans $vars (cf. neria.php::hookActionEmailSendBeforeImpl()) —
            // le vrai contrôle exécuté au moment de Mail::Send() est donc
            // NON scopé ; ce pré-contrôle doit reproduire exactement le
            // même appel non scopé pour ne pas diverger.
            if ((new \CooldownManager())->isDuplicate($email, $template, $cdMinutes, $this->idShop)) {
                return 'cooldown';
            }
        }

        return null;
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

        // Bug du 2026-07-22 : un client tout juste inscrit (0 ouverture,
        // premier envoi il y a quelques heures) tombait dans le ELSE
        // 'ghost' faute d'avoir eu la moindre chance d'ouvrir son premier
        // email — au même titre qu'un client réellement inactif depuis des
        // mois. Impact réel : 'ghost' est le segment recommandé pour les
        // campagnes de réactivation ('win_back', cf. RECOMMENDED_TEMPLATES)
        // — un nouvel inscrit pouvait recevoir un email "vous nous
        // manquez" le jour même de son inscription. On exclut donc du
        // recalcul les clients dont le tout premier envoi date de moins de
        // {$newCustomerGraceDays} jours et qui n'ont encore rien ouvert :
        // ils n'obtiennent simplement pas encore de ligne de segment (même
        // logique que ChurnScoreManager pour les clients trop récents),
        // et se classeront correctement au prochain recalcul une fois
        // cette période de grâce passée.
        $newCustomerGraceDays = self::NEW_CUSTOMER_GRACE_DAYS;

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
                    -- is_mpp = 0 : exclut les pré-chargements automatiques
                    -- d'Apple Mail Privacy Protection (déclenchent le pixel
                    -- sans lecture réelle) — même filtre que StatsManager
                    -- partout ailleurs. Sans lui, un client qui n'ouvre
                    -- jamais réellement ses emails pouvait être classé
                    -- ambassador/loyal au lieu de ghost/dormant.
                    SUM(event_type = 'open' AND is_mpp = 0)        AS total_opens,
                    SUM(event_type = 'click')       AS total_clicks,
                    SUM(event_type = 'conversion')  AS total_conv,
                    MAX(CASE WHEN event_type = 'open' AND is_mpp = 0 THEN date_add END) AS last_open,
                    MAX(CASE WHEN event_type = 'conversion' THEN date_add END) AS last_conv,
                    MIN(CASE WHEN event_type = 'sent'       THEN date_add END) AS first_sent
                FROM `{$stat}`
                WHERE id_shop = {$shop} AND id_customer > 0
                GROUP BY id_customer
            ) m
            -- COALESCE(m.first_sent, '1970-01-01') : sans elle, un client sans
            -- aucun événement 'sent' (données de tracking orphelines — un
            -- open/click/conversion importé sans son 'sent' d'origine) a
            -- first_sent NULL, et `NULL >= ...` vaut NULL en SQL, faisant
            -- échouer tout le WHERE pour cette ligne : le client disparaissait
            -- silencieusement de neria_segment, invisible pour toute campagne.
            WHERE NOT (
                m.total_opens = 0
                AND COALESCE(m.first_sent, '1970-01-01') >= DATE_SUB(NOW(), INTERVAL {$newCustomerGraceDays} DAY)
            )
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

        // Round 148 : $execOk capturé — auparavant le résultat de execute()
        // était totalement ignoré (aucun log d'échec nulle part dans ce
        // fichier), et le log de succès plus bas était émis
        // inconditionnellement. Un échec SQL réel (verrou, table
        // corrompue) laissait neria_segment non recalculé sans aucune
        // trace exploitable — le Watchdog affirmait quand même un
        // "recompute" réussi.
        $execOk = $this->db->execute($sql);

        // Round 181 : Affected_Rows() sur un INSERT ... ON DUPLICATE KEY
        // UPDATE compte 1 par ligne insérée mais 2 par ligne mise à jour
        // dont une colonne change réellement de valeur (comportement mysqli
        // par défaut, MYSQLI_CLIENT_FOUND_ROWS non activé côté core
        // PrestaShop) — le recalcul quotidien traitant très majoritairement
        // des UPDATE (clients déjà segmentés dont les stats évoluent), le
        // chiffre loggué pouvait quasiment doubler le nombre réel de
        // clients traités (ex. "580 clients recalculés" sur une boutique de
        // 300 clients), rendant la métrique Watchdog inexploitable pour
        // détecter une vraie régression de volume. On recompte ici le
        // nombre réel de clients concernés via la même sous-requête/WHERE
        // que l'INSERT ci-dessus.
        $affected = (int) $this->db->getValue("
            SELECT COUNT(*) FROM (
                SELECT m.id_customer
                FROM (
                    SELECT
                        id_customer,
                        SUM(event_type = 'sent')        AS total_sent,
                        SUM(event_type = 'open' AND is_mpp = 0)        AS total_opens,
                        MAX(CASE WHEN event_type = 'conversion' THEN date_add END) AS last_conv,
                        MIN(CASE WHEN event_type = 'sent'       THEN date_add END) AS first_sent
                    FROM `{$stat}`
                    WHERE id_shop = {$shop} AND id_customer > 0
                    GROUP BY id_customer
                ) m
                WHERE NOT (
                    m.total_opens = 0
                    AND COALESCE(m.first_sent, '1970-01-01') >= DATE_SUB(NOW(), INTERVAL {$newCustomerGraceDays} DAY)
                )
            ) counted
        ");

        // Round 166 : contrairement à ChurnScoreManager::recomputeAll() et
        // PropensityScoreManager::recalculateAll(), cette méthode ne
        // purgeait JAMAIS les lignes neria_segment des clients sortis du
        // périmètre de calcul (l'INSERT ... ON DUPLICATE KEY UPDATE
        // ci-dessus ne fait que créer/mettre à jour, jamais supprimer). Un
        // client purgé RGPD (ps_neria_stat vidée) gardait indéfiniment son
        // ancien segment (ex. 'ambassador') — il continuait d'apparaître
        // dans getCustomersBySegment() et pouvait recevoir des campagnes
        // ciblées alors qu'il n'a plus aucune donnée réelle. On supprime
        // ici les lignes dont le client n'a plus AUCUN événement dans
        // neria_stat pour cette boutique (LEFT JOIN … IS NULL) — les
        // nouveaux clients en période de grâce (exclus de l'INSERT
        // ci-dessus mais qui ONT des stats) n'ont eux jamais eu de ligne à
        // purger, donc ne sont pas concernés.
        $purgeSql = "
            DELETE t FROM `{$table}` t
            LEFT JOIN (
                SELECT id_customer FROM `{$stat}`
                WHERE id_shop = {$shop} AND id_customer > 0
                GROUP BY id_customer
            ) keep ON keep.id_customer = t.id_customer
            WHERE t.id_shop = {$shop} AND keep.id_customer IS NULL
        ";
        $purgeOk = $this->db->execute($purgeSql);
        if ($purgeOk === false) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.segment_purge_failed'),
                '', 'SegmentManager'
            );
        }

        if ($execOk === false) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.segment_recompute_sql_failed', []),
                '', 'SegmentManager'
            );
            return 0;
        }

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
        $table  = _DB_PREFIX_ . self::TABLE;
        $cTable = _DB_PREFIX_ . 'customer';
        // Round 272 : doit filtrer c.active=1/c.deleted=0 comme
        // getCustomersBySegment() ci-dessous — sans ce JOIN, ce compteur
        // (affiché en badge sur chaque carte segment, et qui déclenche le
        // message "liste tronquée" au-delà de 50) incluait aussi les
        // clients désactivés/soft-deleted, jamais présents dans la
        // liste réelle affichée en dessous (elle, correctement filtrée).
        // Le marchand voyait un chiffre plus élevé que le nombre de lignes
        // réellement listées, et pouvait voir le message de troncature
        // s'afficher à tort alors que la liste affichée était déjà
        // complète. recomputeAll() ne nettoie neria_customer_segment que
        // pour un client sans AUCUN événement neria_stat restant (purge
        // RGPD complète, round 166) — jamais pour une simple désactivation
        // de compte, qui laisse la ligne de segment orpheline (au sens de
        // ce badge) indéfiniment jusqu'au prochain recalcul.
        $rows  = $this->db->executeS(
            "SELECT s.segment, COUNT(*) AS cnt
             FROM `{$table}` s
             INNER JOIN `{$cTable}` c ON c.id_customer = s.id_customer
             WHERE s.id_shop = {$this->idShop} AND c.active = 1 AND c.deleted = 0
             GROUP BY s.segment"
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

        $customers = $this->getCustomersBySegment($segment, 501, 0, $filters);
        $capped = count($customers) > 500;
        if ($capped) {
            $customers = array_slice($customers, 0, 500);
        }
        $recipientCount = count($customers);

        if ($recipientCount === 0) {
            $issues[] = \AdminTranslator::tVars('msg.segment_no_recipients', ['segment' => $segment]);
            $blockingCount++;
        }

        // Round 146 : filtre les préférences d'abonnement, comme
        // sendToSegment() le fait déjà (voir son commentaire détaillé).
        // Sans ce filtre, un segment ENTIÈREMENT désabonné du template
        // (recipient_count=40, aucune issue) passait ce contrôle à blanc
        // sans alerte, alors que l'envoi réel se solderait par 0 email —
        // contredisant l'objectif documenté de preflightCheck() : détecter
        // un problème AVANT de lancer la campagne, pas après coup dans le
        // rapport d'envoi.
        // Round 153 : isAllowedBatch() (1 requête groupée) au lieu d'un
        // isAllowed() par client (jusqu'à 500 requêtes SQL individuelles
        // pour un segment plein).
        $allowedCount = $recipientCount;
        if ($recipientCount > 0 && class_exists('PreferencesManager')) {
            $preferences = new \PreferencesManager($this->module);
            $allowedMap = $preferences->isAllowedBatch(
                array_column($customers, 'id_customer'),
                $template,
                $this->idShop
            );
            $allowedCount = count(array_filter($allowedMap));
            if ($allowedCount === 0) {
                $issues[] = \AdminTranslator::tVars('msg.segment_all_unsubscribed', ['segment' => $segment, 'template' => $template]);
                $blockingCount++;
            } elseif ($allowedCount < $recipientCount) {
                // Non bloquant : l'envoi continue pour les clients autorisés —
                // informe simplement le marchand du nombre réel attendu.
                $issues[] = \AdminTranslator::tVars('msg.segment_some_unsubscribed', [
                    'skipped' => $recipientCount - $allowedCount,
                    'total'   => $recipientCount,
                ]);
            }
        }

        if ($capped) {
            // Non bloquant : l'envoi continue quand même, plafonné à 500 — mais
            // le marchand doit le savoir AVANT de lancer la campagne, sinon il
            // croit avoir touché tout le segment alors qu'il en existe plus.
            $issues[] = \AdminTranslator::tVars('msg.segment_recipient_cap_exceeded', ['segment' => $segment]);
        }

        $missingLangFiles = [];
        if ($blockingCount === 0 && $recipientCount > 0) {
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
            'ok'               => $blockingCount === 0,
            'blocking'         => $blockingCount > 0,
            'capped'           => $capped,
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

        // Demande 1 de plus que le plafond réel pour détecter un dépassement
        // sans avoir à faire un second COUNT(*) séparé — sans ceci, un segment
        // de plus de 500 clients était tronqué en silence : seuls les 500
        // premiers (triés par engagement) recevaient la campagne, sans que
        // le rapport final n'indique qu'il y avait plus de destinataires réels.
        $customers = $this->getCustomersBySegment($segment, 501, 0, $filters);
        $capped = count($customers) > 500;
        if ($capped) {
            $customers = array_slice($customers, 0, 500);
        }
        $sent = 0; $failed = 0; $skipped = 0;
        $failureSamples = [];
        $preferences = class_exists('PreferencesManager') ? new \PreferencesManager($this->module) : null;

        // Round 153 : deux lots groupés remplacent respectivement le
        // isAllowed() par client (déjà appelé une 1re fois dans
        // preflightCheck() ci-dessus, donc jusqu'à 1000 requêtes pour ce
        // seul motif) et l'instanciation new \Customer() par client
        // (chacune déclenchant sa propre requête ObjectModel juste pour
        // lire id_lang) — jusqu'à ~1500 requêtes SQL individuelles au total
        // pour un segment de 500 clients, ramenées à 2 requêtes groupées.
        $customerIds = array_column($customers, 'id_customer');
        $allowedMap  = $preferences !== null ? $preferences->isAllowedBatch($customerIds, $template, $this->idShop) : [];
        $langMap     = [];
        if ($customerIds) {
            $langRows = $this->db->executeS(
                "SELECT `id_customer`, `id_lang` FROM `" . _DB_PREFIX_ . "customer`
                 WHERE `id_customer` IN (" . implode(',', array_map('intval', $customerIds)) . ")"
            );
            foreach ((array) $langRows as $r) {
                $langMap[(int) $r['id_customer']] = (int) $r['id_lang'];
            }
        }
        $defaultLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        foreach ($customers as $c) {
            $idLang = $langMap[(int) $c['id_customer']] ?: $defaultLang;

            // getCustomersBySegment() ne filtre que active=1/deleted=0 — un
            // client désabonné (one-click ou préférences) reste dans son
            // segment (le recalcul quotidien de segment est indépendant de
            // l'abonnement) et recevait donc quand même la campagne, en
            // contradiction directe avec sa demande de désabonnement. Même
            // garde-fou que BehavioralCronManager avant chaque envoi.
            if ($preferences !== null && !($allowedMap[(int) $c['id_customer']] ?? true)) {
                $skipped++;
                continue;
            }

            // Round 200 : Mail::Send() renvoie toujours true même quand
            // hookActionEmailSendBeforeImpl() (neria.php) bloque
            // silencieusement l'envoi pour bounce/blacklist/cooldown — sans
            // ce pré-contrôle explicite (déjà appliqué partout ailleurs via
            // le même pattern, cf. OrderTriggersManager::
            // explicitSendBlockReason()), une campagne segment comptait à
            // tort en "envoyé" des emails jamais réellement délivrés.
            if ($this->explicitSendBlockReason($template, $c['email'], $idLang, (int) $c['id_customer']) !== null) {
                $skipped++;
                continue;
            }

            $langCode     = \Language::getIsoById($idLang) ?: 'fr';
            $templateFile = _PS_MODULE_DIR_ . 'neria/mails/' . $langCode . '/' . $template . '.html';

            if (!file_exists($templateFile)) {
                $skipped++;
                continue;
            }

            $vars = [
                '{firstname}'   => $c['firstname'],
                '{lastname}'    => $c['lastname'],
                // Configuration::get(..., $this->idShop) : même piège que
                // {shop_url}/{history_url} juste en dessous — round 106,
                // un client de la boutique B recevait le nom d'une AUTRE
                // boutique si le contexte d'exécution divergeait de
                // $this->idShop (déjà utilisé pour filtrer les destinataires).
                '{shop_name}'   => \Configuration::get('PS_SHOP_NAME', null, null, $this->idShop),
                '{history_url}' => \Context::getContext()->link->getPageLink('history', true, $idLang, null, false, $this->idShop),
                // getBaseLink($this->idShop), pas \Tools::getShopDomainSsl()
                // (non scopé, résout via Context::getContext()->shop) : même
                // correctif déjà appliqué à LoyaltyManager/
                // SeasonalCampaignManager/ManualSendManager pour ce piège —
                // sans lui, {shop_url} pointait vers le domaine de la
                // boutique du CONTEXTE d'exécution courant, pas celle du
                // client réellement ciblé par la campagne segment (déjà
                // filtrée par $this->idShop juste au-dessus pour
                // {history_url} et pour la sélection des destinataires).
                '{shop_url}'    => \Context::getContext()->link->getBaseLink($this->idShop),
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

        if ($capped) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.segment_campaign_capped', [
                    'segment' => $segment, 'template' => $template,
                ]),
                $template, 'SegmentManager'
            );
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'capped' => $capped];
    }

    // ============================================================
    // HELPERS
    // ============================================================

    public static function getAllSegments(): array
    {
        return [self::AMBASSADOR, self::LOYAL, self::WARM, self::DORMANT, self::GHOST];
    }
}
