<?php
/**
 * Régression round 243 (30/08/2026) : CertificateManager::redownload() ne
 * filtre volontairement PAS par `id_shop` = contexte BO courant (voir
 * commentaire de la méthode et test_111 — nécessaire pour que le bouton de
 * retéléchargement fonctionne sur la fiche commande en multi-boutique, un
 * cas légitime où le sélecteur d'en-tête ne correspond pas forcément à la
 * boutique réelle de la commande consultée).
 *
 * Mais l'absence TOTALE de tout contrôle laissait un employé dont le PROFIL
 * est restreint à un sous-ensemble de boutiques (association
 * ps_employee_shop, distincte du sélecteur d'en-tête) récupérer le PDF de
 * n'importe quel certificat en changeant simplement id_certificate dans la
 * requête POST — fuite de données clients (nom, produit, note artisan)
 * entre boutiques d'une même installation, hors du périmètre auquel
 * l'employé est censé avoir accès.
 *
 * Corrigé le 30/08/2026 (round 243) : redownload() vérifie désormais
 * Employee::hasAuthOnShop($row['id_shop']) — qui contrôle les boutiques
 * RÉELLEMENT assignées à l'employé (association), pas le sélecteur BO
 * courant — donc sans casser le cas légitime déjà couvert par test_111.
 *
 * Test comportemental réel : construit un vrai Employee (id_profile !=
 * _PS_ADMIN_PROFILE_ pour ne pas court-circuiter le contrôle via
 * isSuperAdmin()) avec, par Reflection, une liste de boutiques associées ne
 * contenant PAS la boutique du certificat — vérifie que redownload()
 * refuse. Puis élargit la liste pour inclure cette boutique — vérifie que
 * redownload() réussit.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    if (!class_exists('CertificateManager')) {
        return ['pass' => true, 'message' => 'CertificateManager absent — test ignoré (classe non chargée)'];
    }

    $serial     = 'REGTEST478-' . uniqid();
    $idCustomer = neria_test_any_customer_id();
    $certShop   = (int) Context::getContext()->shop->id;
    $otherShop  = $certShop === 1 ? 2 : 1;

    $db->execute(
        "INSERT INTO {$prefix}orders (id_shop, id_shop_group, id_customer, id_currency, id_lang, id_address_delivery, id_address_invoice, id_carrier, current_state, payment, conversion_rate, total_paid, total_paid_tax_incl, total_products, total_products_wt, valid, date_add, date_upd, invoice_number, invoice_date, delivery_number, delivery_date, secure_key)
         VALUES ({$certShop}, 1, {$idCustomer}, 1, 1, 0, 0, 1, 1, 'regtest', 1, 10, 10, 10, 10, 1, NOW(), NOW(), 0, '0000-00-00 00:00:00', 0, '0000-00-00 00:00:00', 'regtest478')"
    );
    $idOrderFake = (int) $db->Insert_ID();

    $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial) . "'");

    $originalEmployee = Context::getContext()->employee;

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_certificate
                (id_shop, id_order, id_product, serial_number, customer_name, product_name, date_issued, date_add)
             VALUES ({$certShop}, {$idOrderFake}, 1, '" . pSQL($serial) . "', 'Regtest478', 'Regtest478', NOW(), NOW())"
        );
        $idCert = (int) $db->Insert_ID();
        neria_assert($idCert > 0, "jeu de test invalide : l'INSERT de test a échoué");

        // Employé fictif, non super-admin, dont les boutiques associées ne
        // contiennent PAS $certShop.
        $employee = new Employee();
        $employee->id = 999999; // Validate::isLoadedObject() exige un id truthy
        $employee->id_profile = (int) _PS_ADMIN_PROFILE_ + 999; // != _PS_ADMIN_PROFILE_
        $refAssoc = new ReflectionProperty(Employee::class, 'associated_shops');
        $refAssoc->setAccessible(true);
        $refAssoc->setValue($employee, [$otherShop]);
        neria_assert(!$employee->isSuperAdmin(), "jeu de test invalide : l'employé fictif est détecté comme super-admin, hasAuthOnShop() serait court-circuité");
        neria_assert(!$employee->hasAuthOnShop($certShop), "jeu de test invalide : hasAuthOnShop() autorise déjà la boutique du certificat avant même le scénario testé");

        Context::getContext()->employee = $employee;

        $manager = new CertificateManager($module);
        $result  = $manager->redownload($idCert);
        neria_assert(
            isset($result['error']),
            "CertificateManager::redownload() n'a PAS refusé un employé dont les boutiques associées n'incluent pas celle du certificat — régression du bug corrigé le 30/08/2026 (round 243) : fuite de données clients inter-boutique"
        );

        // Élargit les boutiques associées pour inclure $certShop : doit
        // désormais réussir (le contrôle ne bloque pas un accès légitime).
        $refAssoc->setValue($employee, [$otherShop, $certShop]);
        $result2 = $manager->redownload($idCert);
        neria_assert(
            !isset($result2['error']),
            "CertificateManager::redownload() refuse encore un employé dont les boutiques associées INCLUENT pourtant celle du certificat ('" . ($result2['error'] ?? '') . "') — hasAuthOnShop() ne devrait pas bloquer un accès légitime"
        );

        return [
            'pass'    => true,
            'message' => "CertificateManager::redownload() refuse bien un employé non autorisé sur la boutique du certificat, et autorise un employé dont les boutiques associées l'incluent — bug corrigé le 30/08/2026 (round 243)",
        ];
    } finally {
        Context::getContext()->employee = $originalEmployee;
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number = '" . pSQL($serial) . "'");
        if ($idOrderFake > 0) {
            $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrderFake}");
        }
    }
}
