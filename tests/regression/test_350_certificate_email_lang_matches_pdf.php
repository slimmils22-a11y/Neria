<?php
/**
 * Régression : CertificateManager::issue() envoyait l'email du certificat
 * avec (int) $customer->id_lang (langue brute du compte client) au lieu de
 * $idLangProduct (langue RÉELLEMENT résolue par resolveCertificateLang(),
 * utilisée pour générer le PDF juste avant) — contredisant directement la
 * garantie de parité PDF/email documentée en tête de la méthode (et déjà
 * l'objet du correctif round 158, voir test_258). Quand la résolution
 * diverge du compte client (ex. compte en 'en' mais adresse de facturation
 * FR avec NERIA_AUTO_LANG actif), le PDF joint et le corps de l'email se
 * retrouvaient dans deux langues différentes.
 *
 * Corrigé le 15/08/2026 (round 177) : sendCertificateEmail() reçoit
 * désormais $idLangProduct.
 *
 * Test structurel : vérifie que l'appel à sendCertificateEmail() dans
 * issue() passe bien $idLangProduct (la variable utilisée pour charger le
 * produit dans la langue résolue), pas (int) $customer->id_lang.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CertificateManager.php');

    $posSend = strpos($src, '$err = $this->sendCertificateEmail(');
    neria_assert($posSend !== false, "Appel à sendCertificateEmail() introuvable dans issue() — jeu de test invalide");
    $callBlock = substr($src, $posSend, 250);

    neria_assert(
        strpos($callBlock, '$idLangProduct') !== false,
        "CertificateManager::issue() n'appelle plus sendCertificateEmail() avec \$idLangProduct — régression du bug corrigé le 15/08/2026 (round 177) : l'email pourrait de nouveau être envoyé dans (int) \$customer->id_lang au lieu de la langue réellement résolue pour le PDF, cassant la parité PDF/email"
    );

    neria_assert(
        strpos($callBlock, '(int) $customer->id_lang') === false,
        "CertificateManager::issue() passe encore (int) \$customer->id_lang à sendCertificateEmail() — régression du bug corrigé le 15/08/2026 (round 177)"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager::issue() envoie bien l'email du certificat dans la langue réellement résolue pour le PDF (\$idLangProduct) — bug corrigé le 15/08/2026 (round 177)",
    ];
}
