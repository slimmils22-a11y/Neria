<?php
/**
 * Bloc 5 (19/09/2026) : 83 templates HTML (210 lignes .txt) écrivent
 * "{neria_trad key='x'} :" — espace AVANT les deux-points, typographie
 * française. Rendu tel quel dans les 18 autres langues : « Piece : », « Serial
 * number : » (email de certificat reçu sur ps-test), « Seriennummer : »…
 *
 * Corrigé : EmailRenderer::localizeLabelColons() (appelé avant la résolution
 * des {neria_trad} aux 3 sites de compilation) retire l'espace hors français ;
 * ja/zh/tw reçoivent le deux-points pleine chasse « ： ».
 *
 * Test comportemental réel : le template certificate_email est COMPILÉ
 * (compileNeriaTemplate) en fr/en/de/ja ; le français garde « libellé : »,
 * les autres n'ont plus d'espace avant le deux-points, le japonais utilise
 * « ： ». Vérifie aussi la version .txt.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);
    $method   = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);

    $cases = ['fr' => 'fr', 'en' => 'en', 'de' => 'de', 'ja' => 'ja'];
    $created = [];
    try {
        foreach ($cases as $lang => $iso) {
            $outName = 'regtest795_colon_' . $lang;
            $vars = [
                '{firstname}' => 'Slim', '{time_greeting}' => 'Hello', '{product_name}' => 'Coussin',
                '{serial_number}' => 'CERT-1', '{customer_name}' => 'Slim Test',
            ];
            $res = $method->invoke($renderer, 'certificate_email', $lang, $iso, $vars, false, false, $outName);
            neria_assert($res !== null, "[{$lang}] compileNeriaTemplate() a échoué");
            $html = _PS_MODULE_DIR_ . 'neria/mails/' . $iso . '/' . $outName . '.html';
            $txt  = _PS_MODULE_DIR_ . 'neria/mails/' . $iso . '/' . $outName . '.txt';
            $created[] = $html;
            $created[] = $txt;
            neria_assert(file_exists($html), "[{$lang}] HTML compilé introuvable");
            $h = (string) file_get_contents($html);

            $spaced = preg_match('/<strong>[^<]*[ \x{00A0}]:<\/strong>/u', $h) === 1;
            if ($lang === 'fr') {
                neria_assert($spaced, "[fr] la typographie française « libellé : » doit être conservée");
            } else {
                neria_assert(!$spaced, "[{$lang}] espace résiduel avant les deux-points d'un libellé (typographie française hors français)");
            }
            if ($lang === 'ja') {
                neria_assert(strpos($h, "\u{FF1A}") !== false, "[ja] deux-points pleine chasse « ： » attendu");
            }
            if ($lang !== 'fr' && file_exists($txt)) {
                $t = (string) file_get_contents($txt);
                neria_assert(preg_match('/^[^\n]*\S[ \x{00A0}]:\s/mu', $t) === 0 || $lang === 'ja', "[{$lang}] version .txt : espace avant les deux-points");
            }
        }
    } finally {
        foreach ($created as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    // Sites d'appel : exactement 3 (HTML preview, HTML envoi, TXT envoi).
    $src = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php');
    neria_assert(substr_count($src, 'self::localizeLabelColons(') === 3, "localizeLabelColons() doit être appelé aux 3 sites de résolution des {neria_trad}");

    return ['pass' => true, 'message' => "ponctuation des libellés propre à chaque langue (fr « : », autres « : » collé, ja « ： ») — bloc 5 (19/09/2026)"];
}
