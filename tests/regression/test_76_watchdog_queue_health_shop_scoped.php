<?php
/**
 * Régression : WatchdogManager::getQueueHealth() doit filtrer id_shop sur
 * ses 3 requêtes (stuck/failed/total_pending), comme toutes les autres
 * requêtes de cette classe.
 *
 * Bug réel corrigé le 06/08/2026 (round 73) : sans ce filtre, le widget de
 * santé de CHAQUE boutique agrégeait les emails bloqués/échoués de TOUTES
 * les boutiques — fausse alerte sur une boutique saine si une autre a une
 * panne SMTP transitoire.
 *
 * Test comportemental réel : insère des lignes de file fictives pour DEUX
 * id_shop distincts (id_shop n'a pas de contrainte FK sur neria_queue,
 * vérifiable même sur cet environnement de dev à une seule vraie boutique
 * — même technique que test_63/test_68), et vérifie que getQueueHealth()
 * pour la boutique A ne voit QUE les lignes de A.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $shopA = 1;
    $shopB = 999998; // boutique fictive, isolée des vraies données

    $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_shop = {$shopB}");

    try {
        // 3 lignes 'failed' pour la boutique B fictive — ne doivent JAMAIS
        // apparaître dans le rapport de la boutique A.
        for ($i = 0; $i < 3; $i++) {
            $db->execute(
                "INSERT INTO {$prefix}neria_queue
                    (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
                     vars_json, ref_id, send_at, status, attempts, created_at)
                 VALUES ({$idCustomer}, {$shopB}, " . (int) Configuration::get('PS_LANG_DEFAULT') . ", 'ghost_cart',
                         'regtest-76-{$i}@example.com', 'Regtest',
                         '{}', " . (900000 + $i) . ", NOW(), 'failed', 3, NOW())"
            );
        }

        $mgr = new WatchdogManager(neria_test_module());
        $ref = new ReflectionProperty(WatchdogManager::class, 'idShop');
        $ref->setAccessible(true);
        $ref->setValue($mgr, $shopA);

        $health = $mgr->getQueueHealth();

        neria_assert(
            $health['failed'] === 0,
            "getQueueHealth() de la boutique A voit " . $health['failed'] . " email(s) échoué(s) alors qu'ils appartiennent tous à la boutique B — régression du bug corrigé le 06/08/2026 (round 73) : getQueueHealth() n'est plus scopé par id_shop"
        );

        return [
            'pass'    => true,
            'message' => "getQueueHealth() reste bien scopé par id_shop : les lignes de file d'une autre boutique ne sont plus comptabilisées",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_shop = {$shopB}");
    }
}
