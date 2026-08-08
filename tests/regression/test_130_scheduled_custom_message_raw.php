<?php
/**
 * Régression : ManualSendManager::scheduleManual() doit stocker le message
 * personnalisé du marchand ('custom_message' dans $contentVars) sous la clé
 * {custom_message_raw} — comme send() (envoi immédiat) — pas {custom_message}.
 *
 * Bug réel corrigé le 08/08/2026 (round 126) : send() traite spécialement
 * la clé 'custom_message' (stockée sous {custom_message_raw}), mais
 * scheduleManual() avait perdu ce cas spécial et stockait directement sous
 * {custom_message}. EmailRenderer::injectCustomMessage() — appelée au
 * moment de l'envoi RÉEL (déclenché plus tard par
 * QueueManager::processSingle()) — lit EXCLUSIVEMENT {custom_message_raw}
 * et écrase {custom_message} par une chaîne vide si cette clé est absente.
 * Un marchand programmant un envoi manuel avec un message personnalisé
 * voyait donc son texte disparaître silencieusement à l'envoi réel, sans
 * erreur ni log — contrairement à un envoi immédiat, qui fonctionnait
 * correctement.
 *
 * Test fonctionnel réel : programme un envoi manuel avec un message
 * personnalisé et vérifie que la ligne insérée dans neria_queue stocke bien
 * {custom_message_raw} avec le texte fourni.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest-round126-' . uniqid() . '@example.com';
    $sendAt = date('Y-m-d H:i:s', strtotime('+3 days'));
    $message = 'Merci pour votre fidélité — regtest round 126';

    try {
        $mgr    = new ManualSendManager(neria_test_module());
        $result = $mgr->scheduleManual('vip', $email, '', 'Sujet test', ['custom_message' => $message], $sendAt);

        neria_assert(
            is_array($result) && ($result['ok'] ?? false) === true,
            "scheduleManual() a échoué (jeu de test invalide) : " . json_encode($result)
        );

        $varsJson = (string) $db->getValue(
            "SELECT vars_json FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($email) . "' AND template = 'vip'"
        );
        neria_assert($varsJson !== '', "Aucune ligne trouvée dans neria_queue pour cet envoi programmé — jeu de test invalide");

        $vars = json_decode($varsJson, true);
        neria_assert(is_array($vars), "vars_json n'est pas un JSON valide — jeu de test invalide");

        neria_assert(
            ($vars['{custom_message_raw}'] ?? null) === $message,
            "scheduleManual() ne stocke plus le message personnalisé sous {custom_message_raw} (valeur trouvée : " . json_encode($vars['{custom_message_raw}'] ?? null) . ") — régression du bug corrigé le 08/08/2026 (round 126) : le message disparaîtrait silencieusement à l'envoi réel (EmailRenderer::injectCustomMessage() ne lit que cette clé)"
        );
        neria_assert(
            !isset($vars['{custom_message}']),
            "scheduleManual() stocke encore le message brut sous {custom_message} — cette clé doit être calculée par EmailRenderer::injectCustomMessage() au moment de l'envoi, pas posée directement ici"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE recipient_email = '" . pSQL($email) . "'");
    }

    return [
        'pass'    => true,
        'message' => "ManualSendManager::scheduleManual() stocke bien le message personnalisé sous {custom_message_raw}, comme send()",
    ];
}
