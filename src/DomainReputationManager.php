<?php
/**
 * NERIA — DomainReputationManager
 *
 * Vérifie la réputation du domaine d'envoi via DNS :
 *   • SPF, DKIM (17 sélecteurs communs), DMARC
 *   • 42 blacklists DNS (RBL)
 *
 * Les résultats sont mis en cache 24 h dans ps_configuration.
 * Le cron neria_behavioral actualise quotidiennement.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class DomainReputationManager
{
    const CONFIG_CACHE      = 'NERIA_DOMAIN_REP_CACHE';
    const CONFIG_LAST_CHECK = 'NERIA_DOMAIN_REP_LAST_CHECK';
    const CACHE_TTL         = 86400; // 24 h

    // Sélecteurs DKIM les plus courants
    private const DKIM_SELECTORS = [
        'default', 'google', 'mail', 'dkim', 'selector1', 'selector2',
        's1', 's2', 'k1', 'key1', 'smtp', 'outbound', 'email',
        'protonmail', 'pm', 'dkim1', 'neria',
    ];

    // 42 listes noires DNS (RBL) — mises à jour 2026
    private const RBL_LIST = [
        // Spamhaus — référence mondiale
        'zen.spamhaus.org',
        'sbl.spamhaus.org',
        'xbl.spamhaus.org',
        'pbl.spamhaus.org',
        // SpamCop
        'bl.spamcop.net',
        // SORBS
        'dnsbl.sorbs.net',
        'spam.dnsbl.sorbs.net',
        'smtp.dnsbl.sorbs.net',
        'dul.dnsbl.sorbs.net',
        'problems.dnsbl.sorbs.net',
        // Barracuda
        'b.barracudacentral.org',
        // CBL
        'cbl.abuseat.org',
        // UCEProtect
        'dnsbl-1.uceprotect.net',
        'dnsbl-2.uceprotect.net',
        'dnsbl-3.uceprotect.net',
        // PSBL
        'psbl.surriel.com',
        // WPbl
        'db.wpbl.info',
        // Manitu
        'ix.dnsbl.manitu.net',
        // Mailspike
        'bl.mailspike.net',
        // GBUdb
        'truncate.gbudb.net',
        // Blocklist.de
        'bl.blocklist.de',
        // SPFBL
        'dnsbl.spfbl.net',
        // 0spam
        'bl.0spam.org',
        // Swinog
        'dnsrbl.swinog.ch',
        // Unsubscore
        'ubl.unsubscore.com',
        // abuse.ch
        'spam.abuse.ch',
        'drone.abuse.ch',
        // MegarBL
        'rbl.megarbl.net',
        // Interserver
        'rbl.interserver.net',
        // Suomi spam
        'bl.suomispam.net',
        // Divers
        'all.spam-rbl.fr',
        'hostkarma.junkemailfilter.com',
        'blacklist.woody.ch',
        'spamsources.fabel.dk',
        'rbl.schulte.org',
        'singular.ttk.pte.hu',
        'spambot.bls.digibase.ca',
        'bogons.cymru.com',
        'bsb.empty.us',
        'dnsbl.inps.de',
        'rbl.rbldns.ru',
    ];

    private Neria $module;
    private ?\WatchdogManager $watchdog = null;

    public function __construct(Neria $module)
    {
        $this->module = $module;
    }

    private function watchdog(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // API PUBLIQUE
    // ============================================================

    /**
     * Retourne le rapport (cache ou vérification fraîche).
     */
    public function getReport(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->getCachedReport();
            if ($cached !== null) {
                return $cached;
            }
        }
        return $this->runFullCheck();
    }

    /**
     * Retourne le rapport mis en cache, ou null si périmé / absent.
     */
    public function getCachedReport(): ?array
    {
        $lastCheck = (int) \Configuration::get(self::CONFIG_LAST_CHECK);
        if ($lastCheck && (time() - $lastCheck) < self::CACHE_TTL) {
            $json = \Configuration::get(self::CONFIG_CACHE);
            if ($json) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        return null;
    }

    /**
     * Exécute la vérification complète, met en cache et retourne le rapport.
     */
    public function runFullCheck(): array
    {
        \Configuration::updateValue(\HealthCheckManager::CRON_LAST_DOMREP, date('Y-m-d H:i:s'));
        @set_time_limit(120);

        $domain = $this->getSenderDomain();
        $ip     = $domain ? $this->resolveIp($domain) : null;

        $spf    = $this->checkSpf($domain);
        $dkim   = $this->checkDkim($domain);
        $dmarc  = $this->checkDmarc($domain);
        $mx     = $this->checkMx($domain);
        $bl     = ($ip && !$this->isPrivateIp($ip))
            ? $this->checkBlacklists($ip)
            : ['checked' => 0, 'hits' => [], 'clean' => 0, 'skipped' => true];

        $score = $this->computeScore($spf, $dkim, $dmarc, $bl);
        $grade = $this->computeGrade($score);

        $report = [
            'domain'     => $domain,
            'ip'         => $ip,
            'spf'        => $spf,
            'dkim'       => $dkim,
            'dmarc'      => $dmarc,
            'mx'         => $mx,
            'blacklists' => $bl,
            'score'      => $score,
            'grade'      => $grade,
            'color'      => $this->gradeColor($grade),
            'checked_at' => date('Y-m-d H:i:s'),
            'timestamp'  => time(),
        ];

        \Configuration::updateValue(self::CONFIG_CACHE, json_encode($report));
        \Configuration::updateValue(self::CONFIG_LAST_CHECK, time());

        $rblHits = count($bl['hits'] ?? []);
        $msg = sprintf('Réputation domaine %s — score %d/100 (grade %s)', $domain ?: '?', $score, $grade);

        if ($grade === 'F' || $grade === 'D' || $rblHits > 2) {
            $this->watchdog()->error($msg . ($rblHits ? " — {$rblHits} liste(s) noire(s)" : ''), '', 'DomainReputation');
        } elseif ($grade === 'C' || $rblHits > 0) {
            $this->watchdog()->warning($msg . ($rblHits ? " — {$rblHits} liste(s) noire(s)" : ''), '', 'DomainReputation');
        } else {
            $this->watchdog()->info($msg, '', 'DomainReputation');
        }

        return $report;
    }

    // ============================================================
    // VÉRIFICATIONS DNS
    // ============================================================

    private function checkSpf(string $domain): array
    {
        if (!$domain) {
            return ['found' => false, 'record' => null, 'policy' => null];
        }

        $records = @dns_get_record($domain, DNS_TXT) ?: [];
        foreach ($records as $r) {
            $txt = $r['txt'] ?? '';
            if (!$txt && !empty($r['entries'])) {
                $txt = implode('', (array) $r['entries']);
            }
            if (stripos($txt, 'v=spf1') === 0) {
                $policy = str_contains($txt, '-all') ? 'reject' :
                         (str_contains($txt, '~all') ? 'softfail' : 'neutral');
                return [
                    'found'  => true,
                    'record' => $txt,
                    'policy' => $policy,
                ];
            }
        }
        return ['found' => false, 'record' => null, 'policy' => null];
    }

    private function checkDkim(string $domain): array
    {
        if (!$domain) {
            return ['found' => false, 'selector' => null, 'record' => null];
        }

        foreach (self::DKIM_SELECTORS as $selector) {
            $host    = $selector . '._domainkey.' . $domain;
            $records = @dns_get_record($host, DNS_TXT) ?: [];
            foreach ($records as $r) {
                $txt = $r['txt'] ?? '';
                if (!$txt && !empty($r['entries'])) {
                    $txt = implode('', (array) $r['entries']);
                }
                if (str_contains(strtolower($txt), 'v=dkim1') || preg_match('/p=[A-Za-z0-9+\/]{10,}/', $txt)) {
                    return [
                        'found'    => true,
                        'selector' => $selector,
                        'record'   => substr($txt, 0, 100) . (strlen($txt) > 100 ? '…' : ''),
                    ];
                }
            }
        }
        return ['found' => false, 'selector' => null, 'record' => null];
    }

    private function checkDmarc(string $domain): array
    {
        if (!$domain) {
            return ['found' => false, 'record' => null, 'policy' => null];
        }

        $records = @dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [];
        foreach ($records as $r) {
            $txt = $r['txt'] ?? '';
            if (!$txt && !empty($r['entries'])) {
                $txt = implode('', (array) $r['entries']);
            }
            if (stripos($txt, 'v=DMARC1') === 0) {
                $policy = 'none';
                if (preg_match('/\bp=(\w+)/i', $txt, $m)) {
                    $policy = strtolower($m[1]);
                }
                return [
                    'found'  => true,
                    'record' => $txt,
                    'policy' => $policy,
                ];
            }
        }
        return ['found' => false, 'record' => null, 'policy' => null];
    }

    private function checkMx(string $domain): array
    {
        if (!$domain) {
            return ['found' => false, 'count' => 0, 'records' => []];
        }

        $records = @dns_get_record($domain, DNS_MX) ?: [];
        return [
            'found'   => count($records) > 0,
            'count'   => count($records),
            'records' => array_column($records, 'target'),
        ];
    }

    // ============================================================
    // VÉRIFICATION BLACKLISTS (42 RBL)
    // ============================================================

    private function checkBlacklists(string $ip): array
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return ['checked' => 0, 'hits' => [], 'clean' => 0];
        }

        $reversed = implode('.', array_reverse($parts));
        $hits     = [];
        $checked  = 0;

        foreach (self::RBL_LIST as $rbl) {
            $host = $reversed . '.' . $rbl;
            // dns_get_record retourne false en cas d'erreur réseau,
            // tableau vide si NXDOMAIN (= non listé), tableau non vide si listé.
            $result = @dns_get_record($host, DNS_A);
            $checked++;
            if (is_array($result) && count($result) > 0) {
                $hits[] = $rbl;
            }
        }

        return [
            'checked' => $checked,
            'hits'    => $hits,
            'clean'   => $checked - count($hits),
        ];
    }

    // ============================================================
    // SCORE ET GRADE
    // ============================================================

    private function computeScore(array $spf, array $dkim, array $dmarc, array $bl): int
    {
        $score = 0;

        // SPF — 25 pts
        if ($spf['found']) {
            $score += match($spf['policy'] ?? '') {
                'reject'   => 25,
                'softfail' => 20,
                default    => 12,
            };
        }

        // DKIM — 25 pts
        if ($dkim['found']) {
            $score += 25;
        }

        // DMARC — 20 pts
        if ($dmarc['found']) {
            $score += match($dmarc['policy'] ?? 'none') {
                'reject'     => 20,
                'quarantine' => 15,
                'none'       => 8,
                default      => 8,
            };
        }

        // Blacklists — 30 pts max
        $hits     = count($bl['hits'] ?? []);
        $checked  = max(1, (int) ($bl['checked'] ?? 1));
        $blScore  = max(0, 30 - ($hits * 6));
        if (!empty($bl['skipped'])) {
            $blScore = 30; // IP privée — pas pénalisée
        }
        $score += $blScore;

        return min(100, $score);
    }

    public function computeGrade(int $score): string
    {
        return match(true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 50 => 'C',
            $score >= 25 => 'D',
            default      => 'F',
        };
    }

    public function gradeColor(string $grade): string
    {
        return match($grade) {
            'A'     => '#1a7a40',
            'B'     => '#3a7a6e',
            'C'     => '#a0520d',
            'D'     => '#b03a2e',
            'F'     => '#7b241c',
            default => '#888',
        };
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function getSenderDomain(): string
    {
        // 1. Multi-expéditeur Neria — sélecteur FR ou premier défini
        $sendersJson = \Configuration::get('NERIA_SENDERS_JSON');
        if ($sendersJson) {
            $senders = json_decode($sendersJson, true) ?? [];
            foreach (['fr', 'en', array_key_first($senders)] as $lang) {
                if (!empty($senders[$lang]['from'])) {
                    $d = $this->extractDomain($senders[$lang]['from']);
                    if ($d) return $d;
                }
            }
        }

        // 2. Email de la boutique PrestaShop
        $email = (string) \Configuration::get('PS_SHOP_EMAIL');
        $d = $this->extractDomain($email);
        if ($d) return $d;

        // 3. Nom de domaine de la boutique
        return \Tools::getShopDomainSsl();
    }

    private function extractDomain(string $email): string
    {
        if (str_contains($email, '@')) {
            return trim(explode('@', $email)[1] ?? '');
        }
        return '';
    }

    private function resolveIp(string $domain): ?string
    {
        $r = @dns_get_record($domain, DNS_A);
        return (!empty($r[0]['ip'])) ? $r[0]['ip'] : null;
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
