<?php
/**
 * Régression : TranslationInstaller::importTemplate() ne doit plus
 * valider (COMMIT) la suppression des traductions par défaut d'un
 * template si le batch de réinsertion est vide.
 *
 * Bug réel corrigé le 09/08/2026 (round 140) : si tous les blocs langue
 * du template étaient malformés (scalaire au lieu de tableau) ou toutes
 * leurs valeurs non-string, $batch restait vide, $ok valait true par
 * défaut (`!empty($batch) ? ... : true`), et le DELETE était validé SANS
 * réinsertion — le template perdait définitivement ses traductions par
 * défaut, silencieusement, sans aucune trace Watchdog.
 *
 * Test comportemental réel : pose une vraie traduction par défaut en
 * base pour un template de test, appelle importTemplate() avec un JSON
 * dont ce template ne contient QUE des données malformées, vérifie que
 * la traduction d'origine est TOUJOURS en base après l'appel (ROLLBACK
 * effectif), et que la méthode retourne false.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $testTemplate = 'neria_test_round140_empty_batch';

    // Pose une traduction par défaut existante pour ce template — c'est
    // elle qui ne doit PAS disparaître si l'import échoue.
    $db->execute(
        "INSERT INTO {$prefix}neria_translation
            (template, lang, translation_key, translation_value, is_custom, date_add, date_upd)
         VALUES ('{$testTemplate}', 'fr', 'existing_key', 'valeur existante', 0, NOW(), NOW())"
    );

    $tmpJson = sys_get_temp_dir() . '/neria_test_round140_' . uniqid() . '.json';
    file_put_contents($tmpJson, json_encode([
        $testTemplate => [
            'fr' => 'ceci_est_un_scalaire_pas_un_tableau_de_cles',
        ],
    ]));

    try {
        $installer = new TranslationInstaller(neria_test_module());
        $result = $installer->importTemplate($tmpJson, $testTemplate);

        neria_assert(
            $result === false,
            "importTemplate() a retourné true alors que le batch de réinsertion était vide (données source entièrement malformées) — régression du bug corrigé le 09/08/2026 (round 140)"
        );

        $stillThere = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_translation
             WHERE template = '{$testTemplate}' AND translation_key = 'existing_key'"
        );
        neria_assert(
            $stillThere === 1,
            "La traduction par défaut existante a été supprimée sans réinsertion — régression du bug corrigé le 09/08/2026 (round 140) : importTemplate() a de nouveau validé un DELETE sans réinsertion effective (batch vide traité comme un succès)"
        );
    } finally {
        @unlink($tmpJson);
        $db->execute("DELETE FROM {$prefix}neria_translation WHERE template = '{$testTemplate}'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationInstaller::importTemplate() ne valide plus la suppression des traductions par défaut quand le batch de réinsertion est vide — ROLLBACK effectif, traductions existantes préservées",
    ];
}
