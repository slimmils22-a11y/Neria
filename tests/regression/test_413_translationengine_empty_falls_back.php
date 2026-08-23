<?php
/**
 * Régression : TranslationEngine::get() utilisait isset() SEUL (4
 * occurrences) pour décider si une traduction en cache était valide —
 * isset() renvoie true même pour une chaîne vide en PHP, contrairement à
 * AdminTranslator::t()/tLang()/templateLabels() qui vérifient déjà
 * systématiquement !== ''. update() n'empêche pas d'enregistrer une valeur
 * vide (is_custom=1, translation_value='').
 *
 * Bug réel identifié le 23/08/2026 (round 194) : un marchand vidant
 * accidentellement une traduction personnalisée puis enregistrant voyait
 * get() retourner cette chaîne vide immédiatement — sautant le repli EN,
 * le repli _global, ET l'alerte Watchdog "clé introuvable" — le client
 * recevait un email avec une section vide, sans que personne ne soit
 * alerté.
 *
 * Corrigé le 23/08/2026 (round 194) : !== '' ajouté aux 4 vérifications.
 *
 * Test comportemental réel : enregistre une traduction personnalisée VIDE
 * en 'fr' pour une clé dont la valeur par défaut EN existe, puis vérifie
 * que get() retombe bien sur l'anglais (pas la chaîne vide).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $template = 'test_413_round194';
    $key      = 'greeting_test';

    $db->execute("DELETE FROM {$prefix}neria_translation WHERE template = '" . pSQL($template) . "'");

    try {
        // Valeur par défaut EN (is_custom=0) — le repli attendu.
        $db->execute(
            "INSERT INTO {$prefix}neria_translation
                (template, lang, translation_key, translation_value, is_custom, date_add, date_upd)
             VALUES ('" . pSQL($template) . "', 'en', '" . pSQL($key) . "', 'Hello there', 0, NOW(), NOW())"
        );
        // Traduction personnalisée FR vidée par erreur (is_custom=1, valeur vide).
        $db->execute(
            "INSERT INTO {$prefix}neria_translation
                (template, lang, translation_key, translation_value, is_custom, date_add, date_upd)
             VALUES ('" . pSQL($template) . "', 'fr', '" . pSQL($key) . "', '', 1, NOW(), NOW())"
        );

        $engine = new TranslationEngine($module);
        $result = $engine->get($template, $key, 'fr');

        neria_assert(
            $result === 'Hello there',
            "TranslationEngine::get() retourne '{$result}' au lieu du repli anglais 'Hello there' pour une traduction personnalisée FR vide — régression du bug corrigé le 23/08/2026 (round 194) : une valeur vide serait de nouveau traitée comme valide, sautant le repli EN et l'alerte Watchdog"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_translation WHERE template = '" . pSQL($template) . "'");
    }

    return [
        'pass'    => true,
        'message' => "TranslationEngine::get() retombe bien sur le repli anglais quand la traduction personnalisée est une chaîne vide — bug corrigé le 23/08/2026 (round 194)",
    ];
}
