<?php
/**
 * Régression : CryptoManager::decrypt() retournait '' sur TOUT échec (clé
 * absente/corrompue, tag GCM invalide, base64 corrompu) — exactement la
 * même valeur qu'un secret jamais configuré (chaîne vide en entrée). Le
 * code appelant ne pouvait donc pas distinguer "jamais configuré" de
 * "configuré mais cassé" (ex. après une restauration partielle de base ou
 * une rotation de clé ratée) — une intégration entière (OAuth, IMAP...)
 * pouvait se désactiver silencieusement, traitée comme "non configurée"
 * plutôt que comme une panne à corriger.
 *
 * Corrigé le 15/08/2026 (round 172) : CryptoManager::lastDecryptFailed()
 * ajoutée — vrai après un decrypt() qui a réellement échoué sur une valeur
 * chiffrée présente, false si rien n'était à déchiffrer ou si le
 * déchiffrement a réussi. Le throttle de log est aussi passé d'"une seule
 * fois par requête, tout motif confondu" à "une fois par motif distinct".
 *
 * Test comportemental réel : corrompt une VRAIE valeur chiffrée (tag GCM
 * altéré), vérifie que decrypt() retourne '' ET que lastDecryptFailed()
 * retourne true. Vérifie ensuite qu'un decrypt() réussi (ou sans rien à
 * déchiffrer) remet bien le drapeau à false.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';

    if (!CryptoManager::isAvailable()) {
        return ['pass' => true, 'message' => 'openssl indisponible sur ce serveur de test — test ignoré (rien à vérifier)'];
    }

    // 1) Valeur chiffrée VALIDE dont le tag GCM est ensuite altéré.
    $encrypted = CryptoManager::encrypt('secret-de-test-round172');
    neria_assert(strpos($encrypted, 'ENC:') === 0, "encrypt() n'a pas produit de valeur chiffrée — jeu de test invalide (openssl indisponible ?)");

    // Altère le dernier caractère du base64 (impacte le tag GCM final,
    // 16 derniers octets) pour provoquer un échec de vérification réel.
    $corrupted = substr($encrypted, 0, -1) . (substr($encrypted, -1) === 'A' ? 'B' : 'A');

    $result = CryptoManager::decrypt($corrupted);
    neria_assert(
        $result === '',
        "decrypt() sur une valeur corrompue n'a pas retourné '' — jeu de test invalide"
    );
    neria_assert(
        CryptoManager::lastDecryptFailed() === true,
        "CryptoManager::lastDecryptFailed() ne signale plus un échec réel de déchiffrement — régression du bug corrigé le 15/08/2026 (round 172) : impossible de distinguer un secret jamais configuré d'une intégration cassée"
    );

    // 2) Un decrypt() réussi remet bien le drapeau à false.
    $result2 = CryptoManager::decrypt($encrypted);
    neria_assert($result2 === 'secret-de-test-round172', "decrypt() sur la valeur non corrompue n'a pas restitué le texte original — jeu de test invalide");
    neria_assert(
        CryptoManager::lastDecryptFailed() === false,
        "CryptoManager::lastDecryptFailed() reste à true après un decrypt() réussi — le drapeau ne serait plus réinitialisé à chaque appel"
    );

    // 3) Une valeur non chiffrée (jamais configurée) n'est pas un échec.
    $result3 = CryptoManager::decrypt('');
    neria_assert($result3 === '' && CryptoManager::lastDecryptFailed() === false, "decrypt('') est à tort signalé comme un échec — une valeur jamais configurée ne doit pas être confondue avec une panne");

    return [
        'pass'    => true,
        'message' => "CryptoManager::lastDecryptFailed() distingue bien un échec réel de déchiffrement d'une valeur jamais configurée — bug corrigé le 15/08/2026 (round 172)",
    ];
}
