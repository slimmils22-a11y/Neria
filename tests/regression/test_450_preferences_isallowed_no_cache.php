<?php
/**
 * Régression : PreferencesManager::isAllowed()/isAllowedBatch() lisaient
 * le statut d'abonnement sans $use_cache=false, alors que ce résultat
 * détermine directement si Mail::Send() part ou non
 * (SegmentManager::sendToSegment() et consorts). Même famille de bug
 * systémique que les rounds 210-214 : sous cache SQL BO actif, un client
 * venant de se désabonner (écriture brute via Db::execute(), hors cycle
 * ObjectModel) pouvait recevoir quand même l'email — risque RGPD direct
 * et silencieux.
 *
 * Corrigé le 26/08/2026 (round 215) : $use_cache=false explicite sur les
 * 3 lectures (isAllowed() x2, isAllowedBatch() x1).
 *
 * Test structurel + comportemental réel : un client désabonné (subscribed=0
 * seedé en base) est bien détecté comme non autorisé par isAllowed() ET
 * isAllowedBatch(), confirmant que le comportement nominal fonctionne
 * toujours après l'ajout du paramètre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $srcRaw = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php');
    neria_assert($srcRaw !== false, 'Impossible de lire src/PreferencesManager.php');
    $src = str_replace("\r", '', $srcRaw);

    neria_assert(
        substr_count($src, "AND `category`    = '\" . pSQL(\$cat) . \"'\",\n                false\n            );") >= 1,
        "PreferencesManager::isAllowed() n'a plus \$use_cache=false sur sa lecture opt-out par email — régression du bug corrigé le 26/08/2026 (round 215)"
    );
    neria_assert(
        strpos($src, "AND `category`  = '\" . pSQL(\$cat) . \"'\",\n            false\n        );") !== false,
        "PreferencesManager::isAllowed() n'a plus \$use_cache=false sur sa lecture opt-out par id_customer — régression du bug corrigé le 26/08/2026 (round 215)"
    );
    neria_assert(
        strpos($src, "AND `category`    = '\" . pSQL(\$cat) . \"'\",\n            true,\n            false\n        );") !== false,
        "PreferencesManager::isAllowedBatch() n'a plus \$use_cache=false sur son executeS() — régression du bug corrigé le 26/08/2026 (round 215)"
    );

    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();

    $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer} AND category = 'cart'");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
             VALUES ({$idShop}, {$idCustomer}, 'round215.pref@example.test', 'cart', 0, NOW())"
        );

        $mgr = new PreferencesManager(neria_test_module());

        $allowed = $mgr->isAllowed($idCustomer, 'abandoned_cart_1', $idShop);
        neria_assert(
            $allowed === false,
            "isAllowed() a autorisé l'envoi à un client explicitement désabonné (subscribed=0) — comportement nominal cassé par l'ajout de \$use_cache=false"
        );

        $batch = $mgr->isAllowedBatch([$idCustomer], 'abandoned_cart_1', $idShop);
        neria_assert(
            isset($batch[$idCustomer]) && $batch[$idCustomer] === false,
            "isAllowedBatch() a autorisé l'envoi à un client explicitement désabonné (subscribed=0) — comportement nominal cassé par l'ajout de \$use_cache=false"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer = {$idCustomer} AND category = 'cart'");
    }

    return [
        'pass'    => true,
        'message' => "PreferencesManager::isAllowed()/isAllowedBatch() lisent bien avec \$use_cache=false et détectent toujours correctement un client désabonné — bug corrigé le 26/08/2026 (round 215)",
    ];
}
