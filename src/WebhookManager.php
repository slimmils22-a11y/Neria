<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — WebhookManager
 *
 * Notifications HTTP sortantes vers des applications externes.
 * Chaque événement Neria est mis en queue (ps_neria_webhook_queue),
 * puis traité par lot via cron/displayHeader.
 *
 * Événements gérés :
 *   email_sent    — après chaque envoi tracké
 *   email_opened  — à l'ouverture du pixel
 *   conversion    — quand une commande est attribuée à un email
 *   unsubscribed  — lors d'un désabonnement (GET ou POST RFC 8058)
 *   ab_winner     — quand un test A/B atteint la significativité
 *
 * Livraison :
 *   — Queue en base, max 3 tentatives par événement
 *   — Traitement par lots de 10, timeout 3 s
 *   — Signature HMAC-SHA256 (header X-Neria-Signature)
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class WebhookManager
{
    const TABLE = 'neria_webhook_queue';

    const STATUS_PENDING = 'pending';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

    const MAX_ATTEMPTS  = 3;
    const BATCH_SIZE    = 10;
    const TIMEOUT_SECS  = 3;

    const CONFIG_URL    = 'NERIA_WEBHOOK_URL';
    const CONFIG_SECRET = 'NERIA_WEBHOOK_SECRET';
    const CONFIG_EVENTS = 'NERIA_WEBHOOK_EVENTS';

    const ALL_EVENTS = [
        'email_sent',
        'email_opened',
        'conversion',
        'unsubscribed',
        'ab_winner',
    ];

    private Neria $module;
    private \Db $db;
    private int $idShop;
    private ?WatchdogManager $watchdog = null;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    private function watchdog(): WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    /**
     * Protection SSRF : rejette toute URL de webhook dont l'hôte résout vers
     * une adresse privée, boucle locale, lien-local ou de métadonnées cloud.
     * Sans ce contrôle, un marchand (ou un compte BO compromis) pourrait
     * configurer une URL interne (127.0.0.1, réseau privé, 169.254.169.254…)
     * et faire exécuter par le serveur des requêtes HTTP vers des services
     * internes normalement inaccessibles depuis l'extérieur.
     *
     * Appelé à la fois à l'enregistrement ET juste avant chaque envoi
     * (defense in depth contre le DNS rebinding, où le nom de domaine
     * résoudrait différemment entre la sauvegarde et la livraison réelle).
     */
    public static function isPublicUrl(string $url): bool
    {
        return self::resolvePublicIp($url) !== null;
    }

    /**
     * Valide l'URL comme dans isPublicUrl() et retourne en plus la première
     * IPv4 publique résolue pour l'hôte, ou null si l'URL est invalide/privée.
     *
     * Cette IP doit être pinnée dans l'appel cURL réel (CURLOPT_RESOLVE) :
     * revalider puis laisser cURL refaire sa propre résolution DNS laisse
     * une fenêtre de DNS rebinding (le domaine peut répondre une IP
     * publique à cette résolution-ci puis une IP privée quelques
     * millisecondes plus tard à la résolution de cURL). Pinner l'IP déjà
     * validée ferme complètement cette fenêtre.
     */
    public static function resolvePublicIp(string $url): ?string
    {
        if (!\Validate::isAbsoluteUrl($url)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return null;
        }

        // Résout tous les enregistrements A/AAAA de l'hôte — un domaine peut
        // pointer vers plusieurs IP, il suffit qu'une seule soit privée pour
        // rejeter l'URL entière.
        $ips = [];
        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        }

        if (empty($ips)) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                return null;
            }
        }

        return $ips[0];
    }

    // ============================================================
    // ENQUEUE
    // ============================================================

    /**
     * Insère un événement dans la queue.
     * Silencieux si aucune URL configurée ou si l'événement est filtré.
     */
    public function trigger(string $event, array $data): void
    {
        // Round 116 : $this->idShop transmis explicitement — même piège que
        // rounds 111-115. Sans idShop, Configuration::get() dépend de
        // Shop::$context_id_shop, jamais mise à jour par la réaffectation
        // Context::getContext()->shop faite dans la boucle multi-boutique
        // appelante (neria.php), contrairement à $this->idShop (propriété
        // d'objet, correctement scopée — voir les requêtes SQL ci-dessous).
        $url = (string) \Configuration::get(self::CONFIG_URL, null, null, $this->idShop);
        if ($url === '') {
            return;
        }

        // Filtre par événements activés ([] = tous activés)
        $enabledJson = (string) \Configuration::get(self::CONFIG_EVENTS, null, null, $this->idShop);
        $enabled = ($enabledJson !== '') ? json_decode($enabledJson, true) : [];
        // is_array() est indispensable, pas seulement "?? []" : un JSON valide
        // mais corrompu (ex: une simple chaîne, suite à une écriture partielle
        // de la config) décode sans erreur en un scalaire non-null —
        // in_array() sur ce scalaire lève un TypeError fatal en PHP 8, ce qui
        // bloquerait TOUS les webhooks (l'appelant ne catch pas cette erreur).
        if (!is_array($enabled)) {
            $enabled = [];
        }
        if (!empty($enabled) && !in_array($event, $enabled, true)) {
            return;
        }

        // Round 305 : les clés système (event/shop_id/timestamp) passent
        // désormais en SECOND argument d'array_merge() — en cas de clé
        // identique, array_merge() fait primer le DERNIER tableau fourni.
        // Aucun appelant actuel ne passe ces clés dans $data, donc ceci
        // était latent, mais un futur appelant fournissant par mégarde une
        // clé 'event'/'shop_id'/'timestamp' dans $data écraserait
        // silencieusement l'événement/la boutique/l'horodatage réels du
        // payload envoyé au webhook externe.
        $payload = json_encode(
            array_merge($data, [
                'event'     => $event,
                'shop_id'   => $this->idShop,
                'timestamp' => date('c'),
            ]),
            JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            return;
        }

        $table = _DB_PREFIX_ . self::TABLE;
        // Round 148 : résultat de l'INSERT capturé et journalisé en cas
        // d'échec — auparavant totalement ignoré (ni succès ni échec loggé
        // ici), contrairement à processQueue() plus bas dans ce même
        // fichier qui gère méticuleusement ses propres échecs (secret
        // illisible, verrou MySQL...). Un échec SQL réel sur CET INSERT
        // (verrou, table verrouillée) faisait qu'un événement webhook
        // n'était jamais mis en file, sans aucune trace exploitable.
        $ok = $this->db->execute(sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `event`, `payload`, `status`, `attempts`, `date_add`)
             VALUES (%d, '%s', '%s', 'pending', 0, '%s')",
            $table,
            $this->idShop,
            pSQL($event),
            pSQL($payload),
            date('Y-m-d H:i:s')
        ));

        if ($ok === false) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.webhook_trigger_insert_failed', ['event' => $event]),
                '', 'WebhookManager'
            );
            return;
        }

        // Numéro de séquence : PAS réinjecté ici par un second UPDATE après
        // l'INSERT (id_webhook n'est connu qu'une fois la ligne insérée) —
        // un process tué entre les deux requêtes laissait auparavant la
        // ligne définitivement sans `sequence` dans le payload. Le marqueur
        // d'ordre est désormais ajouté à la volée dans processQueue(), juste
        // avant l'envoi, à partir de la colonne `id_webhook` déjà connue :
        // une seule écriture est nécessaire et aucune fenêtre de mort du
        // process ne peut plus produire un payload sans séquence.
    }

    // ============================================================
    // PROCESS QUEUE (cron / displayHeader)
    // ============================================================

    public function processQueue(): void
    {
        // Round 144 : cleanup() déplacée ICI, avant les return précoces
        // (URL invalide / secret illisible) ci-dessous — auparavant, la
        // purge des lignes terminales anciennes ne tournait que dans le
        // finally du bloc try qui suit ces checks. Si la clé de chiffrement
        // maîtresse devient illisible durablement (rotation ratée, fichier
        // de clé corrompu), processQueue() retourne systématiquement AVANT
        // ce finally : cleanup() ne tournait alors plus jamais pour cette
        // boutique, et ps_neria_webhook_queue croissait sans borne (aucune
        // ligne ne pouvant plus non plus être traitée). Probabiliste (1 sur
        // 10), même logique que QueueManager::purgeOldEntries().
        if (random_int(1, 10) === 1) {
            $this->cleanup();
        }

        // Round 116 : $this->idShop transmis explicitement (même piège que
        // trigger() ci-dessus).
        $url    = (string) \Configuration::get(self::CONFIG_URL, null, null, $this->idShop);
        $secret = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_SECRET, null, null, $this->idShop));

        // Revalidation à l'envoi (pas seulement à la sauvegarde) : protège
        // contre le DNS rebinding, où le domaine configuré résoudrait vers
        // une IP publique au moment de l'enregistrement puis vers une IP
        // interne au moment de la livraison réelle.
        if ($url === '' || !self::isPublicUrl($url)) {
            return;
        }

        // save_webhooks (neria.php) génère TOUJOURS un secret dès qu'une URL
        // est configurée — donc si une URL existe mais que $secret est vide
        // ici, ce n'est jamais un état "volontairement non signé" : c'est
        // que la clé de chiffrement maîtresse est devenue illisible
        // (CryptoManager::decrypt() a échoué). Auparavant, ce cas dégradait
        // silencieusement vers un envoi SANS en-tête de signature au lieu
        // d'annuler l'envoi — un récepteur qui vérifie la signature HMAC
        // n'avait aucun moyen de distinguer "vérification désactivée côté
        // Neria" de "secret illisible côté Neria". On bloque désormais
        // l'envoi et on alerte, comme le fait déjà BounceManager pour un
        // mot de passe IMAP illisible.
        if ($secret === '') {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.webhook_secret_unreadable'),
                '', 'WebhookManager'
            );
            return;
        }

        // Verrou MySQL dédié à CETTE méthode (et non au seul appelant cron) :
        // le bouton BO "Traiter la file maintenant" (neria.php, neria_action
        // process_webhook_queue_now) appelle processQueue() directement, sans
        // passer par le GET_LOCK('neria_webhook_process') de runBackgroundJobs().
        // Sans ce verrou interne, un admin cliquant ce bouton pendant qu'un
        // cron externe tourne au même moment peut faire lire aux deux process
        // le même lot de lignes 'pending' avant que l'un des deux n'ait eu le
        // temps d'incrémenter `attempts` — livrant chaque webhook deux fois.
        $lockName = 'neria_webhook_process_queue_' . $this->idShop;
        if ((int) $this->db->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 0)", false) !== 1) {
            return;
        }

        try {
            $table = _DB_PREFIX_ . self::TABLE;

            // Round 241 : récupère les lignes bloquées à 'sending' par un
            // crash du process entre la réservation (juste avant fire())
            // et l'écriture du statut final ('done'/'failed'/retour à
            // 'pending'). Même logique que QueueManager::processQueue() —
            // 10 minutes est largement supérieur au TIMEOUT_SECS de fire(),
            // et le GET_LOCK ci-dessus garantit qu'un seul process traite
            // cette file à la fois, donc une ligne encore 'sending' après ce
            // délai est nécessairement une reprise après crash.
            $this->db->execute(
                "UPDATE `{$table}`
                 SET `status` = 'pending'
                 WHERE `id_shop` = {$this->idShop}
                   AND `status` = 'sending'
                   AND `last_attempt` <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
            );

            // Backoff exponentiel (2^attempts minutes) via last_attempt : sans lui,
            // les 3 tentatives d'un webhook contre un endpoint en panne
            // transitoire pouvaient être consommées en quelques minutes au
            // prochain passage du cron, sans laisser le temps à l'incident de
            // se résorber.
            $rows = $this->db->executeS(sprintf(
                "SELECT * FROM `%s`
                 WHERE `id_shop` = %d
                   AND `status`  = 'pending'
                   AND `attempts` < %d
                   AND (`last_attempt` IS NULL OR `last_attempt` <= DATE_SUB(NOW(), INTERVAL POW(2, `attempts`) MINUTE))
                 ORDER BY `date_add` ASC
                 LIMIT %d",
                $table, $this->idShop, self::MAX_ATTEMPTS, self::BATCH_SIZE
            ));

            if (!is_array($rows) || empty($rows)) {
                return; // Queue vide — normal, aucun log nécessaire
            }

            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.webhook_batch_start', ['n' => count($rows)]),
                '', 'WebhookManager'
            );

            $now   = date('Y-m-d H:i:s');
            $sent  = 0;
            $definitivelyFailed = 0;

            foreach ($rows as $row) {
            $id = (int) $row['id_webhook'];
            try {
                $attempts = (int) $row['attempts'] + 1;

                // Séquence d'ordre injectée ici (à l'envoi) plutôt qu'à l'écriture :
                // id_webhook (auto-incrémenté, donc strictement croissant) sert de
                // marqueur d'ordre fiable sans dépendre d'une seconde requête après
                // l'INSERT initial. Voir le commentaire dans trigger().
                $decodedForSeq = json_decode($row['payload'], true);
                if (!is_array($decodedForSeq)) {
                    $decodedForSeq = [];
                }
                $decodedForSeq['sequence'] = $id;
                $payloadWithSeq = json_encode($decodedForSeq, JSON_UNESCAPED_UNICODE);
                $payload = ($payloadWithSeq !== false) ? $payloadWithSeq : $row['payload'];

                // Round 241 : réservation atomique (attempts + statut
                // 'sending' en une seule UPDATE conditionnée à
                // status='pending') — avant, cette UPDATE incrémentait
                // `attempts` sans changer le statut : un crash du process
                // entre l'appel fire() réussi et l'UPDATE status='done'
                // plus bas laissait la ligne 'pending' avec attempts <
                // MAX_ATTEMPTS, livrant le même webhook une seconde fois au
                // prochain passage. 'sending' retire la ligne du pool
                // 'pending' dès la réservation ; récupérée par le nettoyage
                // en tête de processQueue() après 10 minutes si le process
                // crashe avant l'écriture du statut final.
                $this->db->execute(
                    "UPDATE `{$table}`
                     SET `attempts` = {$attempts}, `last_attempt` = '{$now}', `status` = 'sending'
                     WHERE `id_webhook` = {$id}
                       AND `status` = 'pending'"
                );
                if ((int) $this->db->Affected_Rows() !== 1) {
                    // Déjà réservée/traitée entre la sélection du lot et cet
                    // appel (protection best-effort en plus du GET_LOCK
                    // englobant) — ne pas retraiter.
                    continue;
                }

                $ok = $this->fire($url, $secret, $payload);

                if ($ok) {
                    $this->db->execute(
                        "UPDATE `{$table}` SET `status` = 'done' WHERE `id_webhook` = {$id}"
                    );
                    $sent++;
                } elseif ($attempts >= self::MAX_ATTEMPTS) {
                    $this->db->execute(
                        "UPDATE `{$table}` SET `status` = 'failed' WHERE `id_webhook` = {$id}"
                    );
                    $definitivelyFailed++;
                    // Round 287 : error() au lieu de warning() — contrairement
                    // à un échec RETENTABLE (attempts < MAX_ATTEMPTS, encore
                    // en cours de recul exponentiel), ceci est un échec
                    // DÉFINITIF : la notification externe (webhook) est
                    // perdue pour de bon, aucune nouvelle tentative n'aura
                    // jamais lieu. warning() ne déclenche jamais
                    // sendImmediateAlert() (round 268/276, réservé à error()/
                    // critical()) — le marchand n'était informé de cette
                    // perte définitive qu'au digest quotidien (jusqu'à ~24h
                    // de délai), jamais immédiatement, alors que
                    // QueueManager::markFailedOrRetry() escalade déjà les
                    // pannes d'envoi équivalentes.
                    $this->watchdog()->error(
                        \WatchdogManager::i18nMsg('watchdog.webhook_definitively_failed', [
                            'event'   => $row['event'],
                            'max'     => self::MAX_ATTEMPTS,
                            'url'     => $url,
                            'timeout' => self::TIMEOUT_SECS,
                        ]),
                        '', 'WebhookManager'
                    );
                } else {
                    // Round 241 : échec retentable (attempts < MAX_ATTEMPTS)
                    // — remet la ligne à 'pending' pour qu'elle redevienne
                    // sélectionnable au prochain passage (le backoff
                    // exponentiel de la requête SELECT plus haut, basé sur
                    // last_attempt, s'applique déjà). Sans ce reset, la
                    // ligne resterait bloquée à 'sending' jusqu'au nettoyage
                    // de 10 minutes en tête de fonction — retard inutile
                    // pour un échec transitoire déjà comptabilisé.
                    $this->db->execute(
                        "UPDATE `{$table}` SET `status` = 'pending' WHERE `id_webhook` = {$id}"
                    );
                }
            } catch (\Throwable $e) {
                // Round 144 : try/catch PAR LIGNE — même schéma que
                // QueueManager::processSingle(). Sans lui, une exception sur
                // UNE ligne (perte de connexion DB transitoire, échec
                // d'écriture Watchdog dans fire()) remontait hors du
                // foreach, hors du try englobant, et TOUTES les lignes
                // suivantes du lot restaient silencieusement non traitées
                // pour ce passage — contrairement à QueueManager, qui
                // continue sur les lignes restantes du lot malgré l'échec
                // d'une ligne isolée. La ligne reste 'pending' (attempts
                // déjà incrémenté ci-dessus si l'UPDATE a réussi avant
                // l'exception) et sera reprise au prochain passage du cron.
                $this->watchdog()->error(
                    \WatchdogManager::i18nMsg('watchdog.webhook_row_exception', [
                        'id'    => $id,
                        'error' => $e->getMessage(),
                    ]),
                    '', 'WebhookManager'
                );

                // Round 163 : si l'exception survient précisément à la 3e
                // tentative (attempts déjà incrémenté à MAX_ATTEMPTS avant
                // l'UPDATE ci-dessus, typiquement une exception dans fire()
                // au moment d'écrire l'alerte Watchdog elle-même — cas
                // explicitement anticipé par le commentaire ci-dessus), la
                // ligne restait 'pending'/attempts=MAX_ATTEMPTS à vie : le
                // batch de sélection filtre `attempts < MAX_ATTEMPTS`
                // (invisible désormais) ET cleanup() ne purge que
                // status IN ('done','failed') (jamais nettoyée non plus) —
                // fuite permanente en base et événement webhook perdu sans
                // jamais déclencher l'alerte de fin de tentatives.
                // isset($attempts) : l'exception a pu survenir AVANT la
                // ligne 334 (payload/json_decode), auquel cas on retombe
                // sur la valeur brute déjà en base.
                $attemptsAfterException = isset($attempts) ? $attempts : ((int) $row['attempts'] + 1);
                if ($attemptsAfterException >= self::MAX_ATTEMPTS) {
                    $this->db->execute(
                        "UPDATE `{$table}` SET `status` = 'failed', `attempts` = {$attemptsAfterException} WHERE `id_webhook` = {$id}"
                    );
                    $definitivelyFailed++;
                } else {
                    // Round 241 : l'exception a pu survenir APRÈS la
                    // réservation atomique (status déjà passé à 'sending'
                    // ci-dessus) — sans ce reset explicite, la ligne resterait
                    // bloquée à 'sending' (invisible du SELECT `status =
                    // 'pending'`) jusqu'au nettoyage de 10 minutes en tête de
                    // fonction, au lieu d'être retentée dès le prochain
                    // passage respectant le backoff.
                    $this->db->execute(
                        "UPDATE `{$table}` SET `status` = 'pending' WHERE `id_webhook` = {$id}"
                    );
                }
            }
        }

        // Bilan du batch
        $total = count($rows);
        if ($sent > 0 && $definitivelyFailed === 0) {
            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.webhook_batch_success', ['sent' => $sent, 'total' => $total]),
                '', 'WebhookManager'
            );
        } elseif ($sent > 0 && $definitivelyFailed > 0) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.webhook_batch_partial', ['sent' => $sent, 'failed' => $definitivelyFailed]),
                '', 'WebhookManager'
            );
        } elseif ($sent === 0 && $definitivelyFailed === 0 && $total > 0) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.webhook_batch_all_failed', ['total' => $total]),
                '', 'WebhookManager'
            );
        }
        } finally {
            // cleanup() appelée en tout début de méthode désormais (round
            // 144) — voir son commentaire plus haut.
            $this->db->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        }
    }

    // ============================================================
    // HTTP FIRE
    // ============================================================

    /**
     * Construit l'entrée CURLOPT_RESOLVE ("host:port:ip") qui force cURL à
     * se connecter à l'IP déjà validée par isPublicUrl()/resolvePublicIp(),
     * sans laisser cURL refaire sa propre résolution DNS (fenêtre de DNS
     * rebinding). Retourne null si l'URL n'est plus valide/publique.
     */
    private function buildResolveOption(string $url): ?string
    {
        $ip = self::resolvePublicIp($url);
        if ($ip === null) {
            return null;
        }

        $host   = (string) parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port   = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        return $host . ':' . $port . ':' . $ip;
    }

    private function fire(string $url, string $secret, string $payload): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        // Pinne l'IP déjà validée par isPublicUrl() (appelée juste avant, dans
        // processQueue()) : sans ça, cURL refait sa propre résolution DNS au
        // moment de curl_exec(), ce qui laisse une fenêtre de DNS rebinding
        // entre la validation et l'envoi réel malgré la revalidation.
        $resolveOpt = $this->buildResolveOption($url);
        if ($resolveOpt === null) {
            return false;
        }

        $decoded = json_decode($payload, true);
        $event   = is_array($decoded) ? ($decoded['event'] ?? '') : '';

        $headers = [
            'Content-Type: application/json',
            'X-Neria-Event: ' . $event,
        ];

        if ($secret !== '') {
            $headers[] = 'X-Neria-Signature: sha256=' . hash_hmac('sha256', $payload, $secret);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // isPublicUrl() ne résout et ne valide que les enregistrements
            // IPv4 (gethostbynamel) — sans forcer cURL à se connecter en
            // IPv4 lui aussi, un domaine avec un A public (validé) ET un
            // AAAA privé (::1, fc00::/7, fe80::/10...) pourrait contourner
            // la protection SSRF si le serveur préfère IPv6 (bypass
            // "dual-stack" classique).
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE        => [$resolveOpt],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            $timeout = self::TIMEOUT_SECS;
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.webhook_curl_error', ['event' => $event, 'error' => $error, 'timeout' => $timeout]),
                '', 'WebhookManager'
            );
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            // Round 267 : mb_substr (pas substr) — même correctif que
            // sendTest() (round 243), jamais porté ici alors que fire()
            // est le VRAI chemin emprunté à chaque déclenchement de
            // webhook en production (sendTest() n'est que le bouton BO
            // « Tester »). $response est la réponse HTTP brute d'un
            // endpoint tiers configuré par le marchand, dont le contenu
            // (message d'erreur localisé) peut être multi-octets — une
            // coupe en octets bruts risque de trancher au milieu d'un
            // caractère, produisant une séquence UTF-8 invalide que
            // htmlspecialchars() (sans ENT_SUBSTITUTE) rejette ensuite
            // silencieusement en chaîne vide dans le digest quotidien.
            $preview = mb_substr((string) $response, 0, 150);
            $this->watchdog()->warning(
                $preview !== ''
                    ? \WatchdogManager::i18nMsg('watchdog.webhook_http_error_response', ['event' => $event, 'code' => $httpCode, 'url' => $url, 'response' => $preview])
                    : \WatchdogManager::i18nMsg('watchdog.webhook_http_error', ['event' => $event, 'code' => $httpCode, 'url' => $url]),
                '', 'WebhookManager'
            );
            return false;
        }

        return true;
    }

    // ============================================================
    // TEST (bouton "Tester" dans le back-office)
    // ============================================================

    public function sendTest(): array
    {
        // Round 116 : $this->idShop transmis explicitement (même piège que
        // trigger()/processQueue() ci-dessus).
        $url    = (string) \Configuration::get(self::CONFIG_URL, null, null, $this->idShop);
        $secret = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_SECRET, null, null, $this->idShop));

        if ($url === '' || !self::isPublicUrl($url)) {
            return ['ok' => false, 'error' => AdminTranslator::t('msg.webhook_url_invalid')];
        }

        // Même garde-fou que processQueue() : une URL configurée implique
        // toujours un secret généré (save_webhooks) — un secret vide ici
        // signifie que la clé de chiffrement maîtresse est illisible, pas
        // qu'aucun secret n'a été défini. On ne teste jamais un envoi non
        // signé silencieusement.
        if ($secret === '') {
            return ['ok' => false, 'error' => AdminTranslator::t('msg.webhook_secret_unreadable')];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => AdminTranslator::t('msg.curl_unavailable')];
        }

        // Même pin d'IP que fire() : ferme la fenêtre de DNS rebinding entre
        // la validation ci-dessus et la connexion cURL réelle.
        $resolveOpt = $this->buildResolveOption($url);
        if ($resolveOpt === null) {
            return ['ok' => false, 'error' => AdminTranslator::t('msg.webhook_url_invalid')];
        }

        $payload = json_encode([
            'event'     => 'test',
            'shop_id'   => $this->idShop,
            'message'   => AdminTranslator::t('msg.webhook_test_connection'),
            'timestamp' => date('c'),
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json',
            'X-Neria-Event: test',
        ];

        if ($secret !== '') {
            $headers[] = 'X-Neria-Signature: sha256=' . hash_hmac('sha256', $payload, $secret);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // isPublicUrl() ne résout et ne valide que les enregistrements
            // IPv4 (gethostbynamel) — sans forcer cURL à se connecter en
            // IPv4 lui aussi, un domaine avec un A public (validé) ET un
            // AAAA privé (::1, fc00::/7, fe80::/10...) pourrait contourner
            // la protection SSRF si le serveur préfère IPv6 (bypass
            // "dual-stack" classique).
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE        => [$resolveOpt],
        ]);

        $body     = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.webhook_test_curl_error', ['error' => $error, 'url' => $url]),
                '', 'WebhookManager'
            );
            return ['ok' => false, 'error' => $error, 'http_code' => 0];
        }

        $ok = $httpCode >= 200 && $httpCode < 300;

        if ($ok) {
            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.webhook_test_success', ['code' => $httpCode, 'url' => $url]),
                '', 'WebhookManager'
            );
        } else {
            // Round 243 : mb_substr (pas substr) -- $body est la réponse
            // HTTP brute d'un endpoint tiers configuré par le marchand, dont
            // le contenu (message d'erreur localisé) peut être multi-octets
            // dans n'importe laquelle des 19 langues supportées ; une coupe
            // en octets bruts risque de trancher au milieu d'un caractère.
            $preview = mb_substr($body, 0, 150);
            $this->watchdog()->warning(
                $preview !== ''
                    ? \WatchdogManager::i18nMsg('watchdog.webhook_test_http_error_response', ['code' => $httpCode, 'url' => $url, 'response' => $preview])
                    : \WatchdogManager::i18nMsg('watchdog.webhook_test_http_error', ['code' => $httpCode, 'url' => $url]),
                '', 'WebhookManager'
            );
        }

        return [
            'ok'        => $ok,
            'http_code' => $httpCode,
            'body'      => mb_substr($body, 0, 300),
        ];
    }

    // ============================================================
    // LECTURE QUEUE (onglet BO)
    // ============================================================

    public function getRecentDeliveries(int $limit = 10): array
    {
        $rows = $this->db->executeS(sprintf(
            "SELECT `id_webhook`, `event`, `status`, `attempts`, `last_attempt`, `date_add`
             FROM `%s`
             WHERE `id_shop` = %d
             ORDER BY `date_add` DESC
             LIMIT %d",
            _DB_PREFIX_ . self::TABLE,
            $this->idShop,
            $limit
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Remet un webhook définitivement échoué en file d'attente
     * (réinitialise ses tentatives) pour une relance manuelle immédiate.
     */
    public function retryOne(int $idWebhook): bool
    {
        $table = _DB_PREFIX_ . self::TABLE;
        // status = 'failed' obligatoire : sans ce filtre, n'importe quel
        // id_webhook de la boutique (même 'done', déjà livré avec succès)
        // pouvait être remis en file par un clic admin sur un mauvais ID —
        // renvoyant deux fois le même événement à un système tiers.
        // Round 213 : $use_cache=false, même famille de bug que les rounds
        // 210-212 (cache SQL PrestaShop pouvant resservir un résultat
        // périmé). Le WHERE de l'UPDATE ci-dessous revérifie désormais
        // aussi status='failed' lui-même, en défense en profondeur —
        // il ne se repose plus uniquement sur ce SELECT.
        $row = $this->db->getRow(sprintf(
            "SELECT id_webhook FROM `%s` WHERE id_webhook = %d AND id_shop = %d AND status = 'failed'",
            $table, $idWebhook, $this->idShop
        ), false);
        if (!$row) {
            return false;
        }

        // last_attempt remis à NULL en plus de attempts=0 : processQueue()
        // ne sélectionne que WHERE last_attempt IS NULL OR last_attempt <=
        // DATE_SUB(NOW(), INTERVAL POW(2, attempts) MINUTE) — sans ce reset,
        // un clic admin "Relancer" moins d'une minute après le dernier échec
        // enregistré (POW(2,0) = 1 minute) laissait le webhook invisible au
        // prochain passage du cron malgré le message de succès affiché.
        $this->db->execute(sprintf(
            "UPDATE `%s` SET `status` = 'pending', `attempts` = 0, `last_attempt` = NULL WHERE `id_webhook` = %d AND id_shop = %d AND status = 'failed'",
            $table, $idWebhook, $this->idShop
        ));

        $this->watchdog()->info(
            \WatchdogManager::i18nMsg('watchdog.webhook_requeued', ['id' => $idWebhook]),
            '', 'WebhookManager'
        );

        return true;
    }

    // ============================================================
    // MAINTENANCE
    // ============================================================

    public function cleanup(int $days = 30): void
    {
        $dateLimit = date('Y-m-d', strtotime("-{$days} days"));
        $this->db->execute(sprintf(
            "DELETE FROM `%s`
             WHERE `id_shop` = %d
               AND `status`  IN ('done', 'failed')
               AND `date_add` < '%s'",
            _DB_PREFIX_ . self::TABLE,
            $this->idShop,
            pSQL($dateLimit)
        ));

        $deleted = (int) $this->db->Affected_Rows();
        if ($deleted > 0) {
            $this->watchdog()->info(
                \WatchdogManager::i18nMsg('watchdog.webhook_cleanup', ['n' => $deleted, 'days' => $days]),
                '', 'WebhookManager'
            );
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(20));
    }
}
