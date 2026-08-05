<?php
/**
 * Régression : ManualSendManager::scheduleManual() doit détecter et
 * signaler honnêtement l'échec de mise en file (contrainte UNIQUE sur
 * neria_queue), au lieu de toujours retourner ok=true.
 *
 * Bug réel corrigé le 05/08/2026 (round 52) : QueueManager::enqueueAt()
 * faisait un simple INSERT sans jamais vérifier le résultat. Depuis
 * l'ajout de la contrainte UNIQUE (id_customer, template, ref_id, id_shop)
 * par upgrade-1.0.36.php, un 2e envoi manuel planifié du même template au
 * même client (ref_id toujours 0 pour ces envois) violait cette contrainte
 * et échouait silencieusement — ManualSendManager retournait quand même
 * ok=true, "programmé avec succès", alors que la ligne n'existait pas
 * réellement en file (le client ne recevrait jamais l'email).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest-sched-' . uniqid() . '@example.com';
    $sendAt = date('Y-m-d H:i:s', strtotime('+2 days'));

    // Pré-existant : une ligne 'pending' déjà en file pour ce template et
    // cet email (ref_id=0, comme le pose toujours scheduleManual()) —
    // simule un premier envoi manuel déjà programmé.
    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, created_at)
         VALUES (0, 1, 1, 'vip', '" . pSQL($email) . "', 'Regtest', '{}', 0, '{$sendAt}', 'pending', NOW())"
    );
    $idQueue = (int) $db->Insert_ID();

    try {
        $mgr = new ManualSendManager(neria_test_module());
        $result = $mgr->scheduleManual('vip', $email, '', 'Sujet test', [], $sendAt);

        neria_assert(
            is_array($result) && ($result['ok'] ?? true) === false,
            "scheduleManual() retourne encore ok=true pour un 2e envoi planifié en doublon (contrainte UNIQUE violée) — régression du bug corrigé le 05/08/2026 : l'admin verrait 'programmé avec succès' pour un envoi qui ne partira jamais"
        );

        // Une seule ligne doit exister en file (celle déjà pré-existante),
        // pas une deuxième qui aurait été insérée malgré le message d'échec.
        $count = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($email) . "' AND template = 'vip'"
        );
        neria_assert($count === 1, "attendu 1 seule ligne en file après la tentative en doublon, obtenu {$count}");

        return [
            'pass'    => true,
            'message' => 'scheduleManual() détecte bien l\'échec de mise en file (doublon) et ne prétend plus au succès',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($email) . "'");
    }
}
