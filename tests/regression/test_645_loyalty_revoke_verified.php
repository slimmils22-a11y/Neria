<?php
/**
 * Régression : LoyaltyManager::revokeUnusedRewardsBelowThreshold()
 * n'affichait jamais un échec — CartRule::update() et Db::delete() (sur
 * neria_loyalty_rewards) n'étaient jamais vérifiés avant de journaliser
 * inconditionnellement watchdog.loyalty_reward_revoked comme un succès.
 *
 * Si update() échoue (verrou DB, timeout, erreur de validation
 * ObjectModel), le CartRule reste ACTIF en base malgré un log affichant
 * "révoqué" — le client garde une réduction qu'il ne devrait plus avoir.
 * Si delete() échoue alors qu'update() a réussi, la ligne de réservation
 * reste en base indéfiniment (pointant vers un bon désactivé) :
 * checkAndReward() continuerait à trouver ce palier "déjà récompensé" et
 * ne récompenserait plus jamais ce client pour ce palier, même s'il
 * regagne légitimement les points — violation directe de l'intention
 * documentée round 307.
 *
 * Corrigé le 08/09/2026 (round 325) : les deux retours sont désormais
 * vérifiés ($updated && $deleted) avant de journaliser un succès ; sinon
 * watchdog.loyalty_reward_revoke_failed (erreur) est journalisé.
 *
 * Test comportemental réel : crée un vrai CartRule actif + une vraie
 * ligne de réservation (points_at_reward=100, client à 0 point réel —
 * force la branche de révocation), appelle
 * revokeUnusedRewardsBelowThreshold() via Reflection, et vérifie que le
 * CartRule est bien désactivé ET la ligne de réservation bien supprimée
 * (chemin nominal, non-régression) — puis vérifie structurellement que le
 * code ne journalise plus le succès sans avoir vérifié les deux retours.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();
    $tierKey = 'regtest645';

    $db->execute("DELETE FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}'");

    $cartRule = new CartRule();
    $cartRule->name              = [(int) Configuration::get('PS_LANG_DEFAULT') => 'Regtest645'];
    $cartRule->code              = 'REGTEST645-' . uniqid();
    $cartRule->quantity          = 1;
    $cartRule->quantity_per_user = 1;
    $cartRule->active            = 1;
    $cartRule->date_from         = date('Y-m-d H:i:s');
    $cartRule->date_to           = date('Y-m-d H:i:s', strtotime('+1 year'));
    $cartRule->reduction_percent = 10;
    $cartRule->minimum_amount    = 0;
    $cartRule->minimum_amount_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cartRule->highlight         = false;
    $cartRule->free_shipping     = false;
    neria_assert($cartRule->add(), 'jeu de test invalide : la création du CartRule a échoué');
    $idCartRule = (int) $cartRule->id;

    try {
        // points_at_reward=100, sans correspondance dans getTiers() (tier_key
        // inconnu) -> le seuil de repli est points_at_reward lui-même (100).
        // Le client n'a aucun point réel enregistré pour ce test -> total=0
        // < 100 -> la branche de révocation se déclenche à coup sûr.
        // id_shop=0 : isLoyaltyCrossShopEnabled() vaut true par défaut
        // (ConfigManager::KEY_LOYALTY_CROSS_SHOP_ENABLED), donc
        // revokeUnusedRewardsBelowThreshold() cherche la réservation via
        // la sentinelle id_shop=0 (cumul transversal), pas l'id_shop réel.
        $db->execute(
            "INSERT INTO {$prefix}neria_loyalty_rewards
                (id_customer, tier_key, tier_name, points_at_reward, id_cart_rule, voucher_code, voucher_amount, is_percent, id_shop, sent_at)
             VALUES ({$idCustomer}, '{$tierKey}', 'Regtest645', 100, {$idCartRule}, '{$cartRule->code}', 10, 1, 0, NOW())"
        );
        neria_assert((int) $db->Affected_Rows() === 1, 'jeu de test invalide : la réservation initiale a échoué');

        $mgr = new LoyaltyManager($module);
        $ref = new ReflectionMethod(LoyaltyManager::class, 'revokeUnusedRewardsBelowThreshold');
        $ref->setAccessible(true);
        $ref->invoke($mgr, $idCustomer, $idShop);

        $reloaded = new CartRule($idCartRule);
        neria_assert(
            Validate::isLoadedObject($reloaded) && (int) $reloaded->active === 0,
            "revokeUnusedRewardsBelowThreshold() n'a pas désactivé le CartRule alors que le client est repassé sous le seuil — chemin nominal cassé"
        );

        $stillThere = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}'"
        );
        neria_assert(
            $stillThere === 0,
            "revokeUnusedRewardsBelowThreshold() n'a pas supprimé la ligne de réservation — chemin nominal cassé"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_loyalty_rewards WHERE id_customer = {$idCustomer} AND tier_key = '{$tierKey}'");
        $cartRuleCleanup = new CartRule($idCartRule);
        if (Validate::isLoadedObject($cartRuleCleanup)) {
            $cartRuleCleanup->delete();
        }
    }

    // Vérification structurelle : le succès n'est journalisé que si les
    // deux retours ($updated ET $deleted) sont vérifiés au préalable.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
    neria_assert(
        strpos($src, 'if ($updated && $deleted) {') !== false,
        "LoyaltyManager::revokeUnusedRewardsBelowThreshold() ne vérifie plus \$updated && \$deleted avant de journaliser un succès — régression du bug corrigé le 08/09/2026 (round 325) : un échec CartRule::update()/Db::delete() serait de nouveau journalisé comme un succès"
    );
    neria_assert(
        strpos($src, "watchdog.loyalty_reward_revoke_failed") !== false,
        "LoyaltyManager::revokeUnusedRewardsBelowThreshold() ne journalise plus d'erreur dédiée en cas d'échec — régression du bug corrigé le 08/09/2026 (round 325)"
    );

    $translations = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(
        isset($translations['watchdog.loyalty_reward_revoke_failed']) && count($translations['watchdog.loyalty_reward_revoke_failed']) === 19,
        "clé watchdog.loyalty_reward_revoke_failed manquante ou incomplète dans admin_translations.json (19 langues attendues)"
    );

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::revokeUnusedRewardsBelowThreshold() désactive bien le CartRule et supprime bien la réservation (chemin nominal), et ne journalise plus de succès sans vérifier les deux retours — bug corrigé le 08/09/2026 (round 325)",
    ];
}
