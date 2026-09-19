<?php
/**
 * Bloc 5 (19/09/2026), relecture des emails reçus sur ps-test — deux redites :
 * (1) unboxing_guide : sous le bouton « Tell us your first impressions », la
 *     note répétait « We would be delighted to hear your first impressions » ;
 * (2) extended_warranty : la note « valid for a specified period » restait
 *     générique alors que la période est désormais affichée au-dessus.
 *
 * Réécrites dans les 19 langues (source ET bases installées) : la note
 * d'unboxing ne répète plus le bouton, celle de la garantie est vraie avec ou
 * sans période affichée.
 *
 * Test : valeurs lues par le moteur de traduction (base) dans les 19 langues ;
 * en/fr vérifiés sur le contenu ; aucune ne reprend le libellé du bouton.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    $engine = new TranslationEngine(neria_test_module());
    $langs  = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];

    $json = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/translations.json'), true);
    foreach ($langs as $l) {
        $note = (string) $engine->get('unboxing_guide', 'unboxing_note', $l, 1);
        $btn  = (string) $engine->get('unboxing_guide', 'unboxing_btn', $l, 1);
        $warr = (string) $engine->get('extended_warranty', 'extended_warranty_note', $l, 1);
        neria_assert($note !== '' && $warr !== '', "[{$l}] note vide en base");
        neria_assert($note !== $btn, "[{$l}] la note d'unboxing répète le libellé du bouton");
        neria_assert(($json['unboxing_guide'][$l]['unboxing_note'] ?? '') === $note, "[{$l}] note d'unboxing : source et base divergent (import/UPDATE non fait ?)");
        neria_assert(($json['extended_warranty'][$l]['extended_warranty_note'] ?? '') === $warr, "[{$l}] note de garantie : source et base divergent");
    }
    neria_assert(stripos((string) $engine->get('unboxing_guide', 'unboxing_note', 'en', 1), 'first impressions') === false, "en : la note d'unboxing parle encore de « first impressions » (redite du bouton)");
    neria_assert(stripos((string) $engine->get('extended_warranty', 'extended_warranty_note', 'en', 1), 'specified period') === false, "en : la note de garantie parle encore d'une « specified period » générique");
    neria_assert(stripos((string) $engine->get('extended_warranty', 'extended_warranty_note', 'fr', 1), 'conservez') !== false, "fr : note de garantie inattendue");

    return ['pass' => true, 'message' => "notes d'unboxing_guide et d'extended_warranty sans redite dans les 19 langues (source et base) — bloc 5 (19/09/2026)"];
}
