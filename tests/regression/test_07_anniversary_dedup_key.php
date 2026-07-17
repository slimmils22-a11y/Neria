<?php
/** Régression : le garde-fou croisé first_anniversary/relationship_anniversary doit comparer sent_at, pas ref_id. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $fakeOrderId = 999888777;

    $db->execute("INSERT INTO {$prefix}neria_behavioral_sent (id_customer, template, ref_id, sent_at)
        VALUES ({$idCustomer}, 'first_anniversary', {$fakeOrderId}, NOW())");
    $id = (int) $db->Insert_ID();

    try {
        $year = (int) date('Y');
        $guardFires = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent bs2
             WHERE bs2.id_customer = {$idCustomer} AND bs2.template = 'first_anniversary'
               AND YEAR(bs2.sent_at) = {$year}"
        );
        neria_assert($guardFires === 1, "le garde-fou sur YEAR(sent_at) ne détecte plus l'envoi first_anniversary — régression du bug corrigé le 17/07/2026 (commit db43ff2)");

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
        neria_assert(
            str_contains($src, 'YEAR(bs2.sent_at) = YEAR(NOW())'),
            "sendRelationshipAnniversaries() ne compare plus YEAR(sent_at) — régression possible"
        );

        return ['pass' => true, 'message' => 'Garde-fou croisé anniversaire toujours basé sur sent_at'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id={$id}");
    }
}
