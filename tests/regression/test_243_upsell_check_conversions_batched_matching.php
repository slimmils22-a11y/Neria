<?php
/**
 * Régression : UpsellManager::checkConversions() doit toujours détecter et
 * enregistrer correctement une conversion réelle après le passage au
 * pré-chargement groupé des commandes candidates (round 153, correctif
 * N+1 — auparavant un getRow() par ligne neria_upsell non convertie,
 * jusqu'à plusieurs centaines de requêtes SQL individuelles selon le
 * trafic upsell des 14 derniers jours).
 *
 * Test comportemental réel : insère une ligne neria_upsell cliquée (non
 * convertie) pour un produit donné, puis une VRAIE commande valide de ce
 * même client contenant CE produit, passée APRÈS le clic et DANS la
 * fenêtre de 7 jours — appelle checkConversions() et vérifie que la ligne
 * est bien marquée convertie avec le bon id_order et le bon montant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $idProduct  = 900153; // produit factice, id élevé pour ne pas collisionner

    $clickedAt = date('Y-m-d H:i:s', strtotime('-2 days'));

    $db->execute(
        "INSERT INTO {$prefix}neria_upsell
            (id_customer, id_shop, id_product_upsell, product_name, tier, reason, clicked_at, sent_at)
         VALUES ({$idCustomer}, {$idShop}, {$idProduct}, 'Regtest Round153', 'bestseller', 'regtest', '{$clickedAt}', '{$clickedAt}')"
    );
    $idUpsell = (int) $db->Insert_ID();

    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,150,150,150,150,150,150,1, DATE_ADD('{$clickedAt}', INTERVAL 1 DAY), NOW())");
    $idOrder = (int) $db->Insert_ID();

    $db->execute("INSERT INTO {$prefix}order_detail
        (id_order, id_shop, product_id, product_name, product_weight, tax_name, product_quantity, unit_price_tax_incl)
        VALUES ({$idOrder}, {$idShop}, {$idProduct}, 'regtest', 0, '', 1, 150.00)");

    try {
        $mgr = new UpsellManager(neria_test_module());
        $n = $mgr->checkConversions();

        $row = $db->getRow("SELECT id_order_converted, conversion_amount FROM {$prefix}neria_upsell WHERE id_upsell = {$idUpsell}");
        neria_assert(
            $row !== false && (int) $row['id_order_converted'] === $idOrder,
            "checkConversions() n'a pas relie la conversion a la bonne commande (attendu id_order={$idOrder}, obtenu " . var_export($row['id_order_converted'] ?? null, true) . ") — regression du bug corrige le 09/08/2026 (round 153) : le pre-chargement groupe des candidats aurait casse la correspondance client/produit/fenetre de 7 jours"
        );
        neria_assert(
            abs((float) $row['conversion_amount'] - 150.00) < 0.01,
            "checkConversions() a enregistre un montant de conversion incorrect (attendu 150.00, obtenu {$row['conversion_amount']})"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_upsell WHERE id_upsell = {$idUpsell}");
        $db->execute("DELETE FROM {$prefix}order_detail WHERE id_order = {$idOrder}");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }

    return [
        'pass'    => true,
        'message' => "UpsellManager::checkConversions() detecte toujours correctement une conversion reelle apres le passage au pre-chargement groupe des commandes candidates",
    ];
}
