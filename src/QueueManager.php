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
        // Round 148 : $execOk capturé explicitement — auparavant le résultat
        // de execute() était totalement ignoré, seul Affected_Rows() était
        // consulté. Or Affected_Rows()===0 est à la fois le cas légitime
        // "doublon ignoré par INSERT IGNORE" ET ce que renvoie un execute()
        // ayant réellement échoué (verrou, erreur SQL) — sans distinguer les
        // deux, un échec SQL réel était traité comme un simple doublon
        // silencieux, ET le log de succès plus bas (avant ce correctif)
        // aurait pu s'exécuter même sur cette situation d'échec. Même
        // correctif que celui déjà appliqué à enqueueAt() (jumelle de cette
        // méthode), qui distingue déjà correctement les deux cas.
        $execOk = $this->db->execute(
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

        if ($execOk === false) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.queue_scheduling_failed', [
                    'template' => $template,
                    'email'    => $customer['email'] ?? '?',
                ]),
                $template,
                'QueueManager'
            );
            return;
        }

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
    /**
     * @return bool true si la ligne a bien été insérée en file.
     */
    public function enqueueAt(
        string $template,
        array  $customer,
        array  $extraVars,
        int    $refId,
        string $sendAt
    ): bool {
        $idLang   = (int) ($customer['id_lang'] ?? \Configuration::get('PS_LANG_DEFAULT'));
        $idShop   = (int) ($customer['id_shop'] ?? \Context::getContext()->shop->id);
        $toName   = trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? ''));
        $varsJson = json_encode($extraVars, JSON_UNESCAPED_UNICODE);

        // INSERT IGNORE (appuyé sur la même contrainte UNIQUE (id_customer,
        // template, ref_id, id_shop) qu'enqueue(), cf. upgrade-1.0.36.php) +
        // vérification du résultat — auparavant un simple INSERT sans
        // aucune vérification : la contrainte UNIQUE ajoutée depuis a rendu
        // cet INSERT capable d'échouer silencieusement (2e envoi manuel du
        // même template au même client, ref_id toujours à 0 ici), et
        // l'appelant (ManualSendManager::scheduleManual()) journalisait
        // quand même "succès" et retournait ok=true sans que la ligne
        // n'existe réellement en file — l'admin voyait "programmé" pour un
        // envoi qui ne partirait jamais, sans trace d'erreur exploitable.
        $ok = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->prefix . 'neria_queue`
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
        ) && (int) $this->db->Affected_Rows() > 0;

        if ($ok) {
            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.queue_manual_scheduled', ['template' => $template, 'email' => $customer['email'] ?? '?', 'sendAt' => $sendAt]),
                $template,
                'QueueManager'
            );
        } else {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.queue_manual_scheduled_duplicate', ['template' => $template, 'email' => $customer['email'] ?? '?']),
                $template,
                'QueueManager'
            );
        }

        return $ok;
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
                    // Configuration::get(..., $idShop) : round 106, même
                    // piège que CollectionManager::checkAndSend() corrigé au
                    // round 105 — cette méthode tourne dans le cron
                    // asynchrone de la file, le contexte d'exécution courant
                    // ne correspond pas forcément à la boutique de CETTE
                    // ligne de file ($idShop, déjà utilisé pour
                    // {history_url} juste en dessous).
                    '{shop_name}'   => \Configuration::get('PS_SHOP_NAME', null, null, $idShop),
                    '{history_url}' => $link ? $link->getPageLink('history', true, $idLang, null, false, $idShop) : '',
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

                // Miroir de ManualSendManager::send() : un envoi comportemental
                // planifié via la fenêtre d'achat (BehavioralCronManager::send()
                // → enqueue() → cette file) doit être enregistré dans
                // neria_behavioral_sent au moment de l'envoi RÉEL (pas à la
                // planification, qui peut échouer/être retentée jusqu'à 3
                // fois puis passer en status='failed' sans jamais être
                // repris) — sinon un template dont l'envoi échoue
                // définitivement (SMTP en panne) est quand même marqué
                // "déjà envoyé" et ne sera plus jamais retenté, silencieusement,
                // pour TOUT template routé par cette file (pas seulement les
                // anniversaires, seul cas traité jusqu'ici — round 51).
                //
                // Seul first_anniversary recalcule son propre ref_id (règle
                // spécifique, voir ci-dessous) ; tous les autres templates,
                // y compris relationship_anniversary depuis ce correctif,
                // utilisent directement row['ref_id'], déjà stocké tel quel
                // à l'enqueue — c'est EXACTEMENT la même valeur que
                // BehavioralCronManager::send() aurait utilisée.
                //
                // relationship_anniversary utilisait (int) date('Y') recalculé
                // ICI, au moment de l'envoi réel, au lieu de row['ref_id']
                // (le millésime déjà figé à l'enqueue) — un envoi reporté au
                // lendemain par la fenêtre d'achat individuelle (heure
                // préférée déjà passée) à cheval sur le Nouvel An (ex. 1er
                // achat un 31/12, cron déclenché le 31/12/2026, préférence
                // horaire dépassée → planifié pour le 01/01/2027) écrivait
                // alors ref_id=2027 dans neria_behavioral_sent au lieu de
                // 2026. Un an plus tard, sendRelationshipAnniversaries()
                // vérifiait NOT EXISTS(... ref_id = 2027 ...) — qui matchait
                // déjà à tort la ligne mal datée de l'an dernier : le client
                // ne recevait alors jamais son email d'anniversaire de
                // relation 2027, silencieusement.
                if ((int) $row['id_customer'] > 0) {
                    if ($row['template'] === 'first_anniversary') {
                        // AND id_shop = $idShop : BehavioralCronManager::
                        // sendFirstAnniversaries() calcule id_first_order en
                        // filtrant explicitement sur LA boutique (« 1ère
                        // commande DE CETTE boutique »). Sans ce même filtre
                        // ici, un client partagé entre boutiques dont la
                        // commande la plus ancienne (toutes boutiques
                        // confondues) est sur une AUTRE boutique obtenait un
                        // ref_id incohérent avec celui utilisé à l'enqueue,
                        // cassant la traçabilité de neria_behavioral_sent en
                        // multi-boutique.
                        $refId = (int) $this->db->getValue(
                            'SELECT MIN(id_order) FROM `' . $this->prefix . 'orders`
                             WHERE id_customer = ' . (int) $row['id_customer'] . '
                               AND valid = 1 AND id_shop = ' . (int) $idShop
                        );
                        // Round 158 : repli sur le ref_id déjà figé à
                        // l'enqueue (row['ref_id']) si le recalcul ci-dessus
                        // ne trouve plus aucune commande valide — la
                        // commande la plus ancienne du client a pu basculer
                        // à valid=0 (annulation/remboursement) entre la mise
                        // en file (par sendFirstAnniversaries()) et l'envoi
                        // réel, différé jusqu'à l'heure préférée du client.
                        // Sans ce repli, $refId valait 0, le garde ci-dessous
                        // sautait silencieusement l'INSERT de dédup alors
                        // que l'email était bien envoyé — un futur nouvel
                        // achat rendait le client de nouveau éligible sans
                        // aucune trace de cet envoi précédent, exposant à un
                        // envoi en double du même email d'anniversaire.
                        if ($refId <= 0) {
                            $refId = (int) $row['ref_id'];
                        }
                    } else {
                        $refId = (int) $row['ref_id'];
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
                \WatchdogManager::i18nMsg('watchdog.queue_send_error', ['email' => $row['recipient_email'], 'id' => $id, 'error' => self::sanitizeErrorMessage($e->getMessage())]),
                $row['template'] ?? '',
                'QueueManager'
            );
            return false;
        }
    }

    /**
     * Round 164 : le message d'exception d'un driver SMTP peut inclure des
     * identifiants ou des fragments d'authentification en clair (ex:
     * "Authentication failed for user X / password Y", en-têtes
     * Authorization). Ces messages étaient jusqu'ici stockés tels quels
     * dans ps_neria_log (consultable en BO) — retire les motifs
     * identifiants/mots de passe/jetons avant stockage et plafonne la
     * longueur, plutôt qu'un simple substr() qui ne filtre aucun contenu.
     */
    private static function sanitizeErrorMessage(string $message): string
    {
        $patterns = [
            '/\b(password|passwd|pwd|pass|secret|token|apikey|api_key)\s*[:=]\s*\S+/i',
            '/\bAuthorization:\s*.+/i',
        ];
        $message = preg_replace($patterns, '[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 500);
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
                 error  = \'' . pSQL(self::sanitizeErrorMessage($error)) . '\'' . $sendAtSql . '
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

        // Round 118 : filtré sur send_at, pas created_at. created_at est la
        // date de MISE EN FILE (ex. scheduleManual() peut programmer un
        // envoi des semaines à l'avance), pas celle de l'échec réel — sans
        // dédicace de colonne "failed_at", send_at (mis à jour à chaque
        // tentative par markFailedOrRetry(), donc figé sur la DERNIÈRE
        // tentative planifiée juste avant l'échec final) en est le meilleur
        // proxy disponible. Avec created_at, un envoi programmé loin dans le
        // futur puis en échec restait invisible dans failed30d au moment de
        // l'échec réel, faussant sent30d/failed30d (même piège que le
        // dénominateur non borné corrigé dans ABTestManager, round 117).
        $failed30d = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . $this->prefix . 'neria_queue`
             WHERE id_shop = ' . $this->idShop . '
               AND status = \'failed\' AND send_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
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
        // Round 168 : fenêtrée sur 90 jours (comme les autres stats de cette
        // méthode, fenêtrées sur 30j) — auparavant sur tout l'historique,
        // ce qui rendait le "pic horaire" affiché en BO de moins en moins
        // représentatif du comportement récent des clients à mesure que la
        // boutique vieillit.
        $peakRow = $this->db->getRow(
            'SELECT HOUR(date_add) AS h, COUNT(*) AS cnt
             FROM `' . $this->prefix . 'orders`
             WHERE valid = 1 AND id_shop = ' . $this->idShop . '
               AND date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)
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
