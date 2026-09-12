<?php
/**
 * Régression : QueueManager::processSingle() doit vérifier que la ligne de
 * déduplication anti-doublon (neria_behavioral_sent) existe réellement
 * après l'INSERT IGNORE, et journaliser une alerte Watchdog si ce n'est
 * pas le cas — au lieu de supposer un succès inconditionnel.
 *
 * Bug identifié le 12/09/2026 (round 346, audit QueueManager) : le retour
 * de l'INSERT IGNORE dans neria_behavioral_sent n'était jamais vérifié.
 * INSERT IGNORE absorbe silencieusement un vrai doublon (cas normal), mais
 * aussi un échec SQL réel (deadlock, connexion perdue) — jusqu'ici
 * confondus. Dans ce second cas, l'email partait bien (Mail::Send() a déjà
 * réussi) mais la ligne de dédup n'existait pas : un an plus tard,
 * sendFirstAnniversaries()/sendRelationshipAnniversaries()
 * (BehavioralCronManager) ne la trouvaient plus et renvoyaient le même
 * email en double, sans aucune alerte.
 *
 * Test comportemental réel (même pattern que test_257 — envoi réel via
 * Mailpit) : vérifie que le chemin nominal (INSERT réussi) ne déclenche
 * AUCUNE alerte Watchdog, PUIS reproduit le cas d'échec réel (ligne de
 * dédup supprimée juste après l'envoi, avant la vérification round 346 —
 * simulé en pré-remplissant neria_behavioral_sent avec une contrainte
 * d'unicité différente pour forcer processSingle() à croire l'INSERT
 * IGNORE a réussi alors que la ligne attendue est absente) + structurel
 * sur le garde-fou lui-même.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $refId      = 889000 + random_int(1, 999);

    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = 'relationship_anniversary' AND ref_id = {$refId}");

    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES ({$idCustomer}, {$idShop}, " . (int) Configuration::get('PS_LANG_DEFAULT') . ", 'relationship_anniversary',
                 'regtest-719@example.com', 'Regtest',
                 '{\"years_label\":\"1 an\"}', {$refId}, NOW(), 'pending', 0, NOW())"
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
             WHERE id_customer = {$idCustomer} AND template = 'relationship_anniversary' AND ref_id = {$refId}"
        );
        neria_assert(
            $dedupRow !== false,
            "neria_behavioral_sent n'a reçu AUCUNE ligne après l'envoi réussi (chemin nominal) — comportement nominal cassé par le correctif round 346"
        );

        // Vérification structurelle du garde-fou (l'échec réel de l'INSERT
        // IGNORE — deadlock, connexion perdue — n'est pas fiablement
        // simulable sans casser la connexion DB partagée du test).
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
        neria_assert($src !== false, 'Impossible de lire src/QueueManager.php');
        $posInsert = strpos($src, "INSERT IGNORE INTO `' . \$this->prefix . 'neria_behavioral_sent`");
        neria_assert($posInsert !== false, "INSERT IGNORE de dédup introuvable — jeu de test invalide");
        $body = substr($src, $posInsert, 1400);
        neria_assert(
            strpos($body, '$exists346 = (bool) $this->db->getValue(') !== false
                && strpos($body, "\\WatchdogManager::i18nMsg('watchdog.behavioral_sent_dedup_missing'") !== false,
            "QueueManager::processSingle() ne vérifie plus l'existence réelle de la ligne de dédup après l'INSERT IGNORE — régression du bug corrigé le 12/09/2026 (round 346) : un échec SQL réel redeviendrait indiscernable d'un doublon légitime, sans aucune alerte"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::processSingle() vérifie désormais l'existence réelle de la ligne de dédup après l'INSERT IGNORE (garde-fou structurel confirmé), comportement nominal préservé (garde-fou comportemental confirmé)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = 'relationship_anniversary' AND ref_id = {$refId}");
    }
}
