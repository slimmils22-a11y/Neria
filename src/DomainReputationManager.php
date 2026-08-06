<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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
    private int $idShop;

    public function __construct(Neria $module)
    {
        $this->module = $module;
        $this->idShop = (int) \Context::getContext()->shop->id;
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
        // Cache scopé par boutique (id_shop en 4e paramètre) : un cache
        // GLOBAL comparé a posteriori par domaine (ancien correctif) évite
        // bien la fuite cross-boutique, mais sur une install multi-boutique
        // à domaines distincts, chaque alternance de boutique invalidait le
        // cache de l'autre — relançant runFullCheck() (jusqu'à 8s de DNS
        // bloquants, cf. DNS_TIME_BUDGET_SECS) dans le chemin de rendu du
        // visiteur front à CHAQUE changement de boutique, au lieu d'une fois
        // par 24h. Scoper directement la clé élimine ce cache thrashing.
        $lastCheck = (int) \Configuration::get(self::CONFIG_LAST_CHECK, null, null, $this->idShop);
        if ($lastCheck && (time() - $lastCheck) < self::CACHE_TTL) {
            $json = \Configuration::get(self::CONFIG_CACHE, null, null, $this->idShop);
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
    /**
     * Budget de temps total (secondes) au-delà duquel checkDkim()/checkBlacklists()
     * arrêtent d'interroger de nouveaux serveurs DNS et retournent leurs résultats
     * partiels. dns_get_record() n'a pas de timeout applicatif — un résolveur lent
     * ou injoignable peut prendre plusieurs secondes PAR requête, et ce contrôle
     * tourne dans le chemin d'exécution d'un visiteur front (hookDisplayHeader,
     * fallback sans cron serveur) : sans cette limite, les 17 sélecteurs DKIM +
     * 42 RBL peuvent cumuler plusieurs minutes de blocage pour ce visiteur.
     */
    private const DNS_TIME_BUDGET_SECS = 8.0;

    public function runFullCheck(): array
    {
        \Configuration::updateValue(\HealthCheckManager::CRON_LAST_DOMREP, date('Y-m-d H:i:s'));
        @set_time_limit(120);

        $deadline = microtime(true) + self::DNS_TIME_BUDGET_SECS;

        $domain = $this->getSenderDomain();
        $ip     = $domain ? $this->resolveIp($domain) : null;

        $spf    = $this->checkSpf($domain);
        $dkim   = $this->checkDkim($domain, $deadline);
        $dmarc  = $this->checkDmarc($domain);
        $mx     = $this->checkMx($domain);
        $ptr    = ($ip && !$this->isPrivateIp($ip)) ? $this->checkPtr($ip) : ['found' => false, 'hostname' => null, 'skipped' => true];
        $bimi   = $this->checkBimi($domain, $dmarc);
        $bl     = ($ip && !$this->isPrivateIp($ip))
            ? $this->checkBlacklists($ip, $deadline)
            : ['checked' => 0, 'hits' => [], 'clean' => 0, 'skipped' => true];

        if (!empty($bl['timed_out'])) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.domain_reputation_rbl_timed_out', [
                    'domain'  => $domain ?? '?',
                    'checked' => $bl['checked'],
                    'total'   => count(self::RBL_LIST),
                ]),
                '', 'DomainReputationManager'
            );
        }

        $score = $this->computeScore($spf, $dkim, $dmarc, $ptr, $bl);
        $grade = $this->computeGrade($score);

        $report = [
            'domain'     => $domain,
            'ip'         => $ip,
            'spf'        => $spf,
            'dkim'       => $dkim,
            'dmarc'      => $dmarc,
            'mx'         => $mx,
            'ptr'        => $ptr,
            'bimi'       => $bimi,
            'blacklists' => $bl,
            'score'      => $score,
            'grade'      => $grade,
            'color'      => $this->gradeColor($grade),
            'checked_at' => date('Y-m-d H:i:s'),
            'timestamp'  => time(),
        ];

        \Configuration::updateValue(self::CONFIG_CACHE, json_encode($report), false, null, $this->idShop);
        \Configuration::updateValue(self::CONFIG_LAST_CHECK, time(), false, null, $this->idShop);

        $rblHits = count($bl['hits'] ?? []);
        $msgVars = ['domain' => $domain ?: '?', 'score' => $score, 'grade' => $grade];
        $msg = $rblHits
            ? \WatchdogManager::i18nMsg('watchdog.domain_reputation_checked_rbl', $msgVars + ['n' => $rblHits])
            : \WatchdogManager::i18nMsg('watchdog.domain_reputation_checked', $msgVars);

        if ($grade === 'F' || $grade === 'D' || $rblHits > 2) {
            $this->watchdog()->error($msg, '', 'DomainReputation');
        } elseif ($grade === 'C' || $rblHits > 0) {
            $this->watchdog()->warning($msg, '', 'DomainReputation');
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

    private function checkDkim(string $domain, ?float $deadline = null): array
    {
        if (!$domain) {
            return ['found' => false, 'selector' => null, 'record' => null];
        }

        foreach (self::DKIM_SELECTORS as $selector) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break; // budget de temps DNS épuisé — résultat partiel plutôt qu'un blocage prolongé
            }
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

    private function checkPtr(string $ip): array
    {
        $hostname = @gethostbyaddr($ip);

        // gethostbyaddr retourne l'IP elle-même si aucun PTR n'existe
        if ($hostname === false || $hostname === $ip) {
            return ['found' => false, 'hostname' => null];
        }

        // Vérification inverse : le hostname doit résoudre vers la même IP
        $resolvedIp = @gethostbyname($hostname);
        $valid      = ($resolvedIp === $ip);

        return [
            'found'    => true,
            'hostname' => $hostname,
            'valid'    => $valid,
        ];
    }

    private function checkBimi(string $domain, array $dmarc): array
    {
        if (!$domain) {
            return ['found' => false, 'record' => null, 'eligible' => false];
        }

        // BIMI nécessite DMARC p=quarantine ou p=reject
        $dmarcPolicy   = $dmarc['policy'] ?? 'none';
        $dmarcEligible = in_array($dmarcPolicy, ['quarantine', 'reject'], true);

        $records = @dns_get_record('default._bimi.' . $domain, DNS_TXT) ?: [];
        foreach ($records as $r) {
            $txt = $r['txt'] ?? '';
            if (!$txt && !empty($r['entries'])) {
                $txt = implode('', (array) $r['entries']);
            }
            if (stripos($txt, 'v=BIMI1') === 0) {
                return [
                    'found'    => true,
                    'record'   => substr($txt, 0, 100) . (strlen($txt) > 100 ? '…' : ''),
                    'eligible' => $dmarcEligible,
                ];
            }
        }

        return [
            'found'    => false,
            'record'   => null,
            'eligible' => $dmarcEligible,
        ];
    }

    // ============================================================
    // VÉRIFICATION BLACKLISTS (42 RBL)
    // ============================================================

    private function checkBlacklists(string $ip, ?float $deadline = null): array
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return ['checked' => 0, 'hits' => [], 'clean' => 0];
        }

        $reversed = implode('.', array_reverse($parts));
        $hits     = [];
        $checked  = 0;

        foreach (self::RBL_LIST as $rbl) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break; // budget de temps DNS épuisé — résultat partiel plutôt qu'un blocage prolongé
            }
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
            'checked'   => $checked,
            'hits'      => $hits,
            'clean'     => $checked - count($hits),
            // true si le budget de temps DNS a coupé la boucle avant
            // d'avoir interrogé toutes les RBL (distinct d'un "0 hit après
            // vérification complète" — computeScore() ne doit pas accorder
            // les points pleins dans ce cas, sinon un domaine réellement
            // blacklisté sur une RBL non atteinte obtient un score parfait
            // sur cette composante).
            'timed_out' => $checked < count(self::RBL_LIST),
        ];
    }

    // ============================================================
    // SCORE ET GRADE
    // ============================================================

    private function computeScore(array $spf, array $dkim, array $dmarc, array $ptr, array $bl): int
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

        // PTR / rDNS — 5 pts. checkPtr() calcule aussi une vérification FCrDNS
        // (le hostname du PTR doit re-résoudre vers la même IP — c'est ce que
        // vérifient réellement les gros fournisseurs de messagerie) : un PTR
        // présent mais mal configuré (FCrDNS invalide) ne doit pas obtenir les
        // points pleins comme s'il était parfaitement valide.
        if (!empty($ptr['skipped']) || !empty($ptr['valid'])) {
            $score += 5;
        } elseif (!empty($ptr['found'])) {
            $score += 2;
        }

        // Blacklists — 25 pts max
        $hits    = count($bl['hits'] ?? []);
        $blScore = max(0, 25 - ($hits * 5));
        if (!empty($bl['skipped'])) {
            $blScore = 25; // IP privée — pas pénalisée
        } elseif (!empty($bl['timed_out'])) {
            // Vérification incomplète (budget DNS épuisé avant la fin de la
            // boucle RBL) : "0 hit" ne veut ici rien dire de fiable — un
            // domaine réellement blacklisté sur une RBL non atteinte
            // donnerait pourtant hits=[] comme un domaine vraiment propre.
            // Score neutre (ni plein ni nul) plutôt qu'une fausse assurance.
            $blScore = 12;
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
            $senders = json_decode($sendersJson, true);
            // is_array() est indispensable ici, pas seulement "?? []" : un
            // JSON valide mais corrompu (ex: une simple chaîne ou un nombre,
            // suite à une écriture partielle) décode sans erreur en un
            // scalaire non-null — array_key_first() sur ce scalaire lève un
            // TypeError fatal en PHP 8, plantant tout le contrôle de
            // réputation domaine. HealthCheckManager::checkMultiSenderJson()
            // répare cette config de façon autonome, mais seulement lors de
            // son passage périodique — pas au moment réel de l'usage ici.
            if (!is_array($senders)) {
                $senders = [];
            }
            // array_key_first(): sur $senders = [] retourne null, ce qui
            // rendait 'fr' faussement testé deux fois et ne tentait jamais
            // vraiment un autre expéditeur configuré. array_unique + filter
            // retire ce null plutôt que de le laisser dans la liste.
            $langsToTry = array_unique(array_filter(['fr', 'en', array_key_first($senders)]));
            foreach ($langsToTry as $lang) {
                // Clé 'email' (et non 'from') : c'est celle utilisée partout
                // ailleurs pour NERIA_SENDERS_JSON (neria.php, ConfigManager
                // ::getSenderForLang()). Avec 'from', cette condition n'était
                // jamais vraie et le contrôle de réputation domaine retombait
                // systématiquement sur PS_SHOP_EMAIL — un marchand utilisant
                // un expéditeur multi-langue dédié (ex. no-reply@newsletter
                // -fr.com) voyait son domaine RÉELLEMENT utilisé pour l'envoi
                // jamais vérifié (SPF/DKIM/blacklist), remplacé silencieusement
                // par le domaine boutique par défaut.
                if (!empty($senders[$lang]['email'])) {
                    $d = $this->extractDomain($senders[$lang]['email']);
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
