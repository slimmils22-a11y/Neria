<?php
/**
 * Régression : LoyaltyManager::generateVoucher() doit filtrer id_shop dans
 * l'UPDATE final qui complète la réservation de palier (id_cart_rule +
 * voucher_code), pas seulement id_customer + tier_key.
 *
 * Bug réel corrigé le 05/08/2026 (round 56) : en mode séparé
 * (NERIA_LOYALTY_CROSS_SHOP_ENABLED désactivé), deux boutiques distinctes
 * réservent chacune leur propre ligne (id_customer, tier_key, id_shop) pour
 * le même client atteignant le même palier. Sans le filtre id_shop, l'UPDATE
 * final de la boutique 1 touchait AUSSI la ligne de réservation de la
 * boutique 2, lui assignant le id_cart_rule/voucher_code de la boutique 1
 * — invalide pour la boutique 2 (CartRule restreint à shop_restriction=1).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $tierKey = 'gold';

    // Réservation "en cours" (id_cart_rule=0) déjà posée par la boutique 2
    // pour le même client et le même palier — état réaliste si la boutique
    // 2 a fait son propre INSERT IGNORE (voir checkAndReward()) mais n'a
    // pas encore terminé la création de son CartRule/UPDATE final.
    $db->execute(
        "INSERT INTO {$prefix}neria_loyalty_rewards
            (id_customer, tier_key, tier_name, points_at_reward, id_cart_rule,
             voucher_code, voucher_amount, is_percent, id_shop, sent_at)
         VALUES
            ({$idCustomer}, '" . pSQL($tierKey) . "', 'Or', 300, 0, '', 20, 0, 2, NOW())"
    );

    try {
        $mgr = new LoyaltyManager(neria_test_module());
        $ref = new ReflectionMethod(LoyaltyManager::class, 'generateVoucher');
        $ref->setAccessible(true);

        $tier = ['key' => $tierKey, 'name' => 'Or', 'amount' => 20, 'is_percent' => false];

        // Complète la réservation de la boutique 1 uniquement.
        $code = $ref->invoke($mgr, $idCustomer, $tier, 1, 300);
        neria_assert($code !== '', "generateVoucher() n'a pas produit de code — jeu de test invalide ou CartRule::add() a échoué");

        $rowShop1 = $db->getRow(
            "SELECT id_cart_rule, voucher_code FROM {$prefix}neria_loyalty_rewards
             WHERE id_customer = {$idCustomer} AND tier_key = '" . pSQL($tierKey) . "' AND id_shop = 1"
        );
        $rowShop2 = $db->getRow(
            "SELECT id_cart_rule, voucher_code FROM {$prefix}neria_loyalty_rewards
             WHERE id_customer = {$idCustomer} AND tier_key = '" . pSQL($tierKey) . "' AND id_shop = 2"
        );

        neria_assert(
            $rowShop1 !== false && (int) $rowShop1['id_cart_rule'] > 0 && $rowShop1['voucher_code'] === $code,
            "la réservation de la boutique 1 n'a pas été complétée par generateVoucher()"
        );
        neria_assert(
            $rowShop2 !== false && (int) $rowShop2['id_cart_rule'] === 0 && $rowShop2['voucher_code'] === '',
            "la réservation de la boutique 2 a été altérée par l'UPDATE de la boutique 1 (id_cart_rule=" . ($rowShop2['id_cart_rule'] ?? 'absent') . ", voucher_code=" . ($rowShop2['voucher_code'] ?? 'absent') . ") — régression du bug corrigé le 05/08/2026 : l'UPDATE final n'est plus scopé par id_shop"
        );

        if ((int) $rowShop1['id_cart_rule'] > 0) {
            (new CartRule((int) $rowShop1['id_cart_rule']))->delete();
        }

        return [
            'pass'    => true,
            'message' => "generateVoucher() ne touche que la réservation de la boutique ciblée ; la réservation d'une autre boutique pour le même palier reste intacte",
        ];
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_loyalty_rewards
             WHERE id_customer = {$idCustomer} AND tier_key = '" . pSQL($tierKey) . "'"
        );
    }
}
