<?php
/**
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
    public static function decrypt(string $value): string
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }

        if (!self::isAvailable()) {
            return '';
        }

        $key = self::loadKey();
        if ($key === '') {
            return '';
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN + 1) {
            return '';
        }

        $iv    = substr($raw, 0, self::IV_LEN);
        $tag   = substr($raw, -self::TAG_LEN);
        $ct    = substr($raw, self::IV_LEN, strlen($raw) - self::IV_LEN - self::TAG_LEN);
        $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain !== false ? $plain : '';
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
        if (strlen($hex) !== 64) {
            return '';
        }
        return (string) hex2bin($hex);
    }
}
