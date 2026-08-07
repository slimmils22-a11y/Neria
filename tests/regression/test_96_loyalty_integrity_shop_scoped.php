<?php
/**
 * Régression : HealthCheckManager::checkLoyaltyIntegrity() (contrôle #45)
 * doit détecter un solde de points négatif PAR BOUTIQUE quand
 * NERIA_LOYALTY_CROSS_SHOP_ENABLED est désactivé — même logique déjà
 * appliquée dans LoyaltyManager::getCustomerStats()/getGlobalStats()/
 * getTopCustomers().
 *
 * Bug réel corrigé le 07/08/2026 (round 92) : la requête GROUP BY id_customer
 * (sans id_shop) additionnait le solde d'un client sur TOUTES les boutiques
 * avant de vérifier qu'il n'est pas négatif. En mode cumul séparé, un client
 * avec -40 points sur la boutique A et +50 sur la boutique B donnait une
 * somme globale de +10 — aucune alerte déclenchée — alors que la boutique A
 * a un solde réellement négatif et corrompu (impacte ses propres paliers/
 * CartRules).
 *
 * Test comportemental réel : client avec -40 points sur une boutique fictive
 * (id_shop=999997) et +50 sur une autre (id_shop=999996), cumul séparé
 * forcé. Le contrôle doit détecter le solde négatif.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $shopA      = 999997; // boutique fictive, isolée des vraies données
    $shopB      = 999996;

    $wasCrossShop = Configuration::getGlobalValue('NERIA_LOYALTY_CROSS_SHOP_ENABLED');
    Configuration::updateGlobalValue('NERIA_LOYALTY_CROSS_SHOP_ENABLED', 0);

    $db->execute("DELETE FROM {$prefix}neria_loyalty_points WHERE id_customer = {$idCustomer} AND id_shop IN ({$shopA}, {$shopB})");
    $db->execute(
        "INSERT INTO {$prefix}neria_loyalty_points (id_customer, id_stat, event_type, points, id_shop, date_add)
         VALUES ({$idCustomer}, 900001, 'conversion', -40, {$shopA}, NOW()),
                ({$idCustomer}, 900002, 'conversion', 50, {$shopB}, NOW())"
    );

    try {
        $module = neria_test_module();
        $mgr    = new HealthCheckManager($module);
        $ref    = new ReflectionMethod(HealthCheckManager::class, 'checkLoyaltyIntegrity');
        $ref->setAccessible(true);

        $result = $ref->invoke($mgr);

        neria_assert(
            $result['status'] === HealthCheckManager::STATUS_ERROR,
            "checkLoyaltyIntegrity() ne détecte plus le solde négatif de la boutique fictive (statut obtenu: {$result['status']}, somme globale positive +10 aurait masqué le problème) — régression du bug corrigé le 07/08/2026 (round 92)"
        );

        return [
            'pass'    => true,
            'message' => "checkLoyaltyIntegrity() détecte bien un solde négatif PAR BOUTIQUE en mode cumul séparé, même quand la somme globale toutes boutiques est positive",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_loyalty_points WHERE id_customer = {$idCustomer} AND id_shop IN ({$shopA}, {$shopB})");
        Configuration::updateGlobalValue('NERIA_LOYALTY_CROSS_SHOP_ENABLED', $wasCrossShop !== false ? $wasCrossShop : 1);
    }
}
