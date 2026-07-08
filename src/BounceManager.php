<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — BounceManager
 *
 * Détecte les adresses email invalides (bounces) via deux canaux :
 *   1. Boîte IMAP/POP3 Return-Path (cron quotidien)
 *   2. Webhook entrant d'un ESP (Mailgun, SendGrid, Postmark, générique)
 *
 * Les adresses en hard bounce sont automatiquement exclues de tout envoi.
 * Les soft bounces sont exclus après N échecs consécutifs (seuil configurable).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BounceManager
{
    // ── Clés de configuration ──────────────────────────────────────────────
    const CFG_IMAP_HOST       = 'NERIA_BOUNCE_IMAP_HOST';
    const CFG_IMAP_PORT       = 'NERIA_BOUNCE_IMAP_PORT';
    const CFG_IMAP_USER       = 'NERIA_BOUNCE_IMAP_USER';
    const CFG_IMAP_PASS       = 'NERIA_BOUNCE_IMAP_PASS';
    const CFG_IMAP_SSL        = 'NERIA_BOUNCE_IMAP_SSL';
    const CFG_IMAP_FOLDER     = 'NERIA_BOUNCE_IMAP_FOLDER';
    const CFG_SOFT_THRESHOLD  = 'NERIA_BOUNCE_SOFT_THRESHOLD';
    const CFG_WEBHOOK_SECRET  = 'NERIA_BOUNCE_WEBHOOK_SECRET';
    const CFG_ENABLED         = 'NERIA_BOUNCE_ENABLED';

    const TABLE = 'neria_bounces';

    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. VÉRIFICATION AVANT ENVOI
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Retourne true si l'adresse doit être bloquée (hard bounce actif,
     * ou soft bounce ayant dépassé le seuil).
     */
    public static function isBounced(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $row = \Db::getInstance()->getRow(
            'SELECT `type`, `bounce_count`, `status` FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `email` = \'' . pSQL($email) . '\''
        );

        if (!$row || $row['status'] !== 'active') {
            return false;
        }

        if ($row['type'] === 'hard') {
            return true;
        }

        // Soft bounce : bloquer uniquement si seuil dépassé
        $threshold = (int) \Configuration::get(self::CFG_SOFT_THRESHOLD) ?: 3;
        return (int) $row['bounce_count'] >= $threshold;
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. LECTURE DE LA BOÎTE IMAP
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Se connecte à la boîte IMAP configurée, parse les messages de rejet
     * (DSN / NDR) et enregistre les bounces détectés.
     * À appeler depuis un cron quotidien.
     *
     * @return array{processed: int, bounces: int, errors: string[]}
     */
    public function checkBounceMailbox(): array
    {
        \Configuration::updateValue(\HealthCheckManager::CRON_LAST_BOUNCES, date('Y-m-d H:i:s'));

        if (!\Configuration::get(self::CFG_ENABLED)) {
            return ['processed' => 0, 'bounces' => 0, 'errors' => [\AdminTranslator::t('msg.bounce_checker_disabled')]];
        }

        if (!extension_loaded('imap')) {
            return ['processed' => 0, 'bounces' => 0, 'errors' => [\AdminTranslator::t('msg.bounce_imap_extension_missing')]];
        }

        $host      = (string) \Configuration::get(self::CFG_IMAP_HOST);
        $port      = (int)   \Configuration::get(self::CFG_IMAP_PORT)   ?: 993;
        $user      = (string) \Configuration::get(self::CFG_IMAP_USER);
        $pass      = \CryptoManager::decrypt((string) \Configuration::get(self::CFG_IMAP_PASS));
        $ssl       = (bool)   \Configuration::get(self::CFG_IMAP_SSL);
        $folder    = (string) \Configuration::get(self::CFG_IMAP_FOLDER) ?: 'INBOX';

        if ($host === '' || $user === '' || $pass === '') {
            return ['processed' => 0, 'bounces' => 0, 'errors' => [\AdminTranslator::t('msg.bounce_imap_incomplete_config')]];
        }

        $flags   = $ssl ? '/imap/ssl' : '/imap/notls';
        $mailbox = '{' . $host . ':' . $port . $flags . '}' . $folder;

        $errors  = [];
        $mbox    = @imap_open($mailbox, $user, $pass, 0, 1);
        if ($mbox === false) {
            return ['processed' => 0, 'bounces' => 0, 'errors' => [\AdminTranslator::tVars('msg.bounce_imap_connection_failed', ['error' => imap_last_error()])]];
        }

        $uids      = imap_search($mbox, 'UNSEEN') ?: [];
        $processed = 0;
        $bounces   = 0;

        foreach ($uids as $uid) {
            try {
                $header = imap_headerinfo($mbox, $uid);
                $body   = imap_fetchbody($mbox, $uid, '');

                $subject = isset($header->subject) ? imap_utf8($header->subject) : '';
                $from    = isset($header->from[0]) ? ($header->from[0]->mailbox . '@' . $header->from[0]->host) : '';

                if (!$this->looksLikeBounce($subject, $from)) {
                    continue;
                }

                $parsed = $this->parseDsnBody($body);
                if (!$parsed) {
                    $parsed = $this->parseBodyFallback($body, $subject);
                }

                if ($parsed && isset($parsed['email']) && $parsed['email'] !== '') {
                    $this->recordBounce(
                        $parsed['email'],
                        $parsed['type'] ?? 'hard',
                        $parsed['reason'] ?? $subject,
                        'imap'
                    );
                    $bounces++;
                }

                imap_setflag_full($mbox, (string) $uid, '\\Seen');
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = 'UID ' . $uid . ': ' . $e->getMessage();
            }
        }

        imap_close($mbox);

        if (class_exists('WatchdogManager')) {
            (new \WatchdogManager($this->module))->info(
                \WatchdogManager::i18nMsg('watchdog.bounce_imap_summary', ['processed' => $processed, 'bounces' => $bounces]),
                'bounce',
                'BounceManager'
            );
        }

        return compact('processed', 'bounces', 'errors');
    }

    /**
     * Vérifie si le message semble être une notification de bounce.
     */
    private function looksLikeBounce(string $subject, string $from): bool
    {
        $subjectLower = mb_strtolower($subject);
        $fromLower    = mb_strtolower($from);

        $subjectKeywords = [
            'delivery', 'undeliverable', 'delivery status notification',
            'delivery failure', 'delivery failed', 'returned mail',
            'mail delivery failed', 'bounce', 'non-delivery', 'ndnr',
            'échec', 'non remis', 'refusé', 'rejet', 'nicht zustellbar',
        ];
        foreach ($subjectKeywords as $kw) {
            if (str_contains($subjectLower, $kw)) {
                return true;
            }
        }

        $fromKeywords = ['mailer-daemon', 'postmaster', 'mail-daemon', 'noreply@bounce'];
        foreach ($fromKeywords as $kw) {
            if (str_contains($fromLower, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse une notification de livraison DSN (RFC 3464).
     * Recherche le bloc MIME de type message/delivery-status.
     *
     * @return array{email: string, type: string, reason: string}|null
     */
    private function parseDsnBody(string $body): ?array
    {
        // Cherche la section delivery-status dans le corps multipart
        if (!preg_match('/Content-Type:\s*message\/delivery-status.*?(?=Content-Type:|$)/si', $body, $m)) {
            return null;
        }
        $dsnBlock = $m[0];

        $email  = '';
        $type   = 'hard';
        $reason = '';

        // Final-Recipient: rfc822; user@example.com
        if (preg_match('/Final-Recipient:\s*(?:rfc822;\s*)?([^\s\r\n]+)/i', $dsnBlock, $m)) {
            $email = mb_strtolower(trim($m[1], '<> '));
        }

        // Status: 5.x.x (hard) ou 4.x.x (soft)
        if (preg_match('/Status:\s*([45]\.\d+\.\d+)/i', $dsnBlock, $m)) {
            $type   = str_starts_with($m[1], '4.') ? 'soft' : 'hard';
            $reason = 'Status DSN : ' . $m[1];
        }

        // Action: failed | delayed | delivered
        if (preg_match('/Action:\s*(\w+)/i', $dsnBlock, $m)) {
            $action = mb_strtolower(trim($m[1]));
            if ($action === 'delayed') {
                $type = 'soft';
            } elseif ($action === 'failed') {
                // garder le type déjà déterminé par Status
            }
        }

        // Diagnostic-Code pour la raison lisible
        if (preg_match('/Diagnostic-Code:\s*(?:smtp;\s*)?(.*?)(?=\r?\n[A-Za-z]|$)/si', $dsnBlock, $m)) {
            $reason = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        return $email !== '' ? compact('email', 'type', 'reason') : null;
    }

    /**
     * Fallback si pas de DSN structuré : cherche une adresse email dans le corps.
     */
    private function parseBodyFallback(string $body, string $subject): ?array
    {
        // Patterns typiques dans les corps de bounce non-DSN
        $patterns = [
            '/(?:failed|invalid|rejected|unknown|no such)\s+(?:user|address|recipient).*?[\s<]([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})[\s>]/i',
            '/The\s+(?:address|email)\s+["\']?([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})["\']?\s+(?:does not exist|was not found|is invalid)/i',
            '/550[- ][^\r\n]*?([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $m)) {
                return ['email' => mb_strtolower($m[1]), 'type' => 'hard', 'reason' => $subject];
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. WEBHOOK ENTRANT (ESP externe)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Traite un payload de webhook de bounce envoyé par un ESP.
     * Supporte Mailgun, SendGrid, Postmark et un format générique.
     *
     * @param array  $payload Données JSON décodées du POST
     * @param string $source  'mailgun' | 'sendgrid' | 'postmark' | 'generic'
     * @return bool  true si un bounce a été enregistré
     */
    public function processBounceWebhook(array $payload, string $source = 'generic'): bool
    {
        $result = match ($source) {
            'mailgun'   => $this->parseMailgun($payload),
            'sendgrid'  => $this->parseSendgrid($payload),
            'postmark'  => $this->parsePostmark($payload),
            default     => $this->parseGenericWebhook($payload),
        };

        if ($result === null || $result['email'] === '') {
            return false;
        }

        $this->recordBounce($result['email'], $result['type'], $result['reason'], 'webhook');
        return true;
    }

    private function parseMailgun(array $p): ?array
    {
        // Mailgun structure : {"event-data": {"event": "failed", "recipient": "..."}}
        $data  = $p['event-data'] ?? $p;
        $event = mb_strtolower($data['event'] ?? '');
        if (!in_array($event, ['failed', 'bounced', 'dropped'], true)) {
            return null;
        }
        $email  = mb_strtolower($data['recipient'] ?? '');
        $sev    = $data['delivery-status']['code'] ?? $data['severity'] ?? '';
        $type   = (str_starts_with((string) $sev, '5') || $sev === 'permanent') ? 'hard' : 'soft';
        $reason = $data['delivery-status']['message'] ?? $data['delivery-status']['description'] ?? "Mailgun $event";
        return compact('email', 'type', 'reason');
    }

    private function parseSendgrid(array $p): ?array
    {
        // SendGrid envoie un tableau d'événements
        if (isset($p[0])) {
            $p = $p[0];
        }
        $event = mb_strtolower($p['event'] ?? '');
        if (!in_array($event, ['bounce', 'dropped', 'blocked'], true)) {
            return null;
        }
        $email  = mb_strtolower($p['email'] ?? '');
        $type   = ($p['type'] ?? '') === 'blocked' ? 'soft' : 'hard';
        $reason = $p['reason'] ?? $p['status'] ?? "SendGrid $event";
        return compact('email', 'type', 'reason');
    }

    private function parsePostmark(array $p): ?array
    {
        $type_raw = $p['Type'] ?? '';
        if (!str_contains(mb_strtolower($type_raw), 'bounce')) {
            return null;
        }
        $email  = mb_strtolower($p['Email'] ?? '');
        $type   = ($type_raw === 'SoftBounce') ? 'soft' : 'hard';
        $reason = $p['Description'] ?? $p['Details'] ?? "Postmark $type_raw";
        return compact('email', 'type', 'reason');
    }

    private function parseGenericWebhook(array $p): ?array
    {
        $event  = mb_strtolower($p['event'] ?? $p['type'] ?? '');
        if (!str_contains($event, 'bounce') && !str_contains($event, 'fail') && !str_contains($event, 'drop')) {
            return null;
        }
        $email  = mb_strtolower($p['email'] ?? $p['recipient'] ?? $p['address'] ?? '');
        $type   = str_contains($event, 'soft') ? 'soft' : 'hard';
        $reason = $p['reason'] ?? $p['message'] ?? $p['description'] ?? $event;
        return compact('email', 'type', 'reason');
    }

    /**
     * Vérifie la signature HMAC du webhook (header X-Neria-Signature).
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = \CryptoManager::decrypt((string) \Configuration::get(self::CFG_WEBHOOK_SECRET));
        if ($secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. ÉCRITURE EN BASE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Enregistre ou met à jour un bounce dans la table neria_bounces.
     */
    public function recordBounce(string $email, string $type, string $reason, string $source = 'imap'): void
    {
        $email  = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $type   = in_array($type, ['hard', 'soft'], true) ? $type : 'hard';
        $source = in_array($source, ['imap', 'webhook', 'manual'], true) ? $source : 'manual';
        $reason = mb_substr($reason, 0, 500);
        $now    = date('Y-m-d H:i:s');

        $db  = \Db::getInstance();
        $exists = $db->getValue(
            'SELECT `id` FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `email` = \'' . pSQL($email) . '\''
        );

        if ($exists) {
            // Mise à jour : incrémente compteur, remonte vers hard si besoin
            $db->execute(
                'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
                 SET `bounce_count` = `bounce_count` + 1,
                     `last_bounce_at` = \'' . pSQL($now) . '\',
                     `reason` = \'' . pSQL($reason) . '\',
                     `type` = IF(`type` = \'hard\', \'hard\', \'' . pSQL($type) . '\')
                 WHERE `email` = \'' . pSQL($email) . '\''
            );
        } else {
            $db->insert(self::TABLE, [
                'email'          => pSQL($email),
                'type'           => pSQL($type),
                'reason'         => pSQL($reason),
                'source'         => pSQL($source),
                'bounce_count'   => 1,
                'last_bounce_at' => pSQL($now),
                'status'         => 'active',
                'date_add'       => pSQL($now),
            ]);
        }

        if (class_exists('WatchdogManager')) {
            (new \WatchdogManager($this->module))->warning(
                \WatchdogManager::i18nMsg('watchdog.bounce_recorded', ['type' => $type, 'email' => $email, 'source' => $source, 'reason' => $reason]),
                'bounce',
                'BounceManager'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. GESTION BACK-OFFICE
    // ══════════════════════════════════════════════════════════════════════

    public function getBounceList(int $limit = 50, int $offset = 0, string $filter = ''): array
    {
        $where = '';
        if ($filter !== '') {
            $f     = pSQL($filter);
            $where = " AND (`email` LIKE '%$f%' OR `reason` LIKE '%$f%')";
        }
        return \Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE 1' . $where . '
             ORDER BY `last_bounce_at` DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        ) ?: [];
    }

    public function getBounceCount(string $filter = ''): int
    {
        $where = '';
        if ($filter !== '') {
            $f     = pSQL($filter);
            $where = " AND (`email` LIKE '%$f%' OR `reason` LIKE '%$f%')";
        }
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE 1' . $where
        );
    }

    public function getBounceStats(): array
    {
        $db = \Db::getInstance();
        return [
            'total'     => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`'),
            'hard'      => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `type` = \'hard\''),
            'soft'      => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `type` = \'soft\''),
            'active'    => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `status` = \'active\''),
            'ignored'   => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `status` = \'ignored\''),
            'imap'      => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `source` = \'imap\''),
            'webhook'   => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `source` = \'webhook\''),
            'manual'    => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `source` = \'manual\''),
        ];
    }

    public function ignoreBounce(string $email): bool
    {
        return (bool) \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
             SET `status` = \'ignored\'
             WHERE `email` = \'' . pSQL(mb_strtolower(trim($email))) . '\''
        );
    }

    public function reactivateBounce(string $email): bool
    {
        return (bool) \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
             SET `status` = \'active\'
             WHERE `email` = \'' . pSQL(mb_strtolower(trim($email))) . '\''
        );
    }

    public function deleteBounce(string $email): bool
    {
        return (bool) \Db::getInstance()->delete(
            self::TABLE,
            '`email` = \'' . pSQL(mb_strtolower(trim($email))) . '\''
        );
    }

    public function addManualBounce(string $email, string $type = 'hard'): void
    {
        $this->recordBounce($email, $type, 'Ajout manuel depuis le back-office', 'manual');
    }

    /**
     * Test de connexion IMAP avec les paramètres actuels.
     * @return array{ok: bool, message: string}
     */
    public function testImapConnection(): array
    {
        if (!extension_loaded('imap')) {
            return ['ok' => false, 'message' => AdminTranslator::t('msg.imap_missing_extension')];
        }

        $host   = (string) \Configuration::get(self::CFG_IMAP_HOST);
        $port   = (int)   \Configuration::get(self::CFG_IMAP_PORT) ?: 993;
        $user   = (string) \Configuration::get(self::CFG_IMAP_USER);
        $pass   = \CryptoManager::decrypt((string) \Configuration::get(self::CFG_IMAP_PASS));
        $ssl    = (bool)   \Configuration::get(self::CFG_IMAP_SSL);
        $folder = (string) \Configuration::get(self::CFG_IMAP_FOLDER) ?: 'INBOX';

        if ($host === '' || $user === '' || $pass === '') {
            return ['ok' => false, 'message' => AdminTranslator::t('msg.imap_missing_fields')];
        }

        $flags   = $ssl ? '/imap/ssl' : '/imap/notls';
        $mailbox = '{' . $host . ':' . $port . $flags . '}' . $folder;
        $mbox    = @imap_open($mailbox, $user, $pass, OP_HALFOPEN, 1);

        if ($mbox === false) {
            return ['ok' => false, 'message' => AdminTranslator::tVars('msg.imap_connection_failed', ['error' => imap_last_error()])];
        }

        $count = imap_num_msg($mbox);
        imap_close($mbox);
        return ['ok' => true, 'message' => AdminTranslator::tVars('msg.imap_connection_ok', ['count' => $count, 'folder' => $folder])];
    }

    /**
     * Génère (ou régénère) le secret HMAC du webhook.
     */
    public static function generateWebhookSecret(): string
    {
        $secret = bin2hex(random_bytes(24));
        \Configuration::updateValue(self::CFG_WEBHOOK_SECRET, \CryptoManager::encrypt($secret));
        return $secret;
    }

    public static function getWebhookUrl(): string
    {
        return \Context::getContext()->link->getModuleLink('neria', 'bounce', [], true);
    }
}
