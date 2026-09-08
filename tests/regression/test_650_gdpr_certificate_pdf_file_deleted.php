<?php
/**
 * Régression : GdprAuditManager::purgeCustomerData() supprimait bien la
 * ligne SQL neria_certificate (round 95, cf. test_99), mais ne touchait
 * JAMAIS au fichier PDF sur disque référencé par sa colonne pdf_path.
 * Contrairement à CertificateManager::delete() (suppression manuelle
 * depuis le BO), qui fait un unlink() correct avant le DELETE.
 *
 * Bug réel : le PDF (nom client en clair, cf. CertificateManager::
 * generatePdf()) survivait indéfiniment sur le serveur, non référencé en
 * base, alors que purgeCustomerData() rapportait un effacement RGPD
 * "complet" au marchand — violation silencieuse du droit à l'effacement
 * (art. 17 RGPD) : les données SQL disparaissent, mais le document PDF
 * contenant les mêmes données personnelles reste physiquement accessible.
 *
 * Corrigé le 08/09/2026 (round 326) : purgeCustomerData() lit désormais
 * pdf_path AVANT le DELETE et fait un unlink() de chaque fichier existant,
 * même pattern que CertificateManager::delete().
 *
 * Test comportemental réel : crée un vrai fichier PDF factice sur disque
 * sous certificates/, une vraie ligne neria_certificate y référençant via
 * pdf_path, appelle purgeCustomerData(), vérifie que la ligne SQL ET le
 * fichier disque ont bien disparu tous les deux.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    $relPath = 'certificates/regtest650_' . uniqid() . '.pdf';
    $absPath = _PS_MODULE_DIR_ . 'neria/' . $relPath;
    file_put_contents($absPath, '%PDF-1.4 regtest650 fake pdf content');
    neria_assert(file_exists($absPath), 'jeu de test invalide : le fichier PDF factice n\'a pas pu être créé');

    $db->execute(
        "INSERT INTO {$prefix}neria_certificate
            (id_shop, id_customer, id_order, id_product, serial_number, customer_name, product_name, pdf_path, date_issued, date_add)
         VALUES ({$idShop}, {$idCustomer}, 0, 0, 'REGTEST650-" . uniqid() . "', 'Regtest650', 'Regtest650', '{$relPath}', NOW(), NOW())"
    );
    $idCertificate = (int) $db->Insert_ID();

    try {
        neria_assert($idCertificate > 0, 'jeu de test invalide : l\'INSERT du certificat de test a échoué');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');
        $mgr->purgeCustomerData($idCustomer, '');

        $stillInDb = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_certificate WHERE id_certificate = {$idCertificate}");
        neria_assert(
            $stillInDb === 0,
            "jeu de test invalide : la ligne neria_certificate n'a pas été purgée — le test ne peut pas vérifier le comportement du unlink()"
        );

        neria_assert(
            !file_exists($absPath),
            "GdprAuditManager::purgeCustomerData() n'a pas supprimé le fichier PDF du certificat ({$relPath}) — régression du bug corrigé le 08/09/2026 (round 326) : une donnée personnelle en clair (nom client, commande) survivrait sur le disque du serveur malgré un effacement RGPD affiché comme complet"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE id_certificate = {$idCertificate}");
        if (file_exists($absPath)) {
            @unlink($absPath);
        }
    }

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::purgeCustomerData() supprime bien le fichier PDF du certificat sur disque, en plus de la ligne SQL — bug corrigé le 08/09/2026 (round 326)",
    ];
}
