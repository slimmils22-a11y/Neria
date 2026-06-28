<?php
/**
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
    const CONFIG_RETURN_URL    = 'NERIA_POSTMASTER_RETURN_URL';
    const CONFIG_OAUTH_STATE   = 'NERIA_POSTMASTER_OAUTH_STATE';

    const CACHE_TTL  = 3600; // 1h
    const SCOPE      = 'https://www.googleapis.com/auth/postmaster.readonly';
    const API_BASE   = 'https://gmailpostmastertools.googleapis.com/v1';
    const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL  = 'https://oauth2.googleapis.com/token';

    private Neria $module;

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
            . '/index.php?fc=module&module=neria&controller=oauth';
    }

    // ============================================================
    // OAUTH
    // ============================================================

    /**
     * Génère l'URL d'autorisation Google et stocke le state + l'URL de retour BO.
     */
    public function getAuthUrl(string $returnUrl = ''): string
    {
        $state = bin2hex(random_bytes(16));
        \Configuration::updateValue(self::CONFIG_OAUTH_STATE,  $state);
        \Configuration::updateValue(self::CONFIG_RETURN_URL,   $returnUrl);

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
     * Échange le code d'autorisation contre les tokens. Appelé par le front controller oauth.
     */
    public function handleCallback(string $code, string $state): bool
    {
        $savedState = (string) \Configuration::get(self::CONFIG_OAUTH_STATE);
        if ($state === '' || $state !== $savedState) {
            return false;
        }

        $response = $this->httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => (string) \Configuration::get(self::CONFIG_CLIENT_ID),
            'client_secret' => (string) \Configuration::get(self::CONFIG_CLIENT_SECRET),
            'redirect_uri'  => $this->getRedirectUri(),
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($response['access_token'])) {
            return false;
        }

        \Configuration::updateValue(self::CONFIG_ACCESS_TOKEN,  $response['access_token']);
        \Configuration::updateValue(self::CONFIG_REFRESH_TOKEN, $response['refresh_token'] ?? '');
        \Configuration::updateValue(self::CONFIG_TOKEN_EXPIRY,  time() + ($response['expires_in'] ?? 3600) - 60);
        \Configuration::deleteByName(self::CONFIG_OAUTH_STATE);

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
            $cached = \Configuration::get(self::CONFIG_CACHE);
            if ($cached) {
                $data = json_decode($cached, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        return $this->fetchAndCache();
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

    private function fetchAndCache(): ?array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        $domains = $this->apiGet('/domains', $token);
        if (empty($domains['domains'])) {
            return [];
        }

        $results = [];
        foreach ($domains['domains'] as $domain) {
            $name = $domain['name'] ?? '';
            if (!$name) {
                continue;
            }
            $stats = $this->fetchDomainStats($name, $token);
            if ($stats !== null) {
                $results[] = $stats;
            }
        }

        \Configuration::updateValue(self::CONFIG_CACHE,      json_encode($results, JSON_UNESCAPED_UNICODE));
        \Configuration::updateValue(self::CONFIG_CACHE_TIME, time());

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
                'date'               => date('d/m/Y', strtotime("-{$i} days")),
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
            $token = (string) \Configuration::get(self::CONFIG_ACCESS_TOKEN);
            if ($token !== '') {
                return $token;
            }
        }

        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): ?string
    {
        $refresh = (string) \Configuration::get(self::CONFIG_REFRESH_TOKEN);
        if ($refresh === '') {
            return null;
        }

        $response = $this->httpPost(self::TOKEN_URL, [
            'client_id'     => (string) \Configuration::get(self::CONFIG_CLIENT_ID),
            'client_secret' => (string) \Configuration::get(self::CONFIG_CLIENT_SECRET),
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]);

        if (empty($response['access_token'])) {
            return null;
        }

        \Configuration::updateValue(self::CONFIG_ACCESS_TOKEN, $response['access_token']);
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
        $body = curl_exec($ch);
        curl_close($ch);

        if (!$body) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
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
