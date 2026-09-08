<?php
/**
 * Régression : GdprAuditManager::encryptExistingRecords() renvoyait 0
 * aussi bien quand il n'y avait rien à chiffrer (cas normal) que quand la
 * clé de chiffrement est illisible (absente/corrompue) — les deux cas
 * étaient indiscernables pour l'appelant. neria.php (action
 * gdpr_encrypt_all) affichait donc "0 enregistrement(s) chiffré(s)" comme
 * un succès dans les deux cas, alors qu'une erreur Watchdog était déjà
 * journalisée en interne pour le cas "clé illisible" sans jamais remonter
 * à l'écran — le marchand qui clique "Chiffrer les enregistrements
 * existants" pour corriger l'axe RGPD "chiffrement" (déjà en échec côté
 * auditEncryption()) recevait un message de succès qui masque le vrai
 * problème.
 *
 * Corrigé le 08/09/2026 (round 323, traitement différé) :
 * GdprAuditManager::isEncryptionKeyReadable() (sonde extraite, réutilisée
 * en interne par encryptExistingRecords()) permet à neria.php de vérifier
 * AVANT l'appel et d'afficher neria_error (msg.gdpr_encryption_key_unreadable)
 * au lieu d'un succès trompeur.
 *
 * Test comportemental réel : corrompt temporairement NERIA_ENCRYPTION_KEY,
 * vérifie qu'isEncryptionKeyReadable() renvoie bien false — puis restaure
 * une clé valide et vérifie qu'elle renvoie bien true (pas de régression
 * du chemin nominal).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';

    $originalKey = Configuration::get(CryptoManager::CONFIG_KEY, null, null, 0);

    try {
        // Clé corrompue : longueur incorrecte, rejetée par
        // CryptoManager::loadKey() (strlen !== 64 || !ctype_xdigit).
        Configuration::updateGlobalValue(CryptoManager::CONFIG_KEY, 'clé_corrompue_pas_hexadecimale');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $readableWhenCorrupt = $mgr->isEncryptionKeyReadable();
        neria_assert(
            $readableWhenCorrupt === false,
            "GdprAuditManager::isEncryptionKeyReadable() renvoie true alors que NERIA_ENCRYPTION_KEY est corrompue — régression du bug corrigé le 08/09/2026 (round 323) : gdpr_encrypt_all afficherait de nouveau un succès trompeur masquant une clé de chiffrement illisible"
        );

        $doneWhenCorrupt = $mgr->encryptExistingRecords();
        neria_assert(
            $doneWhenCorrupt === 0,
            "encryptExistingRecords() ne renvoie plus 0 avec une clé corrompue — jeu de test invalide"
        );

        // Contre-épreuve : une clé valide restaure le fonctionnement normal.
        Configuration::updateGlobalValue(CryptoManager::CONFIG_KEY, bin2hex(random_bytes(32)));
        $readableWhenValid = $mgr->isEncryptionKeyReadable();
        neria_assert(
            $readableWhenValid === true,
            "GdprAuditManager::isEncryptionKeyReadable() renvoie false avec une clé de chiffrement valide — régression : le correctif round 323 bloquerait à tort le chemin nominal"
        );
    } finally {
        if ($originalKey !== false && $originalKey !== '' && $originalKey !== null) {
            Configuration::updateGlobalValue(CryptoManager::CONFIG_KEY, (string) $originalKey);
        } else {
            Configuration::deleteByName(CryptoManager::CONFIG_KEY);
        }
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert(
        strpos($src, "isEncryptionKeyReadable()") !== false
        && strpos($src, "AdminTranslator::t('msg.gdpr_encryption_key_unreadable')") !== false,
        "neria.php (gdpr_encrypt_all) ne vérifie plus isEncryptionKeyReadable() avant d'afficher un succès — régression du bug corrigé le 08/09/2026 (round 323)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        isset($translations['msg.gdpr_encryption_key_unreadable']) && count($translations['msg.gdpr_encryption_key_unreadable']) === 19,
        "clé msg.gdpr_encryption_key_unreadable manquante ou incomplète dans admin_translations.json (19 langues attendues)"
    );

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::isEncryptionKeyReadable() distingue bien 'rien à chiffrer' de 'clé illisible', et neria.php affiche désormais neria_error dans ce dernier cas — bug corrigé le 08/09/2026 (round 323)",
    ];
}
