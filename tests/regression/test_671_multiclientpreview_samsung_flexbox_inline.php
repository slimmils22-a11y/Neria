<?php
/**
 * Régression : `MultiClientPreviewManager::transformSamsungEmail()`
 * neutralisait `display:flex` UNIQUEMENT dans les blocs `<style>` — jamais
 * dans les attributs `style="..."` inline, contrairement à
 * `transformOutlook()` (même neutralisation flexbox pour le même client
 * Android) qui appelle bien `replaceInInlineStyles()` EN PLUS du
 * traitement `<style>`.
 *
 * Or `CssInliner::inline()` tourne systématiquement avant l'envoi d'un
 * email Neria — une règle CSS `display:flex` finit donc très
 * majoritairement `style="display:flex"` INLINE sur l'élément, pas dans un
 * `<style>` résiduel. L'aperçu "Samsung Email" laissait alors le flexbox
 * intact malgré l'annonce explicite "flexbox ignoré" faite au marchand.
 *
 * Bug identifié le 10/09/2026 (round 332, audit MultiClientPreviewManager/
 * CssInliner).
 *
 * Corrigé le 10/09/2026 (round 332) : `replaceInInlineStyles()` appelé en
 * plus du traitement `<style>`, même pattern que `transformOutlook()`.
 *
 * Test comportemental réel : un HTML avec `display:flex` À LA FOIS inline
 * (`style="..."`, cas réel post-inlining) et dans un bloc `<style>`
 * résiduel, transformé pour le client `samsung_email` — vérifie que les
 * DEUX occurrences sont neutralisées en `display:block`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MultiClientPreviewManager.php';

    $html = '<html><head><style>.card{display:flex;color:red;}</style></head>'
        . '<body><div style="display:flex;gap:8px;">Contenu inline flexbox</div></body></html>';

    $mgr = new MultiClientPreviewManager();
    $result = $mgr->transformForClient($html, 'samsung_email');

    neria_assert(
        strpos($result, 'style="display:block;gap:8px;"') !== false || strpos($result, 'style="display:block;') !== false,
        "transformForClient(\$html, 'samsung_email') ne neutralise plus display:flex dans l'attribut style=\"...\" INLINE — régression du bug corrigé le 10/09/2026 (round 332) : le flexbox resterait affiché tel quel dans l'aperçu Samsung Email malgré l'annonce 'flexbox ignoré'. HTML obtenu : {$result}"
    );
    neria_assert(
        strpos($result, 'display:flex') === false,
        "transformForClient(\$html, 'samsung_email') laisse encore 'display:flex' quelque part dans le résultat (inline ou <style>) — régression du bug corrigé le 10/09/2026 (round 332)"
    );
    neria_assert(
        strpos($result, '.card{display:block;color:red;}') !== false,
        "transformForClient(\$html, 'samsung_email') ne neutralise plus display:flex dans le bloc <style> — régression du comportement d'origine (préexistant à ce correctif)"
    );

    return [
        'pass'    => true,
        'message' => "MultiClientPreviewManager::transformSamsungEmail() neutralise désormais display:flex à la fois dans les attributs style=\"...\" inline ET dans les blocs <style> — bug corrigé le 10/09/2026 (round 332)",
    ];
}
