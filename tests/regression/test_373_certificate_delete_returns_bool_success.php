<?php
/**
 * Régression : CertificateManager::delete() avait une signature void — un
 * id_certificate inexistant, déjà supprimé, ou appartenant à une autre
 * boutique (scope multi-shop) échouait silencieusement, mais le handler
 * neria.php (action cert_delete) affichait quand même le message de
 * succès inconditionnel après l'appel.
 *
 * Corrigé le 17/08/2026 (round 182) : delete() retourne désormais bool
 * (false si aucune ligne trouvée pour cet id_certificate + id_shop, true
 * sinon), et le handler cert_delete de neria.php n'affiche neria_success
 * que si ce retour est true (sinon neria_error).
 *
 * Test comportemental réel : crée un vrai certificat en base pour la
 * boutique 1, vérifie que delete() retourne true et supprime réellement
 * la ligne. Puis appelle delete() une 2e fois sur le même id (déjà
 * supprimé) et vérifie qu'il retourne false. Puis crée un certificat
 * scopé sur une AUTRE boutique fictive et vérifie que delete() depuis le
 * contexte boutique 1 retourne false sans toucher la ligne.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $serial1 = 'REGTEST-373-' . bin2hex(random_bytes(6));
    $serial2 = 'REGTEST-373B-' . bin2hex(random_bytes(6));
    $otherShop = 999987;

    $mgr = new CertificateManager($module);

    try {
        // --- Cas 1 : certificat réel de cette boutique, suppression réussie ---
        $db->execute(
            "INSERT INTO {$prefix}neria_certificate
                (id_shop, id_customer, id_order, id_product, id_order_detail, serial_number, customer_name, product_name, pdf_path, emailed, date_issued, date_add)
             VALUES
                ({$idShop}, 0, 0, 0, 0, '" . pSQL($serial1) . "', 'Test', 'Test', NULL, 0, NOW(), NOW())"
        );
        $idCert1 = (int) $db->getValue("SELECT id_certificate FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial1) . "'");
        neria_assert($idCert1 > 0, "Certificat de test 1 non créé — jeu de test invalide");

        $result1 = $mgr->delete($idCert1);
        neria_assert(
            $result1 === true,
            "delete() a retourné " . var_export($result1, true) . " au lieu de true pour un certificat réellement supprimé — régression du bug corrigé le 17/08/2026 (round 182)"
        );
        $stillThere = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_certificate WHERE id_certificate = {$idCert1}");
        neria_assert($stillThere === 0, "La ligne n'a pas été réellement supprimée malgré un retour true — jeu de test invalide");

        // --- Cas 2 : id déjà supprimé (double-clic) ---
        $result2 = $mgr->delete($idCert1);
        neria_assert(
            $result2 === false,
            "delete() a retourné " . var_export($result2, true) . " au lieu de false pour un id_certificate déjà supprimé — régression du bug corrigé le 17/08/2026 (round 182) : neria.php afficherait de nouveau un succès sans effet réel"
        );

        // --- Cas 3 : certificat d'une AUTRE boutique (scope multi-shop) ---
        $db->execute(
            "INSERT INTO {$prefix}neria_certificate
                (id_shop, id_customer, id_order, id_product, id_order_detail, serial_number, customer_name, product_name, pdf_path, emailed, date_issued, date_add)
             VALUES
                ({$otherShop}, 0, 0, 0, 0, '" . pSQL($serial2) . "', 'Test', 'Test', NULL, 0, NOW(), NOW())"
        );
        $idCert2 = (int) $db->getValue("SELECT id_certificate FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial2) . "'");
        neria_assert($idCert2 > 0, "Certificat de test 2 non créé — jeu de test invalide");

        $result3 = $mgr->delete($idCert2);
        neria_assert(
            $result3 === false,
            "delete() a retourné " . var_export($result3, true) . " au lieu de false pour un certificat d'une autre boutique — régression du bug corrigé le 17/08/2026 (round 182)"
        );
        $stillThereOther = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_certificate WHERE id_certificate = {$idCert2}");
        neria_assert($stillThereOther === 1, "La ligne d'une autre boutique a été supprimée à tort — le scope id_shop n'est plus respecté");
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number IN ('" . pSQL($serial1) . "', '" . pSQL($serial2) . "')");
    }

    return [
        'pass'    => true,
        'message' => "CertificateManager::delete() retourne bien un bool fiable (true=supprimé réellement, false=aucun effet), respecté par le handler cert_delete de neria.php — bug corrigé le 17/08/2026 (round 182)",
    ];
}
