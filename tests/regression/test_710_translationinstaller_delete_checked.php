<?php
/**
 * Régression : TranslationInstaller::clearDefaultTranslations()/
 * importTemplate() doivent capturer le retour de Db::delete() (succès de
 * la REQUÊTE SQL, pas "0 ligne supprimée") et traiter un échec réel comme
 * un échec d'import — pas seulement l'échec de flushBatch()/batch vide
 * déjà couverts (rounds 140/172).
 *
 * Bug identifié le 12/09/2026 (round 342, audit SeoApiManager/
 * TranslationInstaller/TranslationEngine) : le retour de Db::delete()
 * n'était jamais vérifié aux 2 sites d'appel (clearDefaultTranslations(),
 * importTemplate()). Un échec réel (verrou transitoire, timeout, connexion
 * perdue — pas une absence de ligne, qui reste un succès) laissait les
 * anciennes lignes is_custom=0 en place ; le flushBatch() suivant
 * (INSERT IGNORE, contrainte UNIQUE template+lang+translation_key)
 * ignorait alors silencieusement les nouvelles valeurs à cause du conflit
 * avec les anciennes non supprimées — une correction de translations.json
 * pouvait ainsi ne jamais être appliquée, sans aucune trace (COMMIT si
 * flushBatch() réussissait par ailleurs).
 *
 * Test comportemental réel sur le chemin NOMINAL (clearDefaultTranslations()
 * retourne bien true sur un DELETE qui réussit normalement, importFromJson()
 * fonctionne toujours) + test structurel sur le chemin d'ÉCHEC (forcer un
 * vrai échec de Db::delete() — pas juste "0 ligne" — nécessiterait de
 * casser la connexion DB ou verrouiller la table depuis une connexion
 * séparée pendant l'appel : trop invasif et non fiable en environnement de
 * test partagé, même contrainte que test_176/test_637 pour ce fichier).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php';

    // Volet structurel : clearDefaultTranslations() doit désormais renvoyer
    // bool (pas void) et son retour doit gater $failed dans importFromJson() ;
    // importTemplate() doit capturer $deleteOk et l'inclure dans $ok.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/TranslationInstaller.php');
    neria_assert($src !== false, 'Impossible de lire src/TranslationInstaller.php');

    neria_assert(
        strpos($src, 'private function clearDefaultTranslations(): bool') !== false,
        "clearDefaultTranslations() ne retourne plus bool — régression du correctif du 12/09/2026 (round 342)"
    );
    neria_assert(
        strpos($src, '$failed = !$this->clearDefaultTranslations();') !== false,
        "importFromJson() ne capture plus le retour de clearDefaultTranslations() — régression du correctif du 12/09/2026 (round 342) : un échec réel du DELETE ne serait plus détecté"
    );
    neria_assert(
        strpos($src, '$deleteOk = (bool) $this->db->delete(') !== false,
        "importTemplate() ne capture plus le retour du DELETE — régression du correctif du 12/09/2026 (round 342)"
    );
    neria_assert(
        strpos($src, '$ok = $deleteOk && !$batchWasEmpty && $this->flushBatch($batch);') !== false,
        "importTemplate() n'inclut plus \$deleteOk dans le calcul de \$ok — régression du correctif du 12/09/2026 (round 342) : un échec réel du DELETE ne bloquerait plus le COMMIT"
    );

    // Volet comportemental réel : le chemin nominal (DELETE qui réussit
    // normalement) doit rester intact — un import réel doit toujours
    // fonctionner après ce correctif.
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $testTemplate = 'neria_test_round342_delete_checked';

    $tmpJson = sys_get_temp_dir() . '/neria_test_round342_' . uniqid() . '.json';
    file_put_contents($tmpJson, json_encode([
        $testTemplate => [
            'fr' => ['greeting' => 'Bonjour round 342'],
        ],
    ]));

    try {
        $installer = new TranslationInstaller(neria_test_module());
        $result = $installer->importTemplate($tmpJson, $testTemplate);

        neria_assert(
            $result === true,
            "importTemplate() a échoué sur un import nominal (DELETE + réinsertion valides) après le correctif du 12/09/2026 (round 342) — comportement nominal cassé"
        );

        $stored = (string) $db->getValue(
            "SELECT translation_value FROM {$prefix}neria_translation
             WHERE template = '{$testTemplate}' AND lang = 'fr' AND translation_key = 'greeting'"
        );
        neria_assert(
            $stored === 'Bonjour round 342',
            "importTemplate() n'a pas persisté la valeur attendue en base après un import nominal — comportement nominal cassé"
        );
    } finally {
        @unlink($tmpJson);
        $db->execute("DELETE FROM {$prefix}neria_translation WHERE template = '{$testTemplate}'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationInstaller::clearDefaultTranslations()/importTemplate() capturent désormais le retour réel de Db::delete() (garde-fou structurel confirmé), comportement nominal d'import préservé (garde-fou comportemental confirmé)",
    ];
}
