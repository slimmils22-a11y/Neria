<?php
/**
 * Régression : `CertificateManager::generatePdf()` résolvait la signature
 * manuscrite à AFFICHER via une requête LIVE sur `neria_signature`
 * (`WHERE is_active = 1 ... ORDER BY date_upd DESC`) — à CHAQUE appel, y
 * compris depuis `redownload()`. Le nom client, le nom produit et la note
 * artisan sont bien figés en base à l'émission (`customer_name`,
 * `product_name`, `artisan_note`), mais la signature, elle, changeait
 * rétroactivement à chaque re-téléchargement dès que le marchand
 * remplaçait la signature manuscrite active en BO — le certificat déjà
 * émis affichait alors une signature qui n'était pas encore active à la
 * date d'émission imprimée sur le document, contredisant sa propre valeur
 * probante de document daté.
 *
 * Bug identifié le 04/09/2026 (round 301, audit "cycle de vie du
 * certificat d'authenticité").
 *
 * Corrigé le 04/09/2026 (round 301) : nouvelle colonne `signature_path`
 * (upgrade 1.0.45) — `issue()` y persiste le chemin de la signature
 * réellement résolue à l'émission ; `redownload()` la relit et la
 * transmet à `generatePdf()` via le nouveau paramètre `$frozenSigPath`,
 * qui l'utilise telle quelle au lieu de re-résoudre la signature active
 * courante.
 *
 * Test comportemental réel : émet un vrai certificat (TCPDF) avec la
 * signature A active, vérifie que `signature_path` est bien persisté en
 * base pointant vers A ; bascule la signature active vers B ; appelle
 * `redownload()` et vérifie que le PDF regénéré utilise toujours A (pas
 * B) — via la clé `sig_path` renvoyée par `generatePdf()`/`redownload()`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $orderRow = $db->getRow("SELECT id_order, id_customer FROM {$prefix}orders");
    $prodRow  = $db->getRow("SELECT id_product FROM {$prefix}product");
    neria_assert(
        $orderRow !== false && $prodRow !== false,
        "jeu de test invalide : aucune commande/produit disponible en base de test"
    );
    $idOrder   = (int) $orderRow['id_order'];
    $idProduct = (int) $prodRow['id_product'];

    // Deux images de signature distinctes (1x1 PNG rouge / bleu — contenu
    // binaire différent, suffisant pour distinguer les deux fichiers).
    $sigDir = _PS_MODULE_DIR_ . 'neria/img/signatures_regtest565/';
    if (!is_dir($sigDir)) {
        @mkdir($sigDir, 0777, true);
    }
    $pngRed  = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $pngBlue = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $pathA = $sigDir . 'sig_a.png';
    $pathB = $sigDir . 'sig_b.png';
    file_put_contents($pathA, $pngRed);
    file_put_contents($pathB, $pngBlue);
    $relA = 'img/signatures_regtest565/sig_a.png';
    $relB = 'img/signatures_regtest565/sig_b.png';

    $db->execute("DELETE FROM {$prefix}neria_signature WHERE signer_name = 'RegtestA' OR signer_name = 'RegtestB'");
    $db->execute(
        "INSERT INTO {$prefix}neria_signature (id_shop, signer_name, signer_title, font_style, color, image_path, is_active, date_add, date_upd)
         VALUES ({$idShop}, 'RegtestA', '', 'elegant', '#b38b59', '" . pSQL($relA) . "', 1, NOW(), NOW())"
    );
    $idSigA = (int) $db->Insert_ID();

    try {
        $mgr = new CertificateManager(neria_test_module());
        $err = $mgr->issue($idOrder, $idProduct, 0, '', 'Note de test', false);
        neria_assert($err === '', "CertificateManager::issue() a échoué : {$err}");

        $certRow = $db->getRow(
            "SELECT id_certificate, signature_path FROM {$prefix}neria_certificate
             WHERE id_order = {$idOrder} AND id_product = {$idProduct}
             ORDER BY id_certificate DESC"
        );
        neria_assert($certRow !== false, "aucun certificat trouvé après issue() — jeu de test invalide");
        $idCertificate = (int) $certRow['id_certificate'];

        neria_assert(
            strpos((string) $certRow['signature_path'], 'sig_a.png') !== false,
            "issue() ne persiste plus le chemin de la signature réellement active à l'émission dans signature_path (obtenu : '" . $certRow['signature_path'] . "') — régression du bug corrigé le 04/09/2026 (round 301)"
        );

        // Bascule la signature active : A désactivée, B activée.
        $db->execute("UPDATE {$prefix}neria_signature SET is_active = 0 WHERE id_signature = {$idSigA}");
        $db->execute(
            "INSERT INTO {$prefix}neria_signature (id_shop, signer_name, signer_title, font_style, color, image_path, is_active, date_add, date_upd)
             VALUES ({$idShop}, 'RegtestB', '', 'elegant', '#b38b59', '" . pSQL($relB) . "', 1, NOW(), NOW())"
        );
        $idSigB = (int) $db->Insert_ID();

        $result = $mgr->redownload($idCertificate);
        neria_assert(
            !isset($result['error']),
            "redownload() a échoué après bascule de signature : " . ($result['error'] ?? '?')
        );
        neria_assert(
            isset($result['sig_path']) && strpos($result['sig_path'], 'sig_a.png') !== false,
            "redownload() utilise désormais la signature ACTIVE COURANTE (B) au lieu de la signature FIGÉE à l'émission (A) — régression du bug corrigé le 04/09/2026 (round 301) : un certificat déjà émis changerait de nouveau rétroactivement de signature à chaque re-téléchargement (sig_path obtenu : '" . ($result['sig_path'] ?? '?') . "')"
        );

        return [
            'pass'    => true,
            'message' => "CertificateManager fige désormais la signature manuscrite à l'émission (colonne signature_path) — un re-téléchargement ultérieur ne change plus rétroactivement de signature — bug corrigé le 04/09/2026 (round 301)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_order = {$idOrder} AND id_product = {$idProduct} AND artisan_note = 'Note de test'");
        $db->execute("DELETE FROM {$prefix}neria_signature WHERE signer_name = 'RegtestA' OR signer_name = 'RegtestB'");
        @unlink($pathA);
        @unlink($pathB);
        @rmdir($sigDir);
    }
}
