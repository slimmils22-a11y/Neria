<?php
/**
 * Régression : CertificateManager::redownload() ne vérifiait jamais
 * isset($pdfResult['error']) contrairement à issue() — si generatePdf()
 * échoue (TCPDF manquant, exception), le résultat ['error' => ...] était
 * retourné tel quel comme si c'était ['content'=>..., 'path'=>...,
 * 'filename'=>...]. L'appelant BO (neria.php, bouton "retélécharger")
 * vérifie déjà isset($result['error']) mais ne le trouvait jamais dans ce
 * cas précis puisque la clé EST 'error' — le vrai problème était que
 * l'appel accédait ensuite à $result['filename']/$result['content']
 * absents en cas d'erreur AVANT ce correctif, si jamais un appelant futur
 * ne faisait pas ce même contrôle.
 *
 * Corrigé le 14/08/2026 (round 167) : redownload() propage désormais
 * explicitement l'erreur au lieu de renvoyer le tableau brut de
 * generatePdf() sans l'avoir inspecté.
 *
 * Test structurel : vérifie la présence du contrôle isset($result['error']).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire CertificateManager.php');

    $posFn = strpos($src, 'public function redownload(int $idCertificate): array');
    neria_assert($posFn !== false, 'redownload() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 3800);

    neria_assert(
        strpos($body, "isset(\$result['error'])") !== false,
        "redownload() ne vérifie plus isset(\$result['error']) — régression du bug corrigé le 14/08/2026 (round 167) : un échec de génération PDF redeviendrait retourné sans être inspecté"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager::redownload() vérifie bien le retour de generatePdf() avant de le renvoyer — bug corrigé le 14/08/2026 (round 167)",
    ];
}
