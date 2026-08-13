<?php
/**
 * Régression : FontManager::generateCssVariables() doit passer les 4
 * couleurs design par NeriaTools::sanitizeColor() avant injection CSS —
 * cohérent avec accentColor dans generateFontCss() (même fichier).
 *
 * Bug réel corrigé le 08/08/2026 (round 129) : les 4 couleurs
 * (color_background/container/accent/text) étaient injectées brutes dans
 * le bloc <style>, sans défense en profondeur, contrairement à accentColor
 * traité juste au-dessus dans generateFontCss(). Pas de chemin
 * d'exploitation confirmé (valeurs déjà validées à l'écriture par
 * ConfigManager::saveDesignConfig()), mais incohérence de traitement entre
 * deux méthodes voisines manipulant la même config.
 *
 * Test comportemental réel : appelle generateCssVariables() et vérifie que
 * la sortie contient bien des couleurs au format hexadécimal valide.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    require_once _PS_MODULE_DIR_ . 'neria/src/FontManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/FontManager.php');
    neria_assert($src !== false, 'Impossible de lire src/FontManager.php');

    $posMethod = strpos($src, 'function generateCssVariables(');
    neria_assert($posMethod !== false, 'generateCssVariables() introuvable');
    $block = substr($src, $posMethod, 2600);

    // Round 159 : sanitizeColor() reçoit désormais un 2e argument explicite
    // (le vrai défaut de marque via ConfigManager::DEFAULTS), plutôt que de
    // retomber sur le défaut générique #000000 de sanitizeColor() — la
    // signature exacte a changé, mais l'appel à sanitizeColor() sur chaque
    // clé reste bien présent (c'est ce que ce test vérifie).
    foreach (['color_background', 'color_container', 'color_accent', 'color_text'] as $key) {
        neria_assert(
            strpos($block, "\\NeriaTools::sanitizeColor((string) \$design['{$key}']") !== false,
            "generateCssVariables() n'applique plus sanitizeColor() sur '{$key}' — régression du bug corrigé le 08/08/2026 (round 129)"
        );
    }

    $fm = new FontManager($module);
    $html = $fm->generateCssVariables('fr');
    neria_assert(
        (bool) preg_match('/--neria-color-accent:\s*#[0-9a-fA-F]{6};/', $html),
        "generateCssVariables() ne produit pas une couleur accent au format hexadécimal valide : {$html}"
    );

    return [
        'pass'    => true,
        'message' => "FontManager::generateCssVariables() passe toujours les 4 couleurs design par sanitizeColor() avant injection CSS",
    ];
}
