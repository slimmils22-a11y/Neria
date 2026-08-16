<?php
/**
 * Régression : BehavioralCronManager::getCheckoutAbandonmentStats() et
 * getRelationshipAnniversaryStats() comptaient `emails_sent` sur TOUTE la
 * table neria_behavioral_sent, sans filtre id_shop — alors que
 * orders_recovered/orders_attributed (numérateur) sont bien scopés par
 * o.id_shop. Sur une install multi-boutiques, le dénominateur (envois)
 * portait sur toutes les boutiques tandis que le numérateur ne portait que
 * sur la boutique courante — conversion_rate/avg_order_value faussé et
 * sous-estimé. Le commentaire du code affirmait à tort que
 * neria_behavioral_sent n'avait pas de colonne id_shop (elle en a une,
 * partie de la clé UNIQUE, sql/install.sql).
 *
 * Corrigé le 16/08/2026 (round 180) : filtre `id_shop` ajouté sur les deux
 * requêtes de comptage `emails_sent`.
 *
 * Test comportemental réel : insère une ligne neria_behavioral_sent réelle
 * pour la boutique courante ET une pour une boutique fictive distincte —
 * emails_sent ne doit compter QUE celle de la boutique courante.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $otherShop = 999986;
    $idCustomer = neria_test_any_customer_id();

    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE template = 'checkout_abandonment' AND id_customer = {$idCustomer} AND ref_id IN (777771, 777772)");
    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE template = 'relationship_anniversary' AND id_customer = {$idCustomer} AND ref_id IN (777773, 777774)");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_behavioral_sent (id_customer, template, ref_id, id_shop, sent_at)
             VALUES
                ({$idCustomer}, 'checkout_abandonment', 777771, {$idShop}, NOW()),
                ({$idCustomer}, 'checkout_abandonment', 777772, {$otherShop}, NOW()),
                ({$idCustomer}, 'relationship_anniversary', 777773, {$idShop}, NOW()),
                ({$idCustomer}, 'relationship_anniversary', 777774, {$otherShop}, NOW())"
        );

        $baselineCheckout = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent WHERE template = 'checkout_abandonment' AND id_shop = {$idShop} AND id_customer != {$idCustomer}"
        );
        $baselineAnniv = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent WHERE template = 'relationship_anniversary' AND id_shop = {$idShop} AND id_customer != {$idCustomer}"
        );

        $mgr = new BehavioralCronManager($module);

        $checkoutStats = $mgr->getCheckoutAbandonmentStats();
        neria_assert(
            $checkoutStats['emails_sent'] === $baselineCheckout + 1,
            "getCheckoutAbandonmentStats()['emails_sent'] = {$checkoutStats['emails_sent']} au lieu de " . ($baselineCheckout + 1) . " — régression du bug corrigé le 16/08/2026 (round 180) : la ligne de la boutique fictive {$otherShop} serait de nouveau comptée dans les stats de la boutique courante"
        );

        $annivStats = $mgr->getRelationshipAnniversaryStats();
        neria_assert(
            $annivStats['emails_sent'] === $baselineAnniv + 1,
            "getRelationshipAnniversaryStats()['emails_sent'] = {$annivStats['emails_sent']} au lieu de " . ($baselineAnniv + 1) . " — régression du bug corrigé le 16/08/2026 (round 180) : la ligne de la boutique fictive {$otherShop} serait de nouveau comptée dans les stats de la boutique courante"
        );

        return [
            'pass'    => true,
            'message' => "BehavioralCronManager::getCheckoutAbandonmentStats()/getRelationshipAnniversaryStats() scopent bien emails_sent par id_shop — bug corrigé le 16/08/2026 (round 180)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE template = 'checkout_abandonment' AND id_customer = {$idCustomer} AND ref_id IN (777771, 777772)");
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE template = 'relationship_anniversary' AND id_customer = {$idCustomer} AND ref_id IN (777773, 777774)");
    }
}
