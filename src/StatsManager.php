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
                // Round 184 : $params['idOrder'] n'existe nulle part dans le
                // module (clé jamais définie par aucun appelant) — chaque
                // ligne 'sent' était donc systématiquement enregistrée avec
                // id_order = 0, quel que soit le template. Le hook
                // hookActionEmailSendBeforeImpl() (neria.php) lit pourtant
                // correctement templateVars['{id_order}'] pour la
                // VÉRIFICATION du cooldown juste avant — cette clé est la
                // seule source fiable de l'id_order réel, on l'utilise
                // désormais aussi pour l'ENREGISTREMENT du "sent" :
                // CooldownManager::isDuplicate() scopé par commande ne
                // trouvait jamais de correspondance, rendant le Mode
                // Silence totalement inopérant pour tous les templates liés
                // à une commande (order_conf, shipped, remboursements,
                // certificats...).
                'id_order'      => (int) ($params['templateVars']['{id_order}'] ?? 0),
                'ref_scope'     => (string) ($params['templateVars']['{cooldown_scope}'] ?? ''),
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

        // Même piège de course que recordClick() (voir son commentaire) :
        // eventExists() + record() ne sont pas atomiques, et record() crédite
        // des points de fidélité pour un événement 'open' au même titre qu'un
        // 'click'. De nombreux clients mail préchargent ou rechargent le pixel
        // de tracking (proxy image Gmail, plusieurs appareils synchronisés
        // ouvrant l'email au même instant) — sans verrou, deux requêtes
        // quasi simultanées peuvent toutes deux lire "aucune ouverture
        // existante" avant que l'une n'ait inséré sa ligne, créditant des
        // points en double pour une seule ouverture réelle.
        $lockKey = 'neria_open_' . md5($token);
        $gotLock = (bool) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockKey) . "', 2)", false);
        // Round 178 : $gotLock était calculé mais jamais vérifié avant
        // d'exécuter la section protégée (eventExists() + record()) — le
        // verrou était pris, mais si son acquisition échouait (timeout de
        // 2s atteint, verrou déjà tenu ailleurs sous forte charge), le code
        // continuait quand même SANS protection, exactement dans le
        // scénario de contention que ce verrou vise à couvrir. Fail-safe :
        // on renonce à cette ouverture plutôt que de risquer un double
        // crédit de points fidélité — une ouverture rare non comptée est
        // préférable à un double crédit silencieux.
        if (!$gotLock) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.stats_lock_failed', ['event' => self::EVENT_OPEN, 'token' => $token]),
                $sent['template'] ?? '', 'StatsManager'
            );
            return;
        }
        try {
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
        } finally {
            // Round 178 : le return anticipé ajouté quand !$gotLock rend ce
            // garde redondant (on n'atteint plus ce bloc sans verrou
            // obtenu) — simplifié en conséquence (PHPStan : condition
            // toujours vraie).
            $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockKey) . "')");
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
        $gotLock  = (bool) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockKey) . "', 2)", false);
        // Round 178 : voir commentaire équivalent dans recordOpen().
        if (!$gotLock) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.stats_lock_failed', ['event' => self::EVENT_CLICK, 'token' => $token]),
                $sent['template'] ?? '', 'StatsManager'
            );
            return;
        }
        try {
            // Round 300 : detectMpp() réutilisé pour les clics — jusqu'ici
            // seul recordOpen() l'appelait, is_mpp restant systématiquement
            // à 0 (valeur par défaut de record()) pour TOUT événement
            // 'click'. Un scanner de sécurité d'entreprise (Microsoft Safe
            // Links, Proofpoint URL Defense, Mimecast) qui pré-visite tous
            // les liens d'un email dès sa réception (délai < 3s, signal 2
            // de detectMpp() — générique, pas spécifique à Apple Mail)
            // déclenchait alors un vrai clic comptabilisé dans les KPIs
            // (rate_click, A/B testing) ET créditait des points de
            // fidélité au destinataire avant même qu'il n'ait ouvert
            // l'email — même classe de bug que les ouvertures MPP Apple,
            // jamais traitée pour ce type d'événement.
            $isMpp = $this->detectMpp(
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $sent['date_add']
            );

            $awardPoints = !$isMpp && !$this->eventExists($token, self::EVENT_CLICK);

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
                    'is_mpp'       => $isMpp ? 1 : 0,
                ],
                $awardPoints
            );
        } finally {
            // Round 178 : le return anticipé ajouté quand !$gotLock rend ce
            // garde redondant (on n'atteint plus ce bloc sans verrou
            // obtenu) — simplifié en conséquence (PHPStan : condition
            // toujours vraie).
            $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockKey) . "')");
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
                 `id_customer`, `id_order`, `ref_scope`, `tracking_token`,
                 `event_type`, `is_mpp`, `abtest_variant`, `rendered_vars`,
                 `revenue`, `ip_address`, `user_agent`, `date_add`)
             VALUES (%d, '%s', '%s', '%s', %d, %d, '%s', '%s', '%s', %d, '%s', %s, %s, '%s', '%s', NOW())",
            $table,
            $this->idShop,
            pSQL($template),
            pSQL($lang),
            pSQL($extra['country_code'] ?? $this->resolveCountryCode()),
            (int) ($extra['id_customer'] ?? 0),
            (int) ($extra['id_order']    ?? 0),
            pSQL($extra['ref_scope']     ?? ''),
            pSQL($token),
            pSQL($event),
            $isMpp,
            pSQL($extra['abtest'] ?? ''),
            $renderedVars !== null ? "'" . pSQL($renderedVars) . "'" : 'NULL',
            // number_format() (jamais sprintf('%.2f')) : sprintf('%f') honore
            // LC_NUMERIC du process PHP (setlocale() appelé par le BO pour le
            // formatage prix/date d'un employé fr_FR/de_DE), transformant
            // 12.5 en "12,50" → SQL invalide, INSERT silencieusement échoué,
            // revenu/points fidélité perdus sans trace visible en BO.
            number_format($revenue, 2, '.', ''),
            pSQL($this->anonymizeIp($this->getClientIp())),
            pSQL(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255))
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
                && !($event === 'open' && $isMpp)
            ) {
                try {
                    (new \LoyaltyManager($this->module))->awardPoints($idCustomer, $idStat, $event);
                } catch (\Throwable $e) {
                    // Round 246 : "non-bloquant" (le tracking ne doit jamais
                    // échouer à cause d'un souci fidélité) ne veut pas dire
                    // "silencieux" -- ce catch était le seul de logEvent()
                    // sans aucune trace Watchdog. checkAndReward() (appelée
                    // en aval par awardPoints()) journalise déjà ses propres
                    // échecs ; seules les erreurs survenant AVANT (accès
                    // contexte, requêtes SQL amont) tombaient ici sans
                    // laisser de trace exploitable par le marchand.
                    $this->watchdog()->warning(
                        \WatchdogManager::i18nMsg('watchdog.stats_award_points_failed', ['event' => $event, 'error' => $e->getMessage()]),
                        '', 'StatsManager'
                    );
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
        // Round 311 : borne calculée côté SQL (DATE_SUB(NOW(), ...)) au
        // lieu de date()/strtotime() PHP — même piège horloge PHP/MySQL
        // déjà corrigé round 310 (getKpiTrends()) et ailleurs dans ce même
        // fichier (detectMpp()/detectAnomalies()) : si le serveur PHP et le
        // serveur MySQL n'ont pas le même fuseau horaire, la fenêtre
        // calculée en PHP pouvait décaler d'un jour les événements proches
        // de minuit, faussant les totaux/taux affichés au marchand.
        $days       = (int) $days;
        $langFilter = $lang ? "AND `lang` = '" . pSQL($lang) . "'" : '';

        $sql = "SELECT
                    `template`,
                    COUNT(CASE WHEN `event_type` = 'sent'                     THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0   THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 1   THEN 1 END) AS mpp_open,
                    COUNT(CASE WHEN `event_type` = 'click'                    THEN 1 END) AS total_click
                FROM `{$table}`
                WHERE `id_shop`  = {$this->idShop}
                  AND `date_add` >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
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
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP.
        $days           = (int) $days;
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
                  AND `date_add` >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
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
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP.
        $days     = (int) $days;

        $sql = "SELECT
                    `country_code`,
                    COUNT(CASE WHEN `event_type` = 'sent'                     THEN 1 END) AS total_sent,
                    COUNT(CASE WHEN `event_type` = 'open' AND `is_mpp` = 0   THEN 1 END) AS total_open,
                    COUNT(CASE WHEN `event_type` = 'click'                    THEN 1 END) AS total_click
                FROM `{$table}`
                WHERE `id_shop`      = {$this->idShop}
                  AND `date_add`     >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
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
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP.
        $days           = (int) $days;
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
                  AND `date_add` >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                  {$templateFilter}
                GROUP BY DATE(`date_add`)
                ORDER BY `date` ASC";

        return $this->db->executeS($sql) ?: [];
    }

    public function getKpis(int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP. Ce
        // widget est comparé à getKpiTrends() (même onglet stats.tpl,
        // round 253) : la propriété "aucune borne haute, inclut toujours
        // l'instant présent" reste inchangée par ce correctif.
        $days     = (int) $days;

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
                  AND `date_add` >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";

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
    // Round 300 : fenêtre de stabilité minimale (peeking) — voir
    // logSignificanceIfNew().
    const SIG_STABILITY_HOURS = 20;

    public function getABTestReport(string $template, int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP.
        // $days=9999 reste le sentinel "aucune borne" (dateClause vide).
        $days     = (int) $days;

        $dateClause = $days < 9999 ? "AND `date_add` >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" : '';

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

        // Format stocké : "gagnant|confiance" — auparavant seule la confiance
        // était comparée, jamais le gagnant. Si la tendance s'inversait après
        // le premier seuil de significativité atteint (B devient gagnant
        // après A), la confiance restait proche du seuil déjà loggé et le
        // garde-fou bloquait tout nouveau webhook/log : le marchand continuait
        // de voir l'ancien gagnant comme vérité, sans alerte de correction.
        // Scopé par boutique — sans ça, sur un multi-boutiques où le même nom
        // de template A/B existe sur plusieurs boutiques, un seuil de
        // signification déjà atteint sur la Boutique A pouvait faire taire à
        // tort la notification "gagnant atteint" d'un résultat pourtant
        // nouveau et différent sur la Boutique B.
        $cfgKey = 'NERIA_SIG_LOGGED_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $template));
        $logged = (string) \Configuration::get($cfgKey, null, null, $this->idShop);
        $loggedWinner = null;
        $loggedConf   = 0.0;
        if (strpos($logged, '|') !== false) {
            [$loggedWinner, $loggedConfStr] = explode('|', $logged, 2);
            $loggedConf = (float) $loggedConfStr;
        }

        if ($loggedWinner === $winner && $loggedConf >= $conf) {
            return;
        }

        // Round 300 : atténuation du "peeking" — ce calcul est réévalué à
        // chaque ouverture de l'onglet A/B testing du BO (getAbtestReportsMap()),
        // sans aucune correction pour comparaisons répétées. Sur un test
        // surveillé quotidiennement pendant plusieurs semaines, le taux réel
        // de faux positifs pour "atteindre 95% au moins une fois" dépasse
        // largement les 5% nominaux d'un test statique unique. Sans éliminer
        // le peeking (nécessiterait un correctif séquentiel complet type
        // O'Brien-Fleming, hors de portée raisonnable ici), on exige une
        // STABILITÉ minimale : le même gagnant doit être observé à ≥95% de
        // confiance à DEUX reprises espacées d'au moins SIG_STABILITY_HOURS
        // — un pic isolé lors d'un unique chargement de page ne déclenche
        // plus seul le webhook/log "gagnant atteint".
        $pendingKey = 'NERIA_SIG_PENDING_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $template));
        $pending = (string) \Configuration::get($pendingKey, null, null, $this->idShop);
        $pendingWinner = null;
        $pendingSince  = 0;
        if (substr_count($pending, '|') === 1) {
            [$pendingWinner, $pendingSinceStr] = explode('|', $pending, 2);
            $pendingSince = (int) $pendingSinceStr;
        }

        if ($pendingWinner !== $winner) {
            // Première observation de ce gagnant (ou changement de tendance
            // depuis la dernière observation en attente) : on l'enregistre
            // comme candidat, sans encore journaliser/déclencher le webhook.
            \Configuration::updateValue($pendingKey, $winner . '|' . time(), false, null, $this->idShop);
            return;
        }

        if ((time() - $pendingSince) < (self::SIG_STABILITY_HOURS * 3600)) {
            // Même gagnant qu'au dernier passage, mais pas encore assez de
            // temps écoulé depuis la première observation — on attend.
            return;
        }

        \Configuration::updateValue($cfgKey, $winner . '|' . $conf, false, null, $this->idShop);
        \Configuration::deleteFromContext($pendingKey, null, $this->idShop);

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

        // Round 300 : règle usuelle de validité de l'approximation normale
        // pour un test z sur proportions — n·p̄ ET n·(1-p̄) doivent valoir au
        // moins 5 dans CHAQUE groupe. self::SIG_MIN_SAMPLE (100) protège
        // bien le taux d'OUVERTURE (~20-30%, largement > 5 dès 100 envois),
        // mais pas le taux de CLIC, souvent 1-3% en e-commerce : avec
        // n1=n2=100 et x1=4/x2=0 (p̄=0,02), n·p̄=2 < 5 des deux côtés —
        // l'approximation normale n'est pas fiable, mais le z-score
        // (≈2,02) franchissait quand même le seuil 90% et déclarait un
        // "gagnant" statistiquement injustifié, appliqué automatiquement
        // via apply_abtest_winner sans aucun garde-fou ni avertissement.
        if ($n1 * $pPool < 5 || $n2 * $pPool < 5 || $n1 * (1 - $pPool) < 5 || $n2 * (1 - $pPool) < 5) {
            $out['sufficient'] = false;
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

        // Scopé par boutique (5e argument) — sans lui, cette valeur est écrite
        // comme une clé de config GLOBALE : sur une install multi-boutiques,
        // un employé consultant l'onglet Stats en contexte Boutique A écrit
        // ici les chiffres de A, puis un employé basculant sur la Boutique B
        // dans la fenêtre de cache (30 min) récupérait telles quelles les
        // données de A (CA, taux d'ouverture, répartition pays) affichées
        // comme si elles étaient celles de B — fuite de données commerciales
        // entre boutiques.
        \Configuration::updateValue(
            'NERIA_STATS_CACHE',
            json_encode($reports, JSON_UNESCAPED_UNICODE),
            false, null, $this->idShop
        );
    }

    public function getCachedReports(): array
    {
        $cached = \Configuration::get('NERIA_STATS_CACHE', null, null, $this->idShop);

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
     *
     * Round 191 : retourne désormais bool (true = conversion réellement
     * enregistrée, ou déjà enregistrée précédemment — dans les deux cas
     * l'appelant peut nettoyer la ligne neria_attribution en toute sécurité ;
     * false = tentative avortée pour une raison TRANSITOIRE — token inconnu,
     * boutique différente, ou verrou non obtenu sous contention — auparavant
     * indiscernable d'un succès pour l'appelant. hookActionOrderStatusPostUpdate
     * (neria.php) journalisait "conversion enregistrée" et supprimait
     * définitivement la ligne neria_attribution même sur ces échecs
     * transitoires, perdant le token sans jamais avoir réellement crédité
     * la conversion — notamment sous verrou non obtenu (2 changements de
     * statut de commande quasi simultanés, cas documenté juste au-dessus
     * comme fréquent avec certains modules de paiement), sous-évaluant
     * durablement le ROI sans que rien ne le signale.
     */
    public function recordConversion(string $token, int $idOrder, float $amount, int $idShop = 0): bool
    {
        $sent = $this->getSentByToken($token);
        if (!$sent) {
            return false;
        }

        // Le cookie neria_ref n'a pas d'attribut domain explicite (host-only
        // par défaut), mais sur une install multi-boutiques dont plusieurs
        // id_shop partagent le même domaine (mode "shop URL" plutôt que
        // sous-domaines distincts), un clic sur un email envoyé par la
        // boutique A reste lisible côté boutique B. Sans cette vérification,
        // un achat sur B dans la fenêtre 24h attribuait à tort son revenu à
        // une campagne envoyée par A. On ne crédite la conversion que si la
        // boutique de la commande correspond à celle de l'envoi tracké.
        // Le garde $idShop > 0 court-circuitait entièrement cette
        // vérification quand $order->id_shop valait 0 (commande orpheline/
        // legacy, objet Order mal chargé) — la conversion était alors
        // créditée sans AUCUNE vérification de boutique, réintroduisant
        // exactement la fuite cross-shop que ce correctif visait à éliminer.
        // id_shop=1 étant le minimum valide sur une install PrestaShop, 0
        // n'est jamais une vraie boutique légitime : pas besoin de ce garde.
        if (isset($sent['id_shop']) && (int) $sent['id_shop'] !== $idShop) {
            return false;
        }

        // Même piège de course que recordOpen()/recordClick() (voir leurs
        // commentaires) : record() crédite des points de fidélité pour un
        // événement 'conversion' au même titre qu'un 'open'/'click', et
        // eventExists()+record() n'est pas atomique. hookActionOrderStatusPostUpdate
        // peut se déclencher plusieurs fois de suite pour la même commande
        // (module de paiement traversant plusieurs statuts rapidement, mise
        // à jour groupée en BO) — sans verrou, deux déclenchements quasi
        // simultanés pouvaient tous deux lire "aucune conversion existante"
        // et créditer des points en double pour un seul achat réel.
        $lockKey = 'neria_conv_' . md5($token);
        $gotLock = (bool) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockKey) . "', 2)", false);
        // Round 178 : voir commentaire équivalent dans recordOpen().
        if (!$gotLock) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.stats_lock_failed', ['event' => self::EVENT_CONVERSION, 'token' => $token]),
                $sent['template'] ?? '', 'StatsManager'
            );
            return false;
        }
        try {
            if ($this->eventExists($token, self::EVENT_CONVERSION)) {
                return true; // déjà attribuée — pas un échec, sûr de nettoyer neria_attribution
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
        } finally {
            // Round 178 : le return anticipé ajouté quand !$gotLock rend ce
            // garde redondant (on n'atteint plus ce bloc sans verrou
            // obtenu) — simplifié en conséquence (PHPStan : condition
            // toujours vraie).
            $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockKey) . "')");
        }

        $this->webhook()->trigger('conversion', [
            'template'       => $sent['template'],
            'lang'           => $sent['lang'],
            'customer_id'    => (int) $sent['id_customer'],
            'order_id'       => $idOrder,
            'revenue'        => $amount,
            'tracking_token' => $token,
        ]);

        return true;
    }

    /**
     * Round 185 : ramène le revenu de la ligne 'conversion' d'une commande
     * au montant réellement conservé par le marchand, appelée par
     * OrderTriggersManager::handleRefund() à chaque remboursement/avoir.
     * Sans elle, getRevenueStats()/MonthlyReportManager continuaient de
     * compter le revenu ORIGINAL de la commande indéfiniment, surestimant
     * durablement le ROI par template/campagne dès qu'un remboursement
     * (même partiel) survient après l'attribution — un cas extrêmement
     * fréquent en e-commerce (retours, litiges, fraude).
     *
     * recordConversion() n'enregistre qu'UNE seule ligne 'conversion' par
     * commande (dédupliquée par token via eventExists()) — le UPDATE
     * ci-dessous cible donc normalement 0 ou 1 ligne, jamais plusieurs.
     */
    // Round 302 : $idShop optionnel ajouté (défaut null = repli sur
    // $this->idShop, comportement historique inchangé pour tout appelant
    // qui ne le fournirait pas). Auparavant le WHERE filtrait TOUJOURS sur
    // $this->idShop, fixé une seule fois dans le constructeur à partir du
    // contexte BO AMBIANT (Context::getContext()->shop->id) — jamais celui
    // de la commande réellement remboursée. Sur une install multi-boutiques
    // où le contexte BO courant (liste "toutes boutiques", ou reliquat
    // d'une boutique précédente) diffère de order->id_shop, l'UPDATE ne
    // matchait aucune ligne (0 ligne affectée, silencieux par conception —
    // cf. commentaire ci-dessous) : le revenu attribué restait à son
    // montant ORIGINAL dans getRevenueStats()/dashboards ROI de la boutique
    // de la commande, malgré le remboursement — exactement le bug que ce
    // correctif (round 185) visait à éliminer. Même pattern déjà appliqué à
    // recordConversion() ci-dessus (paramètre $idShop explicite).
    public function adjustConversionRevenueForOrder(int $idOrder, float $newRevenue, ?int $idShop = null): void
    {
        if ($idOrder <= 0) {
            return;
        }

        $idShop = $idShop ?? $this->idShop;

        // Pas de ligne à ajuster pour une commande sans email tracké (achat
        // direct, hors campagne) : Db::update() ne fait rien si 0 ligne
        // matche le WHERE — comportement normal, pas d'erreur à journaliser.
        $this->db->update(
            self::TABLE,
            ['revenue' => $newRevenue],
            '`id_order` = ' . $idOrder . ' AND `event_type` = \'' . self::EVENT_CONVERSION . '\' AND `id_shop` = ' . $idShop
        );
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
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP.
        $days     = (int) $days;

        // MySQL 5.7+ : JSON_EXTRACT sur un champ TEXT
        $rows = $this->db->executeS(
            "SELECT
                `template`,
                COUNT(*)        AS orders,
                SUM(`revenue`)  AS revenue
             FROM `{$table}`
             WHERE `event_type` = '" . self::EVENT_CONVERSION . "'
               AND `id_shop`    = {$this->idShop}
               AND `date_add`   >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
             GROUP BY `template`
             ORDER BY revenue DESC"
        );

        // Chaque montant est arrondi ICI, avant cumul — auparavant le total
        // était arrondi une seule fois à la fin à partir des valeurs BRUTES,
        // pendant que chaque ligne de $byTemplate restait non arrondie
        // (arrondie séparément à l'affichage côté template BO). Deux
        // arrondis indépendants sur les mêmes données brutes peuvent
        // diverger d'un centime (ex. 10.005 + 10.005 = 20.01 arrondi, mais
        // chaque ligne arrondie séparément donne 10.01 + 10.01 = 20.02) : le
        // marchand voyait un total qui ne correspondait pas exactement à la
        // somme des lignes du tableau juste en dessous. En arrondissant
        // chaque montant avant le cumul, le total est désormais la vraie
        // somme des valeurs affichées.
        $totalRevenue = 0.0;
        $totalOrders  = 0;
        $byTemplate   = [];

        foreach ((is_array($rows) ? $rows : []) as $r) {
            $rev = round((float) $r['revenue'], 2);
            $ord = (int) $r['orders'];
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
        'post'    => ['post_purchase_care','post_purchase_review','complete_your_look','collection_completion',
                      'order_on_hold','order_partial_shipped','refund_processed','return_received',
                      'order_shipped_delay','product_lifespan_reminder','refund_reconciliation_1',
                      'refund_reconciliation_2','refund_reconciliation_3',
                      'waitlist_available','wishlist_reminder','back_in_stock',
                      // Round 72b — miroir de PreferencesManager::TEMPLATE_CAT (mêmes
                      // 17 templates WAVE1 "Artisanat/service" + "Logistique/incidents").
                      'artisan_message','craftsmanship_update','alteration_update','bespoke_ready',
                      'repair_completed','repair_request_confirm','care_certificate',
                      'certificate_provenance','extended_warranty','white_glove_apology',
                      'product_recall','customs_alert','delivery_attempt_failed','packaging_choice',
                      'tax_refund_eligible','gift_message_confirm','unboxing_guide'],
        'loyalty' => ['loyalty_tier_upgrade','loyalty_recap','loyalty_reward_expiry',
                      'milestone_order','referral_invitation'],
        'behav'   => ['birthday','relationship_anniversary','win_back',
                      'reorder_reminder','vip_invitation','private_sale','first_anniversary',
                      // Round 72 (vip/private_invitation/voucher/voucher_new, commit
                      // 072212b) et round 72b (personal_shopper_intro/concierge_followup/
                      // gift_guarantee) — miroir de PreferencesManager::TEMPLATE_CAT,
                      // oublié ici lors du fix round 72.
                      'vip','private_invitation','voucher','voucher_new',
                      'personal_shopper_intro','concierge_followup','gift_guarantee'],
        'season'  => ['christmas','valentine','halloween','eid','ramadan',
                      'diwali','lunar_new_year','nowruz','black_friday','new_year',
                      'hanukkah','fathers_day','mothers_day','grandparents_day',
                      'end_of_year_gift','early_access','exclusive_preview'],
        // Round 72b : corporate_order_confirm, miroir de PreferencesManager::TEMPLATE_CAT.
        'b2b'     => ['quote_expiry_48h','quote_expiry_day','quote_extension_offer','corporate_order_confirm'],
    ];

    public function getRevenueDailyByCategory(int $days = 30): array
    {
        $table    = _DB_PREFIX_ . self::TABLE;
        // Round 311 : borne calculée côté SQL (comme getGlobalReport() et
        // consorts) — ET la liste $dates ci-dessous (labels du graphique,
        // utilisée aussi comme FILTRE : tout jour SQL absent de cette liste
        // voit sa ligne silencieusement ignorée par les isset() plus bas)
        // est désormais ancrée sur CURDATE() MySQL au lieu de date() PHP.
        // Sans cet ancrage, un jour réellement présent en base (horloge
        // MySQL) mais non présent dans $dates (horloge PHP décalée) perdait
        // silencieusement tout son revenu dans le graphique par catégorie.
        $days     = (int) $days;
        $todaySql311 = (string) $this->db->getValue('SELECT CURDATE()');

        $rows = $this->db->executeS(
            "SELECT DATE(`date_add`) AS `d`, `template`, SUM(`revenue`) AS `rev`
             FROM `{$table}`
             WHERE `event_type` = '" . self::EVENT_CONVERSION . "'
               AND `id_shop`    = {$this->idShop}
               AND `date_add`  >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
               AND `revenue`   >  0
             GROUP BY DATE(`date_add`), `template`
             ORDER BY `d` ASC"
        );

        $dates = [];
        for ($i = $days; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime("{$todaySql311} -{$i} days"));
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

        // Même correctif que getRevenueStats() : $rev est arrondi UNE FOIS
        // ici, avant d'être cumulé à la fois dans $series et $total — les
        // deux accumulateurs partent donc de la même valeur déjà arrondie
        // par ligne, au lieu d'accumuler chacun leur propre valeur brute
        // puis d'arrondir séparément à la fin (risque d'écart d'1 centime
        // entre la courbe "total" et la somme empilée des séries).
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $d   = $r['d'];
            $rev = round((float) $r['rev'], 2);
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
            "SELECT `template`, `lang`, `country_code`, `id_shop`,
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

        // TIMESTAMPDIFF calculé côté MySQL (pas time()-strtotime() côté PHP) :
        // strtotime() interprète $sentDateAdd (produit par NOW() MySQL) dans
        // le fuseau PHP (date.timezone). Si le serveur MySQL et PHP ne
        // partagent pas le même fuseau (fréquent : MySQL en UTC système,
        // PHP en Europe/Paris pour la boutique), l'écart décale $elapsed
        // d'1-2h et fausse la classification MPP (signaux 2 et 3), donc les
        // KPIs d'ouverture et l'éligibilité aux points de fidélité.
        $elapsed = $sentDateAdd !== ''
            ? (int) $this->db->getValue(
                "SELECT TIMESTAMPDIFF(SECOND, '" . pSQL($sentDateAdd) . "', NOW())"
            )
            : PHP_INT_MAX;

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

        // Round 210 : $use_cache=false — eventExists() est le "check" de
        // check-then-act appairé au GET_LOCK ci-dessus ; le mettre en
        // cache SQL PrestaShop peut faire lire un résultat périmé
        // ("pas encore enregistré") après qu'un process concurrent ait
        // déjà inséré la ligne, contournant la dédup malgré le verrou.
        $count = (int) $this->db->getValue(
            "SELECT COUNT(*)
             FROM `{$table}`
             WHERE `tracking_token` = '" . pSQL($token) . "'
               AND `event_type`     = '" . pSQL($event) . "'",
            false
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
            // Round 253 : bornes INCLUSIVES des deux côtés (>= ... <= ...),
            // pas >= ... < ... -- l'ancienne borne haute exclusive
            // (`to = aujourd'hui` pour 'current', comparé en `<`) excluait
            // TOTALEMENT la journée en cours de la fenêtre "current", alors
            // que getKpis(7) (widget jumeau affiché sur le MÊME onglet
            // stats.tpl, via $stats.kpis/$kpi_trends) utilise `date_add >=
            // dateFrom` SANS borne haute et inclut donc bien aujourd'hui.
            // Le marchand voyait deux totaux "7 derniers jours" différents
            // côte à côte dès qu'il y avait de l'activité le jour même, et
            // le delta % de tendance semaine-sur-semaine était calculé sur
            // une fenêtre glissée d'un jour en arrière, sous-représentant
            // systématiquement le volume réel de la semaine en cours.
            // Round 310 : bornes calculées côté SQL (DATE_SUB(CURDATE(), ...))
            // au lieu de date()/strtotime() PHP — même piège horloge PHP/MySQL
            // déjà corrigé ailleurs dans le module (detectMpp()/detectAnomalies()
            // dans ce même fichier utilisent déjà ce pattern) : si le serveur
            // PHP et le serveur MySQL n'ont pas le même fuseau horaire, la
            // fenêtre calculée en PHP pouvait décaler d'un jour les événements
            // proches de minuit, faussant systématiquement le delta % de
            // tendance semaine-sur-semaine.
            $offsetLow  = (int) $offset + 6;
            $offsetHigh = (int) $offset;
            $row  = $this->db->getRow("
                SELECT
                    COUNT(CASE WHEN event_type = 'sent'              THEN 1 END) AS sent,
                    COUNT(CASE WHEN event_type = 'open' AND is_mpp=0 THEN 1 END) AS opens,
                    COUNT(CASE WHEN event_type = 'click'             THEN 1 END) AS clicks
                FROM `{$table}`
                WHERE id_shop     = {$this->idShop}
                  AND DATE(date_add) >= DATE_SUB(CURDATE(), INTERVAL {$offsetLow} DAY)
                  AND DATE(date_add) <= DATE_SUB(CURDATE(), INTERVAL {$offsetHigh} DAY)
            ");
            $raw[$period] = $row ?: ['sent' => 0, 'opens' => 0, 'clicks' => 0];

            // Neria n'a pas d'événement 'unsubscribed' dans neria_stat — le vrai
            // désabonnement est stocké dans neria_preferences (subscribed=0),
            // horodaté sur date_upd. On compte les nouveaux "subscribed=0" de la période.
            $raw[$period]['unsubs'] = (int) $this->db->getValue("
                SELECT COUNT(*) FROM `{$prefTable}`
                WHERE id_shop     = {$this->idShop}
                  AND subscribed  = 0
                  AND DATE(date_upd) >= DATE_SUB(CURDATE(), INTERVAL {$offsetLow} DAY)
                  AND DATE(date_upd) <= DATE_SUB(CURDATE(), INTERVAL {$offsetHigh} DAY)
            ");
        }

        // Revenus attribués — depuis neria_stat event_type='conversion'
        $statTable = _DB_PREFIX_ . self::TABLE;
        foreach (['current' => 0, 'previous' => 7] as $period => $offset) {
            // Round 253 : voir justification dans la boucle ci-dessus --
            // bornes inclusives des deux côtés, cohérentes avec getKpis(7).
            // Round 310 : bornes calculées côté SQL — voir commentaire détaillé
            // dans la boucle ci-dessus.
            $offsetLow  = (int) $offset + 6;
            $offsetHigh = (int) $offset;
            $rev  = (float) $this->db->getValue(
                "SELECT COALESCE(SUM(revenue), 0) FROM `{$statTable}`
                 WHERE event_type = 'conversion'
                   AND id_shop    = {$this->idShop}
                   AND DATE(date_add) >= DATE_SUB(CURDATE(), INTERVAL {$offsetLow} DAY)
                   AND DATE(date_add) <= DATE_SUB(CURDATE(), INTERVAL {$offsetHigh} DAY)"
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
        // Round 211 : max(1, $sent) plancher le dénominateur à 1 au lieu
        // de garder $sent réel — contrairement au pattern protecteur
        // ($sent > 0 ? ... : 0) utilisé partout ailleurs dans ce fichier.
        // sent/opens/clicks sont comptés par la DATE PROPRE de chaque
        // événement (pas la date d'envoi) : un email envoyé la semaine
        // précédente peut être ouvert/cliqué durant la semaine courante.
        // Avec 0 envoi mais des ouvertures dans la fenêtre courante,
        // opens/max(1,0) affichait un taux de plusieurs centaines de %
        // au lieu d'un simple 0 (non calculable, pas d'envoi = pas de taux).
        $sentCur  = (float) ($raw['current']['sent']  ?? 0);
        $sentPrev = (float) ($raw['previous']['sent'] ?? 0);
        foreach (['open_rate', 'click_rate'] as $rk) {
            $base  = $rk === 'open_rate' ? 'opens' : 'clicks';
            $cur   = $sentCur  > 0 ? round((float) ($raw['current'][$base]  ?? 0) / $sentCur  * 100, 1) : 0.0;
            $prev  = $sentPrev > 0 ? round((float) ($raw['previous'][$base] ?? 0) / $sentPrev * 100, 1) : 0.0;
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
        // Round 311 : voir commentaire détaillé dans getRevenueDailyByCategory()
        // — borne SQL + $dates ancré sur CURDATE() MySQL (sert aussi de
        // filtre via $byDate[$d] ?? null plus bas, pas seulement de label).
        $days     = (int) $days;
        $todaySql311 = (string) $this->db->getValue('SELECT CURDATE()');

        $rows = $this->db->executeS("
            SELECT DATE(date_add) AS d,
                   COUNT(CASE WHEN event_type = 'sent'              THEN 1 END) AS sent,
                   COUNT(CASE WHEN event_type = 'open' AND is_mpp=0 THEN 1 END) AS opens,
                   COUNT(CASE WHEN event_type = 'click'             THEN 1 END) AS clicks
            FROM `{$table}`
            WHERE id_shop  = {$this->idShop}
              AND date_add >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY DATE(date_add)
            ORDER BY d ASC
        ");

        $dates = [];
        for ($i = $days; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime("{$todaySql311} -{$i} days"));
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
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP.
        $days     = (int) $days;

        $rows = $this->db->executeS("
            SELECT WEEKDAY(date_add) AS dow, HOUR(date_add) AS h, COUNT(*) AS cnt
            FROM `{$table}`
            WHERE event_type = 'open'
              AND is_mpp     = 0
              AND id_shop    = {$this->idShop}
              AND date_add  >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
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
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP.

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
              AND date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)
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
        // Round 311 : voir commentaire détaillé dans getGlobalReport() —
        // borne calculée côté SQL au lieu de date()/strtotime() PHP.

        $rows = $this->db->executeS("
            SELECT template, COUNT(*) AS orders, SUM(revenue) AS revenue
            FROM `{$table}`
            WHERE event_type = 'conversion'
              AND id_shop    = {$this->idShop}
              AND revenue    > 0
              AND date_add  >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY template
            ORDER BY revenue DESC
            LIMIT " . (int) $limit
        );

        // Champ renommé 'orders_with_revenue' (au lieu de 'orders') : cette
        // méthode filtre `revenue > 0` dans le WHERE, contrairement à
        // getRevenueStats() qui compte TOUTES les conversions (y compris
        // les commandes à 0€ — offertes, avoirs...) sous le même nom de
        // champ 'orders'. Les deux méthodes exposaient donc un champ au nom
        // identique mais à la définition différente, risquant de faire
        // croire à une incohérence de données si les deux blocs BO sont
        // comparés côte à côte pour un même template.
        return array_map(fn($r) => [
            'template'             => $r['template'],
            'orders_with_revenue'  => (int) $r['orders'],
            'revenue'              => round((float) $r['revenue'], 2),
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
        //
        // Round 312 : toutes les dates ci-dessous étaient calculées via
        // date()/strtotime() PHP (horloge PHP) comparées à date_add rempli
        // par MySQL — même piège horloge PHP/MySQL déjà corrigé partout
        // ailleurs dans ce fichier (rounds 310/311), oublié ici (dernière
        // méthode de reporting du fichier à en souffrir). Ancré sur
        // CURDATE() MySQL : $anchor sert de "aujourd'hui" pour tous les
        // calculs de date qui suivent, au lieu du "now" PHP local.
        $todaySql312 = (string) $this->db->getValue('SELECT CURDATE()');
        $anchor           = new \DateTime($todaySql312);
        $prevMonthAnchor  = (clone $anchor)->modify('first day of last month');
        $dayOfMonth       = (int) $anchor->format('j');
        $prevMonthLastDay = (int) $prevMonthAnchor->format('t');
        $prevEndDay       = min($dayOfMonth, $prevMonthLastDay);

        $periods = [
            'current'  => [$anchor->format('Y-m-01'), $anchor->format('Y-m-d')],
            'previous' => [
                $prevMonthAnchor->format('Y-m-01'),
                $prevMonthAnchor->format('Y-m-') . str_pad((string) $prevEndDay, 2, '0', STR_PAD_LEFT),
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

        // Round 312 : $anchor/$prevMonthAnchor (ancrés CURDATE() MySQL, voir
        // plus haut) au lieu de `new \DateTime('first day of ...')` qui
        // utilise implicitement l'horloge PHP locale.
        $data['labels'] = [
            'current'  => $this->formatMonthLabel(clone $anchor),
            'previous' => $this->formatMonthLabel(clone $prevMonthAnchor),
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
    /**
     * Version batch (1 requête SQL, quel que soit le nombre de templates) —
     * la version précédente faisait 1 requête pour lister les templates puis
     * 2 appels par template (semaine courante / semaine précédente), chacun
     * 3 COUNT(*) séparés (sent/open/click) = jusqu'à 600 requêtes pour ~100
     * templates actifs. Ici, tous les compteurs sont calculés en une seule
     * requête groupée par template, avec CASE WHEN pour distinguer les deux
     * fenêtres de 7 jours — le reste (comparaison, calcul des taux) reste en
     * PHP sans nouvel accès DB.
     */
    public function detectAnomalies(): array
    {
        $table = _DB_PREFIX_ . 'neria_stat';

        $rows = $this->db->executeS(
            "SELECT `template`,
                    SUM(CASE WHEN `date_add` > DATE_SUB(NOW(), INTERVAL 7 DAY)
                              AND `event_type` = 'sent' THEN 1 ELSE 0 END) AS sent_this,
                    SUM(CASE WHEN `date_add` > DATE_SUB(NOW(), INTERVAL 7 DAY)
                              AND `event_type` = 'open' AND `is_mpp` = 0 THEN 1 ELSE 0 END) AS opens_this,
                    SUM(CASE WHEN `date_add` > DATE_SUB(NOW(), INTERVAL 7 DAY)
                              AND `event_type` = 'click' THEN 1 ELSE 0 END) AS clicks_this,
                    SUM(CASE WHEN `date_add` <= DATE_SUB(NOW(), INTERVAL 7 DAY)
                              AND `date_add` > DATE_SUB(NOW(), INTERVAL 14 DAY)
                              AND `event_type` = 'sent' THEN 1 ELSE 0 END) AS sent_last,
                    SUM(CASE WHEN `date_add` <= DATE_SUB(NOW(), INTERVAL 7 DAY)
                              AND `date_add` > DATE_SUB(NOW(), INTERVAL 14 DAY)
                              AND `event_type` = 'open' AND `is_mpp` = 0 THEN 1 ELSE 0 END) AS opens_last,
                    SUM(CASE WHEN `date_add` <= DATE_SUB(NOW(), INTERVAL 7 DAY)
                              AND `date_add` > DATE_SUB(NOW(), INTERVAL 14 DAY)
                              AND `event_type` = 'click' THEN 1 ELSE 0 END) AS clicks_last
             FROM `{$table}`
             WHERE `id_shop`   = {$this->idShop}
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 14 DAY)
             GROUP BY `template`"
        ) ?: [];

        $anomalies = [];
        foreach ($rows as $r) {
            $sentThis = (int) $r['sent_this'];
            $sentLast = (int) $r['sent_last'];

            if ($sentLast < 10 || $sentThis < 5) {
                continue;
            }

            $thisWeek = [
                'sent'       => $sentThis,
                'open_rate'  => round((int) $r['opens_this']  / $sentThis * 100, 1),
                'click_rate' => round((int) $r['clicks_this'] / $sentThis * 100, 1),
            ];
            $lastWeek = [
                'sent'       => $sentLast,
                'open_rate'  => round((int) $r['opens_last']  / $sentLast * 100, 1),
                'click_rate' => round((int) $r['clicks_last'] / $sentLast * 100, 1),
            ];

            $openDrop  = $lastWeek['open_rate']  > 0
                ? round(($lastWeek['open_rate']  - $thisWeek['open_rate'])  / $lastWeek['open_rate']  * 100, 1)
                : 0.0;
            $clickDrop = $lastWeek['click_rate'] > 0
                ? round(($lastWeek['click_rate'] - $thisWeek['click_rate']) / $lastWeek['click_rate'] * 100, 1)
                : 0.0;

            if ($openDrop >= 20 || $clickDrop >= 20) {
                $anomalies[] = [
                    'template'   => $r['template'],
                    'open_drop'  => $openDrop,
                    'click_drop' => $clickDrop,
                    'this_week'  => $thisWeek,
                    'last_week'  => $lastWeek,
                ];
            }
        }

        return $anomalies;
    }
}