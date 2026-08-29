<?php
/**
 * Régression round 241 (29/08/2026) : QueueManager::processSingle() et
 * WebhookManager::processQueue() suivaient un schéma "envoyer PUIS marquer
 * envoyé" — `attempts` était incrémenté avant l'envoi, mais le statut
 * restait 'pending' jusqu'à l'écriture finale (status='sent'/'done') APRÈS
 * l'envoi réussi. Un crash du process (OOM/kill/timeout serveur) entre
 * l'envoi réel et cette écriture finale laissait la ligne 'pending' avec
 * attempts < MAX_ATTEMPTS — le prochain passage du cron la resélectionnait
 * et renvoyait le même email/webhook une seconde fois au destinataire.
 *
 * Corrigé le 29/08/2026 (round 241) :
 * - QueueManager::processSingle() réserve désormais atomiquement la ligne
 *   (UPDATE ... SET status='sending' WHERE status='pending', vérifié via
 *   Affected_Rows()) AVANT l'envoi. QueueManager::processQueue() récupère
 *   en tête les lignes restées bloquées à 'sending' depuis plus de 10
 *   minutes (crash détecté) en les repassant à 'pending'.
 * - Même schéma pour WebhookManager::processQueue().
 *
 * Test comportemental réel (partie A) : simule une ligne de file abandonnée
 * en 'sending' par un crash (send_at vieux de 15 minutes), appelle
 * QueueManager::processQueue(), et vérifie qu'elle n'est plus bloquée à
 * 'sending' après coup (récupérée puis retraitée).
 *
 * Test comportemental réel (partie B) : vérifie que la réservation atomique
 * refuse bien de retraiter une ligne déjà à 'sending' — appelle
 * processSingle() par Reflection sur une ligne dont le statut n'est PAS
 * 'pending' et vérifie un retour false immédiat, sans incrément supplémentaire
 * de `attempts`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    $db->execute("DELETE FROM {$prefix}neria_queue WHERE recipient_email LIKE 'regtest-477%@example.com'");

    // ref_id distincts : uq_customer_template_ref_shop est une contrainte
    // UNIQUE (id_customer, template, ref_id, id_shop) — les deux lignes de
    // ce test partagent le même client/template/boutique.
    $refA = 477001 + random_int(1, 999);
    $refB = 478001 + random_int(1, 999);

    // ── Partie A : récupération d'une ligne bloquée à 'sending' par un crash ──
    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES ({$idCustomer}, {$idShop}, " . (int) Configuration::get('PS_LANG_DEFAULT') . ", 'reply_msg',
                 'regtest-477a@example.com', 'Regtest477',
                 '{}', {$refA}, DATE_SUB(NOW(), INTERVAL 15 MINUTE), 'sending', 1, NOW())"
    );
    $idQueueA = (int) $db->Insert_ID();

    // ── Partie B : la réservation refuse une ligne qui n'est pas 'pending' ──
    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES ({$idCustomer}, {$idShop}, " . (int) Configuration::get('PS_LANG_DEFAULT') . ", 'reply_msg',
                 'regtest-477b@example.com', 'Regtest477',
                 '{}', {$refB}, NOW(), 'sending', 1, NOW())"
    );
    $idQueueB = (int) $db->Insert_ID();

    try {
        $mgr = new QueueManager(neria_test_module());

        // Partie B d'abord (avant que processQueue() de la partie A ne touche
        // à autre chose) : processSingle() par Reflection sur la ligne B,
        // encore à 'sending', doit refuser de la retraiter.
        $refSingle = new ReflectionMethod($mgr, 'processSingle');
        $refSingle->setAccessible(true);
        $rowB = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueueB}");
        neria_assert($rowB !== false, "partie B : ligne introuvable juste après insertion");

        $resultB = $refSingle->invoke($mgr, $rowB);
        neria_assert(
            $resultB === false,
            "processSingle() a retraité une ligne qui n'était PAS 'pending' (status='sending') — régression du bug corrigé le 29/08/2026 (round 241) : la réservation atomique ne protège plus contre le retraitement d'une ligne déjà réservée/en cours"
        );

        $attemptsAfterB = (int) $db->getValue("SELECT attempts FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueueB}");
        neria_assert(
            $attemptsAfterB === 1,
            "processSingle() a incrémenté attempts (" . $attemptsAfterB . ") sur une ligne refusée par la réservation — la UPDATE conditionnée à status='pending' ne devrait rien modifier"
        );

        // Partie A : processQueue() doit d'abord récupérer la ligne A (status
        // 'sending' depuis 15 min > seuil de 10 min), puis la retraiter
        // (elle redevient 'pending' donc sélectionnable dans le même appel).
        $mgr->processQueue();

        $statusA = (string) $db->getValue("SELECT status FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueueA}");
        neria_assert(
            $statusA !== 'sending',
            "la ligne A est restée bloquée à 'sending' après processQueue() — régression du bug corrigé le 29/08/2026 (round 241) : une ligne abandonnée par un crash resterait bloquée définitivement, jamais récupérée ni retentée"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager récupère bien une ligne bloquée à 'sending' par un crash (statut final: {$statusA}), et refuse de retraiter une ligne déjà réservée (attempts inchangé) — bug corrigé le 29/08/2026 (round 241)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_neria_queue IN ({$idQueueA}, {$idQueueB})");
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND ref_id IN ({$refA}, {$refB}) AND template = 'reply_msg'");
    }
}
