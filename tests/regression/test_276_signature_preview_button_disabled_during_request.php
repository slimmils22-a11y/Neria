<?php
/**
 * Régression : le bouton "Aperçu" de la signature (initSignaturePreview())
 * restait cliquable pendant la requête AJAX, contrairement aux autres
 * actions du BO (Watchdog, traductions, reset design) qui désactivent
 * leur bouton. Le jeton de requête existant empêchait une réponse obsolète
 * d'écraser la bonne (pas de bug de cohérence), mais un marchand qui
 * spamme le clic générait des requêtes réseau inutiles sans aucun
 * indicateur "en cours".
 *
 * Corrigé le 13/08/2026 (round 162) : btn.disabled = true au clic, remis à
 * false dans le .finally() (uniquement si la réponse n'est pas obsolète,
 * pour laisser la requête la plus récente reprendre la main).
 *
 * Test structurel : vérifie la présence de la désactivation/réactivation
 * du bouton dans initSignaturePreview().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/js/neria-admin.js');
    neria_assert($src !== false, 'Impossible de lire neria-admin.js');

    $posFn = strpos($src, 'function initSignaturePreview()');
    neria_assert($posFn !== false, 'initSignaturePreview() introuvable — jeu de test invalide');
    // Round 271 : fenêtre élargie 3600 -> 5200 (correctif preview_signature
    // ajoutant une branche else plus loin dans la même fonction).
    $body = substr($src, $posFn, 5200);

    neria_assert(
        strpos($body, 'btn.disabled = true;') !== false,
        "initSignaturePreview() ne désactive plus le bouton pendant la requête — régression du bug corrigé le 13/08/2026 (round 162)"
    );
    neria_assert(
        strpos($body, 'btn.disabled = false;') !== false,
        "initSignaturePreview() ne réactive plus le bouton après la requête — régression du bug corrigé le 13/08/2026 (round 162) : le bouton resterait bloqué définitivement après le premier clic"
    );

    return [
        'pass'    => true,
        'message' => "initSignaturePreview() désactive/réactive bien le bouton autour de la requête AJAX — bug corrigé le 13/08/2026 (round 162)",
    ];
}
