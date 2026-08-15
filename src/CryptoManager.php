<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * NERIA — CryptoManager
 *
 * Chiffrement symétrique AES-256-GCM des données sensibles au repos.
 * La clé est générée une seule fois à l'installation et stockée dans
 * ps_configuration. Elle n'est jamais exposée dans le back-office.
 *
 * Format des valeurs chiffrées (base64 après le préfixe ENC:) :
 *   [ IV 12 octets ][ ciphertext ][ tag GCM 16 octets ]
 *
 * Rétrocompatibilité : toute valeur sans préfixe ENC: est retournée
 * telle quelle par decrypt() — les données existantes restent lisibles.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CryptoManager
{
    const CONFIG_KEY = 'NERIA_ENCRYPTION_KEY';
    const CIPHER     = 'aes-256-gcm';
    const PREFIX     = 'ENC:';
    const IV_LEN     = 12;
    const TAG_LEN    = 16;

    /**
     * Clés `ps_configuration` contenant un secret (mot de passe, jeton OAuth,
     * clé API tierce) — doivent toujours être chiffrées (préfixe ENC:) si
     * non vides. Liste unique partagée par upgrade/upgrade-1.0.17.php (qui
     * chiffre rétroactivement) et HealthCheckManager::checkSecretsEncrypted()
     * (qui vérifie qu'aucune n'est repassée en clair) — éviter que ces deux
     * listes divergent si une nouvelle intégration ajoute un secret.
     */
    const SENSITIVE_CONFIG_KEYS = [
        'NERIA_BOUNCE_IMAP_PASS',
        'NERIA_BOUNCE_WEBHOOK_SECRET',
        'NERIA_POSTMASTER_CLIENT_SECRET',
        'NERIA_POSTMASTER_ACCESS_TOKEN',
        'NERIA_POSTMASTER_REFRESH_TOKEN',
        'NERIA_SC_CLIENT_SECRET',
        'NERIA_SC_ACCESS_TOKEN',
        'NERIA_SC_REFRESH_TOKEN',
        'NERIA_WEBHOOK_SECRET',
        'NERIA_DEEPL_KEY',
        'NERIA_PAGESPEED_API_KEY',
        'NERIA_SEMRUSH_API_KEY',
        'NERIA_MOZ_ACCESS_ID',
        'NERIA_MOZ_SECRET_KEY',
        'NERIA_LITMUS_KEY',
        'NERIA_EOA_KEY',
    ];

    // ============================================================
    // API PUBLIQUE
    // ============================================================

    /**
     * Chiffre une chaîne. Retourne ENC:<base64> ou la chaîne d'origine
     * si openssl n'est pas disponible ou si la clé ne peut pas être lue.
     */
    public static function encrypt(string $plain): string
    {
        if (!self::isAvailable() || $plain === '') {
            return $plain;
        }

        $key = self::loadKey();
        if ($key === '') {
            return $plain;
        }

        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);

        if ($ct === false) {
            return $plain;
        }

        return self::PREFIX . base64_encode($iv . $ct . $tag);
    }

    /**
     * Déchiffre une valeur chiffrée par encrypt().
     * Si la valeur ne commence pas par ENC: elle est retournée telle quelle
     * (rétrocompatibilité avec les données stockées avant le chiffrement).
     */
    /**
     * Round 172 : vrai si le DERNIER appel à decrypt() a échoué (valeur
     * chiffrée présente mais non récupérable), false sinon — y compris
     * quand decrypt() n'a rien eu à déchiffrer (valeur déjà en clair).
     * decrypt() retourne '' aussi bien sur échec que sur "jamais configuré"
     * (chaîne vide en entrée) : sans ce drapeau, les deux cas sont
     * indiscernables pour l'appelant, qui traite alors silencieusement une
     * intégration cassée (OAuth, IMAP...) comme "non configurée".
     * Vérification optionnelle : les appelants existants restent
     * fonctionnels sans jamais la consulter (aucun changement de signature).
     */
    private static bool $lastDecryptFailed = false;

    public static function lastDecryptFailed(): bool
    {
        return self::$lastDecryptFailed;
    }

    public static function decrypt(string $value): string
    {
        self::$lastDecryptFailed = false;

        if (!self::isEncrypted($value)) {
            return $value;
        }

        if (!self::isAvailable()) {
            self::logDecryptFailure('openssl indisponible');
            self::$lastDecryptFailed = true;
            return '';
        }

        $key = self::loadKey();
        if ($key === '') {
            self::logDecryptFailure('clé de chiffrement absente ou illisible');
            self::$lastDecryptFailed = true;
            return '';
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN + 1) {
            self::logDecryptFailure('valeur chiffrée corrompue (base64/longueur invalide)');
            self::$lastDecryptFailed = true;
            return '';
        }

        $iv    = substr($raw, 0, self::IV_LEN);
        $tag   = substr($raw, -self::TAG_LEN);
        $ct    = substr($raw, self::IV_LEN, strlen($raw) - self::IV_LEN - self::TAG_LEN);
        $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            self::logDecryptFailure('échec openssl_decrypt (clé rotée/corrompue ou tag GCM invalide)');
            self::$lastDecryptFailed = true;
            return '';
        }

        return $plain;
    }

    /**
     * Un échec de déchiffrement ne doit jamais être silencieux : sans trace,
     * il est indiscernable d'une valeur simplement vide dans les stats/audits.
     * Journalisé via le logger natif PrestaShop (toujours disponible, aucune
     * dépendance au contexte module) — au plus une fois PAR MOTIF DISTINCT
     * par requête (round 172 : auparavant une seule fois au total, quelle
     * que soit la cause — si une rotation de clé ratée cassait à la fois
     * une clé API et un token OAuth dans la même requête, un seul des deux
     * échecs était tracé, sous-estimant l'ampleur réelle de la panne).
     */
    private static function logDecryptFailure(string $reason): void
    {
        static $loggedReasons = [];
        if (isset($loggedReasons[$reason]) || !class_exists('\PrestaShopLogger')) {
            return;
        }
        $loggedReasons[$reason] = true;

        \PrestaShopLogger::addLog(
            '[Neria] CryptoManager::decrypt() a échoué : ' . $reason,
            3,
            null,
            'Configuration',
            null,
            true
        );
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Génère et stocke la clé si elle n'existe pas encore.
     * Appelé une seule fois à l'installation du module.
     */
    public static function generateAndStoreKey(): void
    {
        if (!\Configuration::get(self::CONFIG_KEY)) {
            \Configuration::updateValue(self::CONFIG_KEY, bin2hex(random_bytes(32)));
        }
    }

    /**
     * Vérifie que l'extension openssl et le cipher GCM sont disponibles.
     */
    public static function isAvailable(): bool
    {
        static $cache = null;
        if ($cache === null) {
            $cache = function_exists('openssl_encrypt')
                && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
        }
        return $cache;
    }

    // ============================================================
    // UTILITAIRE PRIVÉ
    // ============================================================

    private static function loadKey(): string
    {
        $hex = (string) \Configuration::get(self::CONFIG_KEY);
        // ctype_xdigit() en plus de la longueur — une clé corrompue en base
        // (édition manuelle, restauration partielle) mais conservant 64
        // caractères non-hexadécimaux faisait émettre un warning PHP par
        // hex2bin() ("Hexadecimal input string is not well-formed"), visible
        // dans le BO si display_errors est activé côté hébergement.
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            return '';
        }
        return (string) hex2bin($hex);
    }
}
