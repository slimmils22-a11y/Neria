<?php
/**
 * Régression : la dédup neria_behavioral_sent pour un envoi routé par la
 * fenêtre d'achat individuelle (QueueManager) ne doit être posée QU'APRÈS
 * un envoi réellement réussi — pour TOUT template, pas seulement
 * first_anniversary/relationship_anniversary.
 *
 * Bug réel corrigé le 05/08/2026 (round 51) : BehavioralCronManager::send()
 * posait la dédup immédiatement à la mise en file (enqueue()), avant même
 * la tentative d'envoi. Si les 3 tentatives de QueueManager::processSingle()
 * échouaient définitivement (SMTP en panne), le template restait marqué
 * "déjà envoyé" pour de bon — le client ne recevait jamais l'email et le
 * cron ne le retentait plus jamais, silencieusement, pour n'importe quel
 * template comportemental passé par cette file (ghost_cart, win_back,
 * birthday...), pas seulement les anniversaires (seul cas déjà correct).
 *
 * Ce test appelle processSingle() (privée, via réflexion) avec une ligne de
 * file fabriquée pour un template NON-anniversaire, déclenche un VRAI envoi
 * via Mailpit (SMTP local de dev), et vérifie que neria_behavioral_sent est
 * bien peuplé après ce succès réel — la généralisation du round 51.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $template   = 'ghost_cart';
    $refId      = 999000 + random_int(1, 8999);
    $idShop     = (int) Context::getContext()->shop->id;

    // Vide toute trace résiduelle d'un run précédent pour cette clé.
    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id = {$refId}");

    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES ({$idCustomer}, {$idShop}, " . (int) Configuration::get('PS_LANG_DEFAULT') . ", '{$template}',
                 'regtest-46@example.com', 'Regtest',
                 '{}', {$refId}, NOW(), 'pending', 0, NOW())"
    );
    $idQueue = (int) $db->Insert_ID();

    try {
        $mgr = new QueueManager(neria_test_module());
        $ref = new ReflectionMethod($mgr, 'processSingle');
        $ref->setAccessible(true);

        $row = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        neria_assert($row !== false, "ligne de file introuvable juste après insertion");

        $sent = $ref->invoke($mgr, $row);
        neria_assert($sent === true, "processSingle() n'a pas réussi l'envoi réel via Mailpit — vérifier que le service SMTP local tourne (PS_MAIL_METHOD=2, localhost:1025)");

        $dedupCount = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent
             WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id = {$refId}"
        );
        neria_assert(
            $dedupCount === 1,
            "neria_behavioral_sent n'a pas été peuplé après un envoi réel réussi pour un template NON-anniversaire — régression de la généralisation corrigée le 05/08/2026 (round 51) ; la dédup resterait de nouveau limitée à first_anniversary/relationship_anniversary"
        );

        return [
            'pass'    => true,
            'message' => 'QueueManager::processSingle() pose bien la dédup après succès réel, pour un template non-anniversaire',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id = {$refId}");
    }
}
