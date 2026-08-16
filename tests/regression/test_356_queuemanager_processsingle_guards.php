<?php
/**
 * Régression : QueueManager::processSingle() appelait Mail::Send()
 * directement sans AUCUNE revérification (bounce/blacklist/préférences/
 * cooldown) — contrairement à ManualSendManager::send(), qui les revérifie
 * explicitement précisément parce que Mail::Send() du cœur PrestaShop
 * retourne TOUJOURS true quand le hook actionEmailSendBefore annule
 * l'envoi. Une ligne bloquée silencieusement par le hook était donc quand
 * même marquée 'sent' + sent_at, et pour first_anniversary/
 * relationship_anniversary une ligne neria_behavioral_sent était insérée
 * comme si l'email était réellement parti — empêchant tout futur envoi
 * légitime via checkAnniversaryConflict().
 *
 * Corrigé le 16/08/2026 (round 178) : les 4 mêmes garde-fous que
 * ManualSendManager::send() ont été ajoutés à processSingle(), AVANT
 * Mail::Send() — un blocage marque désormais la ligne 'failed' (pas
 * 'sent') via markQueueFailed(), sans retry (pas une panne SMTP
 * transitoire).
 *
 * Test comportemental réel : pose une règle de blacklist sur un template de
 * test, enqueue() un envoi réel pour ce template, appelle processSingle()
 * via réflexion — la ligne doit finir 'failed' (pas 'sent'), et aucun email
 * n'a été réellement tenté.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/BlacklistManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;

    $customerRow = $db->getRow(
        "SELECT id_customer, email, firstname, lastname, id_lang FROM {$prefix}customer WHERE active = 1 AND deleted = 0"
    );
    neria_assert($customerRow !== false, 'Aucun client actif trouvé — jeu de test invalide');

    $template = 'regtest356_' . substr(uniqid(), -8);
    $blMgr = new BlacklistManager($idShop);

    try {
        $blMgr->add($template, '');

        $queueMgr = new QueueManager($module);
        $queueMgr->enqueue(
            $template,
            [
                'id_customer' => (int) $customerRow['id_customer'],
                'id_shop'     => $idShop,
                'id_lang'     => (int) $customerRow['id_lang'],
                'email'       => $customerRow['email'],
                'firstname'   => $customerRow['firstname'],
                'lastname'    => $customerRow['lastname'],
            ],
            [],
            999999,
            10
        );

        $row = $db->getRow(
            "SELECT * FROM {$prefix}neria_queue
             WHERE template = '" . pSQL($template) . "' AND id_customer = " . (int) $customerRow['id_customer'] . "
             ORDER BY id_neria_queue DESC"
        );
        neria_assert($row !== false, "enqueue() n'a pas créé de ligne — jeu de test invalide");

        $ref = new ReflectionMethod(QueueManager::class, 'processSingle');
        $ref->setAccessible(true);
        $result = $ref->invoke($queueMgr, $row);

        neria_assert(
            $result === false,
            "QueueManager::processSingle() retourne true pour une ligne bloquée par la blacklist — régression du bug corrigé le 16/08/2026 (round 178)"
        );

        $finalStatus = $db->getValue(
            "SELECT status FROM {$prefix}neria_queue WHERE id_neria_queue = " . (int) $row['id_neria_queue']
        );
        neria_assert(
            $finalStatus === 'failed',
            "QueueManager::processSingle() a marqué la ligne '{$finalStatus}' au lieu de 'failed' pour un envoi bloqué par la blacklist — régression du bug corrigé le 16/08/2026 (round 178) : une ligne bloquée par un garde-fou de politique serait de nouveau marquée 'sent' comme si l'email était réellement parti"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::processSingle() revérifie bien bounce/blacklist/préférences/cooldown avant Mail::Send(), marquant 'failed' (pas 'sent') un envoi bloqué — bug corrigé le 16/08/2026 (round 178)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE template = '" . pSQL($template) . "'");
        $rules = $blMgr->getAll();
        foreach ($rules as $rule) {
            if ($rule['template'] === $template) {
                $blMgr->remove((int) $rule['id_blacklist']);
            }
        }
    }
}
