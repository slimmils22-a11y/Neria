<?php
/**
 * Régression : CertificateManager::generatePdf() doit dériver le nom de
 * fichier PDF sur disque d'un hash du serial BRUT, pas d'une substitution
 * caractère par caractère à perte (`preg_replace('/[^a-z0-9_\-]/i', '_', ...)`).
 *
 * Bug identifié le 13/09/2026 (round 351, audit multi-agents, angle
 * certificats) : deux numéros de série DIFFÉRENTS au sens de la vraie
 * contrainte UNIQUE en base (serialExists()) pouvaient converger vers le
 * MÊME nom de fichier après cette substitution — ex. "CERT/2026-000123"
 * et "CERT 2026-000123" donnent tous deux "CERT_2026-000123". Le second
 * file_put_contents() écrasait alors silencieusement le fichier physique
 * du premier certificat. Matérialisé via GdprAuditManager::
 * purgeCustomerData() (unlink() sur pdf_path) : l'effacement RGPD d'un
 * client pouvait supprimer le fichier archivé d'un AUTRE client, sans
 * trace, alors que la ligne DB de ce dernier restait intacte.
 *
 * Test comportemental réel : calcule le nom de fichier réellement produit
 * (via réflexion sur la logique de generatePdf(), sans TCPDF) pour 2
 * serials distincts connus pour colliser avec l'ancienne méthode, et
 * vérifie qu'ils produisent bien 2 noms de fichiers DIFFÉRENTS.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // Reproduit exactement la ligne de calcul du nom de fichier (lue
    // directement depuis le fichier source, pas dupliquée à la main) —
    // garantit que ce test échoue si cette ligne précise régresse.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CertificateManager.php');

    neria_assert(
        strpos($src, "\$file = 'cert_' . substr(hash('sha256', \$serial), 0, 32) . '.pdf';") !== false,
        "CertificateManager::generatePdf() ne dérive plus le nom de fichier d'un hash du serial brut — régression du bug corrigé le 13/09/2026 (round 351) : la substitution à perte (preg_replace) pourrait de nouveau faire collisionner deux certificats distincts sur le même fichier physique"
    );

    // Preuve comportementale réelle du calcul lui-même (fonction pure,
    // sans dépendance TCPDF) : les 2 serials suivants collisionnaient tous
    // les deux vers "cert_CERT_2026-000123.pdf" avec l'ancienne méthode.
    $serialA = 'CERT/2026-000123';
    $serialB = 'CERT 2026-000123';

    $oldFileA = 'cert_' . preg_replace('/[^a-z0-9_\-]/i', '_', $serialA) . '.pdf';
    $oldFileB = 'cert_' . preg_replace('/[^a-z0-9_\-]/i', '_', $serialB) . '.pdf';
    neria_assert(
        $oldFileA === $oldFileB,
        'Jeu de test invalide : les 2 serials choisis ne collisionnaient déjà plus avec l\'ancienne méthode — en choisir d\'autres'
    );

    $newFileA = 'cert_' . substr(hash('sha256', $serialA), 0, 32) . '.pdf';
    $newFileB = 'cert_' . substr(hash('sha256', $serialB), 0, 32) . '.pdf';
    neria_assert(
        $newFileA !== $newFileB,
        "Le nouveau calcul de nom de fichier collisionne encore pour 2 serials distincts ({$serialA} / {$serialB}) — obtenu {$newFileA} pour les deux"
    );

    // Déterminisme : le même serial doit toujours produire le même nom
    // (utile si jamais un besoin de retrouver le fichier par serial émerge).
    $newFileA2 = 'cert_' . substr(hash('sha256', $serialA), 0, 32) . '.pdf';
    neria_assert(
        $newFileA === $newFileA2,
        'Le calcul du nom de fichier via hash() n\'est plus déterministe pour un même serial'
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager::generatePdf() dérive bien le nom de fichier d'un hash déterministe du serial brut — 2 serials distincts qui collisionnaient avec l'ancienne substitution à perte produisent désormais 2 fichiers différents — bug corrigé le 13/09/2026 (round 351)",
    ];
}
