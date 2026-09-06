<?php
/**
 * Régression : CertificateManager::generatePdf() résolvait la signature
 * manuscrite active via `WHERE id_shop = $this->idShop` (contexte BO
 * COURANT de l'employé qui déclenche l'émission), alors que TOUT LE RESTE
 * de cette même méthode (shopName, title/subtitle/bodyText, qrEnabled,
 * qrBaseUrl — rounds 106/212) a déjà été explicitement corrigé pour
 * utiliser `(int) $order->id_shop` (boutique RÉELLE de la commande).
 *
 * Scénario concret : un employé dont le contexte BO courant est la
 * Boutique B émet un certificat pour une commande de la Boutique A —
 * chaque boutique ayant sa propre signature manuscrite active. Avant ce
 * correctif, le PDF affichait le nom de boutique/titre/QR corrects de A
 * (déjà scopés), mais la signature manuscrite imprimée était celle de B.
 *
 * Corrigé le 06/09/2026 (round 307) : la requête de résolution de la
 * signature active utilise désormais (int) $order->id_shop, comme le
 * reste de la méthode.
 *
 * Test comportemental réel : construit un Order réel dont l'id_shop est
 * ensuite substitué EN MÉMOIRE (pas persisté) par une valeur de boutique
 * factice distincte du contexte BO courant, insère une signature active
 * pour CHACUNE des deux boutiques (contexte vs commande) avec un fichier
 * PNG distinct, appelle generatePdf() via Reflection (méthode privée), et
 * vérifie que le sig_path renvoyé pointe vers le fichier de la boutique
 * de LA COMMANDE, jamais celui du contexte BO courant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $contextShopId = (int) Context::getContext()->shop->id;
    $orderShopId   = 999307; // boutique fictive de LA COMMANDE, distincte du contexte BO

    $orderRow = $db->getRow("SELECT id_order, id_customer FROM {$prefix}orders");
    neria_assert($orderRow !== false, 'jeu de test invalide : aucune commande disponible en base de test');
    $idOrder = (int) $orderRow['id_order'];

    $order = new Order($idOrder);
    neria_assert(Validate::isLoadedObject($order), 'jeu de test invalide : commande introuvable');
    // Substitution EN MÉMOIRE uniquement (jamais persistée) — simule une
    // commande appartenant à une autre boutique que le contexte BO courant.
    $order->id_shop = $orderShopId;

    $sigDir = _PS_MODULE_DIR_ . 'neria/img/signatures_regtest581/';
    if (!is_dir($sigDir)) {
        @mkdir($sigDir, 0777, true);
    }
    $pngRed  = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $pngBlue = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $pathContext = $sigDir . 'sig_context.png';
    $pathOrder   = $sigDir . 'sig_order.png';
    file_put_contents($pathContext, $pngRed);
    file_put_contents($pathOrder, $pngBlue);
    $relContext = 'img/signatures_regtest581/sig_context.png';
    $relOrder   = 'img/signatures_regtest581/sig_order.png';

    $db->execute("DELETE FROM {$prefix}neria_signature WHERE signer_name IN ('Regtest581Ctx', 'Regtest581Order')");
    $db->execute(
        "INSERT INTO {$prefix}neria_signature (id_shop, signer_name, signer_title, font_style, color, image_path, is_active, date_add, date_upd)
         VALUES ({$contextShopId}, 'Regtest581Ctx', '', 'elegant', '#b38b59', '" . pSQL($relContext) . "', 1, NOW(), NOW())"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_signature (id_shop, signer_name, signer_title, font_style, color, image_path, is_active, date_add, date_upd)
         VALUES ({$orderShopId}, 'Regtest581Order', '', 'elegant', '#b38b59', '" . pSQL($relOrder) . "', 1, NOW(), NOW())"
    );

    try {
        $mgr    = new CertificateManager(neria_test_module());
        $method = new ReflectionMethod($mgr, 'generatePdf');
        $method->setAccessible(true);
        $result = $method->invoke(
            $mgr,
            'REGTEST581',
            $order,
            'Client Test 581',
            'Produit Test 581',
            'Note de test 581',
            'fr',
            null
        );

        neria_assert(!isset($result['error']), "generatePdf() a échoué : " . ($result['error'] ?? '?'));
        neria_assert(isset($result['sig_path']), "generatePdf() ne renvoie plus la clé sig_path — jeu de test invalide");

        neria_assert(
            strpos((string) $result['sig_path'], 'sig_order.png') !== false,
            "generatePdf() a résolu la signature du CONTEXTE BO courant (boutique {$contextShopId}) au lieu de celle de LA COMMANDE (boutique {$orderShopId}) — régression du bug corrigé le 06/09/2026 (round 307) (sig_path obtenu : '" . ($result['sig_path'] ?? '?') . "')"
        );
        neria_assert(
            strpos((string) $result['sig_path'], 'sig_context.png') === false,
            "generatePdf() a résolu la signature du CONTEXTE BO courant au lieu de celle de LA COMMANDE — régression du bug corrigé le 06/09/2026 (round 307)"
        );

        return [
            'pass'    => true,
            'message' => "CertificateManager::generatePdf() résout bien la signature manuscrite active de la boutique DE LA COMMANDE, pas du contexte BO courant de l'employé — bug corrigé le 06/09/2026 (round 307)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_signature WHERE signer_name IN ('Regtest581Ctx', 'Regtest581Order')");
        @unlink($pathContext);
        @unlink($pathOrder);
        @rmdir($sigDir);
    }
}
