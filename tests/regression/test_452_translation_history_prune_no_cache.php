<?php
/**
 * Régression : TranslationHistoryManager::pruneKey() lisait le "Top
 * MAX_PER_KEY conservé" sans $use_cache=false, alors qu'elle est appelée
 * juste après un INSERT (dans record()). Même famille de bug systémique
 * que les rounds 210-215, mais avec un effet particulièrement pervers :
 * sous cache SQL périmé, la ligne fraîchement insérée pouvait être
 * exclue du Top conservé, puis immédiatement supprimée par le DELETE qui
 * suit — perte silencieuse de l'entrée d'historique la plus récente.
 *
 * Corrigé le 26/08/2026 (round 216) : $use_cache=false explicite.
 *
 * Test comportemental réel : deux modifications successives de la même
 * clé de traduction (via record()) doivent toutes deux être présentes
 * dans l'historique après coup — confirme que pruneKey() ne supprime pas
 * à tort la ligne la plus récente.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $template = 'round216_test_tpl';
    $lang     = 'fr';
    $key      = 'subject';

    $db->execute(
        "DELETE FROM {$prefix}neria_translation_history
         WHERE template_key = '" . pSQL($template) . "' AND lang_code = '" . pSQL($lang) . "' AND translation_key = '" . pSQL($key) . "'"
    );

    try {
        $mgr = new TranslationHistoryManager(neria_test_module());

        $mgr->record($template, $lang, $key, 'Ancien texte A', 'Nouveau texte B', 'Test Round216');
        $mgr->record($template, $lang, $key, 'Nouveau texte B', 'Nouveau texte C', 'Test Round216');

        $rows = $db->executeS(
            "SELECT new_value FROM {$prefix}neria_translation_history
             WHERE template_key = '" . pSQL($template) . "' AND lang_code = '" . pSQL($lang) . "' AND translation_key = '" . pSQL($key) . "'
             ORDER BY id_history ASC",
            true,
            false
        );

        neria_assert(is_array($rows) && count($rows) === 2, 'jeu de test invalide : les 2 record() auraient dû insérer 2 lignes, en trouve ' . (is_array($rows) ? count($rows) : 0));
        neria_assert(
            $rows[1]['new_value'] === 'Nouveau texte C',
            "L'entrée d'historique la plus récente ('Nouveau texte C') a été supprimée par pruneKey() — régression du bug corrigé le 26/08/2026 (round 216) : perte silencieuse de l'entrée qu'on cherche justement à conserver"
        );
    } finally {
        $db->execute(
            "DELETE FROM {$prefix}neria_translation_history
             WHERE template_key = '" . pSQL($template) . "' AND lang_code = '" . pSQL($lang) . "' AND translation_key = '" . pSQL($key) . "'"
        );
    }

    return [
        'pass'    => true,
        'message' => "TranslationHistoryManager::pruneKey() conserve bien la ligne fraîchement insérée après l'ajout de \$use_cache=false — bug corrigé le 26/08/2026 (round 216)",
    ];
}
