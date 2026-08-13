<?php
/**
 * Régression : QueueManager::processSingle() recalcule id_first_order pour
 * first_anniversary au moment de l'envoi réel (MIN(id_order) valide=1),
 * au lieu de réutiliser row['ref_id'] déjà figé à l'enqueue par
 * BehavioralCronManager::sendFirstAnniversaries(). Si la commande la plus
 * ancienne du client bascule à valid=0 (annulation/remboursement) entre la
 * mise en file et l'envoi différé (fenêtre d'achat individuelle), le
 * recalcul renvoie 0 (aucune commande valide restante) — l'email part quand
 * même, mais AUCUNE trace de dédup n'était posée dans
 * neria_behavioral_sent, exposant à un envoi en double si le client
 * effectue un nouvel achat valide plus tard.
 *
 * Corrigé le 09/08/2026 (round 158) : repli sur row['ref_id'] (déjà figé à
 * l'enqueue) si le recalcul ne trouve plus aucune commande valide.
 *
 * Test comportemental réel : fabrique une ligne de file first_anniversary
 * pour un client SANS AUCUNE commande valide (simule le cas "commande la
 * plus ancienne annulée entre-temps"), déclenche un vrai envoi via Mailpit,
 * et vérifie que neria_behavioral_sent est bien peuplé avec le ref_id de
 * l'enqueue (pas 0).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $refId      = 888000 + random_int(1, 999); // ref_id figé à l'enqueue

    // Neutralise temporairement toute commande VALIDE de ce client pour
    // simuler "la commande la plus ancienne a été annulée" — restaure en
    // finally, quel que soit le résultat du test.
    $validOrders = $db->executeS("SELECT id_order, valid FROM {$prefix}orders WHERE id_customer = {$idCustomer} AND valid = 1") ?: [];
    $touchedIds  = array_map(static fn($r) => (int) $r['id_order'], $validOrders);

    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = 'first_anniversary' AND ref_id = {$refId}");

    if (!empty($touchedIds)) {
        $db->execute("UPDATE {$prefix}orders SET valid = 0 WHERE id_order IN (" . implode(',', $touchedIds) . ")");
    }

    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES ({$idCustomer}, {$idShop}, " . (int) Configuration::get('PS_LANG_DEFAULT') . ", 'first_anniversary',
                 'regtest-257@example.com', 'Regtest',
                 '{}', {$refId}, NOW(), 'pending', 0, NOW())"
    );
    $idQueue = (int) $db->Insert_ID();

    try {
        $mgr = new QueueManager(neria_test_module());
        $ref = new ReflectionMethod($mgr, 'processSingle');
        $ref->setAccessible(true);

        $row = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        neria_assert($row !== false, 'ligne de file introuvable juste après insertion');

        $sent = $ref->invoke($mgr, $row);
        neria_assert($sent === true, "processSingle() n'a pas réussi l'envoi réel via Mailpit — vérifier que le service SMTP local tourne (PS_MAIL_METHOD=2, localhost:1025)");

        $dedupRow = $db->getRow(
            "SELECT ref_id FROM {$prefix}neria_behavioral_sent
             WHERE id_customer = {$idCustomer} AND template = 'first_anniversary' AND sent_at >= NOW() - INTERVAL 1 MINUTE
             ORDER BY id DESC"
        );
        neria_assert(
            $dedupRow !== false,
            "neria_behavioral_sent n'a reçu AUCUNE ligne après l'envoi réussi (client sans commande valide) — régression du bug corrigé le 09/08/2026 (round 158) : la dédup redeviendrait silencieusement sautée, exposant à un envoi en double lors d'un futur achat"
        );
        neria_assert(
            (int) $dedupRow['ref_id'] === $refId,
            "neria_behavioral_sent a été peuplé avec ref_id=" . $dedupRow['ref_id'] . " au lieu du ref_id figé à l'enqueue ({$refId}) — le repli sur row['ref_id'] ne fonctionne plus"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::processSingle() retombe bien sur le ref_id figé à l'enqueue quand le recalcul MIN(id_order) ne trouve plus de commande valide — bug corrigé le 09/08/2026 (round 158)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = 'first_anniversary' AND ref_id = {$refId}");
        if (!empty($touchedIds)) {
            $db->execute("UPDATE {$prefix}orders SET valid = 1 WHERE id_order IN (" . implode(',', $touchedIds) . ")");
        }
    }
}
