<?php
/**
 * Régression : ManualSendManager::scheduleManual() doit bloquer la
 * planification d'un template utilisant {order_name}/{order_url}
 * (alteration_update, gift_guarantee...) quand aucune commande valide n'est
 * fournie — exactement le même garde-fou que send() (garde-fou "contexte
 * commande" déjà en place depuis le 30/07/2026), qui manquait sur le
 * chemin PLANIFIÉ.
 *
 * Bug réel corrigé le 05/08/2026 (round 51) : scheduleManual() ignorait
 * complètement $orderRef — un envoi planifié via la file d'attente partait
 * des jours plus tard avec {order_name}/{order_url} non résolus dans le
 * corps de l'email, sans que le marchand n'ait la moindre chance de s'en
 * rendre compte au moment de la planification (pas de message d'erreur,
 * l'envoi semblait accepté).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $mgr = new ManualSendManager(neria_test_module());

    // alteration_update utilise {order_name}/{order_url} (mails/themes/
    // neria_global/core/alteration_update.txt) et fait partie de
    // WAVE1_TEMPLATES (isSendable() = true) — orderRef vide, aucune
    // commande liée.
    $result = $mgr->scheduleManual(
        'alteration_update',
        'regtest-' . uniqid() . '@example.com',
        '',
        'Sujet de test',
        [],
        date('Y-m-d H:i:s', strtotime('+1 day'))
    );

    neria_assert(
        is_array($result) && ($result['ok'] ?? true) === false,
        "scheduleManual() ne bloque plus l'envoi planifié d'un template nécessitant {order_name}/{order_url} sans commande liée — régression du garde-fou 'contexte commande' corrigé le 05/08/2026"
    );

    return [
        'pass'    => true,
        'message' => 'scheduleManual() bloque bien un envoi planifié sans commande pour un template qui en a besoin',
    ];
}
