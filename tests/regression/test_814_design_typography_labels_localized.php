<?php
/**
 * Bloc 7 (19/09/2026) — onglets Design et Typographie ouverts en anglais sur ps-test :
 *  - descriptions des 6 préréglages (« Élégance extrême, doré discret… »), sous-titres des 8
 *    polices de titre (« Cormorant Garamond — Élégance classique »), descriptions des 16 polices
 *    (« Serif coréenne — formelle et élégante ») et libellés des scripts (« Arabe », « Coréen »)
 *    étaient écrits en français dans un BO de 19 langues.
 * Corrigé : clés design.preset.<style>.tagline, font.heading.<police>, font.desc.<police>,
 * font.script.<script> (37 clés × 19 langues) ; le français d'origine reste le repli.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'ConfigManager', 'FontManager'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }
    $dir   = _PS_MODULE_DIR_ . 'neria/';
    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];
    $dict  = json_decode((string) file_get_contents($dir . 'data/admin_translations.json'), true);
    $slug  = static fn (string $n): string => strtolower(str_replace(' ', '_', $n));

    // 1) Couverture : chaque préréglage, police de titre, police du catalogue et script a sa clé × 19 langues.
    $keys = [];
    foreach (array_keys(ConfigManager::DESIGN_PRESETS) as $p) {
        $keys[] = "design.preset.{$p}.tagline";
    }
    foreach (array_keys(ConfigManager::HEADING_FONT_OPTIONS) as $f) {
        $keys[] = 'font.heading.' . $slug($f);
    }
    foreach (array_keys(FontManager::FONT_CATALOG) as $f) {
        $keys[] = 'font.desc.' . $slug($f);
    }
    foreach (['latin', 'arabic', 'japanese', 'korean', 'chinese_simplified', 'chinese_traditional', 'cyrillic'] as $s) {
        $keys[] = "font.script.{$s}";
    }
    neria_assert(count($keys) === 6 + 8 + 16 + 7, "nombre de clés attendues inattendu (" . count($keys) . ") — un préréglage/une police a été ajouté sans traduction ?");
    foreach ($keys as $k) {
        neria_assert(isset($dict[$k]), "clé {$k} absente du dictionnaire");
        foreach ($langs as $l) {
            neria_assert(trim((string) ($dict[$k][$l] ?? '')) !== '', "clé {$k} vide pour '{$l}'");
        }
    }

    // 2) FontManager : descriptions et scripts suivent la langue du BO ; le français reste celui d'origine.
    $orig = AdminTranslator::currentLang();
    try {
        $fm = new FontManager(neria_test_module());
        AdminTranslator::setLang('en');
        $ko = $fm->getFontsForScript('korean');
        neria_assert($ko['Noto Serif KR']['description'] === 'Korean serif — formal and elegant', "description EN inattendue : " . $ko['Noto Serif KR']['description']);
        neria_assert($fm->getAllScripts()['korean']['label'] === 'Korean', "libellé de script EN inattendu : " . $fm->getAllScripts()['korean']['label']);
        AdminTranslator::setLang('ja');
        neria_assert(preg_match('/[\p{Hiragana}\p{Katakana}\p{Han}]/u', $fm->getFontsForScript('latin')['Cormorant Garamond']['description']) === 1, "description JA non traduite");
        AdminTranslator::setLang('fr');
        neria_assert($fm->getFontsForScript('korean')['Noto Serif KR']['description'] === 'Serif coréenne — formelle et élégante', "le français d'origine a changé");
        neria_assert($fm->getAllScripts()['korean']['label'] === 'Coréen', "libellé FR de script changé");

        // 3) Rendu Smarty réel des deux expressions à clé interpolée du template Design.
        $smarty = Context::getContext()->smarty;
        AdminTranslator::register($smarty);
        AdminTranslator::setLang('en');
        $tplFragment = '{foreach [\'Cormorant Garamond\',\'Josefin Sans\'] as $fkey}{assign var="fslug" value=$fkey|lower|replace:\' \':\'_\'}[{$fkey} — {neria_admin key="font.heading.`$fslug`"}]{/foreach}'
            . '{assign var="presetKey" value="haute_joaillerie"}<{neria_admin key="design.preset.`$presetKey`.tagline"}>';
        $html = $smarty->fetch('string:' . $tplFragment);
        neria_assert(strpos($html, '[Cormorant Garamond — Classic elegance]') !== false && strpos($html, '[Josefin Sans — Chic minimalism]') !== false, "rendu des options de police incorrect : {$html}");
        neria_assert(strpos($html, '<Extreme elegance, subtle gold, regal spacing>') !== false, "rendu du tagline de préréglage incorrect : {$html}");
        neria_assert(preg_match('/Élégance|Sobriété|Éditorial/u', $html) !== 1, "français résiduel : {$html}");
    } finally {
        AdminTranslator::setLang($orig);
    }

    // 4) Le template Design n'a plus les libellés français en dur.
    $tpl = (string) file_get_contents($dir . 'views/templates/admin/design.tpl');
    foreach (['Élégance classique', 'Éditorial luxe', 'Intemporel lettres', 'Chaleur contemporaine', 'Prestige romain', 'Minimalisme chic', 'Sophistiqué moderne'] as $fr) {
        neria_assert(strpos($tpl, $fr) === false, "design.tpl contient encore « {$fr} » en dur");
    }
    neria_assert(strpos($tpl, 'design.preset.`$presetKey`.tagline') !== false && strpos($tpl, 'font.heading.`$fslug`') !== false, "design.tpl n'utilise plus les clés traduites");

    return ['pass' => true, 'message' => "taglines des préréglages, polices de titre, descriptions de polices et scripts traduits (37 clés × 19 langues), rendu Smarty vérifié — bloc 7 (19/09/2026)"];
}
