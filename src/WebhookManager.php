<?php
/**
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
        if (!\Validate::isAbsoluteUrl($url)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
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
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                return false;
            }
        }

        return true;
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
        $url = (string) \Configuration::get(self::CONFIG_URL);
        if ($url === '') {
            return;
        }

        // Filtre par événements activés ([] = tous activés)
        $enabledJson = (string) \Configuration::get(self::CONFIG_EVENTS);
        $enabled = ($enabledJson !== '') ? (json_decode($enabledJson, true) ?? []) : [];
        if (!empty($enabled) && !in_array($event, $enabled, true)) {
            return;
        }

        $payload = json_encode(
            array_merge([
                'event'     => $event,
                'shop_id'   => $this->idShop,
                'timestamp' => date('c'),
            ], $data),
            JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            return;
        }

        $this->db->execute(sprintf(
            "INSERT INTO `%s`
                (`id_shop`, `event`, `payload`, `status`, `attempts`, `date_add`)
             VALUES (%d, '%s', '%s', 'pending', 0, '%s')",
            _DB_PREFIX_ . self::TABLE,
            $this->idShop,
            pSQL($event),
            pSQL($payload),
            date('Y-m-d H:i:s')
        ));
    }

    // ============================================================
    // PROCESS QUEUE (cron / displayHeader)
    // ============================================================

    public function processQueue(): void
    {
        $url    = (string) \Configuration::get(self::CONFIG_URL);
        $secret = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_SECRET));

        // Revalidation à l'envoi (pas seulement à la sauvegarde) : protège
        // contre le DNS rebinding, où le domaine configuré résoudrait vers
        // une IP publique au moment de l'enregistrement puis vers une IP
        // interne au moment de la livraison réelle.
        if ($url === '' || !self::isPublicUrl($url)) {
            return;
        }

        $table = _DB_PREFIX_ . self::TABLE;

        $rows = $this->db->executeS(sprintf(
            "SELECT * FROM `%s`
             WHERE `id_shop` = %d
               AND `status`  = 'pending'
               AND `attempts` < %d
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
            $id       = (int) $row['id_webhook'];
            $payload  = $row['payload'];
            $attempts = (int) $row['attempts'] + 1;

            // Incrémenter les tentatives avant l'envoi (évite les doublons parallèles)
            $this->db->execute(
                "UPDATE `{$table}`
                 SET `attempts` = {$attempts}, `last_attempt` = '{$now}'
                 WHERE `id_webhook` = {$id}"
            );

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
                $this->watchdog()->warning(
                    \WatchdogManager::i18nMsg('watchdog.webhook_definitively_failed', [
                        'event'   => $row['event'],
                        'max'     => self::MAX_ATTEMPTS,
                        'url'     => $url,
                        'timeout' => self::TIMEOUT_SECS,
                    ]),
                    '', 'WebhookManager'
                );
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
    }

    // ============================================================
    // HTTP FIRE
    // ============================================================

    private function fire(string $url, string $secret, string $payload): bool
    {
        if (!function_exists('curl_init')) {
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
            $preview = substr((string) $response, 0, 150);
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
        $url    = (string) \Configuration::get(self::CONFIG_URL);
        $secret = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_SECRET));

        if ($url === '' || !self::isPublicUrl($url)) {
            return ['ok' => false, 'error' => AdminTranslator::t('msg.webhook_url_invalid')];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => AdminTranslator::t('msg.curl_unavailable')];
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
            $preview = substr($body, 0, 150);
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
            'body'      => substr($body, 0, 300),
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
        $row = $this->db->getRow(sprintf(
            "SELECT id_webhook FROM `%s` WHERE id_webhook = %d AND id_shop = %d",
            $table, $idWebhook, $this->idShop
        ));
        if (!$row) {
            return false;
        }

        $this->db->execute(sprintf(
            "UPDATE `%s` SET `status` = 'pending', `attempts` = 0 WHERE `id_webhook` = %d",
            $table, $idWebhook
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
