<?php
/**
 * Régression : `LoyaltyManager::generateVoucher()` scopait
 * `reduction_currency` du CartRule via `$reservationShopId`
 * (`Configuration::get('PS_CURRENCY_DEFAULT', null, null, $reservationShopId)`)
 * mais lisait `minimum_amount_currency` SANS aucun scoping boutique
 * (`Configuration::get('PS_CURRENCY_DEFAULT')`, contexte ambiant) — deux
 * propriétés du MÊME CartRule, incohérentes entre elles. Sur une
 * installation multi-devises en mode fidélité séparé par boutique
 * (NERIA_LOYALTY_CROSS_SHOP_ENABLED désactivé), la devise par défaut de la
 * boutique du client pouvait différer de celle de l'installation globale.
 * Sans effet observable tant que `minimum_amount` reste à 0 (aucun seuil
 * réel appliqué), mais incohérence directe à corriger.
 *
 * Bug identifié le 11/09/2026 (round 336, audit BounceManager/
 * LoyaltyManager), corrigé le 11/09/2026 hors round (à la demande de
 * l'utilisateur, suite à la clôture du round 336) :
 * `minimum_amount_currency` scopé par `$reservationShopId`, symétrique à
 * `reduction_currency`.
 *
 * Test comportemental réel : appelle `generateVoucher()` via réflexion
 * (méthode privée) pour un vrai client, avec un tier_key synthétique
 * garanti unique (évite toute collision avec la contrainte UNIQUE
 * `neria_loyalty_rewards`), et vérifie que le CartRule réellement créé a
 * bien `minimum_amount_currency` === la valeur résolue explicitement via
 * `Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop)` —
 * exactement la même résolution que `reduction_currency` du même bon.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif ────────────────────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LoyaltyManager.php');
    $posFn = strpos($src, 'private function generateVoucher(int $idCustomer, array $tier, int $reservationShopId, int $pointsAtReward): string');
    neria_assert($posFn !== false, 'generateVoucher() introuvable — jeu de test invalide');
    $posMinAmt = strpos($src, 'minimum_amount_currency', $posFn);
    neria_assert($posMinAmt !== false, 'minimum_amount_currency introuvable dans generateVoucher() — jeu de test invalide');
    $body = substr($src, $posMinAmt, 260);
    neria_assert(
        strpos($body, '$reservationShopId > 0') !== false
            && strpos($body, "Configuration::get('PS_CURRENCY_DEFAULT', null, null, \$reservationShopId)") !== false,
        "generateVoucher() ne scope plus minimum_amount_currency par \$reservationShopId — régression du correctif du 11/09/2026 (hors round, suite round 336) : incohérence de nouveau introduite avec reduction_currency du même CartRule"
    );

    // ── Vérification comportementale du chemin réel ───────────────────
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idShop     = (int) Context::getContext()->shop->id;
    $module     = neria_test_module();
    $idCustomer = neria_test_any_customer_id();

    // tier_key est varchar(20) en base — clé courte requise.
    $tierKey = 'rt690' . substr(md5(uniqid()), 0, 10);
    $tier = ['key' => $tierKey, 'name' => 'Regtest690', 'amount' => 5.0, 'is_percent' => 0];

    $mgr = new LoyaltyManager($module);
    $ref = new ReflectionMethod(LoyaltyManager::class, 'generateVoucher');
    $ref->setAccessible(true);

    $code = $ref->invoke($mgr, $idCustomer, $tier, $idShop, 0);
    neria_assert($code !== '', "generateVoucher() n'a pas renvoyé de code — jeu de test invalide (réservation en conflit ?)");

    try {
        $row = $db->getRow(
            "SELECT id_cart_rule FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '" . pSQL($tierKey) . "' AND id_shop = {$idShop}"
        );
        neria_assert($row !== false && (int) $row['id_cart_rule'] > 0, "Aucun CartRule réel n'a été créé — jeu de test invalide");
        $idCartRule = (int) $row['id_cart_rule'];

        $cartRule = new CartRule($idCartRule);
        neria_assert(Validate::isLoadedObject($cartRule), "CartRule {$idCartRule} introuvable après création — jeu de test invalide");

        $expectedCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        neria_assert(
            (int) $cartRule->minimum_amount_currency === $expectedCurrency,
            "minimum_amount_currency du CartRule créé ({$cartRule->minimum_amount_currency}) ne correspond plus à la devise résolue explicitement pour la boutique {$idShop} ({$expectedCurrency}) — régression du correctif du 11/09/2026 (hors round, suite round 336)"
        );
        neria_assert(
            (int) $cartRule->minimum_amount_currency === (int) $cartRule->reduction_currency,
            "minimum_amount_currency ({$cartRule->minimum_amount_currency}) et reduction_currency ({$cartRule->reduction_currency}) du même CartRule divergent — incohérence régressée"
        );

        return [
            'pass'    => true,
            'message' => "LoyaltyManager::generateVoucher() scope désormais minimum_amount_currency par la boutique du client, cohérent avec reduction_currency du même CartRule — correctif du 11/09/2026 (hors round, suite round 336)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '" . pSQL($tierKey) . "' AND id_shop = {$idShop}");
        if (isset($idCartRule) && $idCartRule > 0) {
            $cr = new CartRule($idCartRule);
            if (Validate::isLoadedObject($cr)) {
                $cr->delete();
            }
        }
    }
}
