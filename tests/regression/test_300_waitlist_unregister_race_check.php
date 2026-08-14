<?php
/**
 * Régression : unregister() supprimait la ligne sans condition sur
 * claim_started_at — un client se désinscrivant exactement dans la
 * fenêtre entre le claim (réservation posée avant l'envoi) et
 * Mail::Send() recevait quand même l'email "de retour en stock" qu'il
 * venait de refuser (l'UPDATE final notified_at trouvait simplement 0
 * ligne, sans erreur ni indication).
 *
 * Corrigé le 14/08/2026 (round 167) : une re-vérification de
 * l'inscription est faite juste avant Mail::Send() — si le client s'est
 * désinscrit entre le claim et cet instant, l'envoi est annulé (continue).
 * Referme la majeure partie de la fenêtre de course (ne peut pas annuler
 * un envoi déjà en cours de transmission SMTP — résidu inévitable de tout
 * système de notification "au moins une fois").
 *
 * Test structurel : vérifie la présence de la re-vérification entre le
 * claim et l'envoi.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php');
    neria_assert($src !== false, 'Impossible de lire WaitlistManager.php');

    $posClaim = strpos($src, 'if (!$claimed) {');
    neria_assert($posClaim !== false, 'Bloc de claim introuvable — jeu de test invalide');
    $posMail = strpos($src, '\Mail::Send(', $posClaim);
    neria_assert($posMail !== false, 'Appel Mail::Send() introuvable — jeu de test invalide');
    $body = substr($src, $posClaim, $posMail - $posClaim);

    neria_assert(
        strpos($body, '$stillRegistered') !== false,
        "La re-vérification de l'inscription entre le claim et l'envoi a disparu — régression du bug corrigé le 14/08/2026 (round 167) : un client désinscrit dans cette fenêtre recevrait de nouveau l'email sans qu'aucune mitigation ne le referme"
    );
    neria_assert(
        strpos($body, 'if (!$stillRegistered) {') !== false && strpos($body, 'continue;') !== false,
        "La re-vérification ne provoque plus un \"continue\" (annulation de l'envoi) si le client s'est désinscrit — régression du bug corrigé le 14/08/2026 (round 167)"
    );

    return [
        'pass'    => true,
        'message' => "WaitlistManager re-vérifie bien l'inscription juste avant l'envoi, refermant la majeure partie de la fenêtre de course avec unregister() — bug corrigé le 14/08/2026 (round 167)",
    ];
}
