<?php
/**
 * Régression : les 4 moteurs de ciblage marketing (SegmentManager,
 * ClvManager, ChurnScoreManager, PropensityScoreManager) doivent exclure
 * les comptes invités (is_guest=1) de leurs listes de destinataires, comme
 * SeasonalCampaignManager le fait déjà.
 *
 * Décision produit validée le 13/09/2026 (round 350, suite au finding round
 * 349) : un compte invité PrestaShop est une coquille technique sans
 * connexion possible, pas une relation client suivie — le cibler dans des
 * campagnes comportementales (segment VIP, top CLV, alerte churn/propension)
 * n'a pas de valeur business et risque de solliciter un email perçu comme
 * hors contexte par un acheteur ponctuel. `is_guest=0` généralisé aux 4
 * moteurs qui en étaient dépourvus.
 *
 * Test comportemental réel : crée un client invité fictif (is_guest=1,
 * active=1, deleted=0) avec des fixtures dans les 4 tables concernées
 * (neria_customer_segment, orders+customer pour ClvManager,
 * neria_churn_score, neria_propensity_score) et vérifie qu'il n'apparaît
 * dans AUCUNE des 4 listes.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $email  = 'round350guest' . time() . '@example.invalid';

    $idCustomer = null;
    $idOrder    = null;

    try {
        // Client invité fictif — colonnes NOT NULL sans défaut identifiées :
        // id_gender, firstname, lastname, email, passwd, date_add, date_upd.
        $db->execute(
            "INSERT INTO `{$prefix}customer`
                (id_shop, id_shop_group, id_gender, firstname, lastname, email, passwd,
                 is_guest, active, deleted, date_add, date_upd)
             VALUES
                ({$idShop}, 1, 0, 'Round350', 'GuestTest', '" . pSQL($email) . "', 'x',
                 1, 1, 0, NOW(), NOW())"
        );
        $idCustomer = (int) $db->Insert_ID();
        neria_assert($idCustomer > 0, "Impossible de créer le client invité fictif de test");

        // --- SegmentManager ---
        require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';
        $db->execute(
            "INSERT INTO `{$prefix}neria_customer_segment`
                (id_shop, id_customer, segment, total_sent, total_opens, total_clicks, total_conversions, computed_at)
             VALUES
                ({$idShop}, {$idCustomer}, 'ambassador', 10, 10, 5, 2, NOW())"
        );
        $seg = new SegmentManager(neria_test_module());
        $rowsSeg = $seg->getCustomersBySegment('ambassador', 50, 0);
        $foundSeg = array_filter($rowsSeg, fn ($r) => (int) $r['id_customer'] === $idCustomer);
        neria_assert(
            empty($foundSeg),
            "SegmentManager::getCustomersBySegment() liste encore un compte invité (is_guest=1) — régression du bug corrigé le 13/09/2026 (round 350)"
        );

        // --- ClvManager ---
        require_once _PS_MODULE_DIR_ . 'neria/src/ClvManager.php';
        $db->execute(
            "INSERT INTO `{$prefix}orders`
                (id_customer, id_shop, id_shop_group, id_currency, id_lang, id_carrier,
                 id_address_delivery, id_address_invoice, current_state, secure_key,
                 payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl,
                 total_paid_real, total_products, total_products_wt, valid, reference, date_add, date_upd)
             VALUES
                ({$idCustomer}, {$idShop}, 1, 1, 1, 1,
                 0, 0, 1, 'test350g',
                 'test', 1, 999999, 999999, 999999,
                 999999, 999999, 999999, 1, 'TEST350GU', NOW(), NOW())"
        );
        $idOrder = (int) $db->Insert_ID();
        neria_assert($idOrder > 0, "Impossible de créer la commande fictive de test");

        $clv = new ClvManager(neria_test_module());
        $rowsClv = $clv->getTopCustomers(200);
        $foundClv = array_filter($rowsClv, fn ($r) => (int) $r['id_customer'] === $idCustomer);
        neria_assert(
            empty($foundClv),
            "ClvManager::getTopCustomers() liste encore un compte invité (is_guest=1) — régression du bug corrigé le 13/09/2026 (round 350) : un très gros montant de commande fictif garantirait sa présence en tête de liste si le filtre disparaissait"
        );

        // --- ChurnScoreManager ---
        require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';
        $db->execute(
            "INSERT INTO `{$prefix}neria_churn_score`
                (id_shop, id_customer, score, rate_p1, rate_p2, rate_p3, last_open, computed_at)
             VALUES
                ({$idShop}, {$idCustomer}, 99, 0, 0, 0, NOW(), NOW())"
        );
        $churn = new ChurnScoreManager(neria_test_module());
        $rowsChurn = $churn->getHighRiskCustomers(50);
        $foundChurn = array_filter($rowsChurn, fn ($r) => (int) $r['id_customer'] === $idCustomer);
        neria_assert(
            empty($foundChurn),
            "ChurnScoreManager::getHighRiskCustomers() liste encore un compte invité (is_guest=1) — régression du bug corrigé le 13/09/2026 (round 350)"
        );

        // --- PropensityScoreManager ---
        require_once _PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php';
        $db->execute(
            "INSERT INTO `{$prefix}neria_propensity_score`
                (id_shop, id_customer, score, score_recency, score_frequency, score_engagement, score_seasonality, date_upd)
             VALUES
                ({$idShop}, {$idCustomer}, 99, 25, 25, 25, 24, NOW())"
        );
        $prop = new PropensityScoreManager(neria_test_module());
        $rowsProp = $prop->getAlertCustomers(50);
        $foundProp = array_filter($rowsProp, fn ($r) => (int) $r['id_customer'] === $idCustomer);
        neria_assert(
            empty($foundProp),
            "PropensityScoreManager::getAlertCustomers() liste encore un compte invité (is_guest=1) — régression du bug corrigé le 13/09/2026 (round 350)"
        );

        return [
            'pass'    => true,
            'message' => "SegmentManager/ClvManager/ChurnScoreManager/PropensityScoreManager excluent bien désormais les comptes invités (is_guest=1) de leurs listes de ciblage marketing — bug corrigé le 13/09/2026 (round 350)",
        ];
    } finally {
        if ($idOrder) {
            $db->execute("DELETE FROM `{$prefix}orders` WHERE id_order = {$idOrder}");
        }
        if ($idCustomer) {
            $db->execute("DELETE FROM `{$prefix}neria_customer_segment` WHERE id_customer = {$idCustomer}");
            $db->execute("DELETE FROM `{$prefix}neria_churn_score` WHERE id_customer = {$idCustomer}");
            $db->execute("DELETE FROM `{$prefix}neria_propensity_score` WHERE id_customer = {$idCustomer}");
            $db->execute("DELETE FROM `{$prefix}customer` WHERE id_customer = {$idCustomer}");
        }
    }
}
