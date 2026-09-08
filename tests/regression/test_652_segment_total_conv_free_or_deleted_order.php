<?php
/**
 * Régression : SegmentManager::recomputeAll() calcule total_conv en excluant
 * les conversions remboursées à ≥90% (round 300) via :
 *   id_order <= 0
 *   OR COALESCE(SUM(order_slip...), 0) < 0.9 * COALESCE(o.total_paid_tax_incl, 0)
 *
 * Quand o.total_paid_tax_incl vaut 0 — commande à 0€ (coupon -100%, offre)
 * OU commande supprimée depuis (la sous-requête `orders` ne renvoie rien,
 * COALESCE retombe à 0) — le seuil devient 0.9*0=0. Le montant remboursé
 * (COALESCE(SUM(order_slip...),0), généralement 0 puisqu'aucun remboursement
 * réel n'a eu lieu) donne la comparaison '0 < 0', qui est FAUSSE. La
 * conversion, pourtant réelle et jamais remboursée, était donc exclue à
 * tort de total_conv — empêchant un client fidèle (achats répétés via
 * coupons -100%, ou dont une commande a été corrigée/supprimée en BO) de
 * jamais atteindre les segments 'loyal'/'ambassador'.
 *
 * Corrigé le 08/09/2026 (round 326) : ajout d'une clause explicite
 * `OR COALESCE(o.total_paid_tax_incl, 0) <= 0` testée AVANT le calcul du
 * seuil, ne dépendant jamais d'une comparaison 0 < 0.
 *
 * Test comportemental réel : 2 vraies lignes neria_stat 'conversion' pour
 * le même client — l'une avec id_order pointant vers une commande
 * INEXISTANTE (simule commande supprimée, total_paid_tax_incl introuvable
 * -> COALESCE 0, même mécanisme qu'une commande à 0€), l'autre normale
 * (id_order <= 0, cas déjà correctement compté avant ce correctif, sert de
 * témoin) — appelle recomputeAll() et vérifie que total_conversions = 2
 * (pas 1).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $module     = neria_test_module();
    $idShop     = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $template   = 'neria_test_round326_' . time();
    $fakeOrder  = 999777; // id_order fictif, garanti absent de ps_orders

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '{$template}'");
    $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");

    try {
        $orderExists = (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}orders WHERE id_order = {$fakeOrder}");
        neria_assert($orderExists === 0, "jeu de test invalide : la commande fictive {$fakeOrder} existe réellement (collision d'id)");

        // sent (requis pour que le client apparaisse dans l'agrégat) — daté
        // à J-30 : NEW_CUSTOMER_GRACE_DAYS=14 exclut du recalcul tout client
        // sans ouverture dont le premier envoi est trop récent (round
        // 2026-07-22), ce qui masquerait le mécanisme testé ici.
        $db->execute(
            "INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add, is_mpp)
             VALUES ({$idShop}, {$idCustomer}, '{$template}', 'fr', 'sent', 'regtest652_sent_" . uniqid() . "', DATE_SUB(NOW(), INTERVAL 30 DAY), 0)"
        );
        // Conversion 1 : id_order <= 0 — déjà correctement comptée avant ce
        // correctif (témoin, ne doit pas régresser).
        $db->execute(
            "INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add, is_mpp, id_order)
             VALUES ({$idShop}, {$idCustomer}, '{$template}', 'fr', 'conversion', 'regtest652_conv1_" . uniqid() . "', NOW(), 0, 0)"
        );
        // Conversion 2 : id_order pointant vers une commande inexistante —
        // reproduit exactement le mécanisme du bug (COALESCE(total_paid,0)=0).
        $db->execute(
            "INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add, is_mpp, id_order)
             VALUES ({$idShop}, {$idCustomer}, '{$template}', 'fr', 'conversion', 'regtest652_conv2_" . uniqid() . "', NOW(), 0, {$fakeOrder})"
        );

        $mgr = new SegmentManager($module);
        $mgr->recomputeAll();

        $row = $db->getRow(
            "SELECT total_conversions FROM {$prefix}neria_customer_segment
             WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}"
        );
        neria_assert($row !== false, "SegmentManager::recomputeAll() n'a produit aucune ligne de segment pour le client de test — jeu de test invalide");

        neria_assert(
            (int) $row['total_conversions'] === 2,
            "SegmentManager::recomputeAll() ne compte plus la conversion liée à une commande introuvable/à 0€ (total_conversions=" . $row['total_conversions'] . ", attendu 2) — régression du bug corrigé le 08/09/2026 (round 326) : un client fidèle (coupons -100%, ou commande supprimée en BO) ne pourrait plus jamais atteindre les segments loyal/ambassador"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template = '{$template}'");
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$idCustomer} AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "SegmentManager::recomputeAll() compte bien une conversion liée à une commande introuvable ou à 0€ comme non remboursée (COALESCE(total_paid,0)<=0), au lieu de l'exclure à tort via une comparaison 0 < 0 — bug corrigé le 08/09/2026 (round 326)",
    ];
}
