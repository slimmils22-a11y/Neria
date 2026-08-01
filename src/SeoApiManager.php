<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — SeoApiManager
 *
 * Intégration optionnelle d'APIs SEO payantes.
 * Fournisseurs supportés : Semrush (trafic/mots-clés/backlinks)
 * et Moz (Domain Authority, Page Authority, spam score).
 * Cache 24h. Le marchand configure son fournisseur + clé API dans le BO.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SeoApiManager
{
    const CONFIG_PROVIDER    = 'NERIA_SEO_PROVIDER';      // 'semrush' | 'moz' | ''
    const CONFIG_SEMRUSH_KEY = 'NERIA_SEMRUSH_API_KEY';
    const CONFIG_MOZ_ACCESS  = 'NERIA_MOZ_ACCESS_ID';
    const CONFIG_MOZ_SECRET  = 'NERIA_MOZ_SECRET_KEY';
    const CONFIG_CACHE       = 'NERIA_SEO_API_CACHE';
    const CONFIG_CACHE_TIME  = 'NERIA_SEO_API_CACHE_TIME';
    const CONFIG_LAST_ERROR    = 'NERIA_SEO_API_LAST_ERROR';
    const CONFIG_LAST_ERROR_AT = 'NERIA_SEO_API_LAST_ERROR_AT';

    const CACHE_TTL = 86400; // 24h

    const SEMRUSH_API = 'https://api.semrush.com/';
    const MOZ_API     = 'https://lsapi.seomoz.com/v2/url_metrics';

    const PROVIDERS = [
        'semrush' => [
            'name'    => 'Semrush',
            'desc'    => 'Trafic organique estimé, mots-clés positionnés, backlinks, score d\'autorité.',
            'pricing' => 'À partir de 129 $/mois — essai gratuit 7 jours.',
            'url'     => 'https://www.semrush.com/api-documentation/',
        ],
        'moz' => [
            'name'    => 'Moz',
            'desc'    => 'Domain Authority (DA), Page Authority, spam score, nombre de backlinks.',
            'pricing' => 'À partir de 99 $/mois — 30 requêtes/mois gratuites via Mozscape API.',
            'url'     => 'https://moz.com/products/api',
        ],
    ];

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

    public function getProvider(): string
    {
        return (string) \Configuration::get(self::CONFIG_PROVIDER);
    }

    public function isConfigured(): bool
    {
        $provider = $this->getProvider();
        if ($provider === 'semrush') {
            return (string) \Configuration::get(self::CONFIG_SEMRUSH_KEY) !== '';
        }
        if ($provider === 'moz') {
            return (string) \Configuration::get(self::CONFIG_MOZ_ACCESS) !== ''
                && (string) \Configuration::get(self::CONFIG_MOZ_SECRET) !== '';
        }
        return false;
    }

    /**
     * Clé de cache suffixée par boutique — le contrôle sur `domain` dans
     * getReport() limitait déjà l'affichage croisé en lecture, mais deux
     * boutiques écrivant en parallèle (runCheck() concurrent) partageaient
     * la même ligne ps_configuration et pouvaient s'écraser mutuellement.
     */
    private function cacheKey(string $base): string
    {
        return $base . '_' . (int) \Context::getContext()->shop->id;
    }

    public function getCachedReport(): ?array
    {
        $cached = \Configuration::get($this->cacheKey(self::CONFIG_CACHE));
        if (!$cached) {
            return null;
        }
        $data = json_decode($cached, true);
        return is_array($data) ? $data : null;
    }

    public function getCacheAge(): ?int
    {
        $t = (int) \Configuration::get($this->cacheKey(self::CONFIG_CACHE_TIME));
        return $t ? (int) round((time() - $t) / 60) : null;
    }

    /**
     * Invalide le cache de LA BOUTIQUE courante (fournisseur/clé modifié en BO).
     */
    public function invalidateCache(): void
    {
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_CACHE));
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_CACHE_TIME));
    }

    /**
     * Dernière erreur API rencontrée (vide si le dernier appel a réussi).
     * Utilisé par HealthCheckManager pour afficher la vraie cause au lieu
     * d'un simple silence — voir aussi SearchConsoleManager::getLastError().
     */
    public function getLastError(): string
    {
        return (string) \Configuration::get(self::CONFIG_LAST_ERROR);
    }

    /**
     * Timestamp Unix du début de la série d'échecs API en cours (null si le
     * dernier appel a réussi). Permet de mesurer une panne persistante.
     */
    public function getLastErrorAt(): ?int
    {
        $t = (int) \Configuration::get(self::CONFIG_LAST_ERROR_AT);
        return $t ?: null;
    }

    private function recordError(string $msg): void
    {
        \Configuration::updateValue(self::CONFIG_LAST_ERROR, $msg);
        if (!\Configuration::get(self::CONFIG_LAST_ERROR_AT)) {
            \Configuration::updateValue(self::CONFIG_LAST_ERROR_AT, time());
        }
    }

    private function clearError(): void
    {
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR);
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR_AT);
    }

    // ============================================================
    // RAPPORT
    // ============================================================

    public function getReport(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $cacheTime = (int) \Configuration::get($this->cacheKey(self::CONFIG_CACHE_TIME));
        if ($cacheTime && (time() - $cacheTime) < self::CACHE_TTL) {
            $data = $this->getCachedReport();
            $currentDomain = parse_url(\Tools::getShopDomainSsl(true), PHP_URL_HOST);
            if ($data && ($data['domain'] ?? null) === $currentDomain) {
                return $data;
            }
        }
        return $this->runCheck();
    }

    public function runCheck(): ?array
    {
        $domain = parse_url(\Tools::getShopDomainSsl(true), PHP_URL_HOST);
        if (!$domain) {
            return null;
        }

        $provider = $this->getProvider();
        if ($provider === 'semrush') {
            $result = $this->fetchSemrush($domain);
        } elseif ($provider === 'moz') {
            $result = $this->fetchMoz($domain);
        } else {
            return null;
        }

        if ($result === null) {
            return null;
        }

        $result['provider']   = $provider;
        $result['checked_at'] = \NeriaTools::formatDate('now', \AdminTranslator::currentLang(), true);

        \Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE),      json_encode($result, JSON_UNESCAPED_UNICODE));
        \Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_TIME), time());
        $this->clearError();

        return $result;
    }

    // ============================================================
    // SEMRUSH
    // ============================================================

    private function fetchSemrush(string $domain): ?array
    {
        $key = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_SEMRUSH_KEY));
        if ($key === '') {
            return null;
        }

        // Domain overview : trafic organique, mots-clés, backlinks
        $overviewUrl = self::SEMRUSH_API . '?' . http_build_query([
            'type'           => 'domain_ranks',
            'key'            => $key,
            'export_columns' => 'Dn,Rk,Or,Ot,Oc,Ad,At,Ac',
            'domain'         => $domain,
            'database'       => 'fr',
        ]);

        $overview = $this->httpGet($overviewUrl);
        if ($overview === null) {
            return null;
        }

        // Semrush retourne du CSV
        $rows = array_filter(array_map('str_getcsv', explode("\n", trim($overview))));
        $rows = array_values($rows);
        if (count($rows) < 2) {
            $prevLang = \AdminTranslator::currentLang();
            \AdminTranslator::setLang(\WatchdogManager::shopLang());
            $this->recordError(\AdminTranslator::t('msg.semrush_invalid_csv'));
            \AdminTranslator::setLang($prevLang);
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.semrush_invalid_csv'), '', 'SeoApiManager');
            return null;
        }

        $headers = array_map('trim', $rows[0]);
        $values  = array_map('trim', $rows[1]);
        // array_combine() lève une ValueError (PHP 8+) au lieu de renvoyer
        // false si les tailles diffèrent (colonne manquante/en trop dans
        // l'export CSV Semrush) — vérifier avant l'appel plutôt que de
        // planter toute la requête.
        if (count($headers) !== count($values)) {
            return null;
        }
        $row = array_combine($headers, $values);
        if (!$row) {
            return null;
        }

        // Top 5 mots-clés organiques
        $kwUrl = self::SEMRUSH_API . '?' . http_build_query([
            'type'           => 'domain_organic',
            'key'            => $key,
            'export_columns' => 'Ph,Po,Nq,Cp,Ur',
            'domain'         => $domain,
            'database'       => 'fr',
            'display_limit'  => 10,
            'display_sort'   => 'nq_desc',
        ]);
        $kwRaw = $this->httpGet($kwUrl);
        $keywords = [];
        if ($kwRaw) {
            $kwRows = array_filter(array_map('str_getcsv', explode("\n", trim($kwRaw))));
            $kwRows = array_values($kwRows);
            if (count($kwRows) > 1) {
                $kwHeaders = array_map('trim', $kwRows[0]);
                foreach (array_slice($kwRows, 1) as $kwRow) {
                    $kwValues = array_map('trim', $kwRow);
                    if (count($kwHeaders) !== count($kwValues)) {
                        continue;
                    }
                    $kwData = array_combine($kwHeaders, $kwValues);
                    if ($kwData) {
                        // Même mismatch que ci-dessus : export_columns
                        // demandait les codes courts 'Ph,Po,Nq,Cp,Ur', pas
                        // les libellés complets.
                        $keywords[] = [
                            'keyword'  => $kwData['Ph'] ?? '',
                            'position' => (int) ($kwData['Po'] ?? 0),
                            'volume'   => (int) ($kwData['Nq'] ?? 0),
                            'url'      => $kwData['Ur'] ?? '',
                        ];
                    }
                }
            }
        }

        // Les en-têtes CSV renvoyés par Semrush sont les CODES COURTS
        // demandés dans export_columns ('Dn,Rk,Or,Ot,Oc,Ad,At,Ac' ci-dessus),
        // PAS les libellés complets ('Rank', 'Organic Keywords'...) — ce
        // code lisait les mauvaises clés depuis le début : $row['Rank']
        // etc. étaient TOUJOURS absentes, donc ?? 0 déclenchait
        // systématiquement, affichant "0 partout" en silence quel que soit
        // le vrai trafic du domaine, sans jamais lever d'erreur.
        $expectedKeys = ['Rk', 'Or', 'Ot', 'Oc', 'Ad', 'At'];
        if (count(array_intersect($expectedKeys, array_keys($row))) === 0) {
            $this->wd()->warning(
                \WatchdogManager::i18nMsg('watchdog.semrush_unexpected_columns', ['headers' => implode(',', array_keys($row))]),
                '', 'SeoApiManager'
            );
        }

        $result = [
            'domain'           => $domain,
            'authority_score'  => (int) ($row['Rk'] ?? 0),
            'organic_keywords' => (int) ($row['Or'] ?? 0),
            'organic_traffic'  => (int) ($row['Ot'] ?? 0),
            'organic_cost'     => (float) ($row['Oc'] ?? 0),
            'paid_keywords'    => (int) ($row['Ad'] ?? 0),
            'paid_traffic'     => (int) ($row['At'] ?? 0),
            'keywords'         => $keywords,
        ];

        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.semrush_loaded', ['domain' => $domain, 'traffic' => $result['organic_traffic'], 'keywords' => $result['organic_keywords']]),
            '', 'SeoApiManager'
        );

        return $result;
    }

    // ============================================================
    // MOZ
    // ============================================================

    private function fetchMoz(string $domain): ?array
    {
        $accessId  = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_MOZ_ACCESS));
        $secretKey = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_MOZ_SECRET));
        if ($accessId === '' || $secretKey === '') {
            return null;
        }

        $target = $domain . '/';

        $ch = curl_init(self::MOZ_API);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_POST           => true,
            \CURLOPT_POSTFIELDS     => json_encode(['targets' => [$target]]),
            \CURLOPT_TIMEOUT        => 15,
            \CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            \CURLOPT_USERPWD        => $accessId . ':' . $secretKey,
            \CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!$body || $httpCode !== 200) {
            // curl_error() capturé — même motif que httpGet() (Semrush) :
            // sans lui, un timeout/échec DNS/certificat invalide affichait
            // toujours "HTTP 0" au marchand, impossible à diagnostiquer sans
            // accès aux logs serveur.
            $prevLang = \AdminTranslator::currentLang();
            \AdminTranslator::setLang(\WatchdogManager::shopLang());
            $this->recordError($curlErr !== '' ? $curlErr : \AdminTranslator::tVars('msg.moz_http_error', ['code' => $httpCode]));
            \AdminTranslator::setLang($prevLang);
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.moz_http_error', ['code' => $httpCode]), '', 'SeoApiManager');
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['results'])) {
            return null;
        }

        $r = $data['results'][0] ?? [];

        $result = [
            'domain'              => $domain,
            'domain_authority'    => (int)   ($r['domain_authority']    ?? 0),
            'page_authority'      => (int)   ($r['page_authority']      ?? 0),
            'spam_score'          => (float) ($r['spam_score']          ?? 0),
            'links_to_root'       => (int)   ($r['links_to_root_domain'] ?? 0),
            'root_domains_to_root'=> (int)   ($r['root_domains_to_root_domain'] ?? 0),
        ];

        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.moz_loaded', ['domain' => $domain, 'da' => $result['domain_authority'], 'pa' => $result['page_authority'], 'spam' => $result['spam_score']]),
            '', 'SeoApiManager'
        );

        return $result;
    }

    // ============================================================
    // HTTP
    // ============================================================

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 15,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_USERAGENT      => 'Neria/1.0',
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!$body || $httpCode !== 200) {
            $this->recordError($curlErr !== '' ? $curlErr : ('HTTP ' . $httpCode));
            return null;
        }

        return $body;
    }
}
