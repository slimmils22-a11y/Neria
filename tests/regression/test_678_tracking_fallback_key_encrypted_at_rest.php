<?php
/**
 * Régression : `NERIA_TRACKING_FALLBACK_KEY` (clé de secours HMAC générée
 * aléatoirement pour signer le tracking clics/ouvertures — voir
 * `NeriaTools::trackingSignKey()`, utilisée UNIQUEMENT en dernier recours
 * quand `NERIA_ENCRYPTION_KEY` et `_COOKIE_KEY_`/`_NEW_COOKIE_KEY_` sont
 * indisponibles) était stockée en clair en base — absente de
 * `CryptoManager::SENSITIVE_CONFIG_KEYS`, elle échappait donc à la fois au
 * chiffrement rétroactif (`upgrade-1.0.17.php`) et à l'audit
 * `HealthCheckManager::checkSecretsEncrypted()`, alors qu'elle est un
 * secret cryptographique de même nature que les autres entrées de cette
 * liste (ex. `NERIA_WEBHOOK_SECRET`).
 *
 * Bug identifié le 10/09/2026 (round 333, audit CryptoManager/
 * SignatureGenerator) — traité hors round après arbitrage explicite avec
 * l'utilisateur (option A retenue : chiffrer plutôt que documenter comme
 * choix délibéré).
 *
 * Corrigé le 10/09/2026 (hors round) : `NERIA_TRACKING_FALLBACK_KEY`
 * ajoutée à `SENSITIVE_CONFIG_KEYS` ; `NeriaTools::trackingSignKey()`
 * chiffre désormais la clé à l'écriture (`CryptoManager::encrypt()`) et la
 * déchiffre à la lecture (`CryptoManager::decrypt()`, qui gère nativement
 * la rétrocompatibilité — une valeur déjà en clair est retournée telle
 * quelle, donc les installations existantes ne perdent pas leur clé
 * actuelle).
 *
 * Test comportemental réel : force le repli sur la clé de secours (aucune
 * `NERIA_ENCRYPTION_KEY` ni `_COOKIE_KEY_` disponibles — simulé via
 * Reflection sur les constantes n'est pas possible en PHP, donc le test
 * vérifie directement le comportement de stockage/relecture de la clé de
 * secours elle-même, indépendamment du chemin qui y mène) : supprime la
 * clé existante, appelle une fonction équivalente au bloc de génération,
 * vérifie que la valeur stockée en base est bien chiffrée (préfixe ENC:),
 * et qu'elle se déchiffre en un hex de 64 caractères valide.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    neria_assert(
        in_array('NERIA_TRACKING_FALLBACK_KEY', CryptoManager::SENSITIVE_CONFIG_KEYS, true),
        "CryptoManager::SENSITIVE_CONFIG_KEYS n'inclut plus NERIA_TRACKING_FALLBACK_KEY — régression du correctif du 10/09/2026 (hors round) : cette clé échapperait de nouveau au chiffrement rétroactif et à l'audit checkSecretsEncrypted()"
    );

    // Vérification structurelle complémentaire : NeriaTools::trackingSignKey()
    // (méthode réelle, non ré-invoquée directement ci-dessous car elle
    // dépend de constantes _COOKIE_KEY_/_NEW_COOKIE_KEY_ non redéfinissables
    // en test) doit bien chiffrer/déchiffrer via CryptoManager à la lecture
    // ET à l'écriture de ce repli, pas seulement au niveau du stockage brut
    // testé ci-dessous.
    $ntSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/NeriaTools.php');
    neria_assert($ntSrc !== false, 'Impossible de lire src/NeriaTools.php');
    $posFn = strpos($ntSrc, 'public static function trackingSignKey(): string');
    neria_assert($posFn !== false, 'trackingSignKey() introuvable — jeu de test invalide');
    $body = substr($ntSrc, $posFn, 1600);
    neria_assert(
        strpos($body, '\CryptoManager::decrypt($fallbackStored)') !== false,
        "NeriaTools::trackingSignKey() ne déchiffre plus NERIA_TRACKING_FALLBACK_KEY via CryptoManager::decrypt() à la lecture — régression du correctif du 10/09/2026 (hors round)"
    );
    neria_assert(
        strpos($body, '\CryptoManager::encrypt($newHex)') !== false,
        "NeriaTools::trackingSignKey() ne chiffre plus NERIA_TRACKING_FALLBACK_KEY via CryptoManager::encrypt() à l'écriture — régression du correctif du 10/09/2026 (hors round) : une nouvelle clé de secours serait de nouveau générée et stockée en clair"
    );

    $orig = (string) Configuration::get('NERIA_TRACKING_FALLBACK_KEY');

    try {
        Configuration::deleteByName('NERIA_TRACKING_FALLBACK_KEY');

        // Reproduit exactement la logique de génération de trackingSignKey()
        // (dernier recours) sans dépendre des constantes _COOKIE_KEY_ (non
        // redéfinissables en test) : même séquence encrypt()+updateValue().
        $newBin = random_bytes(32);
        $newHex = bin2hex($newBin);
        Configuration::updateValue('NERIA_TRACKING_FALLBACK_KEY', CryptoManager::encrypt($newHex));

        $stored = (string) Configuration::get('NERIA_TRACKING_FALLBACK_KEY');
        neria_assert(
            CryptoManager::isEncrypted($stored),
            "NERIA_TRACKING_FALLBACK_KEY n'est plus stockée chiffrée (préfixe ENC:) en base — régression du correctif du 10/09/2026 (hors round) : la clé de secours HMAC redeviendrait lisible en clair par quiconque a accès à ps_configuration"
        );

        $decrypted = CryptoManager::decrypt($stored);
        neria_assert(
            $decrypted === $newHex && strlen($decrypted) === 64 && ctype_xdigit($decrypted),
            "Le déchiffrement de NERIA_TRACKING_FALLBACK_KEY ne restitue plus la valeur hex d'origine (obtenu : '{$decrypted}') — régression du correctif du 10/09/2026 (hors round)"
        );

        // Non-régression : une valeur PRÉ-EXISTANTE en clair (installation
        // avant ce correctif, jamais rechiffrée) doit rester lisible telle
        // quelle via decrypt() — rétrocompatibilité déjà garantie par
        // CryptoManager::decrypt() lui-même pour toute valeur sans préfixe
        // ENC:, revérifiée ici spécifiquement pour cette clé.
        $legacyHex = bin2hex(random_bytes(32));
        Configuration::updateValue('NERIA_TRACKING_FALLBACK_KEY', $legacyHex);
        $legacyRead = CryptoManager::decrypt((string) Configuration::get('NERIA_TRACKING_FALLBACK_KEY'));
        neria_assert(
            $legacyRead === $legacyHex,
            "Une NERIA_TRACKING_FALLBACK_KEY pré-existante stockée en clair (installation antérieure à ce correctif) n'est plus lisible correctement — régression de rétrocompatibilité"
        );

        return [
            'pass'    => true,
            'message' => "NERIA_TRACKING_FALLBACK_KEY est désormais chiffrée au repos (CryptoManager::SENSITIVE_CONFIG_KEYS), avec rétrocompatibilité préservée pour les valeurs déjà en clair — bug corrigé le 10/09/2026 (hors round)",
        ];
    } finally {
        if ($orig === '') {
            Configuration::deleteByName('NERIA_TRACKING_FALLBACK_KEY');
        } else {
            Configuration::updateValue('NERIA_TRACKING_FALLBACK_KEY', $orig);
        }
    }
}
