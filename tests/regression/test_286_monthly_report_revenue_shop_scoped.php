<?php
/**
 * Régression : MonthlyReportManager::getRevenueByTemplate() — la
 * sous-requête "revenu attribué" (clic suivi d'une commande dans les 7
 * jours) filtrait s.id_shop côté stats mais PAS o.id_shop côté orders.
 * En multi-boutique avec comptes clients partagés, une commande passée
 * sur une AUTRE boutique par le même client (id_customer partagé) après
 * un clic enregistré sur CETTE boutique pouvait être comptée comme
 * "revenu attribué" ici — sur-estimation du CA affiché au marchand, sans
 * rapport avec un email réellement envoyé par cette boutique.
 *
 * Corrigé le 13/08/2026 (round 165) : `AND o.id_shop = {$this->idShop}`
 * ajouté au JOIN de la sous-requête.
 *
 * Test fonctionnel réel : enregistre un clic pour la boutique 1, passe la
 * commande correspondante pour la boutique 2 (même client), vérifie que
 * cette commande n'apparaît PAS dans le CA attribué de la boutique 1.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $template   = 'regtest_round165_shopleak_' . time();

    // Boutique 2 fictive plausible : shop id 2 n'existe pas forcément en
    // dev, mais o.id_shop n'a pas besoin d'être une boutique réellement
    // active pour ce test — seule la valeur numérique compte pour vérifier
    // que le filtre SQL l'exclut bien du calcul de la boutique 1.
    $otherShopId = 999;

    // Clic enregistré sur la boutique 1 (id_shop=1, la boutique de test
    // standard utilisée dans toute la suite).
    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
         VALUES
            (1, '" . pSQL($template) . "', 'fr', {$idCustomer}, 0, '" . bin2hex(random_bytes(16)) . "', 'click', NOW())"
    );

    // Commande passée sur une AUTRE boutique (id_shop=999) par le même
    // client, dans la fenêtre d'attribution de 7 jours.
    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$otherShopId},1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,555.55,555.55,555.55,0,555.55,555.55,1, NOW(), NOW())");
    $idOrderOtherShop = (int) $db->Insert_ID();

    try {
        $mgr = new MonthlyReportManager(neria_test_module());
        $ref = new ReflectionMethod(MonthlyReportManager::class, 'getRevenueByTemplate');
        $ref->setAccessible(true);

        // idShop de l'instance est déjà 1 par défaut (Context de test).
        $dateFrom = date('Y-m-d', strtotime('-1 day'));
        $dateTo   = date('Y-m-d', strtotime('+1 day'));
        $revenue  = $ref->invoke($mgr, $dateFrom, $dateTo);

        neria_assert(
            !isset($revenue[$template]) || (float) $revenue[$template] === 0.0,
            "getRevenueByTemplate() attribue " . ($revenue[$template] ?? 0) . "€ à la boutique 1 pour une commande passée sur une AUTRE boutique (id_shop={$otherShopId}) — régression du bug corrigé le 13/08/2026 (round 165) : le CA attribué fuiterait de nouveau entre boutiques partageant leurs clients"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '" . pSQL($template) . "'");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrderOtherShop}");
    }

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::getRevenueByTemplate() n'attribue plus le CA d'une commande passée sur une autre boutique — bug corrigé le 13/08/2026 (round 165)",
    ];
}
