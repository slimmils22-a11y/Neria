<?php
/** Régression : CustomerEmailHistoryManager::computeAlerts() doit prendre le MAX des ouvertures, pas la première dans l'ordre d'envoi. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    // A: envoyé il y a 100j, ouvert AUJOURD'HUI. B: envoyé il y a 70j, ouvert il y a 65j.
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'sent', 'regtestA', DATE_SUB(NOW(), INTERVAL 100 DAY))");
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'open', 'regtestA', NOW())");
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'sent', 'regtestB', DATE_SUB(NOW(), INTERVAL 70 DAY))");
    $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, lang, event_type, tracking_token, date_add)
        VALUES (1, {$idCustomer}, 'order_conf', 'fr', 'open', 'regtestB', DATE_SUB(NOW(), INTERVAL 65 DAY))");

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/CustomerEmailHistoryManager.php';
        $mgr = new CustomerEmailHistoryManager(neria_test_module());
        $refGet = new ReflectionMethod($mgr, 'getEmails');
        $refGet->setAccessible(true);
        $emails = $refGet->invoke($mgr, $idCustomer);
        $emails = array_values(array_filter($emails, fn($e) => in_array($e['tracking_token'], ['regtestA', 'regtestB'])));

        $badge = $mgr->computeEngagementBadge($emails);
        $alerts = $mgr->computeAlerts($emails, $badge);

        foreach ($alerts as $a) {
            neria_assert($a['key'] !== 'alert_inactive', "alert_inactive s'est déclenchée alors que le dernier email a été ouvert AUJOURD'HUI — régression du bug de tri corrigé le 17/07/2026 (commit fa424e8)");
        }

        return ['pass' => true, 'message' => 'computeAlerts() prend toujours la vraie dernière ouverture'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE tracking_token IN ('regtestA','regtestB')");
    }
}
