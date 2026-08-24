<?php
/**
 * Régression : MonthlyReportManager::getRevenueByTemplate() sommait
 * o.total_paid_tax_incl sans dédupliquer (template, id_order) dans sa
 * requête de CA "direct" — contrairement à la requête "attribué" juste en
 * dessous, qui dédoublonne explicitement. Un renvoi manuel d'un email
 * transactionnel pour la MÊME commande (2e ligne 'sent' avec le même
 * id_order+template) faisait compter le montant de cette commande deux
 * fois.
 *
 * Bug réel identifié le 23/08/2026 (round 197) : gonflait le CA "direct"
 * affiché au marchand dans le rapport mensuel dès qu'un email transactionnel
 * était renvoyé (double-clic BO, retry manuel).
 *
 * Corrigé le 23/08/2026 (round 197) : sous-requête DISTINCT (template,
 * id_order, montant) avant la somme.
 *
 * Test comportemental réel : une commande réelle avec 2 lignes 'sent' pour
 * le même template — le CA "direct" ne doit compter le montant de cette
 * commande qu'UNE seule fois.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();

    neria_assert($idCustomer > 0, 'jeu de test invalide : aucun client actif trouvé');

    $orderTotal = 123.45;
    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,{$orderTotal},{$orderTotal},{$orderTotal},{$orderTotal},{$orderTotal},{$orderTotal},1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();
    neria_assert($idOrder > 0, 'jeu de test invalide : INSERT order échoué');

    $template = 'test_422_round197';
    $tokenA = bin2hex(random_bytes(16));
    $tokenB = bin2hex(random_bytes(16));

    try {
        // Deux lignes 'sent' pour la MÊME commande+template (renvoi manuel).
        foreach ([$tokenA, $tokenB] as $token) {
            $db->execute(
                "INSERT INTO {$prefix}neria_stat
                    (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
                 VALUES
                    ({$idShop}, '" . pSQL($template) . "', 'fr', {$idCustomer}, {$idOrder}, '" . pSQL($token) . "', 'sent', NOW())"
            );
        }

        $mgr = new MonthlyReportManager($module);
        $ref = new ReflectionMethod(MonthlyReportManager::class, 'getRevenueByTemplate');
        $ref->setAccessible(true);
        $result = $ref->invoke($mgr, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')));

        neria_assert(isset($result[$template]), "jeu de test invalide : le template {$template} n'apparaît pas dans le résultat");
        $revenue = (float) $result[$template];
        neria_assert(
            abs($revenue - $orderTotal) < 0.01,
            "getRevenueByTemplate() retourne {$revenue} au lieu de {$orderTotal} pour une commande avec 2 lignes 'sent' du même template — régression du bug corrigé le 23/08/2026 (round 197) : le CA direct compterait de nouveau cette commande deux fois (renvoi manuel d'un email transactionnel)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_order = {$idOrder}");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::getRevenueByTemplate() ne compte plus deux fois une commande avec 2 envois du même template — bug corrigé le 23/08/2026 (round 197)",
    ];
}
