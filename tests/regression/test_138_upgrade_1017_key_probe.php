<?php
/**
 * Régression : upgrade_module_1_0_17() doit sonder la clé de chiffrement
 * AVANT de chiffrer les secrets sensibles, et journaliser via Watchdog si
 * la clé est illisible — comme GdprAuditManager::encryptExistingRecords()
 * (chiffrement rétroactif des snapshots neria_stat).
 *
 * Bug réel corrigé le 08/08/2026 : CryptoManager::encrypt() retourne la
 * valeur EN CLAIR inchangée (pas d'exception, pas de false) si la clé
 * maîtresse est illisible (absente/corrompue au moment précis de
 * l'upgrade) — le script chiffrait "en silence" sans jamais vérifier le
 * résultat ni journaliser un éventuel échec. Le marchand pouvait croire
 * ses secrets (mot de passe IMAP, tokens OAuth, clés API tierces)
 * chiffrés en base alors qu'ils restaient en clair, sans aucune trace au
 * moment où ça se produisait (seul un contrôle de santé PASSIF,
 * checkSecretsEncrypted(), aurait pu le révéler plus tard si le marchand
 * consultait l'onglet Aide).
 *
 * Test structurel (invoquer réellement la fonction d'upgrade toucherait la
 * config globale réelle — NERIA_ENCRYPTION_KEY et les clés SENSITIVE_CONFIG_KEYS
 * — trop invasif pour ce jeu de tests) : vérifie que la sonde de clé et le
 * log Watchdog sont bien présents dans le script, avant toute boucle de
 * chiffrement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.17.php');
    neria_assert($src !== false, 'Impossible de lire upgrade/upgrade-1.0.17.php');

    $posProbe = strpos($src, "CryptoManager::encrypt('neria_key_probe')");
    neria_assert(
        $posProbe !== false,
        "upgrade_module_1_0_17() ne sonde plus la clé de chiffrement avant de chiffrer les secrets — régression du bug corrigé le 08/08/2026 : un échec de chiffrement (clé illisible) redeviendrait silencieux"
    );

    $posLog = strpos($src, "new WatchdogManager(\$module))->error(");
    neria_assert(
        $posLog !== false && $posLog > $posProbe,
        "upgrade_module_1_0_17() ne journalise plus via Watchdog en cas de clé illisible, ou le log n'est plus placé après la sonde — régression du bug corrigé le 08/08/2026"
    );

    // La boucle de chiffrement doit rester dans la branche "clé valide"
    // (après le if isEncrypted($keyProbe)), pas s'exécuter inconditionnellement.
    $posLoop = strpos($src, 'foreach (CryptoManager::SENSITIVE_CONFIG_KEYS as $key)');
    neria_assert(
        $posLoop !== false && $posLoop > $posProbe,
        "upgrade_module_1_0_17() : la boucle de chiffrement des secrets ne suit plus la sonde de clé — le chiffrement pourrait de nouveau tourner même si la clé est illisible"
    );

    return [
        'pass'    => true,
        'message' => "upgrade_module_1_0_17() sonde bien la clé de chiffrement et journalise via Watchdog avant de chiffrer les secrets sensibles",
    ];
}
