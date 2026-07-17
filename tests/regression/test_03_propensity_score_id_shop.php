<?php
/** Régression : PropensityScoreManager::scoreEngagement() doit filtrer id_shop. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    require_once _PS_MODULE_DIR_ . 'neria/src/PropensityScoreManager.php';
    $mgr = new PropensityScoreManager(neria_test_module());
    $ref = new ReflectionMethod($mgr, 'scoreEngagement');
    $ref->setAccessible(true);
    $baseline = $ref->invoke($mgr, $idCustomer);

    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (99999, {$idCustomer}, 'regtest', 'fr', 'open', 'regtest_tok1', NOW())");
    $id1 = (int) $db->Insert_ID();
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (99999, {$idCustomer}, 'regtest', 'fr', 'click', 'regtest_tok2', NOW())");
    $id2 = (int) $db->Insert_ID();

    try {
        $afterSeed = $ref->invoke($mgr, $idCustomer);

        neria_assert(
            $afterSeed == $baseline,
            "scoreEngagement passe de {$baseline} à {$afterSeed} après avoir seedé de l'activité sur un AUTRE id_shop — régression du bug corrigé le 17/07/2026 (commit fef5737)"
        );

        return ['pass' => true, 'message' => 'scoreEngagement() correctement scopé id_shop'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_stat IN ({$id1},{$id2})");
    }
}
