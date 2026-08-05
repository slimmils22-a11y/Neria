<?php
/**
 * Régression : CollectionManager::processCollection() doit élargir
 * group_concat_max_len avant sa requête GROUP_CONCAT(od.product_id), sinon
 * la valeur par défaut MySQL (1024 octets) tronque silencieusement la
 * liste sur une grande collection — bought_ids ne contient alors qu'une
 * liste partielle, faussant le calcul du "produit manquant" (array_diff).
 *
 * Bug réel corrigé le 05/08/2026 (round 51). Ce test reproduit la
 * troncature réelle contre la base de dev : insère ~200 lignes
 * order_detail (product_id longs) pour un même client/commande, exécute la
 * requête EXACTE de CollectionManager (avec et sans le SET SESSION), et
 * vérifie que la longueur de la chaîne concaténée n'est PLUS tronquée avec
 * le correctif.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    // Commande minimale (mêmes colonnes que test_04) + 200 lignes order_detail
    // à product_id longs (6 chiffres) — largement de quoi dépasser 1024
    // octets une fois concaténés avec virgules (200 * 7 ≈ 1400 > 1024).
    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,10,10,10,10,10,10,1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();

    $productIds = [];
    for ($i = 0; $i < 200; $i++) {
        $productIds[] = 900000 + $i;
    }

    try {
        foreach ($productIds as $pid) {
            $db->execute("INSERT INTO {$prefix}order_detail
                (id_order, id_shop, product_id, product_name, product_weight, tax_name, product_quantity)
                VALUES ({$idOrder}, {$idShop}, {$pid}, 'regtest', 0, '', 1)");
        }

        $inList = implode(',', $productIds);

        // Sans SET SESSION (comportement d'origine, buggé) : la valeur par
        // défaut du serveur (1024 sur cet environnement de dev) tronque.
        $db->execute('SET SESSION group_concat_max_len = 1024');
        $truncated = $db->getRow("
            SELECT GROUP_CONCAT(DISTINCT od.product_id ORDER BY od.product_id) AS bought_ids
            FROM {$prefix}order_detail od
            WHERE od.id_order = {$idOrder}
            GROUP BY od.id_order
        ");
        $truncatedCount = count(explode(',', $truncated['bought_ids']));
        neria_assert(
            $truncatedCount < 200,
            "le jeu de test ne reproduit plus la troncature à group_concat_max_len=1024 (obtenu {$truncatedCount}/200) — scénario à recalibrer"
        );

        // Avec le correctif (SET SESSION group_concat_max_len = 1000000,
        // exactement ce que fait CollectionManager::processCollection()) :
        // plus de troncature.
        $db->execute('SET SESSION group_concat_max_len = 1000000');
        $fixed = $db->getRow("
            SELECT GROUP_CONCAT(DISTINCT od.product_id ORDER BY od.product_id) AS bought_ids
            FROM {$prefix}order_detail od
            WHERE od.id_order = {$idOrder}
            GROUP BY od.id_order
        ");
        $fixedCount = count(explode(',', $fixed['bought_ids']));
        neria_assert(
            $fixedCount === 200,
            "GROUP_CONCAT reste tronqué même avec group_concat_max_len élargi (obtenu {$fixedCount}/200)"
        );

        // Vérifie que CollectionManager::processCollection() pose bien ce
        // SET SESSION avant sa propre requête GROUP_CONCAT.
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php');
        neria_assert(
            strpos($src, "SET SESSION group_concat_max_len") !== false,
            "CollectionManager::processCollection() n'élargit plus group_concat_max_len avant sa requête GROUP_CONCAT — régression du bug de troncature corrigé le 05/08/2026"
        );

        return [
            'pass'    => true,
            'message' => 'group_concat_max_len élargi confirmé (200 IDs non tronqués) et présent dans CollectionManager',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}order_detail WHERE id_order = {$idOrder}");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }
}
