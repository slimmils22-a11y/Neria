<?php
/**
 * Bloc 5 (19/09/2026), relecture de l'email unboxing_guide reçu sur ps-test :
 * (1) le bouton « Share the emotion » pointait vers l'accueil ({shop_url})
 *     alors que la note voisine demande les premières impressions du client
 *     → il pointe désormais vers la page contact, via {contact_page_url}
 *     (nouvelle variable injectée aux envois réels ; {contact_url} n'existe
 *     qu'en saisie manuelle) ;
 * (2) la signature anglaise « Waiting for this piece to be delivered to you »
 *     (calque du français) devient « Until your piece is in your hands ».
 *
 * Test comportemental réel : le template est COMPILÉ (compileNeriaTemplate)
 * sans fournir {contact_page_url} — c'est EmailRenderer qui doit la résoudre —
 * et le HTML/TXT obtenus contiennent le vrai lien contact et la nouvelle
 * signature anglaise.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';

    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);
    $method   = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
    $method->setAccessible(true);

    $outName = 'regtest796_unboxing_cta';
    $files   = [
        _PS_MODULE_DIR_ . 'neria/mails/en/' . $outName . '.html',
        _PS_MODULE_DIR_ . 'neria/mails/en/' . $outName . '.txt',
    ];
    try {
        $vars = ['{firstname}' => 'Slim', '{time_greeting}' => 'Hello'];
        $res  = $method->invoke($renderer, 'unboxing_guide', 'en', 'en', $vars, false, false, $outName);
        neria_assert($res !== null && is_file($files[0]), "compileNeriaTemplate() a échoué");

        $html = (string) file_get_contents($files[0]);
        $ctx  = \Context::getContext();
        $contactUrl = $ctx->link->getPageLink('contact', true, (int) $ctx->language->id);
        neria_assert(strpos($contactUrl, 'contact') !== false, "URL contact PrestaShop inattendue : {$contactUrl}");

        neria_assert(
            preg_match('/<a href="([^"]+)"[^>]*class="neria-btn"[^>]*>/', $html, $m) === 1,
            "bouton .neria-btn introuvable dans le HTML compilé"
        );
        $href = html_entity_decode($m[1]);
        neria_assert(strpos($href, '{contact_page_url}') === false, "{contact_page_url} non résolu dans le HTML compilé (lien mort)");
        neria_assert(strpos($href, 'contact') !== false, "le bouton ne pointe pas vers la page contact : {$href}");
        neria_assert(strpos($html, 'Until your piece is in your hands, with all our gratitude') !== false, "signature anglaise mise à jour absente");
        neria_assert(strpos($html, 'Waiting for this piece to be delivered') === false, "ancienne signature anglaise réapparue");

        if (is_file($files[1])) {
            $txt = (string) file_get_contents($files[1]);
            neria_assert(strpos($txt, '{contact_page_url}') === false, "{contact_page_url} non résolu dans le TXT compilé");
        }
    } finally {
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    return ['pass' => true, 'message' => "unboxing_guide : bouton vers la page contact (variable résolue à l'envoi) et signature anglaise naturelle — bloc 5 (19/09/2026)"];
}
