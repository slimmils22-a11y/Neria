<?php
/** Régression : processBounceWebhook() doit traiter TOUS les événements d'un lot SendGrid, pas seulement le premier. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $emails = ['regtest1@example.com', 'regtest2@example.com', 'regtest3@example.com'];

    $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email IN ('" . implode("','", $emails) . "')");

    try {
        $payload = [
            ['event' => 'bounce', 'email' => $emails[0], 'type' => 'bounce', 'reason' => 'test'],
            ['event' => 'bounce', 'email' => $emails[1], 'type' => 'bounce', 'reason' => 'test'],
            ['event' => 'dropped', 'email' => $emails[2], 'reason' => 'test'],
        ];

        require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';
        $mgr = new BounceManager(neria_test_module());
        $mgr->processBounceWebhook($payload, 'sendgrid');

        $recorded = $db->executeS("SELECT email FROM {$prefix}neria_bounces WHERE email IN ('" . implode("','", $emails) . "')");
        $count = count($recorded);

        neria_assert(
            $count === 3,
            "{$count}/3 événements du lot SendGrid enregistrés — régression du bug de traitement partiel corrigé le 17/07/2026 (commit 3f6a0f6)"
        );

        return ['pass' => true, 'message' => 'processBounceWebhook() traite toujours tous les événements du lot SendGrid'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email IN ('" . implode("','", $emails) . "')");
    }
}
