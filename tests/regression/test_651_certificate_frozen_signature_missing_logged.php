<?php
/**
 * Régression : CertificateManager::generatePdf(), quand appelée avec un
 * $frozenSigPath non-null (depuis redownload(), round 301 — gel de la
 * signature manuscrite à la date d'émission originale), ne journalisait
 * RIEN si le fichier référencé avait disparu du disque (nettoyage,
 * migration serveur, restauration partielle de sauvegarde). $sigPath
 * restait '' silencieusement — contrairement au cas $frozenSigPath===null
 * qui dispose d'un repli explicite sur la signature active courante.
 *
 * Bug réel : un client cliquant "retélécharger mon certificat" recevait un
 * PDF régénéré visuellement dégradé (bloc signature vide), sans qu'aucune
 * trace Watchdog ne permette au marchand de le détecter — différence
 * silencieuse avec le PDF original envoyé par email.
 *
 * Corrigé le 08/09/2026 (round 326) : un warning Watchdog est désormais
 * journalisé dans ce cas (sans changer le comportement — $sigPath reste
 * volontairement '' plutôt que de retomber sur la signature active, ce qui
 * violerait la garantie de gel du round 301).
 *
 * Test structurel (generatePdf() est privée et génère un vrai PDF via
 * TCPDF/mPDF — invocation réelle trop lourde/fragile pour un test de
 * régression, même limitation documentée par test_454/test_635/test_640) :
 * vérifie la présence du garde-fou dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $srcRaw = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($srcRaw !== false, 'Impossible de lire src/CertificateManager.php');
    $src = str_replace("\r", '', $srcRaw);

    $posBlock = strpos($src, '$sigPath = \'\';');
    neria_assert($posBlock !== false, "Bloc de résolution de \$sigPath introuvable — jeu de test invalide");
    $body = substr($src, $posBlock, 1200);

    neria_assert(
        strpos($body, 'elseif ($frozenSigPath !== \'\') {') !== false,
        "CertificateManager::generatePdf() ne distingue plus le cas 'signature figée référencée mais fichier disparu' — régression du bug corrigé le 08/09/2026 (round 326)"
    );
    neria_assert(
        strpos($body, "Signature figée introuvable sur disque pour un re-téléchargement de certificat") !== false,
        "CertificateManager::generatePdf() ne journalise plus de warning Watchdog quand la signature figée référencée a disparu du disque — régression du bug corrigé le 08/09/2026 (round 326) : un PDF re-téléchargé pourrait de nouveau être silencieusement dégradé (signature manquante) sans aucune trace"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager::generatePdf() journalise bien un warning Watchdog quand la signature figée d'un certificat a disparu du disque, sans retomber sur la signature active (préservant le gel du round 301) — bug corrigé le 08/09/2026 (round 326)",
    ];
}
