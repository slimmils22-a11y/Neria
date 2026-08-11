<?php
/**
 * Régression : TranslationEngine::update() doit normaliser le code langue
 * (normalizeLang()) avant d'écrire, comme get()/getAll() le font en
 * lecture.
 *
 * Bug réel corrigé le 09/08/2026 (round 145) : update() écrivait $lang brut
 * tel que soumis, sans passer par normalizeLang(). Un code langue arrivant
 * en majuscules ou sous un alias PS (ex. 'pt-br', 'FR') écrivait une ligne
 * avec ce code non normalisé ; get()/getAll() normalisent TOUJOURS en
 * lecture, donc cette traduction personnalisée devenait introuvable au
 * rendu — invisible en pratique bien que réellement enregistrée en base.
 *
 * Test comportemental réel : appelle update() avec un code langue en
 * MAJUSCULES ('FR') et un alias PS ('pt-br'), vérifie que get() avec le
 * code normalisé ('fr', 'br') retrouve bien la valeur écrite.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $engine = new TranslationEngine(neria_test_module());

    $template = 'neria_test_round145';
    $key      = 'test_key';
    $value1   = 'Valeur test majuscules';
    $value2   = 'Valeur test alias PT-BR';

    try {
        $ok1 = $engine->update($template, 'FR', $key, $value1);
        neria_assert($ok1 === true, "update() avec lang='FR' a échoué — jeu de test invalide");

        $rowFr = $db->getRow(
            "SELECT lang, translation_value FROM {$prefix}neria_translation WHERE template = '{$template}' AND translation_key = '{$key}' AND translation_value = '" . pSQL($value1) . "'"
        );
        neria_assert(
            $rowFr !== false && $rowFr['lang'] === 'fr',
            "update() a écrit lang='" . ($rowFr['lang'] ?? 'ABSENT') . "' au lieu de 'fr' normalisé — régression du bug corrigé le 09/08/2026 (round 145)"
        );

        $readBack = $engine->get($template, $key, 'fr');
        neria_assert(
            $readBack === $value1,
            "get(\$template, \$key, 'fr') ne retrouve pas la valeur écrite via update(\$template, 'FR', ...) (obtenu : '{$readBack}') — régression du bug corrigé le 09/08/2026 (round 145) : la traduction personnalisée redeviendrait invisible au rendu"
        );

        // Alias PS 'pt-br' → code Neria 'br'
        $ok2 = $engine->update($template, 'pt-br', $key, $value2);
        neria_assert($ok2 === true, "update() avec lang='pt-br' a échoué — jeu de test invalide");

        $rowBr = $db->getRow(
            "SELECT lang FROM {$prefix}neria_translation WHERE template = '{$template}' AND translation_key = '{$key}' AND translation_value = '" . pSQL($value2) . "'"
        );
        neria_assert(
            $rowBr !== false && $rowBr['lang'] === 'br',
            "update() a écrit lang='" . ($rowBr['lang'] ?? 'ABSENT') . "' au lieu de 'br' (alias 'pt-br' normalisé) — régression du bug corrigé le 09/08/2026 (round 145)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_translation WHERE template = '{$template}'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationEngine::update() normalise bien le code langue avant écriture, cohérent avec get()/getAll() en lecture",
    ];
}
