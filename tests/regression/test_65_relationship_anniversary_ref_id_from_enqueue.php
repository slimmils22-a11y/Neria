<?php
/**
 * Régression : QueueManager::processSingle() doit utiliser row['ref_id']
 * (le millésime déjà figé à l'enqueue) pour relationship_anniversary, et
 * non le recalculer avec (int) date('Y') au moment de l'envoi réel.
 *
 * Bug réel corrigé le 06/08/2026 (round 62) : un envoi reporté au lendemain
 * par la fenêtre d'achat individuelle (heure préférée déjà passée) à cheval
 * sur le Nouvel An écrivait le mauvais millésime dans
 * neria_behavioral_sent — la déduplication de sendRelationshipAnniversaries()
 * l'année suivante matchait alors à tort cette ligne mal datée, privant le
 * client de son email d'anniversaire de relation cette année-là.
 *
 * Ce test simule exactement ce cas : une ligne de file avec ref_id = année
 * PASSÉE (ex. 2025), traitée "aujourd'hui" (année courante réelle) — comme
 * si l'envoi avait été planifié fin décembre puis effectivement envoyé le
 * lendemain 1er janvier. Déclenche un vrai envoi via Mailpit (même
 * méthodologie que test_46) et vérifie que neria_behavioral_sent est peuplé
 * avec le ref_id DE LA FILE, pas l'année du jour du traitement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $template   = 'relationship_anniversary';
    $enqueueYear    = (int) date('Y') - 1; // millésime figé à l'enqueue, l'an dernier
    $processingYear = (int) date('Y');     // année réelle du jour où processSingle() tourne

    $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id IN ({$enqueueYear}, {$processingYear})");

    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES ({$idCustomer}, " . (int) Context::getContext()->shop->id . ", " . (int) Configuration::get('PS_LANG_DEFAULT') . ", '{$template}',
                 'regtest-65@example.com', 'Regtest',
                 '{}', {$enqueueYear}, NOW(), 'pending', 0, NOW())"
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

        $withEnqueueYear = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent
             WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id = {$enqueueYear}"
        );
        $withProcessingYear = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_behavioral_sent
             WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id = {$processingYear}"
        );

        neria_assert(
            $withEnqueueYear === 1,
            "neria_behavioral_sent n'a pas été peuplé avec le ref_id de la file ({$enqueueYear}) — processSingle() n'utilise plus row['ref_id'] pour relationship_anniversary"
        );
        neria_assert(
            $withProcessingYear === 0,
            "neria_behavioral_sent a été peuplé avec l'année du TRAITEMENT ({$processingYear}) au lieu du millésime figé à l'enqueue ({$enqueueYear}) — régression du bug corrigé le 06/08/2026 (round 62) : un envoi reporté à cheval sur le Nouvel An écrirait de nouveau le mauvais millésime, cassant la déduplication l'année suivante"
        );

        return [
            'pass'    => true,
            'message' => "processSingle() utilise bien row['ref_id'] (millésime de l'enqueue) pour relationship_anniversary, pas l'année du jour de traitement",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue}");
        $db->execute("DELETE FROM {$prefix}neria_behavioral_sent WHERE id_customer = {$idCustomer} AND template = '{$template}' AND ref_id IN ({$enqueueYear}, {$processingYear})");
    }
}
