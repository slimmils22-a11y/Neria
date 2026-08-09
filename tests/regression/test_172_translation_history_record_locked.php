<?php
/**
 * Régression : TranslationHistoryManager::record() doit verrouiller
 * (GET_LOCK) le cycle INSERT + pruneKey(), pour garantir que la limite
 * MAX_PER_KEY (50) reste respectée même sous appels concurrents.
 *
 * Bug réel corrigé le 08/08/2026 (round 138) : sans verrou, deux requêtes
 * HTTP concurrentes modifiant la même clé pouvaient chacune insérer puis
 * exécuter leur propre SELECT/DELETE de purge en parallèle, laissant
 * transitoirement plus de MAX_PER_KEY lignes conservées.
 *
 * Test comportemental réel : insère 55 entrées séquentielles pour la même
 * clé (simulateur simple de la logique de purge, le vrai test de
 * concurrence nécessiterait des process séparés) et vérifie que
 * exactement 50 lignes restent — protection déjà couverte par pruneKey()
 * lui-même, ce test vérifie surtout que le verrou ne casse pas ce
 * comportement séquentiel normal.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php';

    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $testTemplate = 'neria_test_round138_lock';
    $testLang = 'fr';
    $testKey = 'test_key_lock_round138';

    try {
        $mgr = new TranslationHistoryManager();
        for ($i = 1; $i <= 55; $i++) {
            $mgr->record($testTemplate, $testLang, $testKey, "valeur_" . ($i - 1), "valeur_{$i}", 'Test Round138');
        }

        $count = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_translation_history
             WHERE template_key = '{$testTemplate}' AND lang_code = '{$testLang}' AND translation_key = '{$testKey}'"
        );
        neria_assert(
            $count === 50,
            "record() verrouillé n'a pas conservé exactement 50 entrées après 55 insertions séquentielles (obtenu : {$count}) — régression potentielle du comportement de pruneKey() sous le nouveau verrou"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php');
        neria_assert(
            strpos($src, "GET_LOCK('") !== false && strpos($src, "RELEASE_LOCK('") !== false,
            "TranslationHistoryManager::record() n'utilise plus GET_LOCK/RELEASE_LOCK — régression du bug corrigé le 08/08/2026 (round 138) : la race condition sous appels concurrents pourrait réapparaître"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_translation_history WHERE template_key = '{$testTemplate}'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationHistoryManager::record() verrouille bien le cycle INSERT + pruneKey() via GET_LOCK, sans casser le comportement normal de rétention (50 entrées max)",
    ];
}
