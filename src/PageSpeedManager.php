<?php
/**
 * NERIA — PageSpeedManager
 *
 * Intégration Google PageSpeed Insights v5 (clé API gratuite).
 * Récupère les scores Lighthouse (perf/accessibilité/SEO/best-practices)
 * et les Core Web Vitals (LCP, CLS, TBT) pour mobile et desktop.
 * Cache 24h en base de données.
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PageSpeedManager
{
    const CONFIG_API_KEY    = 'NERIA_PAGESPEED_API_KEY';
    const CONFIG_TARGET_URL = 'NERIA_PAGESPEED_TARGET_URL'; // URL custom (optionnel)
    const CONFIG_CACHE      = 'NERIA_PAGESPEED_CACHE';
    const CONFIG_CACHE_TIME = 'NERIA_PAGESPEED_CACHE_TIME';
    const CONFIG_LAST_ERROR    = 'NERIA_PAGESPEED_LAST_ERROR';
    const CONFIG_LAST_ERROR_AT = 'NERIA_PAGESPEED_LAST_ERROR_AT';

    const CACHE_TTL = 86400; // 24h
    const API_URL   = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

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
        return (string) \Configuration::get(self::CONFIG_API_KEY) !== '';
    }

    public function getCachedReport(): ?array
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
     * Utilisé par HealthCheckManager pour afficher la vraie cause.
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

    // ============================================================
    // RAPPORT
    // ============================================================

    /**
     * Retourne le rapport depuis le cache si < 24h, sinon le rafraîchit.
     */
    public function getReport(): ?array
    {
        $cacheTime = (int) \Configuration::get(self::CONFIG_CACHE_TIME);
        if ($cacheTime && (time() - $cacheTime) < self::CACHE_TTL) {
            $data = $this->getCachedReport();
            if ($data) {
                return $data;
            }
        }
        return $this->runCheck();
    }

    /**
     * Force un appel API et met à jour le cache.
     */
    public function getTargetUrl(): string
    {
        $custom = trim((string) \Configuration::get(self::CONFIG_TARGET_URL));
        if ($custom !== '') {
            return rtrim($custom, '/') . '/';
        }
        return \Tools::getShopDomainSsl(true) . '/';
    }

    public function runCheck(): ?array
    {
        $key = \CryptoManager::decrypt((string) \Configuration::get(self::CONFIG_API_KEY));
        if ($key === '') {
            return null;
        }

        $shopUrl = $this->getTargetUrl();

        $mobile  = $this->fetchStrategy($shopUrl, $key, 'mobile');
        $desktop = $this->fetchStrategy($shopUrl, $key, 'desktop');

        if ($mobile === null && $desktop === null) {
            return null;
        }

        $result = [
            'url'        => $shopUrl,
            'mobile'     => $mobile,
            'desktop'    => $desktop,
            'checked_at' => date('d/m/Y H:i'),
        ];

        \Configuration::updateValue(self::CONFIG_CACHE,      json_encode($result, JSON_UNESCAPED_UNICODE));
        \Configuration::updateValue(self::CONFIG_CACHE_TIME, time());

        $perfM = $result['mobile']['perf']   ?? '—';
        $perfD = $result['desktop']['perf']  ?? '—';
        $this->wd()->info(
            "PageSpeed analysé : {$shopUrl} — Mobile perf {$perfM}/100, Desktop perf {$perfD}/100.",
            '', 'PageSpeedManager'
        );

        return $result;
    }

    // ============================================================
    // PRIVÉ
    // ============================================================

    private function fetchStrategy(string $url, string $key, string $strategy): ?array
    {
        $apiUrl = self::API_URL
            . '?url='      . urlencode($url)
            . '&key='      . urlencode($key)
            . '&strategy=' . $strategy
            . '&category=performance&category=accessibility&category=seo&category=best-practices';

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 30,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_USERAGENT      => 'Neria/1.0',
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!$body) {
            $msg = 'PageSpeed [{' . $strategy . '}] — erreur réseau : ' . $curlErr . ' — URL non accessible publiquement.';
            $this->recordError('Erreur réseau : ' . $curlErr . ' — L\'URL doit être publiquement accessible par Google.');
            $this->wd()->warning($msg, '', 'PageSpeedManager');
            return null;
        }
        if ($httpCode === 400) {
            $errData = json_decode($body, true);
            $msg = $errData['error']['message'] ?? 'Requête invalide (HTTP 400)';
            $this->recordError($msg);
            $this->wd()->warning('PageSpeed [' . $strategy . '] HTTP 400 : ' . $msg, '', 'PageSpeedManager');
            return null;
        }
        if ($httpCode === 403) {
            $msg = 'Clé API invalide ou PageSpeed Insights API non activée (HTTP 403).';
            $this->recordError($msg);
            $this->wd()->error('PageSpeed [' . $strategy . '] HTTP 403 : clé API invalide ou API non activée.', '', 'PageSpeedManager');
            return null;
        }
        if ($httpCode !== 200) {
            $msg = 'Erreur HTTP ' . $httpCode . ' — vérifiez la clé API et l\'URL cible.';
            $this->recordError($msg);
            $this->wd()->warning('PageSpeed [' . $strategy . '] HTTP ' . $httpCode, '', 'PageSpeedManager');
            return null;
        }
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR);
        \Configuration::deleteByName(self::CONFIG_LAST_ERROR_AT);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        return $this->parseResult($data);
    }

    private function parseResult(array $data): array
    {
        $cats   = $data['lighthouseResult']['categories'] ?? [];
        $audits = $data['lighthouseResult']['audits'] ?? [];

        $score = static function (string $key) use ($cats): ?int {
            return isset($cats[$key]['score'])
                ? (int) round((float) $cats[$key]['score'] * 100)
                : null;
        };

        $auditVal = static function (string $key) use ($audits): string {
            return $audits[$key]['displayValue'] ?? '—';
        };

        $auditStatus = static function (string $key) use ($audits): string {
            $s = $audits[$key]['score'] ?? null;
            if ($s === null) {
                return 'unknown';
            }
            $f = (float) $s;
            if ($f >= 0.9) {
                return 'good';
            }
            if ($f >= 0.5) {
                return 'needs-improvement';
            }
            return 'poor';
        };

        $colorForScore = static function (?int $s): string {
            if ($s === null) {
                return '#999';
            }
            if ($s >= 90) {
                return '#16a34a';
            }
            if ($s >= 50) {
                return '#d97706';
            }
            return '#dc2626';
        };

        return [
            'perf'         => $score('performance'),
            'access'       => $score('accessibility'),
            'seo'          => $score('seo'),
            'best'         => $score('best-practices'),
            'perf_color'   => $colorForScore($score('performance')),
            'access_color' => $colorForScore($score('accessibility')),
            'seo_color'    => $colorForScore($score('seo')),
            'best_color'   => $colorForScore($score('best-practices')),
            'lcp'          => $auditVal('largest-contentful-paint'),
            'lcp_status'   => $auditStatus('largest-contentful-paint'),
            'cls'          => $auditVal('cumulative-layout-shift'),
            'cls_status'   => $auditStatus('cumulative-layout-shift'),
            'tbt'          => $auditVal('total-blocking-time'),
            'tbt_status'   => $auditStatus('total-blocking-time'),
            'fcp'          => $auditVal('first-contentful-paint'),
            'si'           => $auditVal('speed-index'),
        ];
    }
}
