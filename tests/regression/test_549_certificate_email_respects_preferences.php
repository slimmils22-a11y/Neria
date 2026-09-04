<?php
/**
 * Régression : PreferencesManager::TEMPLATE_CAT doit couvrir
 * 'certificate_email' (CertificateManager::send(), certificat PDF
 * d'authenticité joint à une commande) — sinon isAllowed() le traite
 * comme "non classé" et autorise TOUJOURS son envoi, même à un client
 * ayant explicitement désactivé la catégorie 'post' correspondante,
 * malgré un appel isAllowed() explicitement présent avant Mail::Send()
 * dans CertificateManager (garde-fou visible mais inopérant).
 *
 * Bug identifié et corrigé le 03/09/2026 (round 294, audit "chemins
 * d'envoi échappant au système de préférences") : 'certificate_email'
 * n'avait aucune entrée dans TEMPLATE_CAT, contrairement à ses voisins
 * directs de la même famille 'post' ('care_certificate',
 * 'certificate_provenance').
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    // Le client désactive explicitement la catégorie 'post'.
    $db->execute(
        "DELETE FROM {$prefix}neria_preferences
         WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND category = 'post'"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_preferences (id_customer, id_shop, email, category, subscribed, date_upd)
         VALUES ({$idCustomer}, {$idShop}, '', 'post', 0, NOW())"
    );

    try {
        $mgr = new PreferencesManager(neria_test_module());

        neria_assert(
            isset(PreferencesManager::TEMPLATE_CAT['certificate_email']),
            "'certificate_email' est de nouveau absent de TEMPLATE_CAT — régression du bug corrigé le 03/09/2026 (round 294)"
        );
        neria_assert(
            PreferencesManager::TEMPLATE_CAT['certificate_email'] === 'post',
            "'certificate_email' n'est plus classé dans la catégorie 'post' — incohérent avec ses voisins directs 'care_certificate'/'certificate_provenance'"
        );

        $allowed = $mgr->isAllowed($idCustomer, 'certificate_email', $idShop);
        neria_assert(
            $allowed === false,
            "isAllowed() autorise encore l'envoi de 'certificate_email' à un client ayant désactivé la catégorie 'post' — régression du bug corrigé le 03/09/2026 (round 294) : le certificat PDF continuerait d'être envoyé en violation de l'opt-out client, malgré un appel isAllowed() présent mais inopérant dans CertificateManager"
        );

        // Vérification structurelle du site d'appel réel.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
        neria_assert($src !== false, 'Impossible de lire src/CertificateManager.php');
        neria_assert(
            strpos($src, "->isAllowed((int) \$order->id_customer, 'certificate_email', \$idShop, \$to)") !== false,
            "CertificateManager n'appelle plus PreferencesManager::isAllowed('certificate_email', ...) avant l'envoi — le garde-fou lui-même aurait disparu"
        );

        return [
            'pass'    => true,
            'message' => "PreferencesManager respecte désormais l'opt-out 'post' pour certificate_email (CertificateManager) — bug corrigé le 03/09/2026 (round 294)",
        ];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_preferences
             WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND category = 'post'"
        );
    }
}
