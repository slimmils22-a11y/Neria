<?php
/**
 * Régression : TranslationInstaller::importTemplate() doit remettre à
 * zéro countInserted/countSkipped/countErrors en début d'appel, comme
 * reloadDefaultTranslations() le fait déjà avant importFromJson().
 *
 * Bug réel corrigé le 09/08/2026 (round 140) : contrairement à
 * reloadDefaultTranslations(), importTemplate() ne réinitialisait jamais
 * les compteurs — si la même instance était réutilisée pour réinitialiser
 * plusieurs templates successivement (ex. boucle BO), getSummary()
 * additionnait les résultats de tous les appels précédents au lieu de
 * refléter uniquement le dernier import.
 *
 * Test comportemental réel : pré-positionne des compteurs non nuls sur
 * l'instance (simulant un appel précédent), invoque importTemplate() avec
 * un template valide à une seule clé, vérifie que countInserted reflète
 * UNIQUEMENT ce second appel (1), pas la somme avec la valeur précédente.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $testTemplate = 'neria_test_round140_counters';

    $installer = new TranslationInstaller(neria_test_module());

    $refInserted = new ReflectionProperty(TranslationInstaller::class, 'countInserted');
    $refInserted->setAccessible(true);
    $refInserted->setValue($installer, 999);

    $tmpJson = sys_get_temp_dir() . '/neria_test_round140_' . uniqid() . '.json';
    file_put_contents($tmpJson, json_encode([
        $testTemplate => [
            'fr' => ['single_key' => 'valeur unique'],
        ],
    ]));

    try {
        $result = $installer->importTemplate($tmpJson, $testTemplate);
        neria_assert($result === true, "importTemplate() a échoué sur un jeu de données valide — jeu de test invalide");

        neria_assert(
            $refInserted->getValue($installer) === 1,
            "countInserted vaut " . $refInserted->getValue($installer) . " au lieu de 1 après importTemplate() — régression du bug corrigé le 09/08/2026 (round 140) : les compteurs d'un appel précédent (999) ne seraient plus réinitialisés, produisant un rapport cumulatif trompeur"
        );
    } finally {
        @unlink($tmpJson);
        $db->execute("DELETE FROM {$prefix}neria_translation WHERE template = '{$testTemplate}'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationInstaller::importTemplate() remet bien à zéro les compteurs en début d'appel, évitant un rapport cumulatif trompeur si l'instance est réutilisée",
    ];
}
