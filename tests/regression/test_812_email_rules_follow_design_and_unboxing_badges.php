<?php
/**
 * Bloc 7 (19/09/2026) — mail unboxing_guide reçu sur Gmail :
 *  1. Les numéros ①②③ (glyphes Unicode) s'affichaient en petits caractères d'une police de
 *     secours, différents selon le client de messagerie. Remplacés par des pastilles
 *     numérotées en CSS inline (table + span arrondi, couleur d'accent du thème).
 *  2. Le réglage « Séparateur » de l'onglet Design (aucun / trait / pointillés / double) ne
 *     pilotait que <hr class="neria-rule"> : les filets de la signature et du pied de page
 *     étaient écrits en dur (« Aucun » laissait des lignes), et la bordure basse de la
 *     signature doublait celle du pied de page (deux filets à ~20 px d'écart).
 *
 * Test comportemental : rendu RÉEL (compileNeriaTemplate) avec le réglage sur « none » puis
 * « line » ; le réglage d'origine est restauré.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'ConfigManager', 'TranslationEngine', 'EmailRenderer'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }
    $module   = neria_test_module();
    $cfg      = new ConfigManager($module);
    $method   = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);

    neria_assert(ConfigManager::getSeparatorLineWidth('none') === '0', "getSeparatorLineWidth('none') doit valoir 0");
    foreach (['line', 'dotted', 'double'] as $s) {
        neria_assert(ConfigManager::getSeparatorLineWidth($s) === '1px', "getSeparatorLineWidth('{$s}') doit valoir 1px");
    }

    $origStyle = $cfg->get(ConfigManager::KEY_SEPARATOR_STYLE);
    $files = [];
    $render = function (string $style) use ($cfg, $module, $method, &$files): string {
        $cfg->set(ConfigManager::KEY_SEPARATOR_STYLE, $style);
        $renderer = new EmailRenderer($module); // neuf : le design est mis en cache par instance/processus
        $out = 'regtest812_' . $style;
        $res = $method->invoke($renderer, 'unboxing_guide', 'en', 'en', ['{firstname}' => 'Slim', '{time_greeting}' => 'Hello', '{contact_page_url}' => 'https://example.com/contact'], false, false, $out);
        neria_assert($res !== null, "compilation unboxing_guide ({$style}) échouée");
        $h = _PS_MODULE_DIR_ . 'neria/mails/en/' . $out . '.html';
        $files[] = $h;
        $files[] = _PS_MODULE_DIR_ . 'neria/mails/en/' . $out . '.txt';
        return (string) file_get_contents($h);
    };
    try {
        $none = $render('none');
        $line = $render('line');
    } finally {
        $cfg->set(ConfigManager::KEY_SEPARATOR_STYLE, $origStyle);
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    // 1) Pastilles numérotées : plus de glyphes Unicode, trois pastilles 1/2/3 et leurs textes.
    foreach (['none' => $none, 'line' => $line] as $style => $html) {
        foreach (['①', '②', '③'] as $g) {
            neria_assert(strpos($html, $g) === false, "[{$style}] glyphe {$g} encore présent (rendu variable selon le client de messagerie)");
        }
        neria_assert(substr_count($html, 'border-radius:50%') === 3, "[{$style}] les 3 pastilles numérotées sont absentes (" . substr_count($html, 'border-radius:50%') . ")");
        neria_assert(preg_match('/>1<\/span>.*>2<\/span>.*>3<\/span>/s', $html) === 1, "[{$style}] numéros 1/2/3 absents ou dans le désordre");
        neria_assert(strpos($html, 'Prepare a clean, soft space') !== false, "[{$style}] texte de l'étape 1 absent");
        neria_assert(strpos($html, '{$') === false && strpos($html, '{neria_') === false, "[{$style}] résidu de syntaxe de template");
        neria_assert(strpos($html, 'border:1px solid #') !== false, "[{$style}] couleur d'accent non résolue dans les pastilles");
    }

    // 2) Filets structurels pilotés par le réglage Design.
    neria_assert(strpos($none, 'border-top: 0 solid #f0e7db') !== false, "réglage « aucun » : les filets de la signature/du pied de page ne sont pas supprimés");
    neria_assert(strpos($none, 'border-top: 1px solid #f0e7db') === false, "réglage « aucun » : un filet de 1px subsiste");
    neria_assert(strpos($line, 'border-top: 1px solid #f0e7db') !== false, "réglage « trait » : les filets de la signature et du pied de page ne valent pas 1px");
    neria_assert(strpos($line, 'border-top: 0 solid #f0e7db') === false, "réglage « trait » : un filet est resté à 0 (cache du design entre deux rendus ?)");
    // Le pied de page n'a plus de bordure haute propre : le séparateur du Design (<hr class="neria-rule">, en retrait)
    // le précède déjà — les deux ensemble faisaient un double filet à ~20 px d'écart (constaté sur Gmail).
    preg_match('/\.neria-footer\s*\{((?:[^{}]|\{\$[^}]*\})*)\}/', $line, $foot);
    neria_assert(isset($foot[1]) && strpos($foot[1], 'border-top') === false, "le pied de page a encore une bordure haute (double filet avec le séparateur du Design)");
    neria_assert(strpos($line, '<hr class="neria-rule"') !== false, "le séparateur du Design (hr.neria-rule) est absent du rendu");
    // Le trait d'accent sous le logo (en-tête) est aussi un filet : il suit le réglage, et reste présent en « trait ».
    preg_match('/\.neria-header\s*\{(?:[^{}])*\}/', $line, $hLine);
    preg_match('/\.neria-header\s*\{(?:[^{}])*\}/', $none, $hNone);
    neria_assert(isset($hLine[0]) && strpos($hLine[0], 'border-bottom: 1px solid') !== false, "réglage « trait » : le trait d'accent sous le logo (en-tête) a disparu : " . ($hLine[0] ?? 'bloc introuvable'));
    neria_assert(isset($hNone[0]) && strpos($hNone[0], 'border-bottom: 0 solid') !== false && strpos($hNone[0], 'border-bottom: 1px') === false, "réglage « aucun » : le trait sous le logo subsiste : " . ($hNone[0] ?? 'bloc introuvable'));

    return ['pass' => true, 'message' => "pastilles numérotées à la place de ①②③, filets de signature/pied de page pilotés par le réglage Design (aucun = aucun filet), double filet supprimé — bloc 7 (19/09/2026)"];
}
