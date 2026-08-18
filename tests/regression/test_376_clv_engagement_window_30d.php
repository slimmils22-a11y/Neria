<?php
/**
 * Régression : ClvManager::getEngagementRate() calculait un taux
 * d'ouverture SUR TOUTE LA DURÉE DE VIE du client (aucune borne de
 * date), contrairement à ChurnScoreManager::rate_p1 et
 * PropensityScoreManager::scoreEngagement() qui utilisent tous deux une
 * fenêtre glissante de 30 jours. Les deux blocs sont affichés côte à
 * côte sur la même fiche client BO — un client historiquement très
 * engagé (des centaines d'ouvertures il y a plus d'un an) mais silencieux
 * depuis des mois affichait "Engagement: high" côté CLV (gonflant
 * artificiellement engagement_mult dans la projection et le classement
 * Top CLV) alors que le bloc churn juste au-dessus le signalait "risque
 * élevé" via un taux récent proche de 0 — deux signaux contradictoires
 * sur le même écran.
 *
 * Corrigé le 18/08/2026 (round 183) : getEngagementRate() (et son
 * pendant batch dans getTopCustomers()) sont désormais bornés à
 * `date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)`, cohérent avec
 * Churn/Propension.
 *
 * Test comportemental réel : crée pour un client de test un historique
 * ancien massif (200 'sent'/200 'open' il y a 400 jours — engagement
 * lifetime ≈ 100%) et AUCUN événement récent. Vérifie que
 * getCustomerClv() retourne engagement_rate = 0 (pas ~100%), reflétant
 * bien l'absence d'engagement récent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ClvManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $module     = neria_test_module();
    $idShop     = (int) Context::getContext()->shop->id;

    $testCustomerId = 976000 + ($idCustomer % 1000);

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$testCustomerId}");

    try {
        // 200 paires sent/open, toutes vieilles de 400 jours (hors fenêtre
        // 30 jours) — engagement lifetime élevé, engagement récent nul.
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $tokenSent = bin2hex(random_bytes(16));
            $tokenOpen = bin2hex(random_bytes(16));
            $rows[] = "(1, 'order_conf', 'fr', {$testCustomerId}, 0, '{$tokenSent}', 'sent', DATE_SUB(NOW(), INTERVAL 400 DAY))";
            $rows[] = "(1, 'order_conf', 'fr', {$testCustomerId}, 0, '{$tokenOpen}', 'open', DATE_SUB(NOW(), INTERVAL 400 DAY))";
        }
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
             VALUES " . implode(',', $rows)
        );

        $mgr = new ClvManager($module);
        $clv = $mgr->getCustomerClv($testCustomerId);

        neria_assert(
            (float) $clv['engagement_rate'] === 0.0,
            "getCustomerClv() renvoie engagement_rate = {$clv['engagement_rate']} pour un client sans AUCUN événement dans les 30 derniers jours (uniquement un historique ancien de 400 jours) — régression du bug corrigé le 18/08/2026 (round 183) : le taux d'engagement lifetime serait de nouveau utilisé, gonflant artificiellement engagement_mult et le classement Top CLV pour un client en réalité inactif récemment"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$testCustomerId}");
    }

    return [
        'pass'    => true,
        'message' => "ClvManager::getEngagementRate() est bien bornée aux 30 derniers jours, cohérente avec ChurnScoreManager/PropensityScoreManager — bug corrigé le 18/08/2026 (round 183)",
    ];
}
