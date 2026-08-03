<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — QueueManager
 *
 * File d'attente d'emails comportementaux (TABLE 27 : ps_neria_queue).
 *
 * Flux :
 *  1. BehavioralCronManager::send() appelle enqueue() si la fenêtre d'achat
 *     est activée et que le client a un pattern détecté.
 *  2. Le cron quotidien appelle processQueue() pour envoyer les emails arrivés
 *     à échéance (send_at <= NOW()).
 *  3. Chaque tentative est loguée via Watchdog.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class QueueManager
{
    const MAX_ATTEMPTS = 3;
    const BATCH_SIZE   = 50;

    private \Neria $module;
    private \Db    $db;
    private string $prefix;
    private int    $idShop;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(\Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->prefix = _DB_PREFIX_;
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ──────────────────────────────────────────────────────────────────
    // ENQUEUE
    // ──────────────────────────────────────────────────────────────────

    /**
     * Ajoute un email à la file d'attente pour envoi à l'heure préférée du client.
     * Si l'heure préférée est passée aujourd'hui, l'email est programmé pour demain.
     */
    public function enqueue(
        string $template,
        array  $customer,
        array  $extraVars,
        int    $refId,
        int    $preferredHour
    ): void {
        $sendAt    = $this->nextOccurrence($preferredHour);
        $idLang    = (int) ($customer['id_lang'] ?? \Configuration::get('PS_LANG_DEFAULT'));
        $idShop    = (int) ($customer['id_shop'] ?? \Context::getContext()->shop->id);
        $toName    = trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? ''));
        $varsJson  = json_encode($extraVars, JSON_UNESCAPED_UNICODE);

        // INSERT IGNORE appuyé sur la contrainte UNIQUE (id_customer,
        // template, ref_id) — cf. upgrade-1.0.36.php. Auparavant un simple
        // INSERT sans aucune protection : un même événement mis en file deux
        // fois (double exécution de cron, webhook rejoué) faisait recevoir
        // au client le même email en double à l'heure programmée.
        $this->db->execute(
            'INSERT IGNORE INTO `' . $this->prefix . 'neria_queue`
             (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
              vars_json, ref_id, send_at, status, created_at)
             VALUES (
               ' . (int) $customer['id_customer'] . ',
               ' . $idShop . ',
               ' . $idLang . ',
               \'' . pSQL($template) . '\',
               \'' . pSQL($customer['email'] ?? '') . '\',
               \'' . pSQL($toName) . '\',
               \'' . pSQL($varsJson) . '\',
               ' . (int) $refId . ',
               \'' . pSQL($sendAt) . '\',
               \'pending\',
               NOW()
             )'
        );

        if ($this->db->Affected_Rows() === 0) {
            // Doublon ignoré — événement déjà en file (ou déjà traité) pour
            // ce couple client/template/référence.
            return;
        }

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.queue_scheduled', [
                'template' => $template,
                'email'    => $customer['email'] ?? '?',
                'sendAt'   => $sendAt,
                'hour'     => sprintf('%02d', $preferredHour),
            ]),
            $template,
            'QueueManager'
        );
    }

    /**
     * Ajoute un email avec une date/heure précise (envoi manuel planifié depuis le BO).
     */
    public function enqueueAt(
        string $template,
        array  $customer,
        array  $extraVars,
        int    $refId,
        string $sendAt
    ): void {
        $idLang   = (int) ($customer['id_lang'] ?? \Configuration::get('PS_LANG_DEFAULT'));
        $idShop   = (int) ($customer['id_shop'] ?? \Context::getContext()->shop->id);
        $toName   = trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? ''));
        $varsJson = json_encode($extraVars, JSON_UNESCAPED_UNICODE);

        $this->db->execute(
            'INSERT INTO `' . $this->prefix . 'neria_queue`
             (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
              vars_json, ref_id, send_at, status, created_at)
             VALUES (
               ' . (int) ($customer['id_customer'] ?? 0) . ',
               ' . $idShop . ',
               ' . $idLang . ',
               \'' . pSQL($template) . '\',
               \'' . pSQL($customer['email'] ?? '') . '\',
               \'' . pSQL($toName) . '\',
               \'' . pSQL($varsJson) . '\',
               ' . (int) $refId . ',
               \'' . pSQL($sendAt) . '\',
               \'pending\',
               NOW()
             )'
        );

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.queue_manual_scheduled', ['template' => $template, 'email' => $customer['email'] ?? '?', 'sendAt' => $sendAt]),
            $template,
            'QueueManager'
        );
    }

    /**
     * Calcule le prochain datetime correspondant à $hour (ex. 14 → "2026-06-25 14:00:00").
     * Si l'heure est déjà passée aujourd'hui, on programme pour demain.
     */
    private function nextOccurrence(int $hour): string
    {
        $now    = new \DateTime();
        $target = new \DateTime('today ' . sprintf('%02d:00:00', $hour));

        // `<` et non `<=` : si l'heure cible tombe pile à la seconde actuelle
        // (cas limite très rare), elle reste programmée pour AUJOURD'HUI —
        // processQueue() la sélectionnera immédiatement (send_at <= NOW())
        // au lieu de la reporter inutilement à demain pour une égalité à la
        // seconde près.
        if ($target < $now) {
            $target->modify('+1 day');
        }

        return $target->format('Y-m-d H:i:s');
    }

    // ──────────────────────────────────────────────────────────────────
    // PROCESS QUEUE
    // ──────────────────────────────────────────────────────────────────

    /**
     * Envoie tous les emails en attente dont send_at <= NOW().
     * Appelé depuis BehavioralCronManager::run().
     *
     * @return int Nombre d'emails envoyés avec succès.
     */
    /**
     * Retourne les entrées en attente issues d'un envoi manuel (ref_id = 0), pour affichage BO.
     */
    public function getPendingManual(): array
    {
        $rows = $this->db->executeS(
            'SELECT `id_neria_queue`, `template`, `recipient_email`, `recipient_name`,
                    DATE_FORMAT(`send_at`, \'%d/%m/%Y %H:%i\') AS send_at_fmt,
                    `send_at`, `status`
             FROM `' . $this->prefix . 'neria_queue`
             WHERE `id_shop` = ' . $this->idShop . '
               AND `ref_id` = 0
               AND `status` = \'pending\'
             ORDER BY `send_at` ASC
             LIMIT 20'
        );
        return is_array($rows) ? $rows : [];
    }

    /**
     * Compte total des envois manuels en attente (au-delà des 20 affichés
     * par getPendingManual()) — permet au BO d'indiquer "20 affichés sur X"
     * plutôt que de tronquer silencieusement la liste au-delà de 20.
     */
    public function countPendingManual(): int
    {
        return (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->prefix . 'neria_queue`
             WHERE `id_shop` = ' . $this->idShop . '
               AND `ref_id` = 0 AND `status` = \'pending\''
        );
    }

    public function processQueue(): int
    {
        // Point de vérification dispersé #2 : les emails déjà en file
        // restent en attente (jamais perdus) tant que la licence est
        // bloquée — ils partiront normalement dès que le verrou se lève
        // (réactivation, ou simple fin du délai de grâce reconnue par le
        // serveur). Vérification locale uniquement (cf. LicenseManager::
        // isEmailSendingAllowed()), aucun appel réseau ici.
        if (class_exists('LicenseManager') && !(new \LicenseManager($this->module))->isEmailSendingAllowed()) {
            return 0;
        }

        // Verrou MySQL : cette méthode est appelée depuis PLUSIEURS points
        // d'entrée indépendants (BehavioralCronManager::run() sur le cron
        // frontend, HealthCheckManager, et le bouton BO "Traiter la file
        // maintenant" dans neria.php) — même risque déjà identifié et
        // corrigé pour WebhookManager::processQueue() : sans ce verrou, deux
        // exécutions concurrentes peuvent lire le même lot de lignes
        // 'pending' avant que l'une des deux n'ait eu le temps d'incrémenter
        // `attempts`, envoyant chaque email en double au client. Verrou
        // global (pas par boutique) : cette méthode traite déjà toutes les
        // boutiques en un seul appel (le id_shop par ligne est utilisé pour
        // l'envoi, pas pour filtrer la sélection).
        if ((int) $this->db->getValue("SELECT GET_LOCK('neria_queue_process_queue', 0)") !== 1) {
            return 0;
        }

        try {
            $rows = $this->db->executeS(
                'SELECT * FROM `' . $this->prefix . 'neria_queue`
                 WHERE status = \'pending\'
                   AND send_at <= NOW()
                   AND attempts < ' . self::MAX_ATTEMPTS . '
                 ORDER BY send_at ASC, id_neria_queue ASC
                 LIMIT ' . self::BATCH_SIZE
            );

            if (empty($rows)) {
                return 0;
            }

            $sent   = 0;
            $failed = 0;

            foreach ((array) $rows as $row) {
                if ($this->processSingle($row)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.queue_processed_summary', ['sent' => $sent, 'failed' => $failed]),
                '',
                'QueueManager'
            );

            return $sent;
        } finally {
            // Purge des lignes terminales anciennes — auparavant absente :
            // les lignes 'sent'/'failed' s'accumulaient indéfiniment dans
            // ps_neria_queue. Probabiliste (1 tentative sur 10) plutôt qu'à
            // chaque appel, même logique que WatchdogManager::pruneOldLogs().
            // Fenêtre de 60 jours (le double des 30 jours déjà utilisés par
            // getStats()) pour ne jamais purger une donnée encore affichée
            // dans les statistiques BO.
            if (random_int(1, 10) === 1) {
                $this->purgeOldEntries();
            }
            $this->db->execute("SELECT RELEASE_LOCK('neria_queue_process_queue')");
        }
    }

    private function purgeOldEntries(): void
    {
        $this->db->execute(
            'DELETE FROM `' . $this->prefix . 'neria_queue`
             WHERE status IN (\'sent\', \'failed\')
               AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)'
        );
    }

    private function processSingle(array $row): bool
    {
        $id = (int) $row['id_neria_queue'];

        // Incrémenter les tentatives immédiatement pour éviter le double-envoi concurrent.
        $this->db->execute(
            'UPDATE `' . $this->prefix . 'neria_queue`
             SET attempts = attempts + 1
             WHERE id_neria_queue = ' . $id
        );

        try {
            $vars   = json_decode($row['vars_json'] ?? '{}', true) ?: [];
            $idLang = (int) ($row['id_lang'] ?? \Configuration::get('PS_LANG_DEFAULT'));
            $idShop = (int) ($row['id_shop'] ?? 1);

            $ctx  = \Context::getContext();
            $link = ($ctx && $ctx->link) ? $ctx->link : null;

            // {firstname}/{lastname} ne sont jamais stockées dans vars_json (enqueue()
            // ne persiste que $extraVars) — sans ce lookup, tout email comportemental
            // passé par la fenêtre d'achat individuelle afficherait "{firstname}" brut
            // au client, quel que soit le template.
            // id_customer = 0 pour les entrées d'envoi manuel sans client rattaché
            // (cf. enqueue()) — inutile d'instancier Customer(0) (aller-retour DB
            // qui échouera systématiquement à se charger) pour ces lignes-là.
            $idCustomerRow = (int) $row['id_customer'];
            $firstname = '';
            $lastname  = '';
            if ($idCustomerRow > 0) {
                $customer  = new \Customer($idCustomerRow);
                $firstname = \Validate::isLoadedObject($customer) ? $customer->firstname : '';
                $lastname  = \Validate::isLoadedObject($customer) ? $customer->lastname : '';
            }

            $allVars = array_merge(
                [
                    '{firstname}'   => $firstname,
                    '{lastname}'    => $lastname,
                    '{shop_name}'   => \Configuration::get('PS_SHOP_NAME'),
                    '{history_url}' => $link ? $link->getPageLink('history', true, $idLang) : '',
                ],
                $vars
            );

            $sent = \Mail::Send(
                $idLang,
                $row['template'],
                '',
                $allVars,
                $row['recipient_email'],
                $row['recipient_name'] ?: null,
                null, null, null, null,
                _PS_MODULE_DIR_ . 'neria/mails/',
                false,
                $idShop
            );

            if ($sent) {
                $this->db->execute(
                    'UPDATE `' . $this->prefix . 'neria_queue`
                     SET status = \'sent\', sent_at = NOW()
                     WHERE id_neria_queue = ' . $id
                );

                // Miroir de ManualSendManager::send() : un envoi anniversaire
                // planifié (scheduleManual() → cette file) doit être
                // enregistré dans neria_behavioral_sent au moment de l'envoi
                // RÉEL (pas à la planification, qui peut échouer/être
                // retentée) — sinon BehavioralCronManager considère
                // l'anniversaire comme jamais envoyé et le renvoie en double.
                // ref_id doit utiliser EXACTEMENT la même clé que
                // BehavioralCronManager/ManualSendManager::send().
                if (in_array($row['template'], ['first_anniversary', 'relationship_anniversary'], true)
                    && (int) $row['id_customer'] > 0
                ) {
                    if ($row['template'] === 'first_anniversary') {
                        $refId = (int) $this->db->getValue(
                            'SELECT MIN(id_order) FROM `' . $this->prefix . 'orders`
                             WHERE id_customer = ' . (int) $row['id_customer'] . '
                               AND valid = 1'
                        );
                    } else {
                        $refId = (int) date('Y');
                    }

                    if ($refId > 0) {
                        $this->db->execute(
                            'INSERT IGNORE INTO `' . $this->prefix . 'neria_behavioral_sent`
                             (id_customer, template, ref_id, id_shop, sent_at)
                             VALUES (' . (int) $row['id_customer'] . ', \'' . pSQL($row['template']) . '\', '
                            . $refId . ', ' . $idShop . ', NOW())'
                        );
                    }
                }

                $this->watchdog()->info(
                    \WatchdogManager::i18nMsg('watchdog.queue_sent_to', ['template' => $row['template'], 'email' => $row['recipient_email'], 'id' => $id]),
                    $row['template'],
                    'QueueManager'
                );
                return true;
            }

            $this->markFailedOrRetry($id, (int) $row['attempts'] + 1, 'Mail::Send() a retourné false.');
            return false;

        } catch (\Throwable $e) {
            $this->markFailedOrRetry($id, (int) $row['attempts'] + 1, $e->getMessage());
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.queue_send_error', ['email' => $row['recipient_email'], 'id' => $id, 'error' => $e->getMessage()]),
                $row['template'] ?? '',
                'QueueManager'
            );
            return false;
        }
    }

    private function markFailedOrRetry(int $id, int $attempts, string $error): void
    {
        $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

        // Délai de recul avant la prochaine tentative — auparavant `send_at`
        // n'était jamais repoussé : une ligne repassée en 'pending' restait
        // sélectionnable IMMÉDIATEMENT au prochain passage du cron (send_at
        // déjà dans le passé). Sur un cron toutes les 5-15 min, les 3
        // tentatives pouvaient s'épuiser en moins d'une heure lors d'une
        // simple panne SMTP transitoire de quelques heures, clôturant
        // définitivement l'email sans lui laisser de vraie chance de
        // récupération. Recul exponentiel : 2h après le 1er échec, 4h après
        // le 2e — ne s'applique qu'en cas de nouvelle tentative (status
        // toujours 'pending'), pas sur l'échec final ('failed').
        $sendAtSql = '';
        if ($status === 'pending') {
            $backoffHours = 2 * $attempts;
            $sendAtSql = ", send_at = DATE_ADD(NOW(), INTERVAL {$backoffHours} HOUR)";
        }

        $this->db->execute(
            'UPDATE `' . $this->prefix . 'neria_queue`
             SET status = \'' . $status . '\',
                 error  = \'' . pSQL(substr($error, 0, 500)) . '\'' . $sendAtSql . '
             WHERE id_neria_queue = ' . $id
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // STATS BO
    // ──────────────────────────────────────────────────────────────────

    /**
     * Statistiques pour la section BO.
     *
     * @return array{pending: int, sent_30d: int, failed_30d: int, avg_delay_min: int|null, coverage_pct: int, peak_hour: int|null}
     */
    public function getStats(): array
    {
        $pending = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->prefix . 'neria_queue`
             WHERE id_shop = ' . $this->idShop . ' AND status = \'pending\''
        );

        $sent30d = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->prefix . 'neria_queue`
             WHERE id_shop = ' . $this->idShop . '
               AND status = \'sent\' AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );

        $failed30d = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->prefix . 'neria_queue`
             WHERE id_shop = ' . $this->idShop . '
               AND status = \'failed\' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );

        $avgRaw = $this->db->getValue(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, sent_at))
             FROM `' . $this->prefix . 'neria_queue`
             WHERE id_shop = ' . $this->idShop . '
               AND status = \'sent\' AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $avgDelay = ($avgRaw !== false && $avgRaw !== null) ? (int) round((float) $avgRaw) : null;

        // % de clients actifs ayant une fenêtre détectable
        $totalActive = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->prefix . 'customer`
             WHERE id_shop = ' . $this->idShop . ' AND active = 1 AND deleted = 0'
        );
        // Ne pas dupliquer ce calcul ici : la requête inline précédente
        // groupait par HOUR(date_add) exacte, alors que
        // PurchaseWindowManager a depuis corrigé sa propre détection pour
        // grouper par créneau de 2h (FLOOR(HOUR/2)*2) — un client commandant
        // à cheval sur deux heures entières n'atteignait jamais
        // MINIMUM_ORDERS et n'était jamais compté comme couvert. La requête
        // dupliquée ici n'avait pas suivi ce correctif, faussant à la baisse
        // le % de couverture affiché en BO par rapport à la vraie logique
        // utilisée pour programmer les envois (getPreferredHour()).
        $withWindow = (new \PurchaseWindowManager())->getWindowCoverageCount($this->idShop);
        $coveragePct = $totalActive > 0 ? (int) round($withWindow / $totalActive * 100) : 0;

        // Heure de pointe globale (pour l'histogramme simplifié)
        // getRow() ajoute LIMIT 1 automatiquement — pas de LIMIT dans la requête.
        $peakRow = $this->db->getRow(
            'SELECT HOUR(date_add) AS h, COUNT(*) AS cnt
             FROM `' . $this->prefix . 'orders`
             WHERE valid = 1 AND id_shop = ' . $this->idShop . '
             GROUP BY HOUR(date_add)
             ORDER BY cnt DESC'
        );
        $peakHour = $peakRow ? (int) $peakRow['h'] : null;

        return [
            'pending'       => $pending,
            'sent_30d'      => $sent30d,
            'failed_30d'    => $failed30d,
            'avg_delay_min' => $avgDelay,
            'coverage_pct'  => $coveragePct,
            'peak_hour'     => $peakHour,
        ];
    }
}
