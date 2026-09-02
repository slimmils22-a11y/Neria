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
    const CONFIG_EXPIRY_WARNED_FOR = 'NERIA_LICENSE_EXPIRY_WARNED_FOR';
    // Round 160 : timestamp distinct de CONFIG_LAST_CHECK — mis à jour à
    // CHAQUE appel réseau (succès OU échec), alors que CONFIG_LAST_CHECK
    // n'est renseigné que sur succès (storeToken()) et reste donc figé
    // pendant toute une panne serveur prolongée. Sans ce 2e timestamp,
    // dès que CACHE_TTL (24h) expirait pendant une panne, CHAQUE page vue
    // du site redéclenchait un appel curl bloquant (jusqu'à 10s) — la
    // panne fournisseur se transformait en incident de performance côté
    // client, l'inverse exact de l'objectif documenté sur ce fichier.
    const CONFIG_LAST_ATTEMPT = 'NERIA_LICENSE_LAST_ATTEMPT';

    const API_BASE = 'https://neriasoftware.com/api';

    const CACHE_TTL           = 86400;       // 24h avant nouvelle vérification réseau
    const RETRY_BACKOFF       = 900;          // 15 min avant de retenter après un échec réseau
    const GRACE_NEVER_ACTIVATED_DAYS = 30;    // Scénario A
    const GRACE_REVOKED_DAYS         = 7;     // Scénario B
    // Round 176 : plafond de la grâce "dernière validation réussie"
    // (isWithinGracePeriod() ci-dessous) — auparavant illimitée dès que
    // CONFIG_LAST_CHECK > 0, sans aucune borne de durée. Volontairement
    // généreux (3 mois, très au-dessus de toute panne serveur réaliste)
    // pour ne pas réintroduire le bug déjà documenté (un plafond de 7j
    // faisait retomber une panne prolongée sur le calcul installedAt à 30j,
    // qui échoue pour toute boutique en prod ancienne) — mais borné : sans
    // lui, un client dont la licence a expiré naturellement ET dont le
    // serveur ne répond plus JAMAIS (fournisseur définitivement fermé,
    // domaine abandonné) continuait à envoyer indéfiniment, retirant tout
    // effet réel au mécanisme de licence.
    const GRACE_LAST_CHECK_MAX_DAYS  = 90;

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

            // Round 206 : le jeton signé porte un champ 'domain', mais
            // rien ici ne le comparait jamais au domaine réellement exécuté
            // — seul checkDomainChange() (cron) le faisait, en PLANIFIANT
            // une revalidation réseau ASYNCHRONE sans jamais bloquer
            // l'envoi entre-temps. Copier NERIA_LICENSE_TOKEN/_KEY vers une
            // installation non licenciée (clone de config, staging→prod
            // dupliqué) permettait donc l'envoi immédiat et continu tant
            // que le cron Neria de cette installation ne tournait pas — la
            // signature Ed25519 reste valide (elle signe le jeton, pas le
            // domaine d'exécution). Même scoping multi-boutique que
            // checkDomainChange() : le jeton est global à l'installation,
            // comparé uniquement sur la boutique PAR DÉFAUT (une boutique
            // secondaire a légitimement un domaine différent de celui
            // enregistré à l'activation — pas une fraude).
            $cachedDomain   = is_array($payload) ? (string) ($payload['domain'] ?? '') : '';
            $domainMismatch = $this->isDomainMismatch($cachedDomain);

            if ($valid && !$domainMismatch && ($expires === 0 || $expires > time())) {
                return true;
            }

            // Mismatch de domaine détecté sur la boutique par défaut :
            // refus explicite, PAS de repli sur le délai de grâce — celui-ci
            // existe pour une panne réseau/serveur, pas pour couvrir une
            // réutilisation du jeton sur un domaine non enregistré.
            if ($domainMismatch) {
                return false;
            }
        }

        // Jeton absent, signature invalide, ou licence explicitement
        // invalide/expirée : on retombe sur le délai de grâce.
        return $this->isWithinGracePeriod();
    }

    /**
     * Round 206 : true si le domaine enregistré dans le jeton (à
     * l'activation) diverge du domaine réellement exécuté ici. Même
     * scoping multi-boutique que checkDomainChange() : ne compare que sur
     * la boutique PAR DÉFAUT de l'installation (le jeton est global,
     * comparer sur une boutique secondaire en multi-boutique donnerait un
     * faux positif — son domaine diffère légitimement de celui enregistré
     * à l'activation).
     */
    private function isDomainMismatch(string $cachedDomain): bool
    {
        if ($cachedDomain === '') {
            return false;
        }
        $onDefaultShop = true;
        if (\Shop::isFeatureActive()) {
            $defaultShopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
            $currentShopId = (int) \Context::getContext()->shop->id;
            $onDefaultShop = $defaultShopId <= 0 || $currentShopId === $defaultShopId;
        }
        return $onDefaultShop && $cachedDomain !== $this->currentDomain();
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

        // Jeton expiré naturellement (fin d'abonnement) pile au moment où le
        // serveur de licences est injoignable pour confirmer un renouvellement
        // : sans ce cas, un client établi (NERIA_INSTALLED_AT ancien, donc le
        // scénario A ci-dessous ne s'applique jamais) se retrouvait bloqué
        // immédiatement, sans aucune marge — contrairement au principe
        // annoncé en en-tête de ce fichier ("une panne du serveur de
        // licences ne devient JAMAIS un incident chez le client").
        //
        // lastCheck > 0 : cette valeur n'est écrite que par une validation
        // serveur réussie (validateLicense()/storeToken()), donc sa seule
        // présence prouve que ce client a déjà eu une licence valide. Un
        // plafond de GRACE_REVOKED_DAYS (7j) ferait retomber toute panne
        // serveur prolongée sur le calcul `installedAt` à 30j — qui échoue
        // nécessairement pour une boutique en prod depuis longtemps,
        // bloquant les envois d'un client payant en règle sur la seule base
        // d'une panne côté Neria, en contradiction directe avec le principe
        // documenté. GRACE_LAST_CHECK_MAX_DAYS (round 176, 90j) plafonne
        // néanmoins cette grâce à une durée toujours largement suffisante
        // pour couvrir une panne réaliste, sans la laisser illimitée.
        $lastCheck = (int) \Configuration::get(self::CONFIG_LAST_CHECK);
        if ($lastCheck > 0) {
            return (time() - $lastCheck) < (self::GRACE_LAST_CHECK_MAX_DAYS * 86400);
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
            // traité comme non vérifiable, isEmailSendingAllowed() retombe
            // alors sur isWithinGracePeriod(). Pour un marchand installé
            // depuis plus de 30 jours (donc hors du scénario A ci-dessous),
            // ce repli restait auparavant sans effet — blocage permanent dès
            // le 31e jour, malgré une licence valide. Depuis l'ajout du
            // scénario basé sur CONFIG_LAST_CHECK (dernière vérification
            // réseau réussie, indépendante de sodium — validateLicense() ne
            // l'utilise jamais), le marchand reste en grâce roulante tant
            // que cette vérification périodique continue de réussir.
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
            return ['ok' => false, 'message_key' => $this->mapServerErrorKey((string) ($response['error_key'] ?? ''))];
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
     * Traduit un error_key brut renvoyé par le serveur (ex: 'key_revoked')
     * en une clé de traduction complète du dictionnaire BO. Le serveur
     * envoie des codes courts non préfixés — sans cette table de
     * correspondance, AdminTranslator::t() ne trouve aucune entrée et
     * affiche la clé brute non traduite à l'écran (bug trouvé le
     * 2026-07-24 : aucune des clés déjà en usage n'avait jamais eu de
     * traduction associée).
     */
    private function mapServerErrorKey(string $errorKey): string
    {
        $map = [
            'key_not_found'              => 'license.error_key_not_found',
            'key_revoked'                => 'license.error_key_revoked',
            'key_expired'                => 'license.error_key_expired',
            'invalid_domain'             => 'license.error_invalid_domain',
            'reassignment_too_soon'      => 'license.error_reassignment_too_soon',
            'reassignment_limit_reached' => 'license.error_reassignment_limit_reached',
        ];

        return $map[$errorKey] ?? 'license.activation_failed';
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
            // Round 160 : si CONFIG_KEY est vide mais qu'un CONFIG_LAST_CHECK
            // d'une activation antérieure traîne encore (ex. clé effacée
            // directement en base sans passer par le flux de désinstallation,
            // qui vide les deux ensemble), isWithinGracePeriod() retombait
            // indéfiniment sur "lastCheck > 0 => grâce illimitée" — cette
            // méthode ne revalidera plus JAMAIS tant que CONFIG_KEY reste
            // vide (early return ci-dessus), donc ce statut ne s'auto-
            // corrigeait jamais. Purge donc l'état résiduel d'activation
            // pour que isWithinGracePeriod() retombe sur son AUTRE branche
            // (NERIA_INSTALLED_AT + GRACE_NEVER_ACTIVATED_DAYS), la seule
            // qui a une vraie limite dans le temps pour ce cas.
            if ((int) \Configuration::get(self::CONFIG_LAST_CHECK) > 0) {
                \Configuration::deleteByName(self::CONFIG_LAST_CHECK);
                \Configuration::deleteByName(self::CONFIG_REVOKED_AT);
                \Configuration::deleteByName(self::CONFIG_TOKEN);
            }
            return; // Jamais activé — rien à revalider, le délai de grâce s'applique déjà.
        }

        $lastCheck = (int) \Configuration::get(self::CONFIG_LAST_CHECK);
        if (!$force && $lastCheck && (time() - $lastCheck) < self::CACHE_TTL) {
            return;
        }

        // Round 160 : gate indépendant sur CONFIG_LAST_ATTEMPT (mis à jour
        // sur succès ET échec, contrairement à CONFIG_LAST_CHECK ci-dessus,
        // figé sur le dernier succès) — sans lui, une fois CACHE_TTL expiré
        // pendant une panne serveur prolongée, CHAQUE page vue relançait un
        // appel curl bloquant (jusqu'à 10s) sans aucun répit. $force
        // (revalidation explicite, ex. changement de domaine détecté)
        // outrepasse volontairement ce backoff, comme il outrepasse déjà
        // CACHE_TTL ci-dessus.
        $lastAttempt = (int) \Configuration::get(self::CONFIG_LAST_ATTEMPT);
        if (!$force && $lastAttempt && (time() - $lastAttempt) < self::RETRY_BACKOFF) {
            return;
        }

        // Round 160 : verrou non bloquant sur le cycle lecture-modification-
        // écriture de CONFIG_LAST_CHECK/CONFIG_LAST_ATTEMPT — sans lui, deux
        // requêtes front-office concurrentes arrivant juste après expiration
        // du cache pouvaient toutes deux passer les gardes ci-dessus avant
        // que l'une des deux n'ait eu le temps d'écrire, doublant l'appel
        // réseau au serveur de licence. Impact mineur (pas de corruption de
        // données), mais cohérent avec le même pattern déjà appliqué ailleurs
        // dans le projet (DomainReputationManager, WatchdogManager...).
        if ((int) \Db::getInstance()->getValue("SELECT GET_LOCK('neria_license_validate', 0)", false) !== 1) {
            return;
        }
        \Configuration::updateGlobalValue(self::CONFIG_LAST_ATTEMPT, time());

        try {
            $this->validateLicenseLocked($key);
        } finally {
            \Db::getInstance()->execute("SELECT RELEASE_LOCK('neria_license_validate')");
        }
    }

    private function validateLicenseLocked(string $key): void
    {
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

            // Round 160 : array_key_exists() distingue désormais une vraie
            // révocation serveur (clé 'valid' présente et explicitement
            // false) d'une réponse ok:true malformée/tronquée où 'valid' est
            // simplement ABSENTE — empty() traitait les deux cas de façon
            // identique, démarrant à tort le compte à rebours de grâce de 7
            // jours pour un client dont la licence est en réalité toujours
            // valide, sur la seule base d'un signal serveur ambigu.
            if (array_key_exists('valid', $response) && empty($response['valid']) && !\Configuration::get(self::CONFIG_REVOKED_AT)) {
                // Transition valide → invalide détectée par le serveur
                // (résiliation, remboursement, fraude) : démarre le délai
                // de grâce court (scénario B), une seule fois.
                \Configuration::updateGlobalValue(self::CONFIG_REVOKED_AT, time());
                // Round 276 : warning() -> error() — une révocation confirmée
                // par le serveur démarre un compte à rebours de seulement 7
                // jours (GRACE_REVOKED_DAYS) avant l'arrêt COMPLET de tous
                // les envois email du module. warning() ne déclenche jamais
                // sendImmediateAlert() (contrairement à error()/critical(),
                // cf. WatchdogManager::warning()/error()) — sans alerte
                // immédiate, seul le digest quotidien (opt-in, désactivé par
                // défaut) ou une consultation manuelle du BO pouvait révéler
                // l'incident, le marchand ne découvrant l'extinction totale
                // des envois qu'après coup, sans avoir été prévenu à temps
                // pour agir dans le délai de grâce.
                $this->wd()->error(
                    \WatchdogManager::i18nMsg('watchdog.license_revoked'),
                    '', 'LicenseManager'
                );
            } elseif (!empty($response['valid']) && \Configuration::get(self::CONFIG_REVOKED_AT)) {
                // Transition inverse (revalidation) — auparavant jamais
                // gérée : seule activateLicense() effaçait CONFIG_REVOKED_AT,
                // pas cette vérification périodique. Un incident résolu côté
                // serveur (ex. facturation régularisée) SANS ré-activation
                // manuelle de la clé laissait le marchand voir un badge
                // "révoqué"/compte à rebours de grâce en BO indéfiniment,
                // alors que ses emails repartaient normalement.
                \Configuration::deleteByName(self::CONFIG_REVOKED_AT);
                $this->wd()->info(
                    \WatchdogManager::i18nMsg('watchdog.license_revalidated'),
                    '', 'LicenseManager'
                );
            }

            // Alerte proactive avant expiration naturelle — auparavant
            // 'expires_soon' n'était consommé que par le bandeau passif du
            // BO (navigation.tpl) : un marchand qui n'ouvre pas cette page
            // ne recevait aucune notification avant l'expiration. Un seul
            // avertissement par échéance (CONFIG_EXPIRY_WARNED_FOR mémorise
            // la date déjà signalée) — se réarme automatiquement si le
            // marchand renouvelle (nouvelle date d'expiration différente).
            $expires = (int) \Configuration::get(self::CONFIG_EXPIRES);
            if ($expires > 0 && ($expires - time()) < (15 * 86400) && $expires > time()) {
                $alreadyWarnedFor = (int) \Configuration::get(self::CONFIG_EXPIRY_WARNED_FOR);
                if ($alreadyWarnedFor !== $expires) {
                    \Configuration::updateGlobalValue(self::CONFIG_EXPIRY_WARNED_FOR, $expires);
                    // Round 276 : warning() -> error() — même raison que la
                    // révocation ci-dessus (déclenche sendImmediateAlert()).
                    // C'est justement l'alerte censée être PROACTIVE (avant
                    // l'échéance) : la laisser au niveau warning() la
                    // reléguait au même canal opt-in que le reste, alors
                    // qu'elle doit atteindre le marchand à temps pour
                    // renouveler avant l'expiration réelle.
                    $this->wd()->error(
                        \WatchdogManager::i18nMsg('watchdog.license_expiring_soon', [
                            'date' => date('Y-m-d', $expires),
                        ]),
                        '', 'LicenseManager'
                    );
                }
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
        // La licence est globale à l'installation (un seul jeton, aucun
        // scoping id_shop) — sur une install multi-boutiques où chaque
        // boutique a son propre domaine, comparer au domaine de la
        // boutique actuellement visitée par le visiteur front déclenchait
        // à tort un "changement de domaine" sur chaque page vue d'une
        // boutique secondaire (le domaine enregistré au moment de
        // l'activation étant celui d'une autre boutique) : cache 24h
        // contourné en boucle sur chaque hit, appels réseau en rafale.
        // On ne compare donc que sur la boutique par défaut de
        // l'installation, seule boutique dont le domaine reflète
        // légitimement celui enregistré à l'activation.
        if (\Shop::isFeatureActive()) {
            $defaultShopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
            $currentShopId = (int) \Context::getContext()->shop->id;
            if ($defaultShopId > 0 && $currentShopId !== $defaultShopId) {
                return;
            }
        }

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

        // storeToken() est appelée par activateLicense() ET validateLicense()
        // sur succès — auparavant seule validateLicense() renseignait
        // CONFIG_LAST_CHECK. Une activation réussie suivie d'une panne réseau
        // avant le premier passage cron de validateLicense() laissait
        // lastCheck à 0, faisant retomber isWithinGracePeriod() directement
        // sur le calcul installedAt (30j) — qui échoue immédiatement pour une
        // boutique ancienne malgré une activation qui vient de réussir.
        \Configuration::updateGlobalValue(self::CONFIG_LAST_CHECK, time());
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
        // Round 206 : seul $parts[2] était masqué — les segments 0, 1 et 3
        // (8 des 12 caractères significatifs de la clé) restaient en clair
        // dans les logs Watchdog (activateLicense()) et l'affichage BO
        // (getStatusForDisplay()), contredisant le commentaire de cette
        // méthode ("jamais la clé en clair dans un log"). Seul le préfixe
        // fixe (NERIA) et le dernier segment restent visibles, suffisants
        // pour qu'un marchand/support reconnaisse SA clé sans exposer une
        // fraction significative de sa valeur réelle.
        $parts[1] = str_repeat('•', 4);
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
