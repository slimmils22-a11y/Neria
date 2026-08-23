<?php
/**
 * Régression : QueueManager::processSingle() passait row['ref_id'] tel quel
 * comme $idOrder à CooldownManager::isDuplicate() — alors que ref_id est un
 * identifiant de dédup GÉNÉRIQUE de la file (année×mois pour
 * wishlist_reminder, id_cart pour checkout_abandonment...), pas
 * systématiquement un vrai id de commande.
 *
 * Bug réel identifié le 23/08/2026 (round 190) : pour ces templates, la
 * clause SQL "AND id_order = <ref_id bidon>" ne matchait jamais aucune ligne
 * réelle de neria_stat — le pré-contrôle cooldown de processSingle() (censé,
 * depuis le round 178, marquer la ligne 'failed' AVANT Mail::Send()) ne se
 * déclenchait quasiment jamais. Mail::Send() était alors appelé, le hook
 * global bloquait bien l'envoi réel mais renvoyait TOUJOURS true (comme
 * documenté dans classes/Mail.php), et la ligne était marquée à tort 'sent'
 * + insérée dans neria_behavioral_sent — verrouillant définitivement ce
 * créneau alors que l'email n'a jamais été livré.
 *
 * Corrigé le 23/08/2026 (round 190) : $idOrder/$refScope lus depuis
 * $allVars['{id_order}']/['{cooldown_scope}'] (mêmes clés que
 * hookActionEmailSendBefore dans neria.php), pas row['ref_id'].
 *
 * Test comportemental réel : enqueue un template avec un ref_id BIDON (non
 * un id de commande, ex. 202608) mais un {cooldown_scope} explicite dans ses
 * extraVars, seed une ligne neria_stat 'sent' correspondant à CE scope dans
 * la fenêtre de cooldown, puis traite la file et vérifie que la ligne est
 * bien marquée 'failed'/blocked_by_cooldown (pas faussement 'sent').
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $idCustomer = neria_test_any_customer_id();

    neria_assert($idCustomer > 0, 'jeu de test invalide : aucun client actif trouvé');

    $template  = 'wishlist_reminder';
    $refScope  = 'test_405_round190_scope';
    $bogusRefId = 202608; // année×mois, PAS un id de commande

    Configuration::updateGlobalValue('NERIA_COOLDOWN_ENABLED', 1);
    Configuration::updateGlobalValue('NERIA_COOLDOWN_MINUTES', 60);

    $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_customer = {$idCustomer} AND template = '" . pSQL($template) . "' AND ref_id = {$bogusRefId}");
    $db->execute("DELETE FROM {$prefix}neria_stat WHERE ref_scope = '" . pSQL($refScope) . "'");

    $customerRow = $db->getRow("SELECT email, firstname, lastname, id_lang FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    neria_assert($customerRow !== false, 'jeu de test invalide : client introuvable');

    try {
        // Seed une ligne 'sent' déjà enregistrée pour CE scope, dans la
        // fenêtre de cooldown (5 minutes < 60 minutes configurées).
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, id_order, ref_scope, tracking_token, event_type, date_add)
             VALUES
                ({$idShop}, '" . pSQL($template) . "', 'fr', {$idCustomer}, 0, '" . pSQL($refScope) . "', '" . bin2hex(random_bytes(16)) . "', 'sent', DATE_SUB(NOW(), INTERVAL 5 MINUTE))"
        );

        $mgr = new QueueManager($module);
        $mgr->enqueue(
            $template,
            ['id_customer' => $idCustomer, 'id_shop' => $idShop, 'id_lang' => $customerRow['id_lang'], 'email' => $customerRow['email'], 'firstname' => $customerRow['firstname'], 'lastname' => $customerRow['lastname']],
            ['{cooldown_scope}' => $refScope],
            $bogusRefId,
            (int) date('G')
        );

        $idQueue = (int) $db->getValue(
            "SELECT id_neria_queue FROM {$prefix}neria_queue WHERE id_customer = {$idCustomer} AND template = '" . pSQL($template) . "' AND ref_id = {$bogusRefId}"
        );
        neria_assert($idQueue > 0, 'jeu de test invalide : enqueue() n\'a créé aucune ligne');

        // Force le passage immédiat (send_at déjà écoulé) pour ce test.
        $db->execute("UPDATE {$prefix}neria_queue SET send_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id_neria_queue = {$idQueue}");

        $ref = new ReflectionMethod(QueueManager::class, 'processSingle');
        $ref->setAccessible(true);
        $row = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        $ref->invoke($mgr, $row);

        $result = $db->getRow("SELECT status, error FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        neria_assert($result !== false, 'la ligne de file a disparu après traitement');
        neria_assert(
            $result['status'] === 'failed' && $result['error'] === 'blocked_by_cooldown',
            "la ligne a été traitée avec status='{$result['status']}' error='{$result['error']}' au lieu de 'failed'/'blocked_by_cooldown' — régression du bug corrigé le 23/08/2026 (round 190) : le pré-contrôle cooldown utiliserait de nouveau ref_id comme id_order, ne matchant jamais la ligne neria_stat existante, laissant Mail::Send() être appelé et la ligne marquée à tort 'sent'"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_customer = {$idCustomer} AND template = '" . pSQL($template) . "' AND ref_id = {$bogusRefId}");
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE ref_scope = '" . pSQL($refScope) . "'");
    }

    return [
        'pass'    => true,
        'message' => "QueueManager::processSingle() lit bien {id_order}/{cooldown_scope} depuis les vars du template pour le pré-contrôle cooldown, pas ref_id — bug corrigé le 23/08/2026 (round 190)",
    ];
}
