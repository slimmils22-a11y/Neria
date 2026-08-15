<?php
/**
 * Régression : BounceManager::parsePostmark() ignorait purement et
 * simplement (return null, jamais journalisé) tout événement Postmark dont
 * le champ Type ne contenait pas littéralement la sous-chaîne "bounce" —
 * or Transient, Blocked, Undeliverable, SpamNotification, DnsError et
 * SMTPApiError sont tous des Type Postmark RÉELS pour un RecordType
 * "Bounce" (échecs temporaires/environnementaux : boîte pleine, blocage IP
 * passager, erreur DNS transitoire), aucun ne contenant "bounce" dans son
 * nom. Une adresse dont la boîte est répétitivement pleine n'était donc
 * JAMAIS suivie ni bloquée par Neria.
 *
 * Corrigé le 15/08/2026 (round 172) : le gate d'entrée se base désormais
 * sur RecordType === 'Bounce' (champ Postmark dédié, en plus de l'ancien
 * test sur Type pour compatibilité), et ces types temporaires connus sont
 * explicitement classés 'soft' (récupérables, expirent automatiquement)
 * plutôt que 'hard' par défaut ou ignorés.
 *
 * Test comportemental réel : envoie un payload Postmark RecordType=Bounce
 * avec Type=Transient (boîte pleine) à processBounceWebhook(), vérifie que
 * le bounce EST enregistré (plus ignoré) ET classé 'soft' (pas 'hard').
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest172-transient-' . bin2hex(random_bytes(4)) . '@example.invalid';

    $mgr = new BounceManager(neria_test_module());

    try {
        $recorded = $mgr->processBounceWebhook([
            'RecordType' => 'Bounce',
            'Type'       => 'Transient',
            'Email'      => $email,
            'Description'=> 'Mailbox full (round 172 test)',
        ], 'postmark');

        neria_assert(
            $recorded === true,
            "processBounceWebhook() avec Type='Transient' n'a pas enregistré le bounce — régression du bug corrigé le 15/08/2026 (round 172) : les types Postmark temporaires (Transient/Blocked/Undeliverable/...) seraient de nouveau silencieusement ignorés, aucune adresse à boîte pleine répétée ne serait jamais suivie"
        );

        $row = $db->getRow("SELECT type FROM `{$prefix}neria_bounces` WHERE email = '" . pSQL($email) . "'");
        neria_assert(is_array($row), "Aucune ligne neria_bounces trouvée pour l'email de test malgré recorded=true — incohérence");
        neria_assert(
            $row['type'] === 'soft',
            "Le bounce Type='Transient' est classé '{$row['type']}' au lieu de 'soft' — régression du bug corrigé le 15/08/2026 (round 172) : un échec temporaire (boîte pleine) bloquerait de nouveau définitivement l'adresse comme un hard bounce, sans possibilité de réactivation automatique"
        );
    } finally {
        $db->execute("DELETE FROM `{$prefix}neria_bounces` WHERE email = '" . pSQL($email) . "'");
    }

    return [
        'pass'    => true,
        'message' => "BounceManager::parsePostmark() enregistre et classe bien 'soft' les types Postmark temporaires (Transient et similaires), plus ignorés ni classés 'hard' à tort — bug corrigé le 15/08/2026 (round 172)",
    ];
}
