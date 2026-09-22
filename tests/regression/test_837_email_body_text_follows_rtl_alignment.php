<?php
/**
 * Régression (constat réel du 22/09/2026, campagne de tests fonctionnels) : sur un vrai e-mail arabe complet
 * (private_sale), la signature s'alignait correctement à droite ({$neria_text_align} déjà câblé sur
 * .neria-signature), mais tout le corps du texte principal ({.neria-text}, sans aucune règle text-align)
 * restait aligné à gauche par défaut — incohérence visible constatée par l'utilisateur en conditions réelles.
 *
 * Corrigé : .neria-text, .neria-text-note, .neria-info-box et .neria-address-box suivent désormais tous
 * {$neria_text_align}, comme .neria-signature et .neria-products-table th le faisaient déjà.
 *
 * Test comportemental réel : compile private_sale en arabe (RTL) et en français (LTR), vérifie que TOUTES les
 * classes de texte du corps portent bien "text-align: right"/"text-align: left" selon la langue — pas
 * seulement la signature.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $module   = neria_test_module();
    $renderer = new EmailRenderer($module);

    $htmlAr = $renderer->renderPreviewHtml('private_sale', 'ar', []);
    $htmlFr = $renderer->renderPreviewHtml('private_sale', 'fr', []);

    $classes = ['.neria-text', '.neria-text-note', '.neria-info-box', '.neria-address-box', '.neria-signature'];

    foreach ($classes as $class) {
        neria_assert(
            preg_match('/' . preg_quote($class, '/') . '\s*\{[^}]*text-align:\s*right/s', $htmlAr) === 1,
            "{$class} n'est pas aligné à droite (text-align: right) dans le rendu arabe réel — régression du correctif du 22/09/2026"
        );
        neria_assert(
            preg_match('/' . preg_quote($class, '/') . '\s*\{[^}]*text-align:\s*left/s', $htmlFr) === 1,
            "{$class} n'est pas aligné à gauche (text-align: left) dans le rendu français réel — le correctif ne doit pas casser le sens de lecture normal des langues latines"
        );
    }

    return [
        'pass'    => true,
        'message' => "Les 5 classes de texte du corps d'un e-mail (pas seulement la signature) suivent bien le sens de lecture réel de la langue : droite en arabe, gauche en français",
    ];
}
