<?php
/**
 * Régression : PropensityScoreManager::recalculateAll() doit produire
 * EXACTEMENT les mêmes scores que le calcul par client (recalculateCustomer())
 * après le passage au pré-chargement groupé (round 154, correctif N+1 —
 * auparavant 7 requêtes SQL PAR CLIENT, jusqu'à ~35 000 requêtes/nuit sur
 * une boutique de 5000 clients ayant commandé).
 *
 * Test comportemental réel : crée une commande réelle valide pour un
 * client de test, appelle recalculateCustomer() (chemin non-batché,
 * référence) PUIS recalculateAll() (chemin batché) et vérifie que le
 * score persisté en base (recency/frequency/engagement/seasonality/total)
 * est identique dans les deux cas.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    // Commande récente et valide — garantit un score de récence non nul
    // pour rendre la comparaison significative.
    $db->execute("INSERT INTO {$prefix}orders
        (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
        VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,80,80,80,80,80,80,1, NOW(), NOW())");
    $idOrder = (int) $db->Insert_ID();

    try {
        $mgr = new PropensityScoreManager(neria_test_module());

        // Référence : calcul par client (chemin non-batché, jamais touché).
        $mgr->recalculateCustomer($idCustomer);
        $reference = $db->getRow(
            "SELECT score, score_recency, score_frequency, score_engagement, score_seasonality
             FROM {$prefix}neria_propensity_score
             WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}"
        );
        neria_assert($reference !== false, "recalculateCustomer() n'a pas persisté de score — jeu de test invalide");

        // Chemin batché (round 154) — doit produire EXACTEMENT le même résultat.
        $mgr->recalculateAll();
        $batched = $db->getRow(
            "SELECT score, score_recency, score_frequency, score_engagement, score_seasonality
             FROM {$prefix}neria_propensity_score
             WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}"
        );
        neria_assert($batched !== false, "recalculateAll() n'a pas persisté de score pour ce client — regression du bug corrige le 09/08/2026 (round 154)");

        foreach (['score', 'score_recency', 'score_frequency', 'score_engagement', 'score_seasonality'] as $field) {
            neria_assert(
                (int) $reference[$field] === (int) $batched[$field],
                "recalculateAll() (batche) donne un resultat different de recalculateCustomer() (reference) pour '{$field}' (reference=" . $reference[$field] . ", batche=" . $batched[$field] . ") — regression du bug corrige le 09/08/2026 (round 154) : le pre-chargement groupe des agregats aurait casse la formule de calcul"
            );
        }
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_propensity_score WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
        $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
    }

    return [
        'pass'    => true,
        'message' => "PropensityScoreManager::recalculateAll() (batche) produit exactement le meme score que recalculateCustomer() (reference non-batchee)",
    ];
}
