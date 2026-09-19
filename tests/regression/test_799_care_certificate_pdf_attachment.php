<?php
/**
 * Bloc 5 (19/09/2026) : l'email care_certificate annonçait « un certificat
 * d'entretien personnalisé » sans jamais en joindre (bouton « Télécharger »
 * menant à l'accueil). CarePdfGenerator produit désormais un vrai PDF (même
 * identité visuelle que le certificat d'authenticité, textes du template dans
 * la langue du client, polices adaptées à l'écriture) et l'envoi manuel
 * (ManualSendManager::send) le joint à l'email.
 *
 * Test comportemental réel : le PDF est généré (flux non compressé pour
 * inspecter le texte) en en/fr puis en ar/ja/ru (polices non latines) ;
 * contenu, HTML neutralisé, nom de fichier ; et la pièce jointe est bien
 * branchée dans l'envoi manuel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CarePdfGenerator.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $gen = new CarePdfGenerator(neria_test_module());

    $en = $gen->generate('Brown <b>bear</b> cushion', 'Organic cotton', 'Dry clean only.', 'en', 1, false);
    neria_assert(isset($en['content']), "génération EN échouée : " . ($en['error'] ?? '?'));
    neria_assert(substr($en['content'], 0, 5) === '%PDF-', "EN : ce n'est pas un PDF");
    neria_assert(strlen($en['content']) > 3000, "EN : PDF anormalement petit");
    neria_assert(strpos($en['content'], 'CARE CERTIFICATE') !== false, "EN : titre absent du PDF");
    neria_assert(strpos($en['content'], 'Brown bear cushion') !== false, "EN : nom de produit absent (ou HTML non neutralisé)");
    neria_assert(strpos($en['content'], '<b>') === false, "EN : balise HTML présente dans le PDF");
    neria_assert(strpos($en['content'], 'Dry clean only.') !== false, "EN : consignes d'entretien absentes");
    neria_assert($en['filename'] === 'care-certificate-brown-bear-cushion.pdf', "EN : nom de fichier inattendu : " . $en['filename']);

    $fr = $gen->generate('Coussin', 'Coton', 'Nettoyage à sec.', 'fr', 1, false);
    neria_assert(isset($fr['content']), "génération FR échouée : " . ($fr['error'] ?? '?'));
    neria_assert(stripos($fr['content'], "CERTIFICAT D") !== false, "FR : titre en français absent (traduction du template non utilisée)");

    foreach (['ar', 'ja', 'ru', 'zh', 'ko'] as $l) {
        $r = $gen->generate('Cushion', 'Wool', 'Dry clean', $l, 1);
        neria_assert(isset($r['content']) && substr($r['content'], 0, 5) === '%PDF-', "{$l} : génération échouée (" . ($r['error'] ?? '?') . ")");
    }

    // Branchement dans l'envoi manuel.
    $msm = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
    neria_assert(strpos($msm, "\$template === 'care_certificate'") !== false, "ManualSendManager ne génère plus le PDF pour care_certificate");
    neria_assert(strpos($msm, "'mime' => 'application/pdf'") !== false, "ManualSendManager ne prépare plus la pièce jointe PDF");
    neria_assert(preg_match('/null,\s*null,\s*\$attachment,\s*null,\s*_PS_MODULE_DIR_/', $msm) === 1, "la pièce jointe n'est plus transmise à Mail::Send()");

    return ['pass' => true, 'message' => "le certificat d'entretien est généré en PDF (7 langues vérifiées, HTML neutralisé) et joint à l'email care_certificate — bloc 5 (19/09/2026)"];
}
