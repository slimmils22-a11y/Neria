<?php
/**
 * Régression : CryptoManager::loadKey() doit lire la clé de chiffrement
 * avec $idShop=0 explicite, cohérent avec generateAndStoreKey() (round 321)
 * qui écrit/lit déjà ainsi — sans lui, Configuration::get() sans $idShop
 * retombe sur le contexte shop AMBIANT (Shop::getContextShopID()), pas
 * forcément id_shop=0.
 *
 * Bug identifié le 14/09/2026 (round 355, audit multi-agents, confiance
 * moyenne — vérifié non exploitable AUJOURD'HUI dans le mécanisme réel de
 * Configuration::get() du cœur PrestaShop : sans ligne spécifique à un
 * shop pour cette clé, le repli sur le cache global fonctionne quand même.
 * generateAndStoreKey() n'écrit QUE via updateGlobalValue() — aucune ligne
 * par-boutique n'existe jamais pour NERIA_CRYPTO_KEY). Corrigé par
 * cohérence défensive avec le pattern déjà établi dans ce même fichier
 * pour le même chemin lecture/écriture (round 321), au cas où ce chemin
 * d'écriture change un jour.
 *
 * Test structurel + comportemental réel : vérifie le littéral source, puis
 * confirme qu'un cycle encrypt()/decrypt() réel fonctionne toujours
 * correctement (non-régression fonctionnelle du changement).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CryptoManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CryptoManager.php');

    neria_assert(
        strpos($src, '$hex = (string) \Configuration::get(self::CONFIG_KEY, null, null, 0);') !== false,
        "CryptoManager::loadKey() ne lit plus explicitement id_shop=0 — régression du correctif hors round du 14/09/2026 : incohérence défensive avec generateAndStoreKey() (round 321) réapparue"
    );

    // Comportemental réel : un cycle encrypt()/decrypt() doit rester
    // fonctionnellement identique après ce changement (la clé lue reste
    // la bonne).
    $plain = 'regtest749-' . uniqid();
    $encrypted = CryptoManager::encrypt($plain);
    neria_assert($encrypted !== '', 'CryptoManager::encrypt() a retourné une chaîne vide — jeu de test invalide (clé absente ?)');

    $decrypted = CryptoManager::decrypt($encrypted);
    neria_assert(
        $decrypted === $plain,
        "CryptoManager::decrypt(encrypt(\$plain)) ne retourne plus \$plain après le changement de loadKey() (obtenu '{$decrypted}') — régression fonctionnelle du correctif hors round du 14/09/2026"
    );

    return [
        'pass'    => true,
        'message' => "CryptoManager::loadKey() lit désormais explicitement id_shop=0 (cohérent avec generateAndStoreKey(), round 321), cycle encrypt()/decrypt() toujours fonctionnel — correctif hors round du 14/09/2026 validé",
    ];
}
