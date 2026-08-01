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
    const CFG_SOFT_EXPIRY_MONTHS = 'NERIA_BOUNCE_SOFT_EXPIRY_MONTHS';
    const CFG_WEBHOOK_SECRET  = 'NERIA_BOUNCE_WEBHOOK_SECRET';
    const CFG_ENABLED         = 'NERIA_BOUNCE_ENABLED';

    const TABLE = 'neria_bounces';

    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    /**
     * imap_open() n'a aucun timeout par défaut — sans ce réglage préalable,
     * un serveur IMAP lent/injoignable (pare-feu, panne) bloque le handshake
     * TCP/SSL sur le délai par défaut du système (souvent 60-120s+), gelant
     * tout le worker PHP-FPM à la fois pour le cron quotidien et le bouton
     * BO « Tester la connexion IMAP ». À appeler avant CHAQUE imap_open().
     */
    private static function applyImapTimeouts(): void
    {
        @imap_timeout(IMAP_OPENTIMEOUT, 10);
        @imap_timeout(IMAP_READTIMEOUT, 15);
        @imap_timeout(IMAP_WRITETIMEOUT, 15);
        @imap_timeout(IMAP_CLOSETIMEOUT, 10);
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
            'SELECT `type`, `bounce_count`, `status`, `last_bounce_at` FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `email` = \'' . pSQL($email) . '\''
        );

        if (!$row || $row['status'] !== 'active') {
            return false;
        }

        // Hard bounce : jamais de réhabilitation automatique — une adresse
        // réellement inexistante/invalide le reste (contrairement à un soft
        // bounce, qui reflète un problème temporaire). Seule la réactivation
        // manuelle (reactivateBounce()) débloque un hard bounce.
        if ($row['type'] === 'hard') {
            return true;
        }

        // Soft bounce expiré (aucun nouveau bounce depuis N mois) : réhabilité
        // automatiquement, sans intervention marchand. Avant ce correctif,
        // bounce_count n'était jamais décrémenté ni remis à zéro et aucun
        // mécanisme n'expirait les vieux soft bounces — une adresse qui avait
        // eu 3 boîtes pleines en janvier restait bloquée à vie même si tout
        // fonctionnait normalement depuis des mois.
        $expiryMonths = (int) \Configuration::get(self::CFG_SOFT_EXPIRY_MONTHS) ?: 6;
        if (!empty($row['last_bounce_at'])) {
            $ageMonths = (strtotime('now') - strtotime($row['last_bounce_at'])) / (86400 * 30.44);
            if ($ageMonths >= $expiryMonths) {
                return false;
            }
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

        $host          = (string) \Configuration::get(self::CFG_IMAP_HOST);
        $port          = (int)   \Configuration::get(self::CFG_IMAP_PORT)   ?: 993;
        $user          = (string) \Configuration::get(self::CFG_IMAP_USER);
        $passRawStored = (string) \Configuration::get(self::CFG_IMAP_PASS);
        $pass          = \CryptoManager::decrypt($passRawStored);
        $ssl           = (bool)   \Configuration::get(self::CFG_IMAP_SSL);
        $folder        = (string) \Configuration::get(self::CFG_IMAP_FOLDER) ?: 'INBOX';

        // Distingue "mot de passe jamais renseigné" (config vide) de "clé de
        // chiffrement maîtresse illisible" (une valeur chiffrée existe en
        // base mais decrypt() a échoué et retourné ''). Auparavant les deux
        // cas produisaient le même message "configuration incomplète" —
        // trompeur dans le 2e cas : l'admin ressaisit un mot de passe
        // pourtant correct en pensant qu'il était simplement vide, alors que
        // le vrai problème (clé maîtresse illisible) persiste et touche
        // aussi tous les autres secrets du module.
        if ($host === '' || $user === '' || ($pass === '' && $passRawStored === '')) {
            return ['processed' => 0, 'bounces' => 0, 'errors' => [\AdminTranslator::t('msg.bounce_imap_incomplete_config')]];
        }
        if ($pass === '') {
            return ['processed' => 0, 'bounces' => 0, 'errors' => [\AdminTranslator::t('msg.bounce_imap_secret_unreadable')]];
        }

        $flags   = $ssl ? '/imap/ssl' : '/imap/notls';
        $mailbox = '{' . $host . ':' . $port . $flags . '}' . $folder;

        $errors  = [];
        self::applyImapTimeouts();
        $mbox    = @imap_open($mailbox, $user, $pass, 0, 1);
        if ($mbox === false) {
            return ['processed' => 0, 'bounces' => 0, 'errors' => [\AdminTranslator::tVars('msg.bounce_imap_connection_failed', ['error' => imap_last_error()])]];
        }

        $uids      = imap_search($mbox, 'UNSEEN') ?: [];
        $processed = 0;
        $bounces   = 0;

        foreach ($uids as $uid) {
            try {
                // Marqué \Seen EN PREMIER, avant tout traitement — auparavant
                // posé seulement après le parsing complet + recordBounce().
                // Si deux exécutions du cron se chevauchent (cron lent puis
                // relancé, deux workers), les deux lisaient le même UID
                // encore UNSEEN et appelaient chacune recordBounce() pour le
                // MÊME message physique, incrémentant bounce_count deux fois
                // pour un seul rebond réel — rapprochant artificiellement une
                // adresse du seuil de blocage. Poser le flag en premier
                // réduit drastiquement cette fenêtre de course (au prix d'un
                // message non retraité si une exception survient après —
                // compromis acceptable pour un flux de bounces indépendants).
                imap_setflag_full($mbox, (string) $uid, '\\Seen');

                $header = imap_headerinfo($mbox, $uid);
                $body   = imap_fetchbody($mbox, $uid, '');

                // Échec réseau transitoire (pas une exception PHP) : imap_headerinfo()/
                // imap_fetchbody() renvoient simplement false. Sans ce contrôle, le
                // message passait déjà par \Seen (ci-dessus) puis était traité comme
                // "ne ressemble pas à un bounce" (sujet/from vides) — ignoré en
                // silence, sans apparaître dans $errors contrairement aux exceptions.
                if ($header === false || $body === false) {
                    $errors[] = 'UID ' . $uid . ': ' . \AdminTranslator::t('msg.bounce_imap_read_failed');
                    continue;
                }

                $subject = isset($header->subject) ? imap_utf8($header->subject) : '';
                $from    = isset($header->from[0]) ? ($header->from[0]->mailbox . '@' . $header->from[0]->host) : '';

                if (!$this->looksLikeBounce($subject, $from)) {
                    continue;
                }

                // parseDsnBody() retourne un destinataire par échec détecté
                // dans le DSN (peut être plusieurs sur un envoi groupé) ; si
                // aucun DSN structuré n'est trouvé, repli sur un seul résultat
                // best-effort via parseBodyFallback().
                $parsedList = $this->parseDsnBody($body);
                if (empty($parsedList)) {
                    $single = $this->parseBodyFallback($body, $subject);
                    if ($single) {
                        $parsedList = [$single];
                    }
                }

                foreach ($parsedList as $parsed) {
                    if (!isset($parsed['email']) || $parsed['email'] === '') {
                        continue;
                    }
                    $this->recordBounce(
                        $parsed['email'],
                        $parsed['type'] ?? 'hard',
                        $parsed['reason'] ?? $subject,
                        'imap'
                    );
                    $bounces++;
                }

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
     * Un DSN groupe peut contenir PLUSIEURS blocs "par destinataire" (un par
     * adresse en échec) — auparavant un seul destinataire était extrait
     * (le premier), les autres adresses en échec d'un même envoi BCC/multi-
     * destinataires n'étaient jamais enregistrées (faux négatif silencieux :
     * pas de blocage à tort, mais lacune de détection). On découpe désormais
     * le bloc delivery-status sur chaque occurrence de "Final-Recipient" pour
     * traiter chaque destinataire indépendamment.
     *
     * @return array<int,array{email: string, type: string, reason: string}>
     */
    private function parseDsnBody(string $body): array
    {
        // Cherche la section delivery-status dans le corps multipart
        if (!preg_match('/Content-Type:\s*message\/delivery-status.*?(?=Content-Type:|$)/si', $body, $m)) {
            return [];
        }
        $dsnBlock = $m[0];

        $chunks  = preg_split('/(?=Final-Recipient:)/i', $dsnBlock) ?: [];
        $results = [];

        foreach ($chunks as $chunk) {
            // Final-Recipient: rfc822; user@example.com
            if (!preg_match('/Final-Recipient:\s*(?:rfc822;\s*)?([^\s\r\n]+)/i', $chunk, $m)) {
                continue;
            }
            $email  = mb_strtolower(trim($m[1], '<> '));
            $type   = 'hard';
            $reason = '';

            // Status: 5.x.x (hard) ou 4.x.x (soft)
            if (preg_match('/Status:\s*([45]\.\d+\.\d+)/i', $chunk, $m)) {
                $type   = str_starts_with($m[1], '4.') ? 'soft' : 'hard';
                $reason = 'Status DSN : ' . $m[1];
            }

            // Action: failed | delayed | delivered
            if (preg_match('/Action:\s*(\w+)/i', $chunk, $m)) {
                $action = mb_strtolower(trim($m[1]));
                if ($action === 'delayed') {
                    $type = 'soft';
                }
            }

            // Diagnostic-Code pour la raison lisible
            if (preg_match('/Diagnostic-Code:\s*(?:smtp;\s*)?(.*?)(?=\r?\n[A-Za-z]|$)/si', $chunk, $m)) {
                $reason = trim(preg_replace('/\s+/', ' ', $m[1]));
            }

            if ($email !== '') {
                $results[] = compact('email', 'type', 'reason');
            }
        }

        return $results;
    }

    /**
     * Fallback si pas de DSN structuré : cherche une adresse email dans le corps.
     */
    private function parseBodyFallback(string $body, string $subject): ?array
    {
        // Patterns typiques dans les corps de bounce non-DSN permanents (adresse invalide)
        $hardPatterns = [
            '/(?:failed|invalid|rejected|unknown|no such)\s+(?:user|address|recipient).*?[\s<]([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})[\s>]/i',
            '/The\s+(?:address|email)\s+["\']?([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})["\']?\s+(?:does not exist|was not found|is invalid)/i',
            '/550[- ][^\r\n]*?([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i',
        ];

        foreach ($hardPatterns as $pattern) {
            if (preg_match($pattern, $body, $m)) {
                return ['email' => mb_strtolower($m[1]), 'type' => 'hard', 'reason' => $subject];
            }
        }

        // Patterns typiques de bounce TEMPORAIRE en texte libre non-DSN —
        // fréquent chez certains fournisseurs qui n'envoient pas de DSN
        // structuré. Auparavant absents : ces messages ne correspondaient à
        // aucun motif et repartaient marqués lus sans être enregistrés,
        // perdant silencieusement l'information ("boîte pleine" ne comptait
        // jamais dans bounce_count, alors qu'un vrai hard bounce du même
        // fournisseur, lui, était bien détecté).
        $softPatterns = [
            '/(?:mailbox|quota)\s+(?:is\s+)?full.*?[\s<]([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})(?:[\s>]|$)/i',
            '/quota\s+exceeded.*?[\s<]([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})(?:[\s>]|$)/i',
            '/over\s+quota.*?[\s<]([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})(?:[\s>]|$)/i',
            '/4(?:2[0-9]|5[0-9])[- ][^\r\n]*?([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i',
            '/(?:temporarily\s+(?:unavailable|deferred)|try\s+again\s+later).*?[\s<]([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})(?:[\s>]|$)/i',
        ];

        foreach ($softPatterns as $pattern) {
            if (preg_match($pattern, $body, $m)) {
                return ['email' => mb_strtolower($m[1]), 'type' => 'soft', 'reason' => $subject];
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
        // SendGrid regroupe systématiquement plusieurs événements dans un seul
        // POST (tableau JSON) — traiter uniquement $payload[0] ignorait
        // silencieusement tous les autres bounces du même lot (fréquent après
        // un envoi de masse), aucun log, aucune erreur renvoyée à SendGrid.
        if ($source === 'sendgrid' && array_is_list($payload) && isset($payload[0])) {
            $recordedAny = false;
            foreach ($payload as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $result = $this->parseSendgrid($event);
                if ($result !== null && $result['email'] !== '') {
                    $this->recordBounce($result['email'], $result['type'], $result['reason'], 'webhook');
                    $recordedAny = true;
                }
            }
            return $recordedAny;
        }

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
        $reason = (string) ($p['reason'] ?? $p['status'] ?? "SendGrid $event");

        // L'événement "dropped" de SendGrid ne signifie PAS toujours une
        // adresse invalide — contrairement à "bounce" — il couvre aussi des
        // motifs sans rapport avec la délivrabilité de l'adresse elle-même :
        // désabonnement antérieur, plainte spam antérieure, quota de compte
        // dépassé, en-tête SMTPAPI invalide. Classer tout "dropped" en hard
        // bounce (comportement précédent, sauf type==='blocked') bloquait à
        // tort ces clients de façon PERMANENTE. On ignore désormais les
        // motifs clairement non liés à l'adresse (aucun enregistrement), et
        // on classe les "dropped" ambigus en soft plutôt que hard — un faux
        // positif soft se rattrape (seuil + réactivation), un faux positif
        // hard bloque définitivement.
        if ($event === 'dropped') {
            $reasonLower = mb_strtolower($reason);
            $notAnAddressIssue = [
                'unsubscribed', 'spam report', 'invalid smtpapi',
                'over package quota', 'over quota',
            ];
            foreach ($notAnAddressIssue as $needle) {
                if (str_contains($reasonLower, $needle)) {
                    return null;
                }
            }
            $type = str_contains($reasonLower, 'bounced address') ? 'hard' : 'soft';
        } else {
            $type = ($p['type'] ?? '') === 'blocked' ? 'soft' : 'hard';
        }

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

        // INSERT ... ON DUPLICATE KEY UPDATE (atomique, appuyé sur la contrainte
        // UNIQUE `uq_email`) plutôt qu'un SELECT puis INSERT/UPDATE séparés :
        // ce dernier n'était pas atomique, et recordBounce() est appelé à la
        // fois par le webhook ESP (processBounceWebhook — les ESP comme
        // SendGrid/Mailgun retentent automatiquement la livraison d'un webhook
        // non acquitté assez vite) et par la vérification IMAP manuelle. Deux
        // notifications quasi simultanées pour la même adresse pouvaient
        // toutes deux lire "n'existe pas" et entrer en conflit sur l'INSERT,
        // ou toutes deux lire "existe" et incrémenter bounce_count deux fois
        // pour un seul rebond réel — rapprochant artificiellement l'adresse
        // du seuil de mise en liste noire (soft bounce).
        $db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`
                (`email`, `type`, `reason`, `source`, `bounce_count`, `last_bounce_at`, `status`, `date_add`)
             VALUES (
                \'' . pSQL($email) . '\', \'' . pSQL($type) . '\', \'' . pSQL($reason) . '\',
                \'' . pSQL($source) . '\', 1, \'' . pSQL($now) . '\', \'active\', \'' . pSQL($now) . '\'
             )
             ON DUPLICATE KEY UPDATE
                `bounce_count`   = `bounce_count` + 1,
                `last_bounce_at` = VALUES(`last_bounce_at`),
                `reason`         = VALUES(`reason`),
                `type`           = IF(`type` = \'hard\', \'hard\', VALUES(`type`))'
        );

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
        self::applyImapTimeouts();
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
