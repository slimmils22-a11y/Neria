<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — SearchConsoleManager
 *
 * Intégration Google Search Console API v3 (OAuth 2.0 gratuit).
 * Affiche dans le BO les données de visibilité organique :
 * impressions, clics, CTR, position moyenne, top requêtes, top pages.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SearchConsoleManager
{
    const CONFIG_CLIENT_ID     = 'NERIA_SC_CLIENT_ID';
    const CONFIG_CLIENT_SECRET = 'NERIA_SC_CLIENT_SECRET';
    const CONFIG_ACCESS_TOKEN  = 'NERIA_SC_ACCESS_TOKEN';
    const CONFIG_REFRESH_TOKEN = 'NERIA_SC_REFRESH_TOKEN';
    const CONFIG_TOKEN_EXPIRY  = 'NERIA_SC_TOKEN_EXPIRY';
    const CONFIG_CACHE         = 'NERIA_SC_CACHE';
    const CONFIG_CACHE_TIME    = 'NERIA_SC_CACHE_TIME';
    const CONFIG_RETURN_URL    = 'NERIA_SC_RETURN_URL';
    const CONFIG_OAUTH_STATE   = 'NERIA_SC_OAUTH_STATE';
    const CONFIG_LAST_ERROR    = 'NERIA_SC_LAST_ERROR';
    const CONFIG_LAST_ERROR_AT = 'NERIA_SC_LAST_ERROR_AT';

    const CACHE_TTL = 43200; // 12h
    const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';
    const API_BASE  = 'https://www.googleapis.com/webmasters/v3';
    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private \Neria $module;
    private ?\WatchdogManager $wdm = null;

    private function wd(): \WatchdogManager
    {
        if ($this->wdm === null) {
            $this->wdm = new \WatchdogManager($this->module);
        }
        return $this->wdm;
    }

    public function __construct(\Neria $module)
    {
        $this->module = $module;
    }

    // ============================================================
    // ÉTAT
    // ============================================================

    public function isConfigured(): bool
    {
        return (string) \Configuration::get(self::CONFIG_CLIENT_ID) !== ''
            && (string) \Configuration::get(self::CONFIG_CLIENT_SECRET) !== '';
    }

    public function isConnected(): bool
    {
        return (string) \Configuration::get(self::CONFIG_REFRESH_TOKEN) !== '';
    }

    public function getRedirectUri(): string
    {
        return \Tools::getShopDomainSsl(true)
            . __PS_BASE_URI__
            . 'index.php?fc=module&module=neria&controller=oauthsc';
    }

    /**
     * Clé de cache suffixée par boutique — même correctif que PageSpeedManager
     * (déjà appliqué) et DomainReputationManager (2026-08-04) : sans ce
     * scope, la comparaison de domaine a posteriori évite la fuite de
     * données entre boutiques mais provoque un cache thrashing sur une
     * install multi-boutique (chaque alternance de boutique invalide et
     * réécrit le cache de l'autre, déclenchant un appel API Google réel à
     * chaque chargement BO au lieu d'une fois par TTL).
     */
    private function cacheKey(string $base): string
    {
        return $base . '_' . (int) \Context::getContext()->shop->id;
    }

    public function getCacheAge(): ?int
    {
        $t = (int) \Configuration::get($this->cacheKey(self::CONFIG_CACHE_TIME));
        return $t ? (int) round((time() - $t) / 60) : null;
    }

    /**
     * Dernière erreur API rencontrée (vide si le dernier appel a réussi).
     * Utilisé par HealthCheckManager::checkOAuthFreshness() pour afficher
     * la vraie cause au lieu du message générique "reconnectez-vous".
     */
    public function getLastError(): string
    {
        return (string) \Configuration::get(self::CONFIG_LAST_ERROR);
    }

    /**
     * Timestamp Unix du début de la série d'échecs API en cours (null si le
     * dernier appel a réussi). Permet de mesurer depuis combien de temps une
     * erreur persiste sans interruption, pour escalader la sévérité du
     * contrôle de santé au-delà d'un simple avertissement.
     */
    public function getLastErrorAt(): ?int
    {
        $t = (int) \Configuration::get(self::CONFIG_LAST_ERROR_AT);
        return $t ?: null;
    }

    // ============================================================
    // OAUTH
    // ============================================================

    /**
     * Plusieurs flux peuvent être lancés avant qu'un premier ne se termine
     * (deux onglets, double clic) — un seul state global écraserait le
     * précédent et ferait échouer son retour même si Google a bien accepté
     * l'autorisation. Stocke donc une petite liste {state: [return_url, ts]},
     * avec purge des entrées de plus de 10 min à chaque appel.
     */
    public function getAuthUrl(string $returnUrl = ''): string
    {
        $state = bin2hex(random_bytes(16));

        // Round 122 : verrou MySQL autour du cycle lecture-modification-
        // écriture — même correctif que PostmasterManager::getAuthUrl().
        // Sans lui, deux flux lancés à quelques centaines de ms d'écart
        // (double clic, deux onglets BO — le cas que ce mécanisme de liste
        // est précisément censé gérer, voir le commentaire ci-dessus)
        // peuvent tous les deux lire le même $pending avant que l'un des
        // deux n'écrive : le second Configuration::updateValue() écrase
        // intégralement le premier state au lieu de fusionner.
        $db = \Db::getInstance();
        $db->getValue("SELECT GET_LOCK('neria_search_console_oauth_state', 3)");
        try {
            $pending = $this->loadPendingStates();
            $pending[$state] = ['return_url' => $returnUrl, 'ts' => time()];
            $this->savePendingStates($pending);
        } finally {
            $db->execute("SELECT RELEASE_LOCK('neria_search_console_oauth_state')");
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => (string) \Configuration::get(self::CONFIG_CLIENT_ID),
            'redirect_uri'  => $this->getRedirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /**
     * Retourne l'URL de retour BO associée à ce state, sans consommer
     * l'entrée (utilisé par le front controller avant même de savoir si le
     * code est valide, y compris sur le chemin d'erreur Google).
     */
    public function resolveReturnUrl(string $state): string
    {
        $pending = $this->loadPendingStates();
        return (string) ($pending[$state]['return_url'] ?? '');
    }

    private function loadPendingStates(): array
    {
        $raw = (string) \Configuration::get(self::CONFIG_OAUTH_STATE);
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            return [];
        }
        $cutoff = time() - 600;
        foreach ($data as $st => $entry) {
            if (!is_array($entry) || (int) ($entry['ts'] ?? 0) < $cutoff) {
                unset($data[$st]);
            }
        }
        return $data;
    }

    private function savePendingStates(array $pending): void
    {
        \Configuration::updateValue(self::CONFIG_OAUTH_STATE, json_encode($pending));
    }

    public function handleCallback(string $code, string $state): bool
    {
        // Round 122 : même verrou que getAuthUrl().
        $db = \Db::getInstance();
        $db->getValue("SELECT GET_LOCK('neria_search_console_oauth_state', 3)");
        try {
            $pending = $this->loadPendingStates();
            $matchedKey = null;
            foreach (array_keys($pending) as $candidate) {
                if ($state !== '' && hash_equals((string) $candidate, $state)) {
                    $matchedKey = $candidate;
                    break;
                }
            }
            if ($matchedKey === null) {
                return false;
            }
            unset($pending[$matchedKey]);
            $this->savePendingStates($pending);
        } finally {
            $db->execute("SELECT RELEASE_LOCK('neria_search_console_oauth_state')");
        }

        $response = $this->httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => (string) \Configuration::get(self::CONFIG_CLIENT_ID),
            'client_secret' => \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_CLIENT_SECRET)),
            'redirect_uri'  => $this->getRedirectUri(),
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($response['access_token'])) {
            if (isset($response['error'])) {
                $detail = $response['error'] . ' — ' . ($response['error_description'] ?? '');
            } else {
                $prevLang = \AdminTranslator::currentLang();
                \AdminTranslator::setLang(\WatchdogManager::shopLang((int) \Context::getContext()->shop->id));
                $detail = \AdminTranslator::t('watchdog.empty_response');
                \AdminTranslator::setLang($prevLang);
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_oauth_failed', ['error' => $detail]), '', 'SearchConsoleManager');
            return false;
        }

        $this->applyTokenResponse($response);
        // Le state a déjà été consommé (retiré de la liste des flux en
        // attente) plus haut — ne PAS faire deleteByName ici, ça effacerait
        // aussi les autres flux OAuth potentiellement encore en attente.

        $this->wd()->info(\WatchdogManager::i18nMsg('watchdog.gsc_oauth_success'), '', 'SearchConsoleManager');
        return true;
    }

    /**
     * Applique la réponse d'échange de token OAuth à la config — même
     * correctif et même raisonnement que PostmasterManager::applyTokenResponse()
     * (voir tests/regression/test_48_oauth_refresh_token_preserved.php).
     */
    private function applyTokenResponse(array $response): void
    {
        \Configuration::updateValue(self::CONFIG_ACCESS_TOKEN, \CryptoManager::encrypt($response['access_token']));
        if (!empty($response['refresh_token'])) {
            \Configuration::updateValue(self::CONFIG_REFRESH_TOKEN, \CryptoManager::encrypt($response['refresh_token']));
        }
        \Configuration::updateValue(self::CONFIG_TOKEN_EXPIRY, time() + ($response['expires_in'] ?? 3600) - 60);
    }

    /**
     * Invalide le cache de LA BOUTIQUE courante — à utiliser à la place d'un
     * Configuration::deleteByName(SearchConsoleManager::CONFIG_CACHE) direct,
     * qui viserait la clé non scopée (dont la donnée réelle vit désormais
     * sous une clé suffixée par id_shop).
     */
    public function clearCache(): void
    {
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_CACHE));
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_CACHE_TIME));
    }

    public function disconnect(): void
    {
        foreach ([
            self::CONFIG_ACCESS_TOKEN,
            self::CONFIG_REFRESH_TOKEN,
            self::CONFIG_TOKEN_EXPIRY,
            self::CONFIG_OAUTH_STATE,
        ] as $key) {
            \Configuration::deleteByName($key);
        }
        $this->clearCache();
    }

    // ============================================================
    // DONNÉES API
    // ============================================================

    public function getStats(): ?array
    {
        $cacheTime = (int) \Configuration::get($this->cacheKey(self::CONFIG_CACHE_TIME));
        if ($cacheTime && (time() - $cacheTime) < self::CACHE_TTL) {
            $cached = \Configuration::get($this->cacheKey(self::CONFIG_CACHE));
            if ($cached) {
                $data = json_decode($cached, true);
                // Round 150 : revalide le domaine du cache contre le host
                // ACTUEL de la boutique (comme SeoApiManager::getReport()/
                // PageSpeedManager::getReport() le font déjà) — sans ce
                // contrôle, un changement de domaine de la boutique pendant
                // la fenêtre de cache (jusqu'à 12h, le plus long TTL des
                // managers SEO/API) continuait d'afficher les clics/
                // impressions/CTR d'une propriété Search Console qui ne
                // correspond plus au domaine réellement servi.
                if (is_array($data) && (
                    empty($data['site_url']) || $this->matchesShopHost(
                        (string) $data['site_url'],
                        (string) parse_url(\Tools::getShopDomainSsl(true), PHP_URL_HOST)
                    )
                )) {
                    return $data;
                }
            }
        }
        return $this->fetchAndCache();
    }

    public function getCachedStats(): ?array
    {
        $cached = \Configuration::get($this->cacheKey(self::CONFIG_CACHE));
        if (!$cached) {
            return null;
        }
        $data = json_decode($cached, true);
        if (!is_array($data)) {
            return null;
        }
        // Round 150 : même contrôle de domaine que getStats() — évite
        // d'afficher indéfiniment (lecture seule, sans fetch réseau) les
        // statistiques d'un ancien domaine après une migration.
        if (!empty($data['site_url']) && !$this->matchesShopHost(
            (string) $data['site_url'],
            (string) parse_url(\Tools::getShopDomainSsl(true), PHP_URL_HOST)
        )) {
            return null;
        }
        return $data;
    }

    /**
     * Compare bidirectionnellement le siteUrl d'une propriété Search Console
     * au host de la boutique (même logique que
     * PostmasterManager::fetchAndCache()) : une "Domain property" Search
     * Console (siteUrl = 'sc-domain:example.com') est plus COURTE que le
     * host complet de la boutique ('www.example.com'), donc une comparaison
     * à sens unique (stripos($su, $host)) échoue toujours dans ce sens très
     * courant — Google recommande précisément ce type de propriété car il
     * couvre http/https/www automatiquement. Extraite pour être testable
     * sans mocker l'appel réseau à Google.
     */
    private function matchesShopHost(string $siteUrl, string $shopHost): bool
    {
        if ($shopHost === '') {
            return false;
        }
        $suHost = str_replace(['sc-domain:', 'https://', 'http://'], '', rtrim($siteUrl, '/'));

        // Comparaison ancrée sur les frontières de labels DNS, PAS stripos()
        // en sous-chaîne pure : "shop.com" est une sous-chaîne de
        // "myshop.com" (stripos ne retourne jamais false dans ce cas), donc
        // l'ancien filtre acceptait à tort une propriété Search Console
        // totalement non apparentée dès qu'elle partageait une fin de
        // chaîne par coïncidence — même correctif que PostmasterManager::
        // domainsMatch().
        $a = strtolower($suHost);
        $b = strtolower($shopHost);
        return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
    }

    private function fetchAndCache(): ?array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        // Récupère la liste des sites vérifiés
        $sitesData = $this->apiGet('/sites', $token);
        if ($sitesData === null) {
            // Échec réseau/API — erreur déjà journalisée dans apiGet() et
            // dans CONFIG_LAST_ERROR (lu par HealthCheckManager).
            return null;
        }
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR);
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR_AT);
        if (empty($sitesData['siteEntry'])) {
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_no_site'), '', 'SearchConsoleManager');
            return [];
        }

        // Utilise l'URL de la boutique ou le premier site disponible
        $shopUrl  = \Tools::getShopDomainSsl(true) . '/';
        $shopHost = (string) parse_url($shopUrl, PHP_URL_HOST);
        $siteUrl  = null;
        foreach ($sitesData['siteEntry'] as $entry) {
            $su = $entry['siteUrl'] ?? '';
            if ($su !== '' && $this->matchesShopHost($su, $shopHost)) {
                $siteUrl = $su;
                break;
            }
        }
        if (!$siteUrl) {
            // Auparavant repli silencieux sur la première propriété du
            // compte Google (ordre non garanti par l'API), sans rapport
            // garanti avec cette boutique — un marchand avec plusieurs
            // propriétés Search Console pouvait voir les clics/impressions
            // d'un tout autre site affichés comme "ses" statistiques SEO,
            // sans aucune indication que ce n'était pas son domaine.
            $this->wd()->warning(
                \WatchdogManager::i18nMsg('watchdog.gsc_no_matching_site', ['host' => parse_url($shopUrl, PHP_URL_HOST)]),
                '', 'SearchConsoleManager'
            );
            return [];
        }

        $endDate   = date('Y-m-d', strtotime('-3 days')); // GSC a 3 jours de latence
        $startDate = date('Y-m-d', strtotime('-31 days'));

        // KPIs globaux
        $global = $this->querySearchAnalytics($siteUrl, $token, $startDate, $endDate, [], 1);

        // Top 10 requêtes
        $queries = $this->querySearchAnalytics($siteUrl, $token, $startDate, $endDate, ['query'], 10);

        // Top 10 pages
        $pages = $this->querySearchAnalytics($siteUrl, $token, $startDate, $endDate, ['page'], 10);

        if ($global === null) {
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_analytics_fetch_failed'), '', 'SearchConsoleManager');
            return null;
        }

        $kpi = $global[0] ?? [];

        $result = [
            'site_url'   => $siteUrl,
            'period'     => $startDate . ' → ' . $endDate,
            'clicks'     => (int)   ($kpi['clicks']      ?? 0),
            'impressions'=> (int)   ($kpi['impressions'] ?? 0),
            'ctr'        => round((float) ($kpi['ctr']   ?? 0) * 100, 2),
            'position'   => round((float) ($kpi['position'] ?? 0), 1),
            'queries'    => array_map(function (array $row): array {
                return [
                    'label'       => $row['keys'][0] ?? '',
                    'clicks'      => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr'         => round((float) ($row['ctr'] ?? 0) * 100, 2),
                    'position'    => round((float) ($row['position'] ?? 0), 1),
                ];
            }, $queries ?? []),
            'pages'      => array_map(function (array $row): array {
                $url = $row['keys'][0] ?? '';
                return [
                    'label'       => $url,
                    'short'       => $this->shortenUrl($url),
                    'clicks'      => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr'         => round((float) ($row['ctr'] ?? 0) * 100, 2),
                    'position'    => round((float) ($row['position'] ?? 0), 1),
                ];
            }, $pages ?? []),
            'checked_at' => \NeriaTools::formatDate('now', \AdminTranslator::currentLang(), true),
        ];

        \Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE),      json_encode($result, JSON_UNESCAPED_UNICODE));
        \Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_TIME), time());

        // Round 157 : CONFIG_LAST_ERROR n'était effacé qu'une seule fois,
        // juste après le premier appel /sites réussi (plus haut) — un échec
        // de querySearchAnalytics() pour 'queries' ou 'pages' seul (parmi
        // global/queries/pages) laissait CONFIG_LAST_ERROR positionné même
        // si $global a réussi et que $result est bien mis en cache avec
        // succès juste au-dessus. HealthCheckManager::checkOAuthFreshness()
        // affichait alors une erreur/reconnexion à tort de façon permanente
        // au marchand, alors que l'intégration fonctionne et que les
        // données affichées sont à jour (même piège que PostmasterManager,
        // même fichier corrigé au même round).
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR);
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR_AT);

        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.gsc_loaded', [
                'clicks'      => $result['clicks'],
                'impressions' => $result['impressions'],
                'position'    => $result['position'],
            ]),
            '', 'SearchConsoleManager'
        );

        return $result;
    }

    private function querySearchAnalytics(string $siteUrl, string $token, string $start, string $end, array $dimensions, int $limit): ?array
    {
        $body = json_encode([
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => $dimensions,
            'rowLimit'   => $limit,
        ]);

        $path = '/sites/' . urlencode($siteUrl) . '/searchAnalytics/query';
        $response = $this->apiPost($path, $token, $body);

        if ($response === null) {
            return null;
        }

        return $response['rows'] ?? ($dimensions === [] ? [[
            'clicks'      => $response['clicks'] ?? 0,
            'impressions' => $response['impressions'] ?? 0,
            'ctr'         => $response['ctr'] ?? 0,
            'position'    => $response['position'] ?? 0,
        ]] : []);
    }

    private function shortenUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        return $path === '' ? '/' : $path;
    }

    // ============================================================
    // TOKEN
    // ============================================================

    private function getAccessToken(): ?string
    {
        $expiry = (int) \Configuration::get(self::CONFIG_TOKEN_EXPIRY);
        if (time() < $expiry) {
            $token = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_ACCESS_TOKEN));
            if ($token !== '') {
                return $token;
            }
        }
        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): ?string
    {
        $rawRefresh = (string) \Configuration::get(self::CONFIG_REFRESH_TOKEN);
        $refresh    = \CryptoManager::decrypt($rawRefresh);
        if ($refresh === '') {
            // Distingue "jamais connecté" (rawRefresh vide, silence normal)
            // de "token enregistré mais illisible" (decrypt() a échoué — clé
            // de chiffrement maîtresse altérée) : sans ce contrôle, ce cas
            // échouait silencieusement, CONFIG_LAST_ERROR restait vide et
            // HealthCheckManager::checkOAuthFreshness() n'affichait que le
            // message générique "jamais rafraîchi" au lieu de la vraie cause.
            if ($rawRefresh !== '') {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR, 'decrypt_failed: refresh token unreadable');
                if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                    \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
                }
                $this->wd()->warning(
                    \WatchdogManager::i18nMsg('watchdog.search_console_key_unreadable'),
                    '', 'SearchConsoleManager'
                );
            }
            return null;
        }

        $response = $this->httpPost(self::TOKEN_URL, [
            'client_id'     => (string) \Configuration::get(self::CONFIG_CLIENT_ID),
            'client_secret' => \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_CLIENT_SECRET)),
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]);

        if (empty($response['access_token'])) {
            $errCode = $response['error'] ?? '';
            $msg = $response['error_description'] ?? $errCode ?: 'unknown error';
            // Même canal d'erreur que apiGet()/apiPost(), lu par
            // HealthCheckManager::checkOAuthFreshness().
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            // 'invalid_grant' = accès révoqué côté Google — jamais transitoire.
            // On efface le refresh token pour qu'isConnected() cesse de
            // prétendre "connecté" alors que les données ne se rafraîchissent
            // plus silencieusement depuis la révocation.
            if ($errCode === 'invalid_grant') {
                \Configuration::deleteByName(self::CONFIG_REFRESH_TOKEN);
            }
            $this->wd()->error(\WatchdogManager::i18nMsg('watchdog.gsc_token_invalid'), '', 'SearchConsoleManager');
            return null;
        }

        \Configuration::updateValue(self::CONFIG_ACCESS_TOKEN, \CryptoManager::encrypt($response['access_token']));
        \Configuration::updateValue(self::CONFIG_TOKEN_EXPIRY, time() + ($response['expires_in'] ?? 3600) - 60);

        return $response['access_token'];
    }

    // ============================================================
    // HTTP
    // ============================================================

    private function apiGet(string $path, string $token): ?array
    {
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 15,
            \CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            \CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Round 135 : même correctif que PostmasterManager::apiGet() (round
        // 131) — un échec transport (timeout, DNS injoignable, TLS cassé)
        // renvoyait null en silence total, sans jamais écrire
        // CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT ni alerter Watchdog,
        // contrairement à la branche HTTP>=400 ci-dessous. SearchConsoleManager
        // n'avait jamais reçu ce fix alors que PostmasterManager (même
        // famille : OAuth Google) l'a depuis le round 131.
        if ($body === false) {
            $msg = 'network error: ' . ($curlErr !== '' ? $curlErr : 'unknown');
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_api_error', ['error' => $msg]), '', 'SearchConsoleManager');
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $msg = 'invalid JSON response';
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_api_error', ['error' => $msg]), '', 'SearchConsoleManager');
            return null;
        }

        // Google renvoie un corps JSON valide même en erreur (403, 400...) —
        // sans ce contrôle, une erreur d'API était silencieusement confondue
        // avec "aucun site trouvé", masquant la vraie cause.
        if ($httpCode >= 400 || isset($data['error'])) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            // Ne pose le timestamp de début qu'au premier échec de la série —
            // préserve la date de départ réelle pour mesurer une panne
            // persistante, même si le message d'erreur change entre-temps.
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_api_error', ['error' => $msg]), '', 'SearchConsoleManager');
            return null;
        }

        return $data;
    }

    private function apiPost(string $path, string $token, string $body): ?array
    {
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_POST           => true,
            \CURLOPT_POSTFIELDS     => $body,
            \CURLOPT_TIMEOUT        => 15,
            \CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            \CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Round 135 : même correctif que apiGet() ci-dessus — échec
        // transport désormais journalisé, pas seulement les erreurs HTTP
        // explicites de Google.
        if ($resp === false) {
            $msg = 'network error: ' . ($curlErr !== '' ? $curlErr : 'unknown');
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_api_error', ['error' => $msg]), '', 'SearchConsoleManager');
            return null;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            $msg = 'invalid JSON response';
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_api_error', ['error' => $msg]), '', 'SearchConsoleManager');
            return null;
        }

        // Comme apiGet() : Google renvoie un corps JSON valide même en erreur
        // (403, 400...). Sans ce contrôle, une erreur d'API sur la requête
        // searchAnalytics était silencieusement convertie en "0 clic / 0
        // impression" par querySearchAnalytics(), masquant la vraie panne au
        // lieu de la faire remonter dans le Watchdog / CONFIG_LAST_ERROR.
        if ($httpCode >= 400 || isset($data['error'])) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_api_error', ['error' => $msg]), '', 'SearchConsoleManager');
            return null;
        }

        return $data;
    }

    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_POST           => true,
            \CURLOPT_POSTFIELDS     => http_build_query($data),
            \CURLOPT_TIMEOUT        => 10,
            \CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        if (!$body) {
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.gsc_curl_error', ['error' => curl_error($ch)]), '', 'SearchConsoleManager');
        }
        curl_close($ch);

        if (!$body) {
            return [];
        }
        $result = json_decode($body, true);
        return is_array($result) ? $result : [];
    }
}
