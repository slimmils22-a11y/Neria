<?php
/**
 * Régression : issue() retournait immédiatement l'erreur d'envoi email
 * (return $err) AVANT d'atteindre le log Watchdog "Certificat émis" plus
 * bas dans la même méthode — sans jamais journaliser d'échec dédié. Le
 * certificat existe pourtant bel et bien en DB (l'INSERT a réussi avant
 * la tentative d'envoi, emailed reste à 0) : un échec SMTP silencieux
 * laissait un certificat "fantôme" visible dans getAll()/les stats, sans
 * aucune trace explicite du problème d'envoi dans le journal.
 *
 * Corrigé le 14/08/2026 (round 167) : un log Watchdog warning dédié est
 * désormais émis dans la branche $err !== '' avant le retour anticipé.
 *
 * Test structurel : vérifie la présence du log dédié.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire CertificateManager.php');

    $posErr = strpos($src, "if (\$err !== '') {");
    neria_assert($posErr !== false, "Branche d'échec d'envoi email introuvable — jeu de test invalide");
    $body = substr($src, $posErr, 900);

    neria_assert(
        strpos($body, "Certificat émis mais email non envoyé") !== false,
        "Le log Watchdog dédié à l'échec d'envoi email a disparu — régression du bug corrigé le 14/08/2026 (round 167) : un certificat 'fantôme' (créé en DB mais jamais reçu par le client) redeviendrait indétectable dans le journal"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager::issue() journalise bien un échec d'envoi email dédié, même quand le certificat a déjà été inséré en DB — bug corrigé le 14/08/2026 (round 167)",
    ];
}
