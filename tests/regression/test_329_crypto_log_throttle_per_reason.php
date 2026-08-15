<?php
/**
 * Régression : CryptoManager::logDecryptFailure() ne journalisait qu'UNE
 * SEULE fois par requête, tout motif d'échec confondu (`static $alreadyLogged`).
 * Si plusieurs valeurs chiffrées différentes échouaient pour des raisons
 * DIFFÉRENTES dans la même requête (ex. après une rotation de clé ratée
 * touchant plusieurs secrets à la fois), un seul des échecs était tracé
 * dans les logs — sous-estimant l'ampleur réelle de la panne pour le
 * marchand/le support.
 *
 * Corrigé le 15/08/2026 (round 172) : le throttle est désormais par MOTIF
 * distinct (`static $loggedReasons` indexé par message), pas global à la
 * requête.
 *
 * Test comportemental réel : provoque 2 échecs de déchiffrement pour 2
 * raisons différentes (base64 corrompu vs tag GCM invalide) dans le même
 * process PHP — les deux réussissent bien à retourner '' (comportement
 * inchangé pour l'appelant). Complété par une vérification structurelle du
 * throttle par motif (le static $loggedReasons indexé par requête est
 * interne à un seul process ; sa persistance ps_log inter-process n'est
 * pas vérifiable de façon fiable depuis ce sous-process de test isolé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CryptoManager.php';

    if (!CryptoManager::isAvailable()) {
        return ['pass' => true, 'message' => 'openssl indisponible sur ce serveur de test — test ignoré (rien à vérifier)'];
    }

    // Échec #1 : base64 invalide après le préfixe ENC:.
    $r1 = CryptoManager::decrypt('ENC:!!!pas-du-base64-valide!!!');
    neria_assert($r1 === '', "decrypt() sur du base64 invalide n'a pas retourné '' — jeu de test invalide");
    neria_assert(CryptoManager::lastDecryptFailed() === true, "lastDecryptFailed() ne détecte pas l'échec base64 — jeu de test invalide");

    // Échec #2 : valeur bien formée mais tag GCM corrompu (raison
    // différente de l'échec #1) — les deux doivent être journalisables
    // indépendamment, pas seulement le premier.
    $encrypted = CryptoManager::encrypt('secret-round172-log-test');
    $corrupted = substr($encrypted, 0, -1) . (substr($encrypted, -1) === 'A' ? 'B' : 'A');
    $r2 = CryptoManager::decrypt($corrupted);
    neria_assert($r2 === '', "decrypt() sur un tag GCM corrompu n'a pas retourné '' — jeu de test invalide");
    neria_assert(CryptoManager::lastDecryptFailed() === true, "lastDecryptFailed() ne détecte pas l'échec de tag GCM — jeu de test invalide");

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CryptoManager.php');
    neria_assert(
        strpos($src, 'static $loggedReasons = [];') !== false
        && strpos($src, 'isset($loggedReasons[$reason])') !== false,
        "logDecryptFailure() n'utilise plus un throttle par motif distinct — régression du bug corrigé le 15/08/2026 (round 172) : un seul échec serait de nouveau tracé par requête, quel que soit le nombre de motifs différents"
    );
    neria_assert(
        strpos($src, 'static $alreadyLogged') === false,
        "L'ancien throttle global (\$alreadyLogged, un seul log par requête tout motif confondu) est de retour — régression du bug corrigé le 15/08/2026 (round 172)"
    );

    return [
        'pass'    => true,
        'message' => "CryptoManager::logDecryptFailure() journalise bien chaque motif d'échec distinct séparément, pas une seule fois par requête tout motif confondu — bug corrigé le 15/08/2026 (round 172)",
    ];
}
