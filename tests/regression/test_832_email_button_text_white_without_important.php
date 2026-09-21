<?php
/**
 * Texte des boutons d'e-mail (21/09/2026) : Outlook ignore un « color: … !important » posé sur le lien et applique la couleur des liens
 * (dorée) — texte du bouton doré au lieu de blanc. Le bouton porte donc « color: #ffffff » SANS !important. Test comportemental sur le
 * rendu réel : tous les boutons de plusieurs modèles, plusieurs langues.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);
    $found    = 0;
    foreach (['private_sale', 'unboxing_guide', 'vip', 'early_access'] as $tpl) {
        foreach (['en', 'fr', 'ar'] as $lang) {
            $html = $renderer->renderPreviewHtml($tpl, $lang, []);
            preg_match_all('/<a [^>]*class="neria-btn"[^>]*>/i', $html, $m);
            neria_assert(count($m[0]) >= 1, "{$tpl}/{$lang} : aucun bouton dans le rendu");
            foreach ($m[0] as $tag) {
                ++$found;
                neria_assert(preg_match('/style="[^"]*color: ?#ffffff(?![^;"]*!important)/i', $tag) === 1, "{$tpl}/{$lang} : texte du bouton non blanc : {$tag}");
                neria_assert(stripos($tag, '!important') === false, "{$tpl}/{$lang} : !important sur le bouton (ignoré par Outlook) : {$tag}");
            }
        }
    }
    neria_assert($found >= 12, 'trop peu de boutons contrôlés : ' . $found);
    return ['pass' => true, 'message' => "{$found} boutons rendus : texte blanc, sans !important"];
}
