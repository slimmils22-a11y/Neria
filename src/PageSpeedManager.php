<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
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
    const CONFIG_LAST_ATTEMPT       = 'NERIA_PAGESPEED_LAST_ATTEMPT';
    const CONFIG_LAST_ATTEMPT_RATE_LIMITED = 'NERIA_PAGESPEED_LAST_ATTEMPT_RL';

    const CACHE_TTL = 86400; // 24h
    // Round 171 : cooldown court après un échec TOTAL (mobile ET desktop),
    // distinct du CACHE_TTL de 24h — un échec ne doit pas bloquer les
    // tentatives suivantes aussi longtemps qu'un succès, mais doit quand
    // même empêcher un rappel immédiat à chaque chargement de page BO
    // pendant une panne/un quota dépassé (risque d'épuisement de quota).
    const FAILURE_COOLDOWN = 900; // 15 min
    // Round 171 : un 429 (quota dépassé) est un signal de rate-limit
    // explicite, pas une simple erreur transitoire — cooldown plus long
    // pour laisser la fenêtre de quota Google se réinitialiser.
    const RATE_LIMIT_COOLDOWN = 3600; // 1h
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

    /**
     * Clé de cache suffixée par boutique — le contrôle sur `url` dans
     * getReport() limitait déjà l'affichage croisé en lecture, mais deux
     * boutiques écrivant en parallèle (runCheck() concurrent) partageaient
     * la même ligne ps_configuration et pouvaient s'écraser mutuellement.
     * Une clé distincte par boutique élimine la fenêtre de course.
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
     * Invalide le cache de LA BOUTIQUE courante (clé/URL modifiée en BO).
     */
    public function invalidateCache(): void
    {
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_CACHE));
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_CACHE_TIME));
    }

    /**
     * Round 186 : ajoutée pour que neria.php efface bien la clé scopée par
     * boutique (cacheKey()) à la sauvegarde du formulaire — auparavant un
     * Configuration::deleteByName('NERIA_PAGESPEED_LAST_ERROR') direct
     * effaçait la clé GLOBALE, jamais écrite par cette classe (qui n'écrit
     * que via cacheKey() depuis le round 134), donc sans aucun effet réel.
     */
    public function clearError(): void
    {
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_LAST_ERROR));
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_LAST_ERROR_AT));
    }

    /**
     * Dernière erreur API rencontrée (vide si le dernier appel a réussi).
     * Utilisé par HealthCheckManager pour afficher la vraie cause.
     */
    public function getLastError(): string
    {
        // Round 134 : scopé par boutique via cacheKey(), comme
        // CONFIG_CACHE/CONFIG_CACHE_TIME — même bug de fond que celui corrigé
        // pour SeoApiManager au round 133 (état d'erreur global alors que le
        // cache est déjà scopé par boutique). Sur une install multi-boutiques,
        // une erreur de la boutique A pouvait être effacée par un succès de
        // la boutique B, ou inversement affichée à tort sur A.
        return (string) \Configuration::get($this->cacheKey(self::CONFIG_LAST_ERROR));
    }

    /**
     * Timestamp Unix du début de la série d'échecs API en cours (null si le
     * dernier appel a réussi). Permet de mesurer une panne persistante.
     */
    public function getLastErrorAt(): ?int
    {
        $t = (int) \Configuration::get($this->cacheKey(self::CONFIG_LAST_ERROR_AT));
        return $t ?: null;
    }

    /** Round 171 : messages d'erreur par stratégie (mobile/desktop), accumulés
     * le temps d'un runCheck() puis combinés — voir recordStrategyError(). */
    private array $strategyErrorParts = [];
    /** Round 171 : vrai si au moins une stratégie a reçu un 429 (quota Google
     * dépassé) durant le runCheck() en cours — déclenche un cooldown plus
     * long avant la prochaine tentative (voir RATE_LIMIT_COOLDOWN). */
    private bool $rateLimited = false;

    private function recordError(string $msg): void
    {
        \Configuration::updateValue($this->cacheKey(self::CONFIG_LAST_ERROR), $msg);
        if (!\Configuration::get($this->cacheKey(self::CONFIG_LAST_ERROR_AT))) {
            \Configuration::updateValue($this->cacheKey(self::CONFIG_LAST_ERROR_AT), time());
        }
    }

    /**
     * Round 171 : recordError() était appelée séparément par chaque appel
     * fetchStrategy() (mobile puis desktop) et ÉCRASAIT systématiquement le
     * message précédent — si mobile échouait pour une raison (ex. timeout
     * réseau, fréquent) et desktop pour une autre (ex. clé invalide), seul
     * le message desktop (écrit en dernier) survivait dans CONFIG_LAST_ERROR,
     * cachant complètement la cause réelle de l'échec mobile au marchand/à
     * HealthCheckManager. Les deux messages sont désormais accumulés ici
     * (préfixés par la stratégie) puis combinés par runCheck().
     */
    private function recordStrategyError(string $strategy, string $msg): void
    {
        $this->strategyErrorParts[] = "[{$strategy}] {$msg}";
        if (!\Configuration::get($this->cacheKey(self::CONFIG_LAST_ERROR_AT))) {
            \Configuration::updateValue($this->cacheKey(self::CONFIG_LAST_ERROR_AT), time());
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
        $cacheTime = (int) \Configuration::get($this->cacheKey(self::CONFIG_CACHE_TIME));
        if ($cacheTime && (time() - $cacheTime) < self::CACHE_TTL) {
            $data = $this->getCachedReport();
            if ($data && ($data['url'] ?? null) === $this->getTargetUrl()) {
                return $data;
            }
        }
        // Round 171 : un échec TOTAL précédent (mobile ET desktop) n'écrivait
        // jamais CONFIG_CACHE_TIME — chaque appel suivant (chaque chargement
        // de page BO) rappelait donc l'API PageSpeed en direct sans aucun
        // backoff, risquant d'épuiser le quota gratuit pendant une panne/un
        // rate-limit. Cooldown court indépendant du cache de succès.
        if ($this->isInFailureCooldown()) {
            return null;
        }
        return $this->runCheck();
    }

    /**
     * Vrai si un échec TOTAL récent (mobile ET desktop) doit encore bloquer
     * une nouvelle tentative — extrait de getReport() pour rester testable
     * sans dépendre d'un appel réseau réel.
     */
    private function isInFailureCooldown(): bool
    {
        $lastAttempt = (int) \Configuration::get($this->cacheKey(self::CONFIG_LAST_ATTEMPT));
        if (!$lastAttempt) {
            return false;
        }
        $wasRateLimited = (bool) \Configuration::get($this->cacheKey(self::CONFIG_LAST_ATTEMPT_RATE_LIMITED));
        $cooldown = $wasRateLimited ? self::RATE_LIMIT_COOLDOWN : self::FAILURE_COOLDOWN;
        return (time() - $lastAttempt) < $cooldown;
    }

    /**
     * Force un appel API et met à jour le cache.
     */
    public function getTargetUrl(): string
    {
        // Round 182 : lecture scopée par boutique (cacheKey()), comme le
        // reste de la classe — auparavant lu en global, une URL
        // personnalisée configurée par la boutique A était appliquée à
        // TOUTES les boutiques de l'installation : la boutique B se voyait
        // analyser et afficher le rapport PageSpeed du site de A, sous sa
        // propre clé de cache scopée, sans aucun avertissement.
        $custom = trim((string) \Configuration::get($this->cacheKey(self::CONFIG_TARGET_URL)));
        if ($custom !== '') {
            return rtrim($custom, '/') . '/';
        }
        return \Tools::getShopDomainSsl(true) . '/';
    }

    public function runCheck(): ?array
    {
        $rawKey = (string) \Configuration::get(self::CONFIG_API_KEY);
        $key    = \CryptoManager::decrypt($rawKey);
        if ($key === '') {
            // Distingue "jamais configuré" (rawKey vide, silence normal) de
            // "clé enregistrée mais illisible" (decrypt() a échoué — clé de
            // chiffrement maîtresse altérée) : sans ce contrôle, un
            // marchand voyant "pas de données PageSpeed" n'avait aucun
            // indice pour diagnostiquer lequel des deux cas s'appliquait.
            if ($rawKey !== '') {
                $prevLang = \AdminTranslator::currentLang();
                \AdminTranslator::setLang(\WatchdogManager::shopLang((int) \Context::getContext()->shop->id));
                $this->recordError(\AdminTranslator::t('msg.pagespeed_key_unreadable'));
                \AdminTranslator::setLang($prevLang);
                $this->wd()->warning(
                    \WatchdogManager::i18nMsg('watchdog.pagespeed_key_unreadable'),
                    '', 'PageSpeedManager'
                );
            }
            return null;
        }

        $shopUrl = $this->getTargetUrl();

        $this->strategyErrorParts = [];
        $this->rateLimited        = false;

        $mobile  = $this->fetchStrategy($shopUrl, $key, 'mobile');
        $desktop = $this->fetchStrategy($shopUrl, $key, 'desktop');

        if ($mobile === null && $desktop === null) {
            \Configuration::updateValue($this->cacheKey(self::CONFIG_LAST_ATTEMPT), time());
            \Configuration::updateValue($this->cacheKey(self::CONFIG_LAST_ATTEMPT_RATE_LIMITED), $this->rateLimited ? 1 : 0);
            // Round 171 : combine les messages mobile+desktop (au lieu du
            // dernier écrit qui écrasait silencieusement l'autre) — voir
            // recordStrategyError().
            if ($this->strategyErrorParts) {
                $this->recordError(implode(' | ', $this->strategyErrorParts));
            }
            return null;
        }
        // Une des deux stratégies a fonctionné : la prochaine tentative
        // n'a plus besoin d'attendre le cooldown d'échec.
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_LAST_ATTEMPT));
        \Configuration::deleteByName($this->cacheKey(self::CONFIG_LAST_ATTEMPT_RATE_LIMITED));

        // Round 150 : le nettoyage de CONFIG_LAST_ERROR/_AT n'est plus fait
        // à l'intérieur de fetchStrategy() (une par appel), mais ICI, une
        // seule fois, et seulement si les DEUX stratégies ont réussi.
        // Auparavant, un échec mobile (timeout fréquent — émulation réseau
        // throttlée, souvent plus lente que desktop) suivi d'un succès
        // desktop effaçait l'erreur que mobile venait juste d'enregistrer :
        // le rapport global était considéré "réussi" (une seule stratégie
        // en échec ne fait pas échouer runCheck()) et mis en cache avec
        // mobile=null, sans aucune trace exploitable de la cause.
        if ($mobile !== null && $desktop !== null) {
            \Configuration::deleteByName($this->cacheKey(self::CONFIG_LAST_ERROR));
            \Configuration::deleteByName($this->cacheKey(self::CONFIG_LAST_ERROR_AT));
        } elseif ($this->strategyErrorParts) {
            // Échec partiel (une seule stratégie) : la cause de l'échec
            // reste visible, sans faire échouer runCheck() globalement.
            $this->recordError(implode(' | ', $this->strategyErrorParts));
        }

        $result = [
            'url'        => $shopUrl,
            'mobile'     => $mobile,
            'desktop'    => $desktop,
            'checked_at' => \NeriaTools::formatDate('now', \AdminTranslator::currentLang(), true),
        ];

        \Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE),      json_encode($result, JSON_UNESCAPED_UNICODE));
        \Configuration::updateValue($this->cacheKey(self::CONFIG_CACHE_TIME), time());

        $perfM = $result['mobile']['perf']   ?? '—';
        $perfD = $result['desktop']['perf']  ?? '—';
        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.pagespeed_analyzed', ['url' => $shopUrl, 'mobile' => $perfM, 'desktop' => $perfD]),
            '', 'PageSpeedManager'
        );

        return $result;
    }

    // ============================================================
    // PRIVÉ
    // ============================================================

    /**
     * @phpstan-impure appelle l'API PageSpeed (curl_exec) et mute
     * $this->rateLimited/$this->strategyErrorParts sur échec.
     */
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

        $prevLang = \AdminTranslator::currentLang();
        \AdminTranslator::setLang(\WatchdogManager::shopLang((int) \Context::getContext()->shop->id));

        if (!$body) {
            $this->recordStrategyError($strategy, \AdminTranslator::tVars('msg.pagespeed_network_error', ['error' => $curlErr]));
            \AdminTranslator::setLang($prevLang);
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.pagespeed_network_error_wd', ['strategy' => $strategy, 'error' => $curlErr]), '', 'PageSpeedManager');
            return null;
        }
        if ($httpCode === 400) {
            $errData = json_decode($body, true);
            $msg = $errData['error']['message'] ?? \AdminTranslator::t('msg.pagespeed_invalid_request');
            $this->recordStrategyError($strategy, $msg);
            \AdminTranslator::setLang($prevLang);
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.pagespeed_http400', ['strategy' => $strategy, 'msg' => $msg]), '', 'PageSpeedManager');
            return null;
        }
        if ($httpCode === 403) {
            $this->recordStrategyError($strategy, \AdminTranslator::t('msg.pagespeed_api_key_invalid'));
            \AdminTranslator::setLang($prevLang);
            $this->wd()->error(\WatchdogManager::i18nMsg('watchdog.pagespeed_http403', ['strategy' => $strategy]), '', 'PageSpeedManager');
            return null;
        }
        if ($httpCode === 429) {
            // Round 171 : distingue le 429 (quota Google dépassé) des autres
            // erreurs HTTP génériques — déclenche un cooldown plus long
            // (RATE_LIMIT_COOLDOWN) plutôt que le cooldown court générique,
            // pour laisser la fenêtre de quota se réinitialiser au lieu de
            // retenter (et échouer) toutes les 15 minutes.
            $this->rateLimited = true;
            $this->recordStrategyError($strategy, \AdminTranslator::tVars('msg.pagespeed_http_error', ['code' => $httpCode]));
            \AdminTranslator::setLang($prevLang);
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.pagespeed_http_other', ['strategy' => $strategy, 'code' => $httpCode]), '', 'PageSpeedManager');
            return null;
        }
        if ($httpCode !== 200) {
            $this->recordStrategyError($strategy, \AdminTranslator::tVars('msg.pagespeed_http_error', ['code' => $httpCode]));
            \AdminTranslator::setLang($prevLang);
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.pagespeed_http_other', ['strategy' => $strategy, 'code' => $httpCode]), '', 'PageSpeedManager');
            return null;
        }
        \AdminTranslator::setLang($prevLang);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->recordStrategyError($strategy, \AdminTranslator::t('msg.pagespeed_invalid_request'));
            $this->wd()->warning(\WatchdogManager::i18nMsg('watchdog.pagespeed_http400', ['strategy' => $strategy, 'msg' => 'invalid JSON']), '', 'PageSpeedManager');
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
