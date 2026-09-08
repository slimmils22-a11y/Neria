<?php
/**
 * Régression : TranslationInstaller::importFromJson() vérifiait que la
 * racine du JSON décodé était bien un tableau (!is_array($translations)),
 * mais ne détectait PAS le cas où ce tableau est VIDE ([]) — is_array([])
 * vaut true, donc ce cas passait le garde-fou existant.
 *
 * Un fichier translations.json vidé par erreur (déploiement interrompu,
 * réponse d'erreur d'un CDN livrée à la place du vrai fichier — le
 * scénario même que le commentaire round 172 du fichier redoutait déjà,
 * mais que la garde !is_array() seule ne couvrait pas) laisse passer un
 * tableau [] syntaxiquement valide : le foreach ne s'exécute jamais,
 * $batch reste vide, $failed reste false — la transaction est COMMIT,
 * effaçant silencieusement TOUT le dictionnaire de traductions par défaut
 * (clearDefaultTranslations() déjà exécuté avant le foreach) sans jamais
 * rien réinsérer. L'appelant BO ("Réinitialiser les textes") voit
 * "0 traductions importées — 0 erreurs" et importFromJson() retourne true
 * (succès).
 *
 * Corrigé le 08/09/2026 (round 323) : !empty($translations) ajouté au
 * garde-fou existant.
 *
 * Test comportemental réel : pose une vraie traduction par défaut en
 * base, appelle importFromJson() avec un fichier JSON dont la racine est
 * un tableau VIDE, vérifie que la traduction d'origine est TOUJOURS en
 * base après l'appel (transaction jamais COMMIT car return false avant
 * même START TRANSACTION), et que la méthode retourne false.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $testTemplate = 'neria_test_round323_empty_json';

    $db->execute(
        "INSERT INTO {$prefix}neria_translation
            (template, lang, translation_key, translation_value, is_custom, date_add, date_upd)
         VALUES ('{$testTemplate}', 'fr', 'existing_key', 'valeur existante', 0, NOW(), NOW())"
    );

    $tmpJson = sys_get_temp_dir() . '/neria_test_round323_' . uniqid() . '.json';
    file_put_contents($tmpJson, '[]');

    try {
        $installer = new TranslationInstaller(neria_test_module());
        $result = $installer->importFromJson($tmpJson);

        neria_assert(
            $result === false,
            "importFromJson() a retourné true pour un JSON racine [] (tableau vide) — régression du bug corrigé le 08/09/2026 (round 323) : is_array([]) vaut true, ce cas passait à tort le garde-fou existant (!is_array())"
        );

        $stillThere = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_translation
             WHERE template = '{$testTemplate}' AND translation_key = 'existing_key'"
        );
        neria_assert(
            $stillThere === 1,
            "La traduction par défaut existante a disparu après un import avec JSON racine vide — régression du bug corrigé le 08/09/2026 (round 323) : tout le dictionnaire de traductions par défaut serait de nouveau effacé sans réinsertion pour un translations.json vidé/tronqué"
        );
    } finally {
        @unlink($tmpJson);
        $db->execute("DELETE FROM {$prefix}neria_translation WHERE template = '{$testTemplate}'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationInstaller::importFromJson() rejette bien un JSON racine vide ([]), sans effacer le dictionnaire de traductions par défaut — bug corrigé le 08/09/2026 (round 323)",
    ];
}
