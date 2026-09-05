<?php
/**
 * Régression : StatsManager::adjustConversionRevenueForOrder() filtrait
 * TOUJOURS son UPDATE sur $this->idShop — fixé une seule fois dans le
 * constructeur à partir du contexte BO AMBIANT (Context::getContext()->
 * shop->id), jamais celui de la commande réellement remboursée.
 *
 * Contraste révélateur : la méthode sœur recordConversion() reçoit un
 * paramètre $idShop explicite (avec vérification anti-fuite cross-shop
 * dédiée). adjustConversionRevenueForOrder(), ajoutée plus tard (round 185)
 * pour le même sujet (attribution de revenu), n'avait jamais reçu ce même
 * traitement alors qu'elle touche la même table (ps_neria_stat).
 *
 * Scénario réel : sur une install multi-boutiques où le contexte BO
 * courant (liste "toutes boutiques", ou reliquat d'une boutique
 * précédente) diffère de order->id_shop, l'UPDATE ne matchait aucune ligne
 * — le revenu attribué restait à son montant ORIGINAL dans
 * getRevenueStats()/dashboards ROI de la boutique de la commande, malgré
 * le remboursement.
 *
 * Corrigé le 05/09/2026 (round 302) : $idShop optionnel ajouté (défaut
 * null = repli sur $this->idShop, comportement historique inchangé),
 * OrderTriggersManager::handleRefund() transmet désormais explicitement
 * (int) $order->id_shop.
 *
 * Test comportemental réel : insère 2 lignes 'conversion' pour le MÊME
 * id_order mais 2 id_shop différents (simulant une commande de la
 * boutique B alors que le contexte StatsManager ambiant serait A) ; vérifie
 * que seule la ligne de la boutique explicitement ciblée est ajustée, et
 * que l'appel sans $idShop (repli historique) cible bien $this->idShop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $db     = Db::getInstance();
    $prefix = _DB_PREFIX_;
    $table  = $prefix . 'neria_stat';

    $realShopId = (int) Context::getContext()->shop->id;
    $fakeShopId = 999888; // boutique fictive — n'existe pas réellement, sert juste à distinguer les 2 lignes
    $idOrder    = 888777; // commande fictive — aucune ligne réelle ne doit exister

    $cleanup = function () use ($db, $table, $idOrder) {
        $db->execute("DELETE FROM `{$table}` WHERE id_order = {$idOrder}");
    };
    $cleanup();

    try {
        $db->execute(
            "INSERT INTO `{$table}` (id_shop, template, lang, country_code, id_customer, id_order, ref_scope, tracking_token, event_type, is_mpp, abtest_variant, revenue, ip_address, user_agent, date_add)
             VALUES ({$realShopId}, 'order_conf', 'fr', 'FR', 1, {$idOrder}, '', 'regtest567_real', 'conversion', 0, '', 100.00, '', '', NOW())"
        );
        $db->execute(
            "INSERT INTO `{$table}` (id_shop, template, lang, country_code, id_customer, id_order, ref_scope, tracking_token, event_type, is_mpp, abtest_variant, revenue, ip_address, user_agent, date_add)
             VALUES ({$fakeShopId}, 'order_conf', 'fr', 'FR', 1, {$idOrder}, '', 'regtest567_fake', 'conversion', 0, '', 100.00, '', '', NOW())"
        );

        $mgr = new StatsManager(neria_test_module());

        // Ajustement explicitement scopé à la boutique FICTIVE — la ligne
        // de la vraie boutique ne doit PAS être touchée.
        $mgr->adjustConversionRevenueForOrder($idOrder, 40.00, $fakeShopId);

        $revenueFake = (float) $db->getValue(
            "SELECT revenue FROM `{$table}` WHERE id_order = {$idOrder} AND id_shop = {$fakeShopId}",
            false
        );
        $revenueReal = (float) $db->getValue(
            "SELECT revenue FROM `{$table}` WHERE id_order = {$idOrder} AND id_shop = {$realShopId}",
            false
        );

        neria_assert(
            abs($revenueFake - 40.00) < 0.001,
            "adjustConversionRevenueForOrder(\$idOrder, 40.00, \$fakeShopId) n'a pas mis à jour la ligne de la boutique {$fakeShopId} (revenue={$revenueFake}) — régression : le scoping explicite par \$idShop ne fonctionne plus"
        );
        neria_assert(
            abs($revenueReal - 100.00) < 0.001,
            "adjustConversionRevenueForOrder(\$idOrder, 40.00, \$fakeShopId) a modifié à tort la ligne de la boutique réelle {$realShopId} (revenue={$revenueReal}) — régression du bug corrigé le 05/09/2026 (round 302) : l'UPDATE n'est plus scopé par boutique et affecterait de nouveau toute ligne du même id_order, quelle que soit sa boutique"
        );

        // Repli historique : sans $idShop explicite, doit cibler
        // $this->idShop (contexte ambiant = la boutique réelle ici).
        $mgr->adjustConversionRevenueForOrder($idOrder, 77.00);
        $revenueRealAfterDefault = (float) $db->getValue(
            "SELECT revenue FROM `{$table}` WHERE id_order = {$idOrder} AND id_shop = {$realShopId}",
            false
        );
        $revenueFakeAfterDefault = (float) $db->getValue(
            "SELECT revenue FROM `{$table}` WHERE id_order = {$idOrder} AND id_shop = {$fakeShopId}",
            false
        );
        neria_assert(
            abs($revenueRealAfterDefault - 77.00) < 0.001,
            "adjustConversionRevenueForOrder(\$idOrder, 77.00) sans \$idShop explicite ne cible plus \$this->idShop (contexte ambiant) — repli historique cassé"
        );
        neria_assert(
            abs($revenueFakeAfterDefault - 40.00) < 0.001,
            "adjustConversionRevenueForOrder(\$idOrder, 77.00) sans \$idShop explicite a modifié à tort la ligne de la boutique fictive {$fakeShopId} — le repli sur \$this->idShop ne scope plus correctement"
        );

        return [
            'pass'    => true,
            'message' => "StatsManager::adjustConversionRevenueForOrder() scope bien son UPDATE par la boutique explicitement transmise (ou \$this->idShop par défaut), sans affecter les lignes des autres boutiques du même id_order — bug corrigé le 05/09/2026 (round 302)",
        ];
    } finally {
        $cleanup();
    }
}
