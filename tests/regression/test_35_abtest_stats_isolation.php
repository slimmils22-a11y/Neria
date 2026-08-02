<?php
/**
 * Régression : les événements (sent/open/click/conversion) d'un ancien test
 * A/B doivent être désétiquetés (abtest_variant = NULL) quand ce test est
 * supprimé, pour ne jamais contaminer le calcul de significativité d'un
 * NOUVEAU test relancé plus tard sur le même template.
 *
 * Bug réel corrigé le 02/08/2026 (commit 92d2ed0) : les événements n'étaient
 * rattachés qu'à template+variante A/B, jamais à un id_abtest précis.
 * Relancer un test des mois après un premier (même template) agrégeait les
 * centaines d'anciens événements aux nouveaux — un "gagnant" pouvait être
 * déclaré et appliqué en production après seulement quelques envois du
 * nouveau test, sur la base de résultats appartenant à l'ancien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $template = 'regtest_abtest_isolation_' . time();

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';
        $ab = new ABTestManager(neria_test_module());

        // "Ancien" test : cree, active, et accumule 300 evenements (150 par variante)
        $ab->createTest($template, 'Ancien A', 'Ancien B', 50);
        $ab->activateTest($template);
        for ($i = 0; $i < 150; $i++) {
            $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, template, event_type, abtest_variant, tracking_token, date_add)
                          VALUES ({$idShop}, '{$template}', 'sent', 'A', CONCAT('regisoA', {$i}), NOW())");
            $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, template, event_type, abtest_variant, tracking_token, date_add)
                          VALUES ({$idShop}, '{$template}', 'sent', 'B', CONCAT('regisoB', {$i}), NOW())");
        }
        $ab->deactivateTest($template);

        // Nouveau test relance sur le MEME template (simule create_abtest) :
        // deleteTests() doit desormais desetiqueter les vieux evenements.
        $ab->deleteTests($template);
        $ab->createTest($template, 'Nouveau A', 'Nouveau B', 50);
        $ab->activateTest($template);

        $stillTagged = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_stat
             WHERE template='{$template}' AND abtest_variant IN ('A','B')"
        );
        neria_assert(
            $stillTagged === 0,
            "{$stillTagged} evenement(s) de l'ancien test toujours etiquete(s) A/B apres deleteTests() — regression du bug de contamination corrige le 02/08/2026 (commit 92d2ed0)"
        );

        // Le nouveau test, lui, doit voir ses propres evenements normalement
        require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';
        $statsMgr = new StatsManager(neria_test_module());
        for ($i = 0; $i < 5; $i++) {
            $db->execute("INSERT INTO {$prefix}neria_stat (id_shop, template, event_type, abtest_variant, tracking_token, date_add)
                          VALUES ({$idShop}, '{$template}', 'sent', 'A', CONCAT('regisonewA', {$i}), NOW())");
        }
        $report = $statsMgr->getABTestReport($template, 9999);
        $sentA = (int) ($report['A']['total_sent'] ?? -1);
        neria_assert(
            $sentA === 5,
            "sent_a={$sentA} apres le nouveau test (attendu 5) — les anciens evenements desetiquetes ne doivent plus compter, ni manquer les nouveaux"
        );

        return ['pass' => true, 'message' => "deleteTests() desetiquette toujours les anciens evenements A/B, evitant leur contamination d'un nouveau test"];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_abtest WHERE template='{$template}'");
        $db->execute("DELETE FROM {$prefix}neria_abtest_translation WHERE id_abtest NOT IN (SELECT id_abtest FROM {$prefix}neria_abtest)");
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE template='{$template}'");
    }
}
