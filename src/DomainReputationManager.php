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
     * Invalide le cache de réputation pour une boutique donnée — round 144 :
     * rien n'appelait jamais cette invalidation quand le marchand changeait
     * son expéditeur transactionnel (neria.php, action save_senders). Le
     * tableau de bord continuait alors d'afficher jusqu'à 24h le score/grade
     * de l'ANCIEN domaine, masquant le fait qu'un nouveau domaine fraîchement
     * configuré (sans SPF/DKIM/DMARC en place) n'a en réalité aucune
     * authentification — précisément le moment où ce risque est le plus
     * élevé. getSenderDomain() lit NERIA_SENDERS_JSON (à défaut
     * PS_SHOP_EMAIL) pour déterminer le domaine à auditer : c'est la même
     * donnée que save_senders modifie.
     */
    public static function invalidateCache(int $idShop): void
    {
        \Configuration::deleteFromContext(self::CONFIG_LAST_CHECK, null, $idShop);
        \Configuration::deleteFromContext(self::CONFIG_CACHE, null, $idShop);
    }

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

    /**
     * Round 299 : nom de verrou basé sur le DOMAINE expéditeur (haché, pas
     * l'id_shop) — deux boutiques distinctes envoyant depuis le MÊME
     * domaine (sous-domaines partageant SPF/DKIM/DMARC racine, ou même
     * expéditeur transactionnel configuré sur les deux) déclenchaient
     * jusqu'ici chacune leur propre cycle DNS/RBL complet (jusqu'à 84
     * requêtes RBL redondantes par cycle, verrou round 154/165 scopé par
     * boutique donc totalement inefficace entre elles), avec un risque
     * réel d'incohérence : un grade/score différent affiché pour CHACUNE
     * alors que la réputation DNS sous-jacente est objectivement unique.
     * getSenderDomain() n'effectue aucun appel réseau (lecture config +
     * JSON) — appelable ici sans coût avant la prise de verrou.
     * Repli sur l'id_shop si le domaine n'a pas pu être déterminé, pour ne
     * jamais faire cohabiter deux boutiques à domaine "vide"/inconnu sous
     * le même verrou par accident.
     */
    private function lockName(string $domain): string
    {
        return $domain !== ''
            ? 'neria_domain_reputation_dom_' . md5($domain)
            : 'neria_domain_reputation_' . $this->idShop;
    }

    /**
     * Round 299 : cherche, parmi les AUTRES boutiques actives, un rapport
     * encore frais (< CACHE_TTL) pour ce MÊME domaine — évite de relancer
     * une résolution DNS/RBL complète quand une boutique sœur vient déjà
     * de la faire pour le domaine partagé, et garantit que les deux
     * boutiques affichent le même grade/score pour la même réputation
     * réelle plutôt que deux résultats potentiellement divergents obtenus
     * à quelques minutes/heures d'écart.
     */
    private function findFreshReportForDomain(string $domain, ?array $shopIds = null): ?array
    {
        if ($domain === '') {
            return null;
        }
        // $shopIds injectable (tests) — par défaut, toutes les boutiques
        // actives réelles. Sur une install mono-boutique, Shop::getShops()
        // ne renvoie que la boutique courante, exclue juste en dessous : le
        // résultat est donc naturellement null sans avoir besoin d'un garde
        // Shop::isFeatureActive() séparé.
        $shopIds = $shopIds ?? \Shop::getShops(true, null, true);
        foreach ($shopIds as $otherShopId) {
            $otherShopId = (int) $otherShopId;
            if ($otherShopId === $this->idShop) {
                continue;
            }
            $lastCheck = (int) \Configuration::get(self::CONFIG_LAST_CHECK, null, null, $otherShopId);
            if (!$lastCheck || (time() - $lastCheck) >= self::CACHE_TTL) {
                continue;
            }
            $json = \Configuration::get(self::CONFIG_CACHE, null, null, $otherShopId);
            if (!$json) {
                continue;
            }
            $data = json_decode($json, true);
            if (is_array($data) && ($data['domain'] ?? null) === $domain) {
                return $data;
            }
        }
        return null;
    }

    public function runFullCheck(): array
    {
        $domain = $this->getSenderDomain();
        $lockName = $this->lockName($domain);

        // Round 299 : mutualisation par domaine tentée AVANT toute prise de
        // verrou/résolution DNS — voir findFreshReportForDomain() ci-dessus.
        $shared = $this->findFreshReportForDomain($domain);
        if ($shared !== null) {
            $this->cacheReport($shared);
            return $shared;
        }

        // Round 154 : verrou MySQL — sans lui, deux déclenchements
        // concurrents (hookDisplayHeader sur deux visiteurs simultanés, ou
        // fallback front + vrai cron serveur) pouvaient tous deux relancer
        // runFullCheck() (jusqu'à 8s de résolutions DNS/RBL bloquantes)
        // dans la même fenêtre de cache périmé, doublant inutilement la
        // charge DNS et — si le grade est F/D ou RBL>2 — pouvant chacun
        // déclencher watchdog()->error() → sendImmediateAlert(), dont le
        // throttle par boutique est lui-même un check-then-act non
        // atomique : une alerte critique de réputation de domaine pouvait
        // ainsi partir en double au marchand pour le même incident.
        // Round 299 : $lockName scopé par domaine (pas id_shop) — voir
        // lockName() ci-dessus, mutualise aussi la protection anti-double-
        // exécution entre boutiques partageant le même domaine.
        if ((int) \Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 0)", false) !== 1) {
            // Verrou déjà tenu par un autre process en train de rafraîchir
            // le même rapport : sert le cache s'il en existe un (même
            // périmé), plutôt que de dupliquer la vérification DNS/RBL
            // coûteuse.
            $cached = $this->getCachedReport();
            if ($cached !== null) {
                return $cached;
            }

            // Round 165 : le tout premier check à froid d'une boutique
            // (aucun cache) exécutait auparavant runFullCheckLocked() SANS
            // verrou dans ce cas précis — précisément le scénario que ce
            // verrou (round 154) visait à corriger : deux déclenchements
            // concurrents (deux visiteurs simultanés, ou fallback front +
            // cron serveur) au tout premier lancement d'une boutique
            // pouvaient chacun dupliquer la résolution DNS/RBL complète et
            // chacun déclencher sa propre alerte Watchdog pour le même
            // incident — le double-envoi que le verrou devait éliminer
            // réapparaissait donc systématiquement au cold start. On
            // attend maintenant réellement le verrou (jusqu'à 6s, sous le
            // budget DNS de 8s) : l'autre process a normalement fini et
            // écrit le cache pendant l'attente.
            if ((int) \Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($lockName) . "', 6)", false) === 1) {
                try {
                    $cached = $this->getCachedReport();
                    if ($cached !== null) {
                        return $cached;
                    }
                    $shared = $this->findFreshReportForDomain($domain);
                    if ($shared !== null) {
                        $this->cacheReport($shared);
                        return $shared;
                    }
                    return $this->runFullCheckLocked($domain);
                } finally {
                    \Db::getInstance()->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
                }
            }

            // Verrou toujours indisponible après l'attente (cas extrême) :
            // un tableau vide serait pire qu'une vérification non
            // dédupliquée une dernière fois.
            return $this->runFullCheckLocked($domain);
        }

        try {
            return $this->runFullCheckLocked($domain);
        } finally {
            \Db::getInstance()->execute("SELECT RELEASE_LOCK('" . pSQL($lockName) . "')");
        }
    }

    /**
     * Round 299 : écrit un rapport (potentiellement mutualisé depuis une
     * boutique sœur au même domaine) dans le cache de CETTE boutique, sans
     * refaire le calcul — même écriture que la fin de runFullCheckLocked(),
     * factorisée pour être réutilisée par les 2 branches "domaine partagé".
     */
    private function cacheReport(array $report): void
    {
        $encoded = json_encode($report);
        if ($encoded === false) {
            return;
        }
        \Configuration::updateValue(self::CONFIG_CACHE, $encoded, false, null, $this->idShop);
        \Configuration::updateValue(self::CONFIG_LAST_CHECK, time(), false, null, $this->idShop);
    }

    private function runFullCheckLocked(?string $domain = null): array
    {
        // Round 193 : $this->idShop transmis explicitement — absent
        // jusqu'ici, alors que toutes les autres clés de ce fichier sont
        // scrupuleusement scopées par boutique. Le cron multi-boutique
        // (neria.php) appelle runFullCheckLocked() indépendamment pour
        // chaque boutique (échecs individuels avalés) : si la Boutique A
        // réussit mais la Boutique B échoue systématiquement, ce timestamp
        // GLOBAL était quand même rafraîchi grâce au seul succès de A — un
        // admin consultant le Diagnostic depuis le contexte de la Boutique
        // B voyait "OK, exécuté récemment" alors que SA vérification
        // échoue silencieusement depuis des jours.
        \Configuration::updateValue(\HealthCheckManager::CRON_LAST_DOMREP, date('Y-m-d H:i:s'), false, null, $this->idShop);
        @set_time_limit(120);

        $deadline = microtime(true) + self::DNS_TIME_BUDGET_SECS;

        // Round 299 : $domain accepté en paramètre optionnel — runFullCheck()
        // l'a déjà calculé (sans coût réseau) pour choisir le nom de verrou
        // et tenter la mutualisation par domaine ; éviter de le relire une
        // 2e fois ici garde un comportement strictement identique si cette
        // méthode est appelée directement (tests, ou $domain omis).
        $domain = $domain ?? $this->getSenderDomain();
        $ip     = $domain ? $this->resolveIp($domain, $deadline) : null;

        $spf    = $this->checkSpf($domain, $deadline);
        $dkim   = $this->checkDkim($domain, $deadline);
        $dmarc  = $this->checkDmarc($domain, $deadline);
        $mx     = $this->checkMx($domain, $deadline);

        // Round 165 : $ip === null (domaine expéditeur introuvable/non
        // résolvable) et $ip privée légitime (environnement de dev/test)
        // produisaient jusqu'ici le MÊME tableau 'skipped' => true, que
        // computeScore() traite comme "IP privée, non pénalisée" et crédite
        // des points pleins (5/5 PTR + 25/25 RBL = 30 pts). Un domaine
        // expéditeur cassé/mal configuré (aucune IP résolvable) obtenait
        // ainsi un plancher de score de 30 pts au lieu de 0 — pouvant faire
        // basculer le grade de F à D et éviter l'alerte watchdog critique
        // (seuil grade==='F'). 'ip_missing' distingue désormais l'échec réel
        // du skip légitime, sans changer le comportement pour une IP privée.
        $ipMissing = ($ip === null);
        $ptr    = ($ip && !$this->isPrivateIp($ip))
            ? $this->checkPtr($ip, $deadline)
            : ['found' => false, 'hostname' => null, 'skipped' => !$ipMissing, 'ip_missing' => $ipMissing];
        $bimi   = $this->checkBimi($domain, $dmarc, $deadline);
        $bl     = ($ip && !$this->isPrivateIp($ip))
            ? $this->checkBlacklists($ip, $deadline)
            : ['checked' => 0, 'hits' => [], 'clean' => 0, 'skipped' => !$ipMissing, 'ip_missing' => $ipMissing];

        if (!empty($bl['timed_out'])) {
            $this->watchdog()->warning(
                \WatchdogManager::i18nMsg('watchdog.domain_reputation_rbl_timed_out', [
                    'domain'  => $domain,
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

        // Round 248 : json_encode() retourne false en cas de donnée non
        // encodable (défense en profondeur -- la source la plus probable,
        // le hostname PTR, est désormais assainie dans checkPtr() ci-dessus,
        // mais d'autres champs de $report pourraient théoriquement poser le
        // même problème à l'avenir). Écrire false tel quel dans
        // Configuration::updateValue() le convertit silencieusement en
        // chaîne vide -- CONFIG_LAST_CHECK ne doit alors PAS être mis à
        // jour : le laisser à sa valeur précédente (ou absent) permet à
        // getCachedReport() de retenter runFullCheck() dès le prochain
        // appel plutôt que de rester bloqué sur un cache vide jusqu'à
        // l'expiration du TTL de 24h.
        $encodedReport = json_encode($report);
        if ($encodedReport === false) {
            $this->watchdog()->error(
                \WatchdogManager::i18nMsg('watchdog.domain_reputation_encode_failed', ['domain' => $domain ?: '?']),
                '', 'DomainReputationManager'
            );
        } else {
            \Configuration::updateValue(self::CONFIG_CACHE, $encodedReport, false, null, $this->idShop);
            \Configuration::updateValue(self::CONFIG_LAST_CHECK, time(), false, null, $this->idShop);
        }

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

    private function checkSpf(string $domain, ?float $deadline = null): array
    {
        if (!$domain) {
            return ['found' => false, 'record' => null, 'policy' => null];
        }

        // Round 165 : DNS_TIME_BUDGET_SECS n'était appliqué qu'à
        // checkDkim()/checkPtr()/checkBlacklists() — checkSpf()/checkDmarc()/
        // checkMx()/checkBimi()/resolveIp() s'exécutaient sans jamais
        // consulter le budget, y compris ceux appelés APRÈS checkDkim()
        // (déjà potentiellement épuisé par ses 17 sélecteurs). Le budget
        // censé borner le blocage du visiteur front ne couvrait donc en
        // réalité qu'une partie du chemin d'exécution.
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['found' => false, 'record' => null, 'policy' => null];
        }

        // Round 177 : dns_get_record() retourne `false` sur une erreur
        // réseau/résolveur (panne DNS temporaire), un tableau vide sur un
        // NXDOMAIN légitime (domaine sans TXT SPF) — `?: []` confondait les
        // deux en un même "found=false", indiscernable pour l'appelant
        // (computeScore() traitait alors une panne DNS transitoire comme
        // "domaine sans SPF", pénalité pleine et résultat mis en cache 24h).
        $raw = @dns_get_record($domain, DNS_TXT);
        $dnsError = ($raw === false);
        $records = $dnsError ? [] : $raw;
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
        return ['found' => false, 'record' => null, 'policy' => null, 'dns_error' => $dnsError];
    }

    private function checkDkim(string $domain, ?float $deadline = null): array
    {
        if (!$domain) {
            return ['found' => false, 'selector' => null, 'record' => null, 'timed_out' => false];
        }

        // Round 177 : au moins une requête par sélecteur ayant échoué au
        // niveau réseau/résolveur (dns_get_record() === false, distinct
        // d'un NXDOMAIN légitime) — voir commentaire de checkSpf(). Une
        // panne transitoire sur un seul sélecteur ne doit pas empêcher de
        // tester les suivants (elle peut réussir), mais si TOUS échouent
        // ainsi, "found=false" ne doit pas être traité comme une absence de
        // DKIM confirmée : le drapeau ci-dessous permet à computeScore() de
        // retomber sur le même score neutre que pour un budget DNS épuisé.
        $anyDnsError = false;
        foreach (self::DKIM_SELECTORS as $selector) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                // Round 144 : budget de temps DNS épuisé AVANT d'avoir
                // interrogé tous les sélecteurs — contrairement à
                // checkBlacklists() (round 74), ce cas n'était jamais
                // distingué d'un "DKIM absent après vérification complète" :
                // computeScore() traitait les deux identiquement (0 point),
                // faussant silencieusement le score sur un résolveur DNS
                // lent alors que le domaine peut avoir du DKIM configuré sur
                // un sélecteur non encore atteint.
                return ['found' => false, 'selector' => null, 'record' => null, 'timed_out' => true];
            }
            $host = $selector . '._domainkey.' . $domain;
            $raw  = @dns_get_record($host, DNS_TXT);
            if ($raw === false) {
                $anyDnsError = true;
                continue;
            }
            foreach ($raw as $r) {
                $txt = $r['txt'] ?? '';
                if (!$txt && !empty($r['entries'])) {
                    $txt = implode('', (array) $r['entries']);
                }
                if (str_contains(strtolower($txt), 'v=dkim1') || preg_match('/p=[A-Za-z0-9+\/]{10,}/', $txt)) {
                    return [
                        'found'     => true,
                        'selector'  => $selector,
                        'record'    => substr($txt, 0, 100) . (strlen($txt) > 100 ? '…' : ''),
                        'timed_out' => false,
                    ];
                }
            }
        }
        return ['found' => false, 'selector' => null, 'record' => null, 'timed_out' => false, 'dns_error' => $anyDnsError];
    }

    private function checkDmarc(string $domain, ?float $deadline = null): array
    {
        if (!$domain) {
            return ['found' => false, 'record' => null, 'policy' => null];
        }

        // Round 165 : voir commentaire de checkSpf() — budget DNS honoré.
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['found' => false, 'record' => null, 'policy' => null];
        }

        // Round 177 : voir commentaire de checkSpf() — distingue une erreur
        // réseau/résolveur (false) d'un NXDOMAIN légitime ([]).
        $raw = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
        $dnsError = ($raw === false);
        $records = $dnsError ? [] : $raw;
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
        return ['found' => false, 'record' => null, 'policy' => null, 'dns_error' => $dnsError];
    }

    private function checkMx(string $domain, ?float $deadline = null): array
    {
        if (!$domain) {
            return ['found' => false, 'count' => 0, 'records' => []];
        }

        // Round 165 : voir commentaire de checkSpf() — budget DNS honoré.
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['found' => false, 'count' => 0, 'records' => []];
        }

        $records = @dns_get_record($domain, DNS_MX) ?: [];
        return [
            'found'   => count($records) > 0,
            'count'   => count($records),
            'records' => array_column($records, 'target'),
        ];
    }

    private function checkPtr(string $ip, ?float $deadline = null): array
    {
        // Round 144 : gethostbyaddr()/gethostbyname() sont des appels
        // résolveur système BLOQUANTS sans paramètre de timeout applicatif
        // (contrairement à dns_get_record(), utilisé par checkDkim()/
        // checkBlacklists()) — ils échappaient donc totalement au budget
        // DNS_TIME_BUDGET_SECS, alors que le docblock de ce budget justifie
        // son existence précisément par le fait que ce contrôle tourne dans
        // le chemin d'exécution d'un visiteur front (hookDisplayHeader,
        // fallback sans cron serveur). On ne peut pas borner la durée de
        // ces deux appels eux-mêmes, mais on évite au moins de les lancer
        // en plus d'un budget déjà épuisé par checkDkim() ci-dessus.
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['found' => false, 'hostname' => null, 'timed_out' => true];
        }

        $hostname = @gethostbyaddr($ip);

        // gethostbyaddr retourne l'IP elle-même si aucun PTR n'existe
        if ($hostname === false || $hostname === $ip) {
            return ['found' => false, 'hostname' => null];
        }

        // Round 248 : le hostname PTR est publié par le PROPRIÉTAIRE du
        // bloc IP inverse -- une donnée exogène, pas garantie UTF-8 valide
        // ni conforme à la syntaxe d'un nom d'hôte (résolveur mal
        // configuré, IP recyclée, relai compromis). Sans ce garde,
        // $report (construit plus bas dans runFullCheck() à partir de la
        // valeur retournée ici) pouvait contenir un octet UTF-8 invalide,
        // faisant échouer json_encode($report) SILENCIEUSEMENT (retour
        // false, jamais vérifié avant l'écriture en Configuration) --
        // CONFIG_CACHE se retrouvait alors vide alors que CONFIG_LAST_CHECK
        // était quand même mis à jour, empêchant TOUTE mise en cache
        // future tant que ce PTR ne changeait pas : ce contrôle DNS
        // complet (jusqu'à DNS_TIME_BUDGET_SECS de résolutions bloquantes)
        // se relançait alors à CHAQUE visite front (hookDisplayHeader,
        // chemin sans cron serveur) au lieu d'une fois par 24h.
        if (!mb_check_encoding($hostname, 'UTF-8')) {
            return ['found' => false, 'hostname' => null];
        }

        // Round 177 : le budget DNS_TIME_BUDGET_SECS n'était vérifié qu'AVANT
        // gethostbyaddr() ci-dessus, jamais avant ce second appel bloquant
        // (gethostbyname(), vérification FCrDNS) — contrairement à tous les
        // autres points de contrôle DNS du fichier depuis le round 165. Un
        // hostname PTR pointant vers un domaine dont la résolution A est
        // lente pouvait faire dépasser largement le budget censé protéger le
        // visiteur front (hookDisplayHeader, chemin sans cron serveur). On
        // ne peut toujours pas borner la durée de l'appel système lui-même,
        // mais on évite de le lancer en plus d'un budget déjà épuisé par
        // gethostbyaddr() ci-dessus.
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['found' => true, 'hostname' => $hostname, 'timed_out' => true];
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

    private function checkBimi(string $domain, array $dmarc, ?float $deadline = null): array
    {
        if (!$domain) {
            return ['found' => false, 'record' => null, 'eligible' => false];
        }

        // BIMI nécessite DMARC p=quarantine ou p=reject
        $dmarcPolicy   = $dmarc['policy'] ?? 'none';
        $dmarcEligible = in_array($dmarcPolicy, ['quarantine', 'reject'], true);

        // Round 165 : voir commentaire de checkSpf() — budget DNS honoré.
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['found' => false, 'record' => null, 'eligible' => $dmarcEligible];
        }

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
            // Round 177 : `$checked++` s'exécutait AUSSI sur une erreur
            // réseau (`$result === false`), indiscernable ensuite d'une
            // vérification réussie qui n'a simplement rien trouvé. Une
            // panne DNS totale (toutes les RBL retournent false) donnait
            // alors `checked === count(RBL_LIST)` avec `hits=[]` — les 25
            // points pleins étaient accordés dans computeScore() alors
            // qu'AUCUNE requête RBL n'avait réellement abouti. En ne
            // comptant `$checked` que sur une réponse DNS réelle (succès ou
            // NXDOMAIN), une panne totale fait retomber `checked` en dessous
            // de count(RBL_LIST) — 'timed_out' devient vrai ci-dessous et
            // computeScore() applique déjà le score neutre prévu pour une
            // vérification incomplète, sans logique supplémentaire à ajouter.
            if ($result === false) {
                continue;
            }
            $checked++;
            if (count($result) > 0) {
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

        // SPF — 25 pts. Round 177 : score neutre (12/25, même valeur que le
        // repli par défaut) sur une erreur DNS réseau/résolveur plutôt que 0
        // — sinon une panne DNS transitoire est traitée comme "confirmé sans
        // SPF", identique en pratique à un vrai domaine non protégé.
        if ($spf['found']) {
            $score += match($spf['policy'] ?? '') {
                'reject'   => 25,
                'softfail' => 20,
                default    => 12,
            };
        } elseif (!empty($spf['dns_error'])) {
            $score += 12;
        }

        // DKIM — 25 pts. Round 144 : score neutre (12/25, même valeur que le
        // repli SPF par défaut) si la vérification a été tronquée par le
        // budget DNS avant d'avoir interrogé tous les sélecteurs — sinon un
        // domaine avec du DKIM réellement configuré sur un sélecteur non
        // encore atteint (résolveur lent) perdait les 25 points en entier,
        // comme s'il n'avait aucun DKIM. Même principe que les blacklists
        // ci-dessous (round 74).
        if ($dkim['found']) {
            $score += 25;
        } elseif (!empty($dkim['timed_out']) || !empty($dkim['dns_error'])) {
            // Round 177 : dns_error (tous les sélecteurs ont échoué au
            // niveau réseau) traité comme timed_out — même incertitude
            // ("non vérifié", pas "confirmé absent"), même score neutre.
            $score += 12;
        }

        // DMARC — 20 pts. Round 177 : voir commentaire SPF ci-dessus.
        if ($dmarc['found']) {
            $score += match($dmarc['policy'] ?? 'none') {
                'reject'     => 20,
                'quarantine' => 15,
                'none'       => 8,
                default      => 8,
            };
        } elseif (!empty($dmarc['dns_error'])) {
            $score += 8;
        }

        // PTR / rDNS — 5 pts. checkPtr() calcule aussi une vérification FCrDNS
        // (le hostname du PTR doit re-résoudre vers la même IP — c'est ce que
        // vérifient réellement les gros fournisseurs de messagerie) : un PTR
        // présent mais mal configuré (FCrDNS invalide) ne doit pas obtenir les
        // points pleins comme s'il était parfaitement valide.
        if (!empty($ptr['ip_missing'])) {
            // Round 165 : domaine sans IP résolvable — échec réel, pas un
            // skip légitime (IP privée) : aucun point, contrairement à
            // 'skipped' ci-dessous qui reste réservé à l'IP privée.
        } elseif (!empty($ptr['skipped']) || !empty($ptr['valid'])) {
            $score += 5;
        } elseif (!empty($ptr['found'])) {
            $score += 2;
        } elseif (!empty($ptr['timed_out'])) {
            // Round 144 : score neutre (2/5) si le budget DNS était déjà
            // épuisé avant même de tenter la résolution PTR — pas de points
            // pleins (non vérifié) mais pas 0 non plus (pas la preuve d'une
            // absence de PTR).
            $score += 2;
        }

        // Blacklists — 25 pts max
        $hits    = count($bl['hits'] ?? []);
        $blScore = max(0, 25 - ($hits * 5));
        if (!empty($bl['ip_missing'])) {
            // Round 165 : domaine sans IP résolvable — échec réel, aucun
            // point (voir commentaire PTR ci-dessus).
            $blScore = 0;
        } elseif (!empty($bl['skipped'])) {
            $blScore = 25; // IP privée — pas pénalisée
        } elseif (!empty($bl['timed_out']) && $hits === 0) {
            // Vérification incomplète (budget DNS épuisé avant la fin de la
            // boucle RBL) : "0 hit" ne veut ici rien dire de fiable — un
            // domaine réellement blacklisté sur une RBL non atteinte
            // donnerait pourtant hits=[] comme un domaine vraiment propre.
            // Score neutre (ni plein ni nul) plutôt qu'une fausse assurance.
            //
            // Round 305 : cette neutralisation ne s'applique désormais QUE
            // si hits=0 — auparavant elle écrasait INCONDITIONNELLEMENT
            // $blScore dès qu'un timeout survenait, y compris quand des
            // hits avaient déjà été confirmés AVANT l'épuisement du budget
            // DNS. Un hit RBL confirmé est une preuve positive, jamais une
            // incertitude : un domaine à 5 hits confirmés + timeout
            // affichait un score neutre de 12/25, MEILLEUR que le score
            // réel de 0/25 pourtant déjà établi par ces hits — inversant
            // silencieusement la logique de pénalisation pour le cas le
            // plus grave (déjà confirmé blacklisté sur plusieurs RBL).
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
        // $this->idShop explicite (comme ailleurs dans ce fichier, cf.
        // lignes ~147/225) : réaffecter Context::getContext()->shop dans la
        // boucle multi-boutique du cron (neria.php) NE met PAS à jour
        // Shop::$context_id_shop (seul Shop::setContext() le fait) — sans ce
        // 4e argument explicite, Configuration::get() retombe sur la
        // boutique "ambiante" figée au bootstrap du process, pas celle de
        // l'itération courante. Le cache de ce manager est bien scopé par
        // boutique (CONFIG_CACHE/CONFIG_LAST_CHECK), mais stockait la
        // mauvaise donnée source pour toute boutique après la première de
        // la boucle : le rapport SPF/DKIM/DMARC/RBL d'une boutique B pouvait
        // en réalité concerner le domaine expéditeur de la boutique A.
        $sendersJson = \Configuration::get('NERIA_SENDERS_JSON', null, null, $this->idShop);
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

        // 2. Email de la boutique PrestaShop — même scoping explicite, même raison.
        $email = (string) \Configuration::get('PS_SHOP_EMAIL', null, null, $this->idShop);
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

    private function resolveIp(string $domain, ?float $deadline = null): ?string
    {
        // Round 165 : voir commentaire de checkSpf() — budget DNS honoré.
        if ($deadline !== null && microtime(true) >= $deadline) {
            return null;
        }

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
