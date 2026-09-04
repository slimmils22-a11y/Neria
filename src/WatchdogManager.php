<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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
    // 'label_key' référence une clé AdminTranslator (19 langues) plutôt que
    // du texte en dur — une constante de classe ne peut pas appeler de
    // fonction, la résolution se fait dans getCronHealth().
    const TABLE_CRON  = 'neria_cron_health';
    const KNOWN_CRONS = [
        'behavioral'         => ['label_key' => 'health.cron_label_behavioral',         'threshold_hours' => 25],
        'calendar'           => ['label_key' => 'health.cron_label_calendar',           'threshold_hours' => 25],
        'webhook'            => ['label_key' => 'health.cron_label_webhook',            'threshold_hours' => 2],
        'queue'              => ['label_key' => 'health.cron_label_queue',              'threshold_hours' => 2],
        'monthly_report'     => ['label_key' => 'health.cron_label_monthly_report',     'threshold_hours' => 25],
        'upsell_conversions' => ['label_key' => 'health.cron_label_upsell_conversions', 'threshold_hours' => 25],
        'seasonal_campaigns' => ['label_key' => 'health.cron_label_seasonal_campaigns', 'threshold_hours' => 25],
        'loyalty_recaps'     => ['label_key' => 'health.cron_label_loyalty_recaps',     'threshold_hours' => 25, 'enabled_cfg' => 'NERIA_LOYALTY_ENABLED'],
        'render_canary'      => ['label_key' => 'health.cron_label_render_canary',      'threshold_hours' => 30],
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

    /**
     * Résout un message éventuellement encodé par i18nMsg() en texte lisible.
     * Tolère un JSON tronqué/corrompu (au moins la clé est récupérée si possible)
     * plutôt que d'exposer la structure brute au destinataire.
     */
    public static function resolveLogMessage(string $message): string
    {
        if (!str_starts_with($message, '::i18n::')) {
            return $message;
        }

        $decoded = json_decode(substr($message, 8), true);
        if (is_array($decoded) && isset($decoded['k'])) {
            $str = AdminTranslator::t($decoded['k']);
            foreach ($decoded['v'] ?? [] as $k => $v) {
                $str = str_replace('{' . $k . '}', (string) $v, $str);
            }
            return $str;
        }

        if (preg_match('/"k"\s*:\s*"([a-z0-9_.]+)"/i', $message, $m)) {
            return AdminTranslator::t($m[1]);
        }

        return AdminTranslator::t('health.log_undecodable');
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

        // Consolidation : même message+class dans la dernière heure → incrémenter au lieu d'insérer.
        // Verrou nommé (même motif que QueueManager/WebhookManager::processQueue) :
        // sans lui, deux écritures concurrentes du même message dans la même
        // seconde (rafale d'erreurs identiques) peuvent toutes deux ne rien
        // trouver au SELECT et créer 2 lignes distinctes au lieu d'une seule
        // consolidée à occurrence_count=2. Pas de fenêtre de temps fixe
        // exploitable en ON DUPLICATE KEY (la fenêtre "dernière heure" glisse),
        // donc un verrou explicite est la protection appropriée ici.
        $lockName = 'neria_log_' . md5($this->idShop . '|' . $level . '|' . $class . '|' . $message);
        $locked   = (int) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 1)", false) === 1;
        // Round 179 (audit transversal de fin de série) : $locked était
        // calculé mais jamais vérifié avant la section critique (SELECT +
        // INSERT/UPDATE) — pire que le pattern "vérifié+loggé mais sans
        // effet" déjà corrigé ailleurs (TranslationHistoryManager round
        // 175, StatsManager round 178) : ici il n'y avait même pas de log
        // d'échec, le résultat était simplement ignoré. Sous rafale de
        // messages identiques (le scénario même que ce verrou vise à
        // couvrir, cf. commentaire ci-dessus), deux appels concurrents
        // pouvaient tous deux lire "aucune entrée existante" et créer 2
        // lignes au lieu d'une seule consolidée — recréant exactement la
        // duplication que ce verrou est censé empêcher. Renonce à cette
        // écriture précise plutôt que de risquer une duplication
        // silencieuse (fail-safe, même principe que StatsManager) : sous
        // contention aussi brève et rare, une ligne de log non écrite est
        // largement préférable à un compteur d'occurrences faussé.
        if (!$locked) {
            return;
        }

        try {
            // Round 212 : $use_cache=false, même famille de bug que les
            // rounds 210-211 — même sous verrou GET_LOCK (déjà corrigé),
            // ce SELECT reste une lecture indépendante que le cache SQL
            // PrestaShop peut resservir périmée, cassant le throttle
            // anti-spam de logs identiques.
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
            ), false);

            if ($existing > 0) {
                // Round 189 : date_add rafraîchi à chaque occurrence
                // consolidée — absent jusqu'ici, alors que le commentaire
                // ci-dessus affirme explicitement que la fenêtre "glisse".
                // Sans ce rafraîchissement, date_add restait figé à la
                // création de la ligne : sendImmediateAlert()/
                // sendDailyDigestIfDueLocked() filtrent tous deux par
                // date_add (burst count depuis $lastSent, digest sur 24h) —
                // une ligne créée juste avant la fenêtre mais dont
                // l'occurrence_count grimpait APRÈS le début de la fenêtre
                // restait invisible à ces deux comptages, sous-évaluant
                // l'incident réel dans le mécanisme même censé le signaler.
                $this->db->execute(
                    "UPDATE `{$table}` SET `occurrence_count` = `occurrence_count` + 1, `date_add` = NOW()
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
        } finally {
            // Round 179 : le return anticipé ajouté quand !$locked rend ce
            // garde redondant (on n'atteint plus ce bloc sans verrou
            // obtenu) — simplifié en conséquence (PHPStan : condition
            // toujours vraie).
            $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        }

        // Purge probabiliste (1 tentative sur 10) plutôt qu'à CHAQUE nouvelle
        // ligne distincte insérée — MAX_LOGS borne déjà la table de façon
        // sûre (pas de croissance incontrôlée), mais une rafale de messages
        // tous légèrement différents (ex. contenant un ID de commande
        // variable, fréquent avec les vars i18n de neria.php) échappe à la
        // consolidation ci-dessus et déclenchait un DELETE+COUNT à chaque
        // insertion. Le tirage probabiliste répartit ce coût sans changer le
        // comportement de purge à moyen terme.
        if (random_int(1, 10) === 1) {
            $this->pruneOldLogs();
        }
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

        // Clé suffixée par boutique : sans ça, sur une install multi-boutiques,
        // une alerte critique sur la boutique A consommait le throttle global
        // et faisait taire silencieusement une alerte critique déclenchée sur
        // la boutique B quelques minutes plus tard.
        $lastSent = (int) \Configuration::getGlobalValue(self::CFG_ALERT_LAST_SENT . '_' . $this->idShop);
        if ((time() - $lastSent) < self::ALERT_THROTTLE) {
            return;
        }

        $email = $this->getAlertEmail();
        if ($email === '') {
            // Round 298 : error_log() ajouté — sans lui, ce cas (NERIA_ALERT_EMAIL
            // mal saisi/typo ET PS_SHOP_EMAIL lui-même invalide/vide) était
            // structurellement indiscernable de "rien à signaler" : aucune
            // trace nulle part, contrairement à un échec réel de mail()
            // (ligne ci-dessous), qui lui journalise déjà explicitement.
            // Le marchand ne recevait alors plus JAMAIS aucune alerte
            // critique — précisément le scénario que ce mécanisme est censé
            // prévenir, mais appliqué à sa propre configuration.
            error_log('[Neria WatchdogManager] Alerte immédiate non envoyée : aucune adresse email de destination valide (NERIA_ALERT_EMAIL et PS_SHOP_EMAIL invalides ou vides).');
            return;
        }

        // Compte les ERROR/CRITICAL survenus depuis la DERNIÈRE alerte
        // envoyée (avant de mettre à jour ce timestamp ci-dessous) — sans
        // ça, une rafale (ex. panne DB de 10 minutes générant 50 erreurs)
        // ne produisait qu'UN SEUL email pour la 1ère erreur : les 49
        // suivantes étaient absorbées par le throttle sans jamais être
        // mentionnées nulle part, laissant croire au marchand à un
        // incident isolé alors qu'une vraie panne était en cours.
        $burstCount = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE id_shop = ' . $this->idShop . '
               AND level IN (\'' . self::LEVEL_ERROR . '\', \'' . self::LEVEL_CRITICAL . '\')
               AND date_add >= \'' . date('Y-m-d H:i:s', $lastSent ?: (time() - 86400)) . '\''
        );

        \Configuration::updateGlobalValue(self::CFG_ALERT_LAST_SENT . '_' . $this->idShop, time());

        // Rendu dans la langue de la boutique (le marchand configure la même
        // langue pour le BO et le front) plutôt que celle du contexte courant
        // — cet email peut être déclenché depuis un contrôleur front (visiteur)
        // où Context::language ne reflète pas la langue du destinataire réel.
        $prevLang = AdminTranslator::currentLang();
        AdminTranslator::setLang($this->getShopLang());

        // Round 261 : try/finally -- sans lui, une exception levée entre
        // setLang() et sa restauration (ex. échec SQL improbable mais
        // possible sur un appel ultérieur, corruption de données passée à
        // NeriaTools::formatDate()) laissait AdminTranslator::$lang
        // (propriété STATIQUE) bloqué sur la langue de LA BOUTIQUE pour le
        // reste du process PHP entier -- pas seulement cette alerte -- donc
        // potentiellement tous les rendus Neria suivants dans la même
        // requête/cron. Même correctif déjà appliqué à PageSpeedManager/
        // SeoApiManager (round 240) et MonthlyReportManager (round 239)
        // pour ce pattern identique, jamais porté ici alors que ce fichier
        // est le point d'échec le plus visité du module (wd()->error()/
        // critical() appelés depuis presque tous les autres Manager).
        try {
        // mail() natif n'assainit pas les en-têtes lui-même — retire tout
        // retour à la ligne des valeurs interpolées dans le sujet/en-têtes.
        // Round 115 : $this->idShop transmis explicitement — même piège que
        // rounds 111-114. Cette méthode est appelée depuis un manager
        // instancié à la volée pendant une boucle multi-boutique
        // (Context::getContext()->shop = new \Shop($idShop) ne modifie pas
        // Shop::$context_id_shop, dont dépend Configuration::get() sans
        // idShop explicite), donc $shopName/$fromEmail retombaient sur la
        // boutique ambiante réelle plutôt que celle réellement en alerte.
        $shopName   = str_replace(["\r", "\n"], '', (string) \Configuration::get('PS_SHOP_NAME', null, null, $this->idShop));
        $shopDomain = \Tools::getShopDomainSsl(true);
        $levelUpper = strtoupper($level);
        $color      = $level === self::LEVEL_CRITICAL ? '#7a0000' : '#a32d2d';
        $subject    = $burstCount > 1
            ? str_replace(["\r", "\n"], '', AdminTranslator::tVars('wd_alert.subject_burst', ['level' => $levelUpper, 'shop' => $shopName, 'n' => $burstCount]))
            : str_replace(["\r", "\n"], '', AdminTranslator::tVars('wd_alert.subject', ['level' => $levelUpper, 'shop' => $shopName]));
        // Round 250 : cette méthode utilise mail() natif (pas Mail::Send()
        // du cœur PS, volontairement -- "pour fonctionner même si PS
        // Mail::Send est cassé", voir plus bas), donc porte l'entière
        // responsabilité de l'encodage RFC 2047 de l'en-tête Subject. Sans
        // ça, un sujet contenant du texte non-ASCII (traduction dans une
        // langue non-latine, ou simplement PS_SHOP_NAME saisi par le
        // marchand avec des caractères accentués/non-latins) partait en
        // octets UTF-8 bruts dans l'en-tête SMTP -- non conforme RFC 822,
        // affichage "??????"/mojibake côté client mail (Outlook
        // notamment), précisément dans la situation où l'alerte doit être
        // comprise vite (erreur/critique). mb_encode_mimeheader() laisse
        // un sujet purement ASCII inchangé (pas de surcoût inutile).
        $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

        $emergencyToken = (string) \Configuration::getGlobalValue('NERIA_EMERGENCY_TOKEN');
        $emergencyUrl   = $emergencyToken
            ? $shopDomain . \__PS_BASE_URI__ . 'modules/neria/neria-emergency.php?token=' . urlencode($emergencyToken)
            : '';

        $cleanMsg = htmlspecialchars(strip_tags(self::resolveLogMessage($message)));

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;background:#f5f5f5;margin:0;padding:20px;">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0;">'
            . '<div style="background:' . $color . ';padding:20px 24px;">'
            . '<p style="color:#fff;margin:0;font-size:11px;letter-spacing:.1em;text-transform:uppercase;">Neria · ' . htmlspecialchars($shopName) . '</p>'
            . '<h1 style="color:#fff;margin:8px 0 0;font-size:20px;">' . AdminTranslator::tVars('wd_alert.title', ['level' => $levelUpper]) . '</h1>'
            . '</div>'
            . '<div style="padding:24px;">'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">'
            . '<tr><td style="padding:8px;color:#888;width:90px;vertical-align:top;">' . AdminTranslator::t('wd_alert.level_label') . '</td>'
            . '<td style="padding:8px;font-weight:700;color:' . $color . ';">' . $levelUpper . '</td></tr>'
            . '<tr style="background:#fafafa;"><td style="padding:8px;color:#888;vertical-align:top;">' . AdminTranslator::t('wd_alert.class_label') . '</td>'
            . '<td style="padding:8px;">' . htmlspecialchars($class ?: '—') . '</td></tr>'
            . '<tr><td style="padding:8px;color:#888;vertical-align:top;">' . AdminTranslator::t('wd_alert.template_label') . '</td>'
            . '<td style="padding:8px;">' . htmlspecialchars($template ?: '—') . '</td></tr>'
            . '<tr style="background:#fafafa;"><td style="padding:8px;color:#888;vertical-align:top;">' . AdminTranslator::t('wd_alert.message_label') . '</td>'
            . '<td style="padding:8px;line-height:1.5;">' . $cleanMsg . '</td></tr>'
            . '<tr><td style="padding:8px;color:#888;">' . AdminTranslator::t('wd_alert.date_label') . '</td>'
            . '<td style="padding:8px;">' . \NeriaTools::formatDate('now', AdminTranslator::currentLang(), true) . '</td></tr>'
            . '</table>'
            . ($burstCount > 1
                ? '<p style="margin:0 0 16px;padding:10px 14px;background:#fff3f3;border-left:3px solid ' . $color . ';font-size:12px;color:#7a2d2d;">'
                  . AdminTranslator::tVars('wd_alert.burst_notice', ['n' => $burstCount]) . '</p>'
                : '')
            . ($emergencyUrl
                ? '<a href="' . htmlspecialchars($emergencyUrl) . '" style="display:inline-block;background:#b38b59;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">' . AdminTranslator::t('wd_alert.open_emergency') . '</a>'
                : '')
            . '<p style="margin-top:20px;font-size:11px;color:#aaa;">' . AdminTranslator::t('wd_alert.footer') . '</p>'
            . '</div></div></body></html>';
        } finally {
            AdminTranslator::setLang($prevLang);
        }

        $fromEmail = str_replace(["\r", "\n"], '', (string) \Configuration::get('PS_SHOP_EMAIL', null, null, $this->idShop) ?: 'noreply@' . parse_url($shopDomain, PHP_URL_HOST));
        $headers   = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                   . "From: Neria <" . $fromEmail . ">\r\n"
                   . "X-Mailer: Neria-WatchdogAlert/1.0\r\n";

        // Le retour n'était jusqu'ici jamais vérifié : un SMTP/sendmail local
        // en panne (précisément le cas où cette alerte est la plus utile)
        // échouait silencieusement, sans trace nulle part. error_log() ici
        // délibérément — PAS un nouveau log Watchdog (critical()/error()) :
        // ça créerait un risque de boucle (un échec d'alerte redéclenchant
        // une tentative d'alerte). Le journal serveur reste le seul canal
        // sûr pour signaler l'échec de CE mécanisme précis.
        if (!@mail($email, $subject, $body, $headers)) {
            error_log('[Neria WatchdogManager] Échec envoi alerte email vers ' . $email);
        }
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

        // Round 154 : verrou MySQL — même piège déjà corrigé pour la queue
        // d'envoi, la queue webhook, le cron comportemental, le calendrier
        // et le rapport mensuel (cf. MonthlyReportManager::checkAndSend()).
        // Sans lui, deux déclenchements concurrents (hookDisplayHeader sur
        // deux visiteurs simultanés, ou le fallback front + un vrai cron
        // serveur tournant en même temps) pouvaient tous deux lire
        // $lastDigest périmé AVANT que l'un des deux n'ait eu le temps
        // d'écrire sa mise à jour (après la construction complète de
        // l'email) — le marchand recevait alors deux digests quotidiens
        // identiques.
        if ((int) $this->db->getValue("SELECT GET_LOCK('neria_watchdog_digest_" . $this->idShop . "', 0)", false) !== 1) {
            return;
        }

        try {
            $this->sendDailyDigestIfDueLocked();
        } finally {
            $this->db->execute("SELECT RELEASE_LOCK('neria_watchdog_digest_" . $this->idShop . "')");
        }
    }

    private function sendDailyDigestIfDueLocked(): void
    {
        // Même correctif que sendImmediateAlert() : clé suffixée par boutique,
        // sinon une seule boutique par jour (la première dont le hook déclenche
        // sendDailyDigestIfDue()) recevait effectivement un digest — les
        // autres boutiques voyaient leur fenêtre de 24h "consommée" sans
        // jamais recevoir leur propre résumé, alors que leurs logs existent
        // bien en base avec leur id_shop respectif.
        //
        // Revérifie APRÈS obtention du verrou : un autre process a pu
        // terminer son propre envoi pendant qu'on attendait.
        $lastDigest = (int) \Configuration::getGlobalValue(self::CFG_DIGEST_LAST . '_' . $this->idShop);
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
            \Configuration::updateGlobalValue(self::CFG_DIGEST_LAST . '_' . $this->idShop, time());
            return;
        }

        $email = $this->getAlertEmail();
        if ($email === '') {
            // Round 203 : sans cette mise à jour du throttle, tant qu'aucune
            // adresse d'alerte valide n'est configurée ET qu'il existe des
            // logs warning/error/critical dans les 24h, cette branche était
            // atteinte à CHAQUE appel de sendDailyDigestIfDue() (déclenché
            // depuis hookDisplayHeader, donc potentiellement à chaque hit
            // front) — ré-exécutant les 2 requêtes SQL ci-dessus en boucle,
            // sans throttle, précisément pendant un incident actif où la
            // base est déjà sous tension.
            // Round 298 : error_log() ajouté — même raison que
            // sendImmediateAlert() ci-dessus, pour que ce cas (email de
            // destination invalide) laisse au moins une trace serveur au
            // lieu d'être indiscernable de "rien à signaler".
            error_log('[Neria WatchdogManager] Digest quotidien non envoyé : aucune adresse email de destination valide (NERIA_ALERT_EMAIL et PS_SHOP_EMAIL invalides ou vides).');
            \Configuration::updateGlobalValue(self::CFG_DIGEST_LAST . '_' . $this->idShop, time());
            return;
        }

        // Même clé suffixée par boutique que la lecture ci-dessus (L409) et
        // que la branche "rien à signaler" (L426) — écrire ici la clé SANS
        // suffixe (bug réel) faisait relire $lastDigest toujours à 0 pour
        // cette boutique au prochain appel de sendDailyDigestIfDue()
        // (déclenché à chaque hit hookDisplayHeader), renvoyant un nouveau
        // digest en boucle au lieu d'un seul par 24h.
        \Configuration::updateGlobalValue(self::CFG_DIGEST_LAST . '_' . $this->idShop, time());

        $prevLang = AdminTranslator::currentLang();
        AdminTranslator::setLang($this->getShopLang());

        // Round 261 : try/finally -- même correctif que sendImmediateAlert()
        // ci-dessus, d'autant plus nécessaire ici qu'une vraie requête SQL
        // (executeS() ci-dessous) s'exécute entre setLang() et sa
        // restauration -- un échec DB (deadlock, perte de connexion) y
        // laisserait AdminTranslator::$lang (propriété statique) bloqué sur
        // la langue de la boutique pour le reste du process entier.
        try {
        // Round 115 : $this->idShop transmis explicitement (même piège que
        // sendImmediateAlert() ci-dessus).
        $shopName   = str_replace(["\r", "\n"], '', (string) \Configuration::get('PS_SHOP_NAME', null, null, $this->idShop));
        $shopDomain = \Tools::getShopDomainSsl(true);

        // Compteurs réels (GROUP BY, SANS LIMIT) — $rows ci-dessus est
        // plafonné à 50 lignes pour l'affichage détaillé du tableau, donc
        // compter/dériver le sujet depuis $rows uniquement donnait une
        // image tronquée dès qu'une rafale dépassait 50 événements en 24h
        // (ex. panne DB) : le marchand voyait "50 événements" avec une
        // répartition partielle (parfois aucun 'critical' visible si les
        // plus anciens ont été exclus par le LIMIT), pensant l'incident
        // contenu alors qu'il ne voyait qu'une fraction de la réalité.
        $countRows = $this->db->executeS(
            "SELECT `level`, COUNT(*) AS n
             FROM `{$table}`
             WHERE `id_shop` = {$this->idShop}
               AND `level` IN ('warning','error','critical')
               AND `date_add` > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY `level`"
        ) ?: [];
        $counts    = ['warning' => 0, 'error' => 0, 'critical' => 0];
        $totalReal = 0;
        foreach ($countRows as $cr) {
            if (isset($counts[$cr['level']])) {
                $counts[$cr['level']] = (int) $cr['n'];
                $totalReal += (int) $cr['n'];
            }
        }

        $subject = str_replace(["\r", "\n"], '', AdminTranslator::tVars('wd_digest.subject', ['count' => $totalReal, 'shop' => $shopName]));
        // Round 250 : voir justification dans sendImmediateAlert() -- mail()
        // natif ici aussi, encodage RFC 2047 à la charge de Neria.
        $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

        $emergencyToken = (string) \Configuration::getGlobalValue('NERIA_EMERGENCY_TOKEN');
        $emergencyUrl   = $emergencyToken
            ? $shopDomain . \__PS_BASE_URI__ . 'modules/neria/neria-emergency.php?token=' . urlencode($emergencyToken)
            : '';

        $rows_html = '';
        foreach ($rows as $r) {
            $color  = $r['level'] === 'critical' ? '#7a0000' : ($r['level'] === 'error' ? '#a32d2d' : '#ba7517');
            $cleanM = htmlspecialchars(strip_tags(self::resolveLogMessage($r['message'])));
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
            . '<h1 style="color:#fff;margin:8px 0 0;font-size:18px;">' . AdminTranslator::t('wd_digest.title') . '</h1>'
            . '<p style="color:#aaa;margin:6px 0 0;font-size:12px;">' . AdminTranslator::tVars('wd_digest.subtitle', ['date' => \NeriaTools::formatDate('now', AdminTranslator::currentLang())]) . '</p>'
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
            . '<th style="padding:8px 10px;text-align:left;font-size:11px;color:#555;font-weight:600;">' . AdminTranslator::t('wd_alert.date_label') . '</th>'
            . '<th style="padding:8px 10px;text-align:left;font-size:11px;color:#555;font-weight:600;">' . AdminTranslator::t('wd_alert.level_label') . '</th>'
            . '<th style="padding:8px 10px;text-align:left;font-size:11px;color:#555;font-weight:600;">' . AdminTranslator::t('wd_alert.class_label') . '</th>'
            . '<th style="padding:8px 10px;text-align:left;font-size:11px;color:#555;font-weight:600;">' . AdminTranslator::t('wd_alert.message_label') . '</th>'
            . '</tr></thead><tbody>' . $rows_html . '</tbody></table>'
            . '</div>'
            . ($emergencyUrl
                ? '<div style="margin-top:20px;"><a href="' . htmlspecialchars($emergencyUrl) . '" style="display:inline-block;background:#b38b59;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">' . AdminTranslator::t('wd_alert.open_emergency') . '</a></div>'
                : '')
            . '<p style="margin-top:20px;font-size:11px;color:#aaa;">' . AdminTranslator::t('wd_digest.footer') . '</p>'
            . '</div></div></body></html>';
        } finally {
            AdminTranslator::setLang($prevLang);
        }

        $fromEmail = str_replace(["\r", "\n"], '', (string) \Configuration::get('PS_SHOP_EMAIL', null, null, $this->idShop) ?: 'noreply@' . parse_url($shopDomain, PHP_URL_HOST));
        $headers   = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                   . "From: Neria <" . $fromEmail . ">\r\n"
                   . "X-Mailer: Neria-WatchdogDigest/1.0\r\n";

        // Le retour n'était jusqu'ici jamais vérifié : un SMTP/sendmail local
        // en panne (précisément le cas où cette alerte est la plus utile)
        // échouait silencieusement, sans trace nulle part. error_log() ici
        // délibérément — PAS un nouveau log Watchdog (critical()/error()) :
        // ça créerait un risque de boucle (un échec d'alerte redéclenchant
        // une tentative d'alerte). Le journal serveur reste le seul canal
        // sûr pour signaler l'échec de CE mécanisme précis.
        if (!@mail($email, $subject, $body, $headers)) {
            error_log('[Neria WatchdogManager] Échec envoi alerte email vers ' . $email);
        }
    }

    private function getAlertEmail(): string
    {
        $email = (string) \Configuration::getGlobalValue(self::CFG_ALERT_EMAIL);
        if ($email !== '' && \Validate::isEmail($email)) {
            return $email;
        }
        // Round 115 : $this->idShop transmis explicitement (même piège que
        // sendImmediateAlert()/sendDailyDigest() ci-dessus).
        $shopEmail = (string) \Configuration::get('PS_SHOP_EMAIL', null, null, $this->idShop);
        return \Validate::isEmail($shopEmail) ? $shopEmail : '';
    }

    /**
     * Langue de la boutique (le marchand configure la même langue pour le BO
     * et le front) — source stable pour les emails d'alerte Watchdog, qui
     * peuvent être déclenchés depuis n'importe quel contexte (cron, front,
     * BO) où Context::language ne reflète pas forcément la langue du
     * destinataire réel de l'alerte.
     *
     * @param int|null $idShop Round 142 : à transmettre explicitement depuis
     *  tout appelant qui connaît sa boutique ($this->idShop capturé au
     *  constructeur). Sans lui, Configuration::get() retombe sur le static
     *  Shop::$context_id_shop — qui reste figé sur la boutique d'origine
     *  pendant une boucle multi-boutique (simple réassignation de
     *  Context->shop, jamais Shop::setContext()) — et peut renvoyer la
     *  langue par défaut d'une AUTRE boutique que celle réellement en cours
     *  de traitement. Impact limité au libellé des messages Watchdog, pas
     *  de fuite de données client.
     */
    public static function shopLang(?int $idShop = null): string
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $iso    = $idLang ? \Language::getIsoById($idLang) : false;
        return $iso ?: 'en';
    }

    private function getShopLang(): string
    {
        return self::shopLang($this->idShop);
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

        // Round 223 : $use_cache=false, même famille de bug que les
        // rounds 210-222 — sans lui, la purge par plafond pourrait être
        // retardée d'un cycle sous cache SQL périmé.
        $count = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `id_shop` = {$this->idShop}",
            false
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
            // Cron lié à une fonctionnalité optionnelle désactivée (ex. fidélité) :
            // ne jamais le compter en retard, il n'est pas censé tourner.
            if (!empty($cfg['enabled_cfg']) && !\Configuration::getGlobalValue($cfg['enabled_cfg'])) {
                continue;
            }
            $row       = $byKey[$key] ?? null;
            $lastRun   = $row ? $row['last_run']    : null;
            $lastStatus= $row ? $row['last_status'] : 'unknown';
            $lastCount = $row ? (int) $row['last_count'] : 0;
            $threshold = ($cfg['threshold_hours'] ?? 25) * 3600;
            $age       = $lastRun ? (time() - strtotime($lastRun)) : null;
            $isLate    = ($age === null || $age > $threshold);

            $result[$key] = [
                'label'       => class_exists('AdminTranslator') ? \AdminTranslator::t($cfg['label_key']) : $cfg['label_key'],
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

    /**
     * Timestamp de la dernière fois où le point d'entrée cron externe
     * (controllers/front/cron.php) a été appelé avec succès, ou null si
     * jamais. Contrairement aux entrées de KNOWN_CRONS, ce cron est
     * facultatif (le fallback via hookDisplayHeader existe toujours) —
     * son absence ne doit donc jamais pénaliser le score de santé, elle
     * est juste signalée en information (cf. HealthCheckManager).
     */
    public function getLastCronEndpointHit(): ?string
    {
        $table = _DB_PREFIX_ . self::TABLE_CRON;
        $value = $this->db->getValue(
            "SELECT `last_run` FROM `{$table}`
             WHERE `id_shop` = {$this->idShop} AND `cron_key` = 'cron_endpoint'"
        );
        return $value ?: null;
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

        // AND id_shop = $this->idShop sur les 3 requêtes : sans lui (comme
        // partout ailleurs dans cette classe, cf. getCronHealth() ci-dessous),
        // le widget de santé de CHAQUE boutique agrégeait les emails
        // bloqués/échoués de TOUTES les boutiques — fausse alerte sur une
        // boutique saine si une autre boutique a une panne SMTP, ou
        // inversement un vrai problème dilué/masqué dans un total global
        // jamais attribué à la bonne boutique.
        $stuck = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE `status` = 'pending'
               AND `send_at` < DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND `id_shop` = {$this->idShop}"
        );

        $failed = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'failed' AND `id_shop` = {$this->idShop}"
        );

        $totalPending = (int) $this->db->getValue(
            "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'pending' AND `id_shop` = {$this->idShop}"
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
        // Pas de plafond par catégorie — auparavant min(15, warning*2) etc.
        // figeait la pénalité dès 8 warnings (16 plafonné à 15), et toute
        // aggravation au-delà (20, 30 templates cassés...) n'était plus du
        // tout reflétée : le score pouvait afficher "Bon" (>=70) en pleine
        // dégradation massive. Seul le plancher final (max(0, ...) plus bas)
        // borne désormais le score affiché — chaque événement supplémentaire
        // continue de faire baisser le score jusqu'à 0.
        $score -= $rc['warning']  * 2;
        $score -= $rc['error']    * 5;
        $score -= $rc['critical'] * 10;
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
