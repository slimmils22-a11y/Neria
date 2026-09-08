<?php
/**
 * Correctif hors round (08/09/2026) : neria.php::loyalty_cross_shop_toggle
 * basculait NERIA_LOYALTY_CROSS_SHOP_ENABLED via un simple
 * Configuration::updateGlobalValue(), sans jamais migrer les lignes
 * existantes de neria_loyalty_rewards. LoyaltyManager::checkAndReward()/
 * revokeUnusedRewardsBelowThreshold() scopent leurs requêtes via
 * $reservationShopId = $crossShop ? 0 : $idShop — un marchand basculant ce
 * réglage APRÈS que des récompenses existent rendait les anciennes lignes
 * invisibles sous la nouvelle clé, risquant un bon de fidélité émis en
 * double pour un palier déjà récompensé.
 *
 * Une migration bidirectionnelle des données a été jugée trop risquée
 * (ambiguë dans le sens transversal→par-boutique pour un client partagé
 * entre boutiques d'un même groupe — voir
 * project_neria_loyalty_crossshop_toggle_migration_gap.md). Correctif
 * retenu : bloquer la bascule tant que des réservations neria_loyalty_rewards
 * existent, plutôt que de risquer la corruption silencieuse.
 *
 * Test : comportemental réel (COUNT() effectif sur neria_loyalty_rewards
 * avec au moins une ligne insérée, confirmant que le mécanisme de comptage
 * fonctionne) + structurel (code inline dans le contrôleur admin,
 * nécessitant un contexte AdminController/Employee complet pour être
 * invoqué réellement — même limitation documentée par test_635/test_640).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $tierKey    = 'regtest648';

    $db->execute("DELETE FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}'");

    try {
        $countBefore = (int) $db->getValue('SELECT COUNT(*) FROM `' . $prefix . LoyaltyManager::TABLE_REWARDS . '`');

        $db->execute(
            "INSERT INTO {$prefix}neria_loyalty_rewards
                (id_customer, tier_key, tier_name, points_at_reward, id_cart_rule, voucher_code, voucher_amount, is_percent, id_shop, sent_at)
             VALUES ({$idCustomer}, '{$tierKey}', 'Regtest648', 100, 0, 'REGTEST648', 10, 1, 0, NOW())"
        );
        neria_assert((int) $db->Affected_Rows() === 1, 'jeu de test invalide : la réservation initiale a échoué');

        $countAfter = (int) $db->getValue('SELECT COUNT(*) FROM `' . $prefix . LoyaltyManager::TABLE_REWARDS . '`');
        neria_assert(
            $countAfter === $countBefore + 1,
            "le COUNT() sur neria_loyalty_rewards ne reflète pas l'insertion réelle — jeu de test invalide, la requête que le handler bloquant doit utiliser ne fonctionnerait pas non plus"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}'");
    }

    $srcRaw = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($srcRaw !== false, 'Impossible de lire neria.php');
    $src = str_replace("\r", '', $srcRaw);

    $posAction = strpos($src, "Tools::getValue('neria_action') === 'loyalty_cross_shop_toggle'");
    neria_assert($posAction !== false, "Action 'loyalty_cross_shop_toggle' introuvable — jeu de test invalide");

    $body = substr($src, $posAction, 2000);

    neria_assert(
        strpos($body, "SELECT COUNT(*) FROM `' . _DB_PREFIX_ . LoyaltyManager::TABLE_REWARDS . '`") !== false,
        "neria.php::loyalty_cross_shop_toggle ne compte plus les réservations neria_loyalty_rewards en attente avant de basculer — régression du correctif du 08/09/2026 : le réglage pourrait de nouveau être changé alors que des récompenses existent, risquant un bon de fidélité en double"
    );
    neria_assert(
        strpos($body, '$pendingRewards > 0') !== false,
        "neria.php::loyalty_cross_shop_toggle ne bloque plus la bascule quand des réservations sont en attente — régression du correctif du 08/09/2026"
    );
    neria_assert(
        strpos($body, "msg.loyalty_crossshop_toggle_blocked_pending") !== false,
        "neria.php::loyalty_cross_shop_toggle n'affiche plus de message d'erreur dédié quand la bascule est bloquée — régression du correctif du 08/09/2026"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        isset($translations['msg.loyalty_crossshop_toggle_blocked_pending'])
        && count($translations['msg.loyalty_crossshop_toggle_blocked_pending']) === 19,
        "clé msg.loyalty_crossshop_toggle_blocked_pending manquante ou incomplète dans admin_translations.json (19 langues attendues)"
    );

    return [
        'pass'    => true,
        'message' => "neria.php::loyalty_cross_shop_toggle bloque bien la bascule tant que des réservations neria_loyalty_rewards sont en attente, évitant un bon de fidélité émis en double — correctif du 08/09/2026 (hors round)",
    ];
}
