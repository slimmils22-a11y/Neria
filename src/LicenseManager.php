<?php
/**
 * © 2026 NeriaSoftware - All rights reserved
 *
 * NERIA — LicenseManager
 *
 * Vérification de licence auprès du serveur externe NeriaSoftware.
 *
 * Principe fondateur (non négociable) : une panne du serveur de licences
 * ne devient JAMAIS un incident chez le client. Si le serveur est
 * injoignable, le dernier jeton connu en cache reste valable indéfiniment,
 * sans bascule automatique vers "invalide".
 *
 * Seul levier de blocage : les nouveaux envois d'emails (voir
 * isEmailSendingAllowed(), appelée depuis les points de vérification
 * dispersés dans neria.php/QueueManager/BehavioralCronManager/
 * ManualSendManager/OrderTriggersManager — jamais une valeur brute en
 * config, toujours la signature du jeton en cache).
 *
 * Le module ne contient ni la logique de génération de clé, ni la clé API
 * Sellers Addons — uniquement un identifiant produit fixe, un écran
 * d'activation, et la clé PUBLIQUE de vérification de signature
 * (non secrète, cf. cahier des charges section 8bis).
 *
 * @author  Neria
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class LicenseManager
{
    // ============================================================
    // CONFIGURATION
    // ============================================================

    const CONFIG_KEY          = 'NERIA_LICENSE_KEY';
    const CONFIG_TOKEN        = 'NERIA_LICENSE_TOKEN';
    const CONFIG_LAST_CHECK   = 'NERIA_LICENSE_LAST_CHECK';
    const CONFIG_EXPIRES      = 'NERIA_LICENSE_EXPIRES';
    const CONFIG_PLAN         = 'NERIA_LICENSE_PLAN';
    const CONFIG_SOURCE       = 'NERIA_LICENSE_SOURCE';
    const CONFIG_GRACE_UNTIL  = 'NERIA_LICENSE_GRACE_UNTIL';
    const CONFIG_REVOKED_AT   = 'NERIA_LICENSE_REVOKED_AT';
    const CONFIG_LAST_ERROR   = 'NERIA_LICENSE_LAST_ERROR';

    const API_BASE = 'https://neriasoftware.com/api';

    const CACHE_TTL           = 86400;       // 24h avant nouvelle vérification réseau
    const GRACE_NEVER_ACTIVATED_DAYS = 30;    // Scénario A
    const GRACE_REVOKED_DAYS         = 7;     // Scénario B

    /**
     * Clé PUBLIQUE Ed25519 (base64) de vérification de signature — non
     * secrète, cf. cahier des charges section 8bis. Clé de production
     * réelle, générée sur le serveur neriasoftware.com (clé privée
     * conservée hors webroot, jamais exposée).
     */
    const PUBLIC_KEY_B64 = 'bZP6dNVzJM1QpqeYp3WK9EWHJLrINnvvVl98L+JxpNs=';

    /** @var Neria */
    private $module;

    /** @var \Db */
    private $db;

    /** @var \WatchdogManager|null */
    private $watchdog = null;

    public function __construct(\Neria $module)
    {
        $this->module = $module;
        $this->db     = \Db::getInstance();
    }

    private function wd(): \WatchdogManager
    {
        if ($this->watchdog === null) {
            $this->watchdog = new \WatchdogManager($this->module);
        }
        return $this->watchdog;
    }

    // ============================================================
    // API PUBLIQUE — VERROU (aucun appel réseau, lecture cache seule)
    // ============================================================

    /**
     * Unique point de décision du verrou : les envois d'emails sont-ils
     * autorisés ? Appelée par les 5 points de vérification dispersés.
     * Ne fait jamais d'appel réseau — lit uniquement le jeton en cache et
     * sa signature, avec calcul du délai de grâce.
     */
    public function isEmailSendingAllowed(): bool
    {
        $token = (string) \Configuration::get(self::CONFIG_TOKEN);

        if ($token !== '' && $this->verifyTokenSignature($token)) {
            $payload = json_decode($token, true);
            $expires = is_array($payload) ? (int) ($payload['expires'] ?? 0) : 0;
            $valid   = is_array($payload) ? !empty($payload['valid']) : false;

            if ($valid && ($expires === 0 || $expires > time())) {
                return true;
            }
        }

        // Jeton absent, signature invalide, ou licence explicitement
        // invalide/expirée : on retombe sur le délai de grâce.
        return $this->isWithinGracePeriod();
    }

    /**
     * Délai de grâce : jamais activé (30j depuis l'installation) ou
     * révoqué après activation (7j depuis la révocation, déjà notifié par
     * email au moment de la révocation).
     */
    private function isWithinGracePeriod(): bool
    {
        $revokedAt = (int) \Configuration::get(self::CONFIG_REVOKED_AT);
        if ($revokedAt > 0) {
            return (time() - $revokedAt) < (self::GRACE_REVOKED_DAYS * 86400);
        }

        $installedAt = (int) strtotime((string) \Configuration::get('NERIA_INSTALLED_AT'));
        if ($installedAt <= 0) {
            // Pas de date d'installation exploitable (install très ancienne
            // avant l'ajout de cette clé) — ne jamais bloquer sur une
            // donnée absente, seulement sur une expiration confirmée.
            return true;
        }

        return (time() - $installedAt) < (self::GRACE_NEVER_ACTIVATED_DAYS * 86400);
    }

    /**
     * Vérifie la signature Ed25519 d'un jeton (JSON contenant 'valid',
     * 'expires', 'domain', 'plan', 'source', 'sig' en base64). Rapide,
     * aucun appel réseau — c'est cette méthode que les points dispersés
     * appellent, jamais une valeur brute en config.
     */
    public function verifyTokenSignature(?string $token = null): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            // Extension sodium indisponible (hébergement très ancien) —
            // fail-closed sur la signature spécifiquement : le jeton est
            // traité comme non vérifiable, mais isEmailSendingAllowed()
            // retombe alors sur le délai de grâce, jamais un blocage
            // immédiat pour une simple absence d'extension serveur.
            return false;
        }

        $token = $token ?? (string) \Configuration::get(self::CONFIG_TOKEN);
        if ($token === '') {
            return false;
        }

        $payload = json_decode($token, true);
        if (!is_array($payload) || !isset($payload['sig'])) {
            return false;
        }

        $signature = base64_decode((string) $payload['sig'], true);
        if ($signature === false) {
            return false;
        }

        $unsigned = $payload;
        unset($unsigned['sig']);
        // Encodage canonique (clés triées) pour que le message signé par
        // le serveur et celui reconstruit ici soient identiques.
        ksort($unsigned);
        $message = json_encode($unsigned, JSON_UNESCAPED_SLASHES);
        if ($message === false) {
            return false;
        }

        $publicKey = base64_decode(self::PUBLIC_KEY_B64, true);
        if ($publicKey === false) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ============================================================
    // ACTIVATION / VALIDATION (appels réseau)
    // ============================================================

    /**
     * Active une nouvelle clé de licence. Valide le format en local avant
     * tout appel réseau.
     *
     * @return array{ok:bool, message_key:string}
     */
    public function activateLicense(string $rawKey): array
    {
        $key = strtoupper(trim($rawKey));
        if (!preg_match('/^NERIA-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key)) {
            return ['ok' => false, 'message_key' => 'license.invalid_format'];
        }

        $domain = $this->currentDomain();
        $response = $this->callLicenseApi('activate', [
            'key'    => $key,
            'domain' => $domain,
        ]);

        if ($response === null) {
            return ['ok' => false, 'message_key' => 'license.activation_network_error'];
        }

        if (empty($response['ok'])) {
            return ['ok' => false, 'message_key' => (string) ($response['error_key'] ?? 'license.activation_failed')];
        }

        $this->storeToken($response);
        \Configuration::updateGlobalValue(self::CONFIG_KEY, $key);
        \Configuration::deleteByName(self::CONFIG_REVOKED_AT);

        $this->wd()->info(
            \WatchdogManager::i18nMsg('watchdog.license_activated', ['key' => $this->maskKey($key)]),
            '', 'LicenseManager'
        );

        return ['ok' => true, 'message_key' => 'license.activation_success'];
    }

    /**
     * Revalide la licence auprès du serveur si le cache 24h est expiré.
     * Tolérance de panne totale : toute erreur réseau conserve le jeton
     * en cache tel quel, log Watchdog en avertissement uniquement, jamais
     * de bascule vers "invalide".
     */
    public function validateLicense(bool $force = false): void
    {
        $key = (string) \Configuration::get(self::CONFIG_KEY);
        if ($key === '') {
            return; // Jamais activé — rien à revalider, le délai de grâce s'applique déjà.
        }

        $lastCheck = (int) \Configuration::get(self::CONFIG_LAST_CHECK);
        if (!$force && $lastCheck && (time() - $lastCheck) < self::CACHE_TTL) {
            return;
        }

        $response = $this->callLicenseApi('validate', [
            'key'    => $key,
            'domain' => $this->currentDomain(),
        ]);

        if ($response === null) {
            // Panne/injoignable : on ne touche PAS au jeton en cache — le
            // dernier statut connu reste valable indéfiniment. On journalise
            // uniquement pour l'éditeur (invisible au marchand).
            $this->wd()->warning(
                \WatchdogManager::i18nMsg('watchdog.license_check_unreachable'),
                '', 'LicenseManager'
            );
            return;
        }

        if (!empty($response['ok'])) {
            $this->storeToken($response);
            \Configuration::updateGlobalValue(self::CONFIG_LAST_CHECK, time());

            if (empty($response['valid']) && !\Configuration::get(self::CONFIG_REVOKED_AT)) {
                // Transition valide → invalide détectée par le serveur
                // (résiliation, remboursement, fraude) : démarre le délai
                // de grâce court (scénario B), une seule fois.
                \Configuration::updateGlobalValue(self::CONFIG_REVOKED_AT, time());
                $this->wd()->warning(
                    \WatchdogManager::i18nMsg('watchdog.license_revoked'),
                    '', 'LicenseManager'
                );
            }
        } else {
            // Réponse serveur explicite mais négative (ex: clé introuvable) —
            // différent d'une panne : on journalise mais on ne touche pas
            // non plus au jeton, la signature vérifiée localement reste
            // l'unique source de vérité pour le verrou.
            $this->wd()->warning(
                \WatchdogManager::i18nMsg('watchdog.license_check_error', [
                    'error' => (string) ($response['error_key'] ?? 'unknown'),
                ]),
                '', 'LicenseManager'
            );
        }
    }

    /**
     * Compare le domaine courant (normalisé) à celui du jeton en cache —
     * appelé périodiquement (cron) pour déclencher une revalidation
     * lorsque la boutique change de domaine. La logique de confirmation
     * par email est entièrement gérée côté serveur.
     */
    public function checkDomainChange(): void
    {
        $token = (string) \Configuration::get(self::CONFIG_TOKEN);
        if ($token === '') {
            return;
        }
        $payload = json_decode($token, true);
        $cachedDomain = is_array($payload) ? (string) ($payload['domain'] ?? '') : '';
        if ($cachedDomain !== '' && $cachedDomain !== $this->currentDomain()) {
            $this->validateLicense(true);
        }
    }

    private function storeToken(array $response): void
    {
        $token = json_encode($response, JSON_UNESCAPED_SLASHES);
        \Configuration::updateGlobalValue(self::CONFIG_TOKEN, $token !== false ? $token : '');
        \Configuration::updateGlobalValue(self::CONFIG_EXPIRES, (int) ($response['expires'] ?? 0));
        \Configuration::updateGlobalValue(self::CONFIG_PLAN, (string) ($response['plan'] ?? ''));
        \Configuration::updateGlobalValue(self::CONFIG_SOURCE, (string) ($response['source'] ?? 'direct'));
    }

    // ============================================================
    // AFFICHAGE BO
    // ============================================================

    /**
     * Statut lisible pour l'onglet Aide/Accueil du BO — clé toujours masquée.
     */
    public function getStatusForDisplay(): array
    {
        $key   = (string) \Configuration::get(self::CONFIG_KEY);
        $token = (string) \Configuration::get(self::CONFIG_TOKEN);
        $valid = $this->isEmailSendingAllowed();

        $expires = (int) \Configuration::get(self::CONFIG_EXPIRES);
        $revokedAt = (int) \Configuration::get(self::CONFIG_REVOKED_AT);

        $graceDaysLeft = null;
        if ($key === '') {
            $installedAt = (int) strtotime((string) \Configuration::get('NERIA_INSTALLED_AT'));
            if ($installedAt > 0) {
                $graceDaysLeft = max(0, self::GRACE_NEVER_ACTIVATED_DAYS - (int) floor((time() - $installedAt) / 86400));
            }
        } elseif ($revokedAt > 0) {
            $graceDaysLeft = max(0, self::GRACE_REVOKED_DAYS - (int) floor((time() - $revokedAt) / 86400));
        }

        return [
            'has_key'          => $key !== '',
            'key_masked'       => $key !== '' ? $this->maskKey($key) : '',
            'plan'             => (string) \Configuration::get(self::CONFIG_PLAN),
            'source'           => (string) \Configuration::get(self::CONFIG_SOURCE),
            'expires_at'       => $expires > 0 ? date('Y-m-d', $expires) : '',
            'expires_soon'     => $expires > 0 && ($expires - time()) < (15 * 86400) && $expires > time(),
            'sending_allowed'  => $valid,
            'in_grace_period'  => $graceDaysLeft !== null,
            'grace_days_left'  => $graceDaysLeft,
            'revoked'          => $revokedAt > 0,
        ];
    }

    /**
     * Masque une clé de licence pour affichage/log : NERIA-A3F2-••••-2P8Q.
     * Jamais la clé en clair dans un log, cohérent avec le reste du module.
     */
    public function maskKey(string $key): string
    {
        $parts = explode('-', $key);
        if (count($parts) !== 4) {
            return '••••';
        }
        $parts[2] = str_repeat('•', 4);
        return implode('-', $parts);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function currentDomain(): string
    {
        $domain = (string) \Tools::getShopDomain();
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^www\.#', '', $domain);
        return (string) $domain;
    }

    /**
     * Appelle l'API du serveur de licences. Retourne null en cas de
     * panne/injoignable (tolérance de panne — l'appelant ne doit alors
     * jamais toucher au jeton en cache), ou le tableau JSON décodé sinon
     * (y compris en cas d'erreur métier explicite, ex. clé inconnue).
     */
    private function callLicenseApi(string $action, array $params): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $url = self::API_BASE . '/' . $action . '.php';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(array_merge($params, [
                'module_version' => $this->module->version,
            ])),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            \Configuration::updateGlobalValue(self::CONFIG_LAST_ERROR, $error);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            \Configuration::updateGlobalValue(self::CONFIG_LAST_ERROR, 'HTTP ' . $httpCode);
            return null;
        }

        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : null;
    }
}
