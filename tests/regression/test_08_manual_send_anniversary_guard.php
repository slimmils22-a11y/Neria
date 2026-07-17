<?php
/** Régression : ManualSendManager doit bloquer l'envoi manuel de relationship_anniversary si first_anniversary a déjà été envoyé. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $email = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    $fakeOrderId = 999888666;

    $db->execute("INSERT INTO {$prefix}neria_behavioral_sent (id_customer, template, ref_id, sent_at)
        VALUES ({$idCustomer}, 'first_anniversary', {$fakeOrderId}, NOW())");
    $id = (int) $db->Insert_ID();

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
        $mgr = new ManualSendManager(neria_test_module());
        $ref = new ReflectionMethod($mgr, 'checkAnniversaryConflict');
        $ref->setAccessible(true);
        $guard = $ref->invoke($mgr, $email, 'first_anniversary');

        neria_assert($guard !== null, "checkAnniversaryConflict() ne bloque plus l'envoi — régression du bug corrigé le 17/07/2026 (commit 92905dd)");

        return ['pass' => true, 'message' => 'Garde-fou envoi manuel anniversaire toujours opérant'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id={$id}");
    }
}
