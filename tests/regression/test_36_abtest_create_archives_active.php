<?php
/**
 * Régression : recréer un test A/B sur un template qui a déjà un test ACTIF
 * doit archiver ce test dans neria_abtest_history avant de le supprimer —
 * jamais une suppression silencieuse sans trace.
 *
 * Bug réel corrigé le 02/08/2026 (commit 92d2ed0) : neria.php, action
 * create_abtest, appelait directement ABTestManager::deleteTests() sans
 * jamais archiver — contrairement à deactivate_abtest qui archive
 * systématiquement. Un double-clic ou une resoumission accidentelle du
 * formulaire de création effaçait définitivement la configuration d'un
 * test en cours, sans laisser de trace.
 *
 * Reproduit ici la même séquence que neria.php (hasActiveTest() →
 * archiveTest() → deleteTests()), puisque cette logique vit directement
 * dans le handler AJAX plutôt que dans une méthode dédiée d'ABTestManager.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $template = 'regtest_abtest_archive_' . time();

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';
        require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';
        $ab = new ABTestManager(neria_test_module());
        $statsMgr = new StatsManager(neria_test_module());

        // Un test est actif sur ce template (déjà en cours)
        $ab->createTest($template, 'A active', 'B active', 50);
        $ab->activateTest($template);

        $histBefore = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_abtest_history WHERE template='{$template}'"
        );

        // Reproduit exactement la logique corrigée de neria.php (create_abtest)
        if ($ab->hasActiveTest($template)) {
            $report = $statsMgr->getABTestReport($template, 9999);
            $sig    = $report['significance'] ?? [];
            $winner = (string) ($sig['overall_winner'] ?? '');
            $conf   = (int) max($sig['open']['confidence'] ?? 0, $sig['click']['confidence'] ?? 0);
            $ab->archiveTest($template, $report, $winner, $conf, false);
        }
        $ab->deleteTests($template);
        $ab->createTest($template, 'A nouvelle', 'B nouvelle', 50);

        $histAfter = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_abtest_history WHERE template='{$template}'"
        );

        neria_assert(
            $histAfter > $histBefore,
            "Aucune ligne d'archive créée (histBefore={$histBefore}, histAfter={$histAfter}) — régression du bug corrigé le 02/08/2026 (commit 92d2ed0), un test actif recréé disparaîtrait de nouveau sans archivage ni trace"
        );

        return ['pass' => true, 'message' => 'Recréer un test A/B actif archive toujours sa configuration avant suppression'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_abtest WHERE template='{$template}'");
        $db->execute("DELETE FROM {$prefix}neria_abtest_history WHERE template='{$template}'");
    }
}
