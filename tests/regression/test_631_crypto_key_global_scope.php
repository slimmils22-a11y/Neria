<?php
/**
 * Régression : CryptoManager::generateAndStoreKey() écrivait la clé de
 * chiffrement AES-256 (NERIA_ENCRYPTION_KEY) via Configuration::updateValue()
 * — même architecture que PostmasterManager/SearchConsoleManager (round 185)
 * et NERIA_INSTALLED_VERSION (round 312) : Configuration::updateValue() sans
 * $idShop retombe sur la boutique du CONTEXTE COURANT dès que le
 * multi-boutique est actif (cœur PrestaShop, classes/Configuration.php). Une
 * clé écrite ainsi depuis le contexte BO d'une boutique devient différente
 * (ou vide) quand relue depuis un autre contexte (cron/CLI, front d'une
 * autre boutique), provoquant un échec de déchiffrement silencieux (secrets
 * IMAP/OAuth illisibles, decrypt() renvoie '') sans que la donnée en base
 * soit réellement corrompue.
 *
 * Corrigé le 08/09/2026 (round 321) : Configuration::updateGlobalValue() +
 * lecture forcée id_shop=0 dans le check d'existence.
 *
 * Test comportemental réel : vérifie DIRECTEMENT en base que la ligne
 * ps_configuration créée par generateAndStoreKey() a bien id_shop IS NULL
 * (global), pas l'id_shop du contexte courant — même méthodologie que
 * test_383 (PostmasterManager/SearchConsoleManager, round 185).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $originalGlobal = $db->getValue("SELECT value FROM {$prefix}configuration WHERE name = 'NERIA_ENCRYPTION_KEY' AND id_shop IS NULL");

    try {
        // Configuration::deleteByName() (pas un DELETE SQL brut) : réinitialise
        // aussi le cache statique interne de Configuration — indispensable,
        // même raisonnement que test_383.
        Configuration::deleteByName('NERIA_ENCRYPTION_KEY');

        CryptoManager::generateAndStoreKey();

        $scopedRow = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration WHERE name = 'NERIA_ENCRYPTION_KEY' AND id_shop IS NOT NULL"
        );
        neria_assert(
            $scopedRow === 0,
            "CryptoManager::generateAndStoreKey() a créé une ligne id_shop-scopée pour NERIA_ENCRYPTION_KEY — régression du bug corrigé le 08/09/2026 (round 321) : une clé de chiffrement écrite ainsi deviendrait illisible depuis un autre contexte shop/cron"
        );
        $globalRow = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}configuration WHERE name = 'NERIA_ENCRYPTION_KEY' AND id_shop IS NULL"
        );
        neria_assert($globalRow === 1, "generateAndStoreKey() n'a créé aucune ligne globale — jeu de test invalide");

        // Contre-épreuve : un second appel ne doit RIEN écraser (clé déjà
        // présente) — comportement idempotent inchangé par le correctif.
        $keyAfterFirst = (string) $db->getValue("SELECT value FROM {$prefix}configuration WHERE name = 'NERIA_ENCRYPTION_KEY' AND id_shop IS NULL");
        CryptoManager::generateAndStoreKey();
        $keyAfterSecond = (string) $db->getValue("SELECT value FROM {$prefix}configuration WHERE name = 'NERIA_ENCRYPTION_KEY' AND id_shop IS NULL");
        neria_assert(
            $keyAfterFirst === $keyAfterSecond,
            "generateAndStoreKey() a régénéré une clé déjà existante — régression : la lecture d'existence ne cible plus le bon scope (id_shop=0)"
        );
    } finally {
        Configuration::deleteByName('NERIA_ENCRYPTION_KEY');
        if ($originalGlobal !== false && $originalGlobal !== null) {
            Configuration::updateGlobalValue('NERIA_ENCRYPTION_KEY', $originalGlobal);
        }
    }

    return [
        'pass'    => true,
        'message' => "CryptoManager::generateAndStoreKey() écrit bien la clé de chiffrement en global (id_shop NULL), lisible depuis tout contexte shop/cron — bug corrigé le 08/09/2026 (round 321)",
    ];
}
