<?php
/**
 * Bloc 5 (19/09/2026) : la page publique de traçabilité du certificat
 * (controllers/front/certificate.php + views/templates/front/certificate.tpl),
 * cible du QR code imprimé sur le certificat et donc scannée sur téléphone,
 * était servie comme un simple <section> brut : aucun <title> (onglet
 * affichant l'URL), aucun <html lang>, aucun viewport mobile, aucune balise
 * noindex. Trouvé en ouvrant le vrai lien sur ps-test.
 *
 * Corrigé : document HTML complet, comme preferences.tpl/unsubscribe.tpl.
 *
 * Test comportemental réel : le template est rendu par Smarty avec les
 * variables du contrôleur (cas « trouvé » et « introuvable ») ; le HTML
 * produit doit contenir DOCTYPE, <html lang>, viewport, noindex et un
 * <title> incluant le nom de la boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
    $ctx    = \Context::getContext();
    $smarty = $ctx->smarty;
    // Recompilation forcée : sans cela, un compilé en cache (mode PS sans
    // compile_check) masquerait un template modifié et le test resterait vert.
    $oldForce = $smarty->force_compile;
    $smarty->force_compile = true;
    if (class_exists('AdminTranslator')) {
        AdminTranslator::register($smarty);
    }
    $file = _PS_MODULE_DIR_ . 'neria/views/templates/front/certificate.tpl';
    neria_assert(is_file($file), "certificate.tpl introuvable");

    $base = [
        'neria_shop_name' => 'Ma Boutique Test',
        'neria_shop_url'  => 'https://example.test/',
        'neria_trace_dir' => 'ltr',
        'neria_trace_lang' => 'en',
        'neria_trace_serial' => 'CERT-2026-000001',
    ];
    $cases = [
        'trouvé' => $base + [
            'neria_trace_found' => true, 'neria_trace_product' => 'Coussin', 'neria_trace_artisan' => 'Fatima',
            'neria_trace_region' => 'Kabylie', 'neria_trace_duration' => '3 mois', 'neria_trace_note' => 'Note', 'neria_trace_date' => '09/19/2026',
        ],
        'introuvable' => $base + ['neria_trace_found' => false],
    ];

    try {
    foreach ($cases as $name => $vars) {
        $tpl = $smarty->createTemplate($file, $smarty);
        foreach ($vars as $k => $v) {
            $tpl->assign($k, $v);
        }
        $html = $tpl->fetch();
        neria_assert(stripos($html, '<!DOCTYPE html>') === 0 || stripos(ltrim($html), '<!DOCTYPE html>') === 0, "[{$name}] pas de DOCTYPE");
        neria_assert(preg_match('/<html lang="en"/i', $html) === 1, "[{$name}] <html lang> absent ou incorrect");
        neria_assert(strpos($html, 'name="viewport"') !== false, "[{$name}] viewport mobile absent");
        neria_assert(strpos($html, 'noindex') !== false, "[{$name}] balise noindex absente");
        neria_assert(preg_match('/<title>[^<]*Ma Boutique Test[^<]*<\/title>/u', $html) === 1, "[{$name}] <title> absent ou sans nom de boutique");
        neria_assert(substr_count(strtolower($html), '<html') === 1 && strpos($html, '</html>') !== false, "[{$name}] document mal formé");
    }

    } finally {
        $smarty->force_compile = $oldForce;
    }

    return ['pass' => true, 'message' => "la page publique du certificat est un document HTML complet (DOCTYPE, lang, viewport, noindex, title) — bloc 5 (19/09/2026)"];
}
