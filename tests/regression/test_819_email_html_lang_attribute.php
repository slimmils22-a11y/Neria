<?php
/**
 * Constat F-001 (campagne de tests fonctionnels, 20/09/2026) : la balise <html> des e-mails n'avait pas d'attribut lang.
 * Test comportemental : rend un vrai template (renderWithVars) dans plusieurs langues (chemin d'envoi réel) et vérifie que
 * <html lang="xx" xml:lang="xx" dir="…"> reprend la langue du destinataire, sans variable Smarty résiduelle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    $renderer = new EmailRenderer(neria_test_module());
    $checked = [];
    foreach (['fr' => 'ltr', 'en' => 'ltr', 'ar' => 'rtl', 'de' => 'ltr'] as $lang => $dir) {
        $html = $renderer->renderWithVars('reply_msg', $lang, ['firstname' => 'Test', 'reply' => 'x']);
        neria_assert(is_string($html) && $html !== '', "rendu reply_msg/{$lang} vide");
        neria_assert(preg_match('/<html\b[^>]*>/', $html, $tag) === 1, "balise <html> absente ({$lang})");
        neria_assert(strpos($tag[0], 'lang="' . $lang . '"') !== false, "lang=\"{$lang}\" absent : {$tag[0]}");
        neria_assert(strpos($tag[0], 'xml:lang="' . $lang . '"') !== false, "xml:lang=\"{$lang}\" absent : {$tag[0]}");
        neria_assert(strpos($tag[0], 'dir="' . $dir . '"') !== false, "dir=\"{$dir}\" absent : {$tag[0]}");
        neria_assert(strpos($tag[0], '{$') === false, "variable non résolue dans <html> ({$lang}) : {$tag[0]}");
        $checked[] = $lang;
    }
    return ['pass' => true, 'message' => 'la balise <html> porte lang/xml:lang/dir du destinataire (' . implode(', ', $checked) . ')'];
}
