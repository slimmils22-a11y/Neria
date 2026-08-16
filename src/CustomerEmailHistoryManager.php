<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — CustomerEmailHistoryManager
 *
 * Construit le bloc « Emails reçus » affiché sur la fiche client du BO
 * (hook displayAdminCustomersView) :
 * — historique chronologique des envois (timeline + tableau complet)
 * — badge d'engagement (taux d'ouverture personnel du client)
 * — alertes intelligentes (inactif, très engagé, tempête d'emails)
 * — export CSV
 *
 * S'appuie uniquement sur la table ps_neria_stat (déjà alimentée par
 * StatsManager) — aucune nouvelle table nécessaire pour le MVP.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CustomerEmailHistoryManager
{
    const TIMELINE_LIMIT = 15;
    const INACTIVE_DAYS  = 60;
    const STORM_WINDOW_HOURS = 2;
    const STORM_THRESHOLD    = 3;

    private Neria $module;
    private \Db $db;
    private int $idShop;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    /**
     * Construit toutes les données nécessaires au bloc (timeline, tableau
     * complet, badge, alertes) pour un client donné.
     */
    public function buildBlockData(int $idCustomer): array
    {
        $emails = $this->getEmails($idCustomer);
        $badge  = $this->computeEngagementBadge($emails);
        $badge['shop_avg_rate_open'] = $this->getShopAverageOpenRate();

        return [
            'id_customer'   => $idCustomer,
            'emails'        => $emails,
            'timeline'      => array_slice($emails, 0, self::TIMELINE_LIMIT),
            'has_more'      => count($emails) > self::TIMELINE_LIMIT,
            'badge'         => $badge,
            'alerts'        => $this->computeAlerts($emails, $badge),
            'templates_list' => array_values(array_unique(array_column($emails, 'template'))),
        ];
    }

    /**
     * Taux d'ouverture moyen de la boutique, tous clients confondus —
     * sert de point de comparaison au badge d'engagement individuel.
     */
    public function getShopAverageOpenRate(): float
    {
        $table = _DB_PREFIX_ . StatsManager::TABLE;

        $sql = "SELECT
                    COUNT(*) AS total_sent,
                    SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM `{$table}` o
                        WHERE o.tracking_token = s.tracking_token AND o.event_type = 'open' AND o.is_mpp = 0
                    ) THEN 1 ELSE 0 END) AS total_opened
                FROM `{$table}` s
                WHERE s.id_shop = {$this->idShop} AND s.event_type = 'sent'";

        $row = $this->db->getRow($sql);
        if (!$row || (int) $row['total_sent'] === 0) {
            return 0.0;
        }

        return round(((int) $row['total_opened'] / (int) $row['total_sent']) * 100);
    }

    /**
     * Récupère tous les emails envoyés à ce client, du plus récent au plus
     * ancien, avec leur statut d'ouverture (jointure sur tracking_token).
     */
    public function getEmails(int $idCustomer): array
    {
        $table = _DB_PREFIX_ . StatsManager::TABLE;

        $sql = "SELECT
                    s.id_stat,
                    s.template,
                    s.lang,
                    s.date_add AS sent_at,
                    s.tracking_token,
                    s.id_order,
                    s.rendered_vars,
                    (SELECT o.date_add FROM `{$table}` o
                        WHERE o.tracking_token = s.tracking_token
                          AND o.event_type = 'open'
                          AND o.is_mpp = 0
                        ORDER BY o.date_add ASC LIMIT 1) AS opened_at
                FROM `{$table}` s
                WHERE s.id_shop = {$this->idShop}
                  AND s.id_customer = " . (int) $idCustomer . "
                  AND s.event_type = 'sent'
                ORDER BY s.date_add DESC";

        $rows = $this->db->executeS($sql);
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['opened']       = !empty($row['opened_at']);
            $row['sent_at_fmt']  = \NeriaTools::formatDate($row['sent_at'], \AdminTranslator::currentLang(), true);
            $row['has_snapshot'] = !empty($row['rendered_vars']);
        }

        return $rows;
    }

    /**
     * Badge d'engagement basé sur le taux d'ouverture global du client.
     * Seuils : 70%+ Très engagé, 40%+ Engagé, 10%+ Peu réactif, sinon Inactif.
     */
    public function computeEngagementBadge(array $emails): array
    {
        $total = count($emails);
        if ($total === 0) {
            return [
                'total_sent' => 0,
                'rate_open'  => 0,
                'level'      => 'new',
            ];
        }

        $opened   = count(array_filter($emails, fn($e) => $e['opened']));
        $rateOpen = round(($opened / $total) * 100);

        if ($rateOpen >= 70) {
            $level = 'very_engaged';
        } elseif ($rateOpen >= 40) {
            $level = 'engaged';
        } elseif ($rateOpen >= 10) {
            $level = 'low';
        } else {
            $level = 'inactive';
        }

        return [
            'total_sent' => $total,
            'total_open' => $opened,
            'rate_open'  => $rateOpen,
            'level'      => $level,
        ];
    }

    /**
     * Alertes contextuelles affichées au-dessus du bloc :
     * — inactif depuis longtemps → suggérer un win-back
     * — taux d'ouverture parfait → suggérer le programme VIP
     * — rafale d'envois récente → suggérer le mode silence
     */
    public function computeAlerts(array $emails, array $badge): array
    {
        $alerts = [];

        if (!empty($emails)) {
            // $emails est trié par date d'ENVOI décroissante, pas par date
            // d'ouverture : un email envoyé plus tôt peut avoir été ouvert
            // plus récemment qu'un email envoyé après. Il faut donc prendre
            // le max des opened_at sur tout l'historique, pas le premier
            // email ouvert rencontré dans l'ordre d'envoi.
            $lastOpen = null;
            foreach ($emails as $e) {
                if ($e['opened'] && ($lastOpen === null || strtotime($e['opened_at']) > strtotime($lastOpen))) {
                    $lastOpen = $e['opened_at'];
                }
            }

            $daysSinceLastOpen = $lastOpen
                ? (int) floor((time() - strtotime($lastOpen)) / 86400)
                : (int) floor((time() - strtotime(end($emails)['sent_at'])) / 86400);

            if ($badge['total_sent'] >= 2 && $daysSinceLastOpen >= self::INACTIVE_DAYS) {
                $alerts[] = [
                    'type' => 'warning',
                    'key'  => 'alert_inactive',
                    'vars' => ['days' => $daysSinceLastOpen],
                ];
            }
        }

        if ($badge['total_sent'] >= 3 && $badge['rate_open'] == 100) {
            $alerts[] = [
                'type' => 'success',
                'key'  => 'alert_perfect',
                'vars' => [],
            ];
        }

        $stormCount = $this->detectStorm($emails);
        if ($stormCount >= self::STORM_THRESHOLD) {
            $alerts[] = [
                'type' => 'warning',
                'key'  => 'alert_storm',
                'vars' => ['count' => $stormCount],
            ];
        }

        return $alerts;
    }

    /**
     * Détecte si plusieurs emails ont été envoyés dans une fenêtre courte
     * (fenêtre glissante simple sur les envois les plus récents).
     */
    private function detectStorm(array $emails): int
    {
        if (count($emails) < self::STORM_THRESHOLD) {
            return 0;
        }

        $windowSeconds = self::STORM_WINDOW_HOURS * 3600;
        $recent        = array_slice($emails, 0, self::STORM_THRESHOLD);
        $newest        = strtotime($recent[0]['sent_at']);
        $oldest        = strtotime(end($recent)['sent_at']);

        return ($newest - $oldest) <= $windowSeconds ? count($recent) : 0;
    }

    /**
     * Récupère un email précis, en s'assurant qu'il appartient bien au client
     * donné (sécurité : empêche de prévisualiser/renvoyer l'email d'un autre
     * client en bricolant l'id_stat dans l'URL).
     */
    public function getEmailById(int $idStat, int $idCustomer): ?array
    {
        $table = _DB_PREFIX_ . StatsManager::TABLE;

        $row = $this->db->getRow(
            "SELECT * FROM `{$table}`
             WHERE id_stat = " . (int) $idStat . "
               AND id_customer = " . (int) $idCustomer . "
               AND id_shop = {$this->idShop}
               AND event_type = 'sent'"
        );

        return $row ?: null;
    }

    /**
     * Reconstruit le HTML d'un email passé à partir de son snapshot JSON
     * (Option C) et du template actuel. Fidèle aux données d'origine
     * (montant, n° commande...), mais avec la mise en forme/design du jour.
     *
     * @return string|null HTML, ou null si email introuvable / pas de snapshot
     */
    public function buildPreviewHtml(int $idStat, int $idCustomer): ?string
    {
        $email = $this->getEmailById($idStat, $idCustomer);
        if (!$email || !class_exists('EmailRenderer')) {
            return null;
        }

        $vars = $this->decodeSnapshot($email['rendered_vars'] ?? null);

        return (new EmailRenderer($this->module))->renderWithVars(
            $email['template'],
            $email['lang'],
            $vars
        );
    }

    /**
     * Renvoie un email déjà envoyé à ce client, en réutilisant les variables
     * du snapshot d'origine. Passe par Mail::Send → hook Neria, comme un
     * envoi normal (même pipeline que ManualSendManager) : design actuel,
     * pixel de tracking, en-tête List-Unsubscribe, et apparaît automatiquement
     * dans l'historique (nouvel évènement « sent »).
     *
     * @return array{ok:bool, message_key:string, vars:array}
     */
    public function resend(int $idStat, int $idCustomer): array
    {
        $email = $this->getEmailById($idStat, $idCustomer);
        if (!$email) {
            return ['ok' => false, 'message_key' => 'history.resend_not_found', 'vars' => []];
        }

        $customer = new \Customer($idCustomer);
        if (!\Validate::isLoadedObject($customer) || !\Validate::isEmail($customer->email)) {
            return ['ok' => false, 'message_key' => 'history.resend_no_customer_email', 'vars' => []];
        }

        $vars = $this->decodeSnapshot($email['rendered_vars'] ?? null);

        $templateVars = [];
        foreach ($vars as $key => $value) {
            $templateVars['{' . $key . '}'] = $value;
        }
        $templateVars['{firstname}'] = $templateVars['{firstname}'] ?? $customer->firstname;
        $templateVars['{lastname}']  = $templateVars['{lastname}']  ?? $customer->lastname;
        $templateVars['{email}']     = $customer->email;

        $toName = trim($customer->firstname . ' ' . $customer->lastname);

        // Round 179 (audit transversal de fin de série) : Mail::Send() du
        // cœur PrestaShop retourne TOUJOURS true quand le hook
        // actionEmailSendBefore annule l'envoi (bounce/blacklist/
        // préférences/cooldown) — même piège déjà corrigé pour
        // ManualSendManager::send() (round 178, renvoi manuel BO
        // équivalent) mais jamais étendu à ce renvoi-ci. Sans ce contrôle,
        // un employé renvoyant un email à un client blacklisté/désabonné/en
        // bounce voyait "renvoyé avec succès" alors que rien n'était
        // réellement reparti.
        if (class_exists('BounceManager') && \BounceManager::isBounced($customer->email)) {
            return ['ok' => false, 'message_key' => 'history.resend_blocked', 'vars' => ['email' => $customer->email]];
        }
        if (class_exists('BlacklistManager')) {
            $langIso = class_exists('TranslationEngine')
                ? (new \TranslationEngine($this->module))->langFromId((int) $customer->id_lang)
                : (string) (\Language::getIsoById((int) $customer->id_lang) ?: '');
            if ((new \BlacklistManager($this->idShop))->isBlacklisted($email['template'], $langIso)) {
                return ['ok' => false, 'message_key' => 'history.resend_blocked', 'vars' => ['email' => $customer->email]];
            }
        }
        if (class_exists('PreferencesManager')
            && !(new \PreferencesManager($this->module))->isAllowed($idCustomer, $email['template'], $this->idShop, $customer->email)
        ) {
            return ['ok' => false, 'message_key' => 'history.resend_blocked', 'vars' => ['email' => $customer->email]];
        }
        if (class_exists('ConfigManager') && class_exists('CooldownManager')
            && (new \ConfigManager($this->module))->isCooldownEnabled()
        ) {
            $cdMinutes = (new \ConfigManager($this->module))->getCooldownMinutes();
            if ((new \CooldownManager())->isDuplicate($customer->email, $email['template'], $cdMinutes, $this->idShop)) {
                return ['ok' => false, 'message_key' => 'history.resend_blocked', 'vars' => ['email' => $customer->email]];
            }
        }

        $sent = \Mail::Send(
            (int) $customer->id_lang,
            $email['template'],
            '', // sujet vide : EmailRenderer le remplit avec le titre traduit
            $templateVars,
            $customer->email,
            $toName !== '' ? $toName : null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'neria/mails/',
            false,
            $this->idShop
        );

        if (class_exists('WatchdogManager')) {
            $wd = new \WatchdogManager($this->module);
            if ($sent) {
                $wd->info(
                    \WatchdogManager::i18nMsg('watchdog.resend_success', [
                        'template' => $email['template'],
                        'email'    => $customer->email,
                    ]),
                    $email['template'],
                    'CustomerEmailHistoryManager'
                );
            } else {
                $wd->error(
                    \WatchdogManager::i18nMsg('watchdog.resend_failed', [
                        'template' => $email['template'],
                        'email'    => $customer->email,
                    ]),
                    $email['template'],
                    'CustomerEmailHistoryManager'
                );
            }
        }

        return [
            'ok'          => (bool) $sent,
            'message_key' => $sent ? 'history.resend_success' : 'history.resend_failed',
            'vars'        => ['template' => $email['template'], 'email' => $customer->email],
        ];
    }

    /**
     * Décode le snapshot JSON stocké au moment de l'envoi. Retourne un
     * tableau vide si absent (envoi antérieur à cette fonctionnalité) — le
     * renvoi/aperçu se fait alors avec les données actuelles uniquement.
     */
    private function decodeSnapshot(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (class_exists('CryptoManager')) {
            $json = \CryptoManager::decrypt($json);
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Génère le contenu CSV de l'historique complet d'un client.
     */
    public function buildCsv(int $idCustomer): string
    {
        $emails = $this->getEmails($idCustomer);

        $lines   = [];
        $lines[] = implode(';', ['Date', 'Heure', 'Template', 'Langue', 'Statut']);

        foreach ($emails as $e) {
            $dt = strtotime($e['sent_at']);
            $lines[] = implode(';', [
                date('Y-m-d', $dt),
                date('H:i:s', $dt),
                $e['template'],
                $e['lang'],
                $e['opened'] ? 'Ouvert' : 'Envoyé',
            ]);
        }

        return implode("\r\n", $lines);
    }
}
