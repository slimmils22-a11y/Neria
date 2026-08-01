<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — PostmasterManager
 *
 * Intégration Gmail Postmaster Tools API (OAuth 2.0).
 * Affiche dans le BO les vraies données de réputation Google :
 * taux de spam signalé, réputation domaine/IP, succès SPF/DKIM/DMARC.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PostmasterManager
{
    const CONFIG_CLIENT_ID     = 'NERIA_POSTMASTER_CLIENT_ID';
    const CONFIG_CLIENT_SECRET = 'NERIA_POSTMASTER_CLIENT_SECRET';
    const CONFIG_ACCESS_TOKEN  = 'NERIA_POSTMASTER_ACCESS_TOKEN';
    const CONFIG_REFRESH_TOKEN = 'NERIA_POSTMASTER_REFRESH_TOKEN';
    const CONFIG_TOKEN_EXPIRY  = 'NERIA_POSTMASTER_TOKEN_EXPIRY';
    const CONFIG_CACHE         = 'NERIA_POSTMASTER_CACHE';
    const CONFIG_CACHE_TIME    = 'NERIA_POSTMASTER_CACHE_TIME';
    const CONFIG_CACHE_HOST    = 'NERIA_POSTMASTER_CACHE_HOST';
    const CONFIG_RETURN_URL    = 'NERIA_POSTMASTER_RETURN_URL';
    const CONFIG_OAUTH_STATE   = 'NERIA_POSTMASTER_OAUTH_STATE';
    const CONFIG_LAST_ERROR    = 'NERIA_POSTMASTER_LAST_ERROR';
    const CONFIG_LAST_ERROR_AT = 'NERIA_POSTMASTER_LAST_ERROR_AT';

    const CACHE_TTL  = 3600; // 1h
    const SCOPE      = 'https://www.googleapis.com/auth/postmaster.readonly';
    const API_BASE   = 'https://gmailpostmastertools.googleapis.com/v1';
    const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL  = 'https://oauth2.googleapis.com/token';

    private Neria $module;
    private ?\WatchdogManager $wdm = null;

    private function wd(): \WatchdogManager
    {
        if ($this->wdm === null) {
            $this->wdm = new \WatchdogManager($this->module);
        }
        return $this->wdm;
    }

    public function __construct(Neria $module)
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
            . 'index.php?fc=module&module=neria&controller=oauth';
    }

    // ============================================================
    // OAUTH
    // ============================================================

    /**
     * Génère l'URL d'autorisation Google et stocke le state + l'URL de retour BO.
     *
     * Plusieurs flux peuvent être lancés avant qu'un premier ne se termine
     * (deux onglets, double clic) — un seul state global écraserait le
     * précédent et ferait échouer son retour même si Google a bien accepté
     * l'autorisation. Stocke donc une petite liste {state: [return_url, ts]},
     * avec purge des entrées de plus de 10 min à chaque appel.
     */
    public function getAuthUrl(string $returnUrl = ''): string
    {
        $state   = bin2hex(random_bytes(16));
        $pending = $this->loadPendingStates();
        $pending[$state] = ['return_url' => $returnUrl, 'ts' => time()];
        $this->savePendingStates($pending);

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
     * Retourne l'URL de retour BO associée à ce state, sans consommer l'entrée
     * (utilisé par le front controller avant même de savoir si le code est
     * valide, y compris sur le chemin d'erreur Google).
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
        // Purge des flux de plus de 10 min (jamais terminés — abandon, échec)
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

    /**
     * Échange le code d'autorisation contre les tokens. Appelé par le front controller oauth.
     */
    public function handleCallback(string $code, string $state): bool
    {
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
                \AdminTranslator::setLang(\WatchdogManager::shopLang());
                $detail = \AdminTranslator::t('watchdog.empty_response');
                \AdminTranslator::setLang($prevLang);
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.postmaster_oauth_failed', ['error' => $detail]), '', 'PostmasterManager');
            return false;
        }

        \Configuration::updateValue(self::CONFIG_ACCESS_TOKEN,  \CryptoManager::encrypt($response['access_token']));
        \Configuration::updateValue(self::CONFIG_REFRESH_TOKEN, \CryptoManager::encrypt($response['refresh_token'] ?? ''));
        \Configuration::updateValue(self::CONFIG_TOKEN_EXPIRY,  time() + ($response['expires_in'] ?? 3600) - 60);
        // Le state a déjà été consommé (retiré de la liste des flux en
        // attente) plus haut — ne PAS faire deleteByName ici, ça effacerait
        // aussi les autres flux OAuth potentiellement encore en attente.

        $this->wd()->info(\WatchdogManager::i18nMsg('watchdog.postmaster_oauth_success'), '', 'PostmasterManager');
        return true;
    }

    public function disconnect(): void
    {
        foreach ([
            self::CONFIG_ACCESS_TOKEN,
            self::CONFIG_REFRESH_TOKEN,
            self::CONFIG_TOKEN_EXPIRY,
            self::CONFIG_CACHE,
            self::CONFIG_CACHE_TIME,
            self::CONFIG_OAUTH_STATE,
        ] as $key) {
            \Configuration::deleteByName($key);
        }
    }

    // ============================================================
    // DONNÉES API
    // ============================================================

    /**
     * Retourne les stats en cache ou les rafraîchit via l'API.
     */
    public function getStats(): ?array
    {
        $cacheTime = (int) \Configuration::get(self::CONFIG_CACHE_TIME);
        if ($cacheTime && (time() - $cacheTime) < self::CACHE_TTL) {
            // Le cache est stocké en config GLOBALE (pas par boutique) : sur
            // une install multi-boutique où chaque boutique a un domaine
            // différent, la boutique A déclenchait la récupération et
            // mettait en cache SES domaines Postmaster, puis la boutique B
            // lisait ce même cache pendant 1h — affichant le score de
            // réputation d'envoi de A comme si c'était le sien, pouvant
            // déclencher de fausses alertes Watchdog sur B. Même bug déjà
            // trouvé et corrigé dans SearchConsoleManager::getStats(), non
            // répliqué ici jusqu'à présent.
            $cachedHost = (string) \Configuration::get(self::CONFIG_CACHE_HOST);
            if ($cachedHost !== '' && $cachedHost === $this->getShopHost()) {
                $cached = \Configuration::get(self::CONFIG_CACHE);
                if ($cached) {
                    $data = json_decode($cached, true);
                    if (is_array($data)) {
                        return $data;
                    }
                }
            }
        }

        return $this->fetchAndCache();
    }

    private function getShopHost(): string
    {
        return (string) parse_url(\Tools::getShopDomainSsl(true), PHP_URL_HOST);
    }

    public function getCachedStats(): ?array
    {
        $cached = \Configuration::get(self::CONFIG_CACHE);
        if (!$cached) {
            return null;
        }
        $data = json_decode($cached, true);
        return is_array($data) ? $data : null;
    }

    public function getCacheAge(): ?int
    {
        $t = (int) \Configuration::get(self::CONFIG_CACHE_TIME);
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

    private function fetchAndCache(): ?array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        $domains = $this->apiGet('/domains', $token);
        if ($domains === null) {
            // Échec réseau/API — erreur déjà journalisée dans apiGet() et
            // dans CONFIG_LAST_ERROR (lu par HealthCheckManager).
            return null;
        }
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR);
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR_AT);
        if (empty($domains['domains'])) {
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.postmaster_no_domain'), '', 'PostmasterManager');
            return [];
        }

        // Ne retient que le(s) domaine(s) correspondant à la boutique
        // courante — auparavant TOUS les domaines vérifiés du compte
        // Google Postmaster Tools du marchand étaient récupérés et mélangés
        // sans filtre. Si le marchand a plusieurs sites/domaines enregistrés
        // sous le même compte Google (fréquent), les réputations d'envoi de
        // domaines n'ayant rien à voir avec CETTE boutique se retrouvaient
        // affichées ensemble, et $results[0] (ordre non garanti par l'API)
        // servait de base au log Watchdog — potentiellement le mauvais
        // domaine.
        $shopHost = $this->getShopHost();
        $results  = [];
        foreach ($domains['domains'] as $domain) {
            $name = $domain['name'] ?? '';
            if (!$name) {
                continue;
            }
            $domainName = str_replace('domains/', '', $name);
            if ($shopHost !== '' && stripos($shopHost, $domainName) === false && stripos($domainName, $shopHost) === false) {
                continue;
            }
            $stats = $this->fetchDomainStats($name, $token);
            if ($stats !== null) {
                $results[] = $stats;
            }
        }

        if (empty($results)) {
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.postmaster_no_matching_domain', ['host' => $shopHost]), '', 'PostmasterManager');
        }

        \Configuration::updateValue(self::CONFIG_CACHE,      json_encode($results, JSON_UNESCAPED_UNICODE));
        \Configuration::updateValue(self::CONFIG_CACHE_TIME, time());
        \Configuration::updateValue(self::CONFIG_CACHE_HOST, $shopHost);

        if (!empty($results)) {
            $first = $results[0];
            $this->wd()->info(
                \WatchdogManager::i18nMsg('watchdog.postmaster_loaded', [
                    'domain'     => $first['domain'],
                    'reputation' => $first['domain_reputation'] ?? '?',
                    'spam'       => sprintf('%.4f', $first['spam_rate'] ?? 0),
                    'spf'        => sprintf('%.1f', $first['spf_success'] ?? 0),
                    'dkim'       => sprintf('%.1f', $first['dkim_success'] ?? 0),
                ]),
                '',
                'PostmasterManager'
            );
        }

        return $results;
    }

    private function fetchDomainStats(string $domainResource, string $token): ?array
    {
        // Essaie les 7 derniers jours jusqu'à trouver des données
        for ($i = 1; $i <= 7; $i++) {
            $date = date('Ymd', strtotime("-{$i} days"));
            $stat = $this->apiGet("/{$domainResource}/trafficStats/{$date}", $token);

            if (!is_array($stat) || empty($stat['domainReputation'])) {
                continue;
            }

            $domainName = str_replace('domains/', '', $domainResource);

            return [
                'domain'             => $domainName,
                'date'               => \NeriaTools::formatDate("-{$i} days", \AdminTranslator::currentLang()),
                'domain_reputation'  => $stat['domainReputation'] ?? null,
                'spam_rate'          => $this->extractSpamRate($stat),
                'spf_success'        => isset($stat['spfSuccessRatio'])  ? round((float) $stat['spfSuccessRatio']  * 100, 1) : null,
                'dkim_success'       => isset($stat['dkimSuccessRatio']) ? round((float) $stat['dkimSuccessRatio'] * 100, 1) : null,
                'dmarc_success'      => isset($stat['dmarcSuccessRatio'])? round((float) $stat['dmarcSuccessRatio']* 100, 1) : null,
                'tls_outbound'       => isset($stat['outboundEncryptionRatio']) ? round((float) $stat['outboundEncryptionRatio'] * 100, 1) : null,
                'ip_reputations'     => $stat['ipReputations'] ?? [],
                'delivery_errors'    => $stat['deliveryErrors'] ?? [],
            ];
        }

        return null;
    }

    private function extractSpamRate(array $stat): ?float
    {
        if (!empty($stat['spamRateHistory'])) {
            $rates = array_column($stat['spamRateHistory'], 'spamRatio');
            $rates = array_filter($rates, fn ($r) => $r !== null);
            if ($rates) {
                return round((float) (array_sum($rates) / count($rates)) * 100, 4);
            }
        }
        return null;
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
        $refresh = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_REFRESH_TOKEN));
        if ($refresh === '') {
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
            // HealthCheckManager::checkOAuthFreshness() — sans ça, un
            // rafraîchissement échoué en boucle chaque nuit ne remontait que
            // dans le journal Watchdog, jamais dans le statut lu par le BO.
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            // 'invalid_grant' = le marchand a révoqué l'accès côté Google (ou
            // le refresh token a expiré) — jamais transitoire, un nouveau
            // rafraîchissement échouera à l'identique indéfiniment. On efface
            // le refresh token pour qu'isConnected() cesse de mentir "connecté"
            // et que le BO invite explicitement à ré-autoriser.
            if ($errCode === 'invalid_grant') {
                \Configuration::deleteByName(self::CONFIG_REFRESH_TOKEN);
            }
            $this->wd()->error(\WatchdogManager::i18nMsg('watchdog.postmaster_token_invalid'), '', 'PostmasterManager');
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
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$body) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        // Google renvoie un corps JSON valide même en erreur (403, 400...) —
        // sans ce contrôle, une erreur d'API (ex. API désactivée dans Google
        // Cloud Console) était silencieusement confondue avec "aucun domaine
        // trouvé", masquant la vraie cause.
        if ($httpCode >= 400 || isset($data['error'])) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
            // Ne pose le timestamp de début qu'au premier échec de la série —
            // préserve la date de départ réelle pour mesurer une panne
            // persistante, même si le message d'erreur change entre-temps.
            if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
                \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
            }
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.postmaster_api_error', ['error' => $msg]), '', 'PostmasterManager');
            return null;
        }

        return $data;
    }

    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if (!$body) {
            return [];
        }
        $result = json_decode($body, true);
        return is_array($result) ? $result : [];
    }
}
