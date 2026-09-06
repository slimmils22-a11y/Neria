<?php
/**
 * Régression : WaitlistManager::notifyProductLocked() ne vérifiait jamais
 * Affected_Rows() sur l'UPDATE posant notified_at = NOW() après un envoi
 * réussi — contrairement au claim initial de cette même méthode (round
 * 167/187), qui lui vérifie bien Affected_Rows() > 0. Un échec silencieux
 * de cet UPDATE (perte de connexion transitoire, ou fenêtre de claim
 * expirée pendant un envoi SMTP anormalement lent et reprise entre-temps
 * par un autre process) laissait notified_at NULL malgré un email
 * réellement envoyé et un log Watchdog "succès" — au prochain réassort,
 * ce même inscrit pouvait recevoir un second email "de retour en stock",
 * exactement le double-envoi que le mécanisme de claim est censé
 * empêcher.
 *
 * Corrigé le 06/09/2026 (round 312) : Affected_Rows() vérifié après cet
 * UPDATE ; $sent n'est incrémenté et le log "succès" écrit QUE si la ligne
 * a réellement été mise à jour, avec un log Watchdog "warning" distinct
 * dans le cas contraire (email envoyé mais confirmation DB manquante).
 *
 * Test structurel : reproduire ce chemin d'échec précis nécessiterait de
 * faire échouer un seul UPDATE ciblé pendant un envoi Mail::Send() réel
 * en plein vol — non déterministe/fragile à simuler. Vérifie la présence
 * du garde-fou dans le code source (mécanisme déjà démontré de bout en
 * bout ailleurs dans le module, ex. test_440/441 pour le pattern
 * Affected_Rows()/cache SQL).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WaitlistManager.php');

    $posUpdate = strpos($src, 'SET notified_at = NOW()');
    neria_assert($posUpdate !== false, "UPDATE notified_at introuvable — jeu de test invalide");

    $body = substr($src, $posUpdate, 1400);

    // Cible l'appel réel en CODE ($this->db->Affected_Rows()), pas une
    // simple mention dans un commentaire explicatif — sinon ce test
    // passerait à tort même si le garde-fou réel avait été retiré du code
    // en ne laissant que le commentaire qui le documente.
    $needleCall = '(int) $this->db->Affected_Rows() > 0';
    neria_assert(
        strpos($body, $needleCall) !== false,
        "WaitlistManager::notifyProductLocked() ne vérifie plus Affected_Rows() (appel réel en code, pas juste en commentaire) après l'UPDATE de notified_at — régression du bug corrigé le 06/09/2026 (round 312) : un échec silencieux de cet UPDATE laisserait de nouveau notified_at NULL malgré un email réellement envoyé et un log Watchdog 'succès', exposant à un second envoi au prochain réassort"
    );
    neria_assert(
        strpos($body, '$sent++;') !== false,
        "WaitlistManager::notifyProductLocked() : \$sent++ n'est plus dans la fenêtre attendue après la vérification Affected_Rows() — jeu de test invalide ou régression structurelle"
    );

    // Vérifie que $sent++ apparaît bien APRÈS l'appel réel Affected_Rows(),
    // pas avant (sinon la vérification ne protège rien réellement).
    $posAffected = strpos($body, $needleCall);
    $posSentInc  = strpos($body, '$sent++;');
    neria_assert(
        $posAffected < $posSentInc,
        "WaitlistManager::notifyProductLocked() : \$sent++ est positionné AVANT l'appel réel Affected_Rows(), pas dedans — régression du bug corrigé le 06/09/2026 (round 312) : le compteur d'envois réussis serait de nouveau incrémenté même si la confirmation DB échoue"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager::notifyProductLocked() vérifie bien Affected_Rows() avant de compter un envoi comme confirmé et de journaliser un succès — bug corrigé le 06/09/2026 (round 312)",
    ];
}
