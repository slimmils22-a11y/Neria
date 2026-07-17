<?php
/** Régression : le texte gagnant d'un test A/B appliqué en production doit être marqué is_custom=1 pour survivre à un reset/upgrade. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $template = 'regtest_abtest_' . time();

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';
        $ab = new ABTestManager(neria_test_module());
        $idA = $ab->createTest($template, 'Variante A', 'Variante B', 50);
        neria_assert($idA !== false, "createTest() a échoué");
        $ab->activateTest($template);

        $db->execute("INSERT INTO {$prefix}neria_translation (template, lang, translation_key, translation_value, is_custom, date_add, date_upd)
            VALUES ('{$template}', 'fr', 'greeting_main', 'Texte variante B', 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE translation_value='Texte variante B'");

        $refApply = new ReflectionMethod($ab, 'applyWinner');
        $refApply->setAccessible(true);
        $refApply->invoke($ab, $template, 'B');

        $isCustom = (int) $db->getValue(
            "SELECT is_custom FROM {$prefix}neria_translation
             WHERE template='{$template}' AND lang='fr' AND translation_key='greeting_main'"
        );
        neria_assert(
            $isCustom === 1,
            "is_custom={$isCustom} après applyWinner(), attendu 1 — régression du bug corrigé le 17/07/2026 (commit aba5b16), le texte gagnant serait de nouveau écrasable par un reset BO"
        );

        return ['pass' => true, 'message' => 'copyVariantBToDefault() marque toujours is_custom=1'];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_translation WHERE template='{$template}'");
        $db->execute("DELETE FROM {$prefix}neria_abtest WHERE template='{$template}'");
    }
}
