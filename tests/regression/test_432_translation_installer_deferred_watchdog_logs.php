<?php
/**
 * Régression : TranslationInstaller journalisait ses erreurs Watchdog
 * (doublon de clé, échec bulk insert) DANS la transaction SQL
 * (START TRANSACTION/ROLLBACK) qui encadre l'import. WatchdogManager
 * (et Neria::log(), via PrestaShopLogger::addLog()) utilisent la MÊME
 * connexion Db::getInstance() singleton que TranslationInstaller — un log
 * émis pendant la transaction est donc annulé par le ROLLBACK qui suit un
 * import échoué, effaçant précisément le diagnostic censé expliquer
 * pourquoi l'import a échoué.
 *
 * Bug réel identifié le 24/08/2026 (round 204) : un hébergement mutualisé
 * avec timeout/verrou transitoire pendant l'import fait échouer
 * flushBatch(), qui journalise l'erreur SQL réelle via Watchdog — puis
 * importFromJson()/importTemplate() exécutent ROLLBACK, annulant ce log
 * avec le reste. Le marchand voit "import échoué" dans le BO mais
 * l'onglet Watchdog ne contient aucune trace de la cause réelle.
 *
 * Corrigé le 24/08/2026 (round 204) : les logs Watchdog/module sont
 * désormais mis en attente (queueWatchdogError()/queueModuleLog()) pendant
 * la transaction, puis réellement journalisés par
 * flushPendingWatchdogLogs() APRÈS COMMIT ou ROLLBACK.
 *
 * Test comportemental réel : met en file un log via la méthode privée
 * queueWatchdogError(), vérifie qu'il n'est PAS encore en base (comme
 * pendant une transaction active), puis appelle flushPendingWatchdogLogs()
 * et vérifie qu'il apparaît bien dans neria_log.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php';

    $module = neria_test_module();
    $installer = new TranslationInstaller($module);

    $refQueue = new ReflectionMethod('TranslationInstaller', 'queueWatchdogError');
    $refQueue->setAccessible(true);
    $refFlush = new ReflectionMethod('TranslationInstaller', 'flushPendingWatchdogLogs');
    $refFlush->setAccessible(true);
    $refPending = new ReflectionProperty('TranslationInstaller', 'pendingWatchdogLogs');
    $refPending->setAccessible(true);

    $marker = 'ROUND204_TEST_' . uniqid();
    $db = Db::getInstance();

    try {
        // Étape 1 : mise en file — simule le comportement pendant que la
        // transaction est encore active. Le log ne doit PAS être en base.
        $refQueue->invoke($installer, $marker);

        $pending = $refPending->getValue($installer);
        neria_assert(
            count($pending) === 1,
            "TranslationInstaller::queueWatchdogError() ne met plus le log en attente — régression du bug corrigé le 24/08/2026 (round 204)"
        );

        $countBeforeFlush = (int) $db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_log` WHERE `message` LIKE '%{$marker}%'"
        );
        neria_assert(
            $countBeforeFlush === 0,
            "Le log Watchdog a été écrit en base AVANT flushPendingWatchdogLogs() — le mécanisme de report ne fonctionne pas comme attendu"
        );

        // Étape 2 : flush — simule l'appel après COMMIT/ROLLBACK. Le log
        // doit maintenant être réellement en base.
        $refFlush->invoke($installer);

        $countAfterFlush = (int) $db->getValue(
            "SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "neria_log` WHERE `message` LIKE '%{$marker}%'"
        );
        neria_assert(
            $countAfterFlush === 1,
            "TranslationInstaller::flushPendingWatchdogLogs() n'écrit plus le log Watchdog différé en base — régression du bug corrigé le 24/08/2026 (round 204) : un diagnostic d'échec d'import serait de nouveau perdu"
        );

        $pendingAfter = $refPending->getValue($installer);
        neria_assert(
            count($pendingAfter) === 0,
            "flushPendingWatchdogLogs() ne vide plus la file après journalisation — risque de doublon si appelée deux fois"
        );
    } finally {
        $db->execute("DELETE FROM `" . _DB_PREFIX_ . "neria_log` WHERE `message` LIKE '%{$marker}%'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationInstaller diffère bien ses logs Watchdog jusqu'après résolution de la transaction, évitant qu'un ROLLBACK n'efface le diagnostic d'échec d'import — bug corrigé le 24/08/2026 (round 204)",
    ];
}
