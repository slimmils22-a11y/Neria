<?php
/**
 * Régression : FontManager::generateFontCss() doit passer accentColor par
 * NeriaTools::sanitizeColor() avant de l'injecter dans le CSS de l'email.
 *
 * Bug réel corrigé le 06/08/2026 (round 68, piste identifiée le 05/08/2026
 * round 54) : la couleur était injectée telle quelle (color: {$accentColor}).
 * Non exploitable aujourd'hui via l'admin (ConfigManager::saveDesignConfig()
 * valide déjà ce format à l'écriture), mais sans second contrôle si la
 * valeur en base était altérée par un autre chemin (import direct, script
 * d'upgrade, accès DB).
 *
 * Ce test écrit directement en base une valeur malveillante (contournant
 * volontairement saveDesignConfig(), pour simuler ce chemin alternatif) et
 * vérifie que generateFontCss() ne la reproduit jamais telle quelle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/FontManager.php';

    $malicious = '#fff;}body{background:url(//evil.com/x)}/*';
    $original  = Configuration::get('NERIA_COLOR_ACCENT');

    try {
        Configuration::updateValue('NERIA_COLOR_ACCENT', $malicious);

        $mgr = new FontManager(neria_test_module());
        $css = $mgr->generateFontCss('fr');

        neria_assert($css !== '', "generateFontCss() n'a rien retourné — jeu de test invalide");

        neria_assert(
            strpos($css, $malicious) === false,
            "generateFontCss() a injecté la valeur brute non filtrée dans le CSS — régression du bug corrigé le 06/08/2026 (round 68) : sanitizeColor() n'est plus appelé sur accentColor"
        );
        neria_assert(
            strpos($css, 'evil.com') === false,
            "generateFontCss() a laissé passer une injection CSS (url externe) — sanitizeColor() n'est plus appelé sur accentColor"
        );
        neria_assert(
            (bool) preg_match('/color:\s*#[0-9a-fA-F]{6};/', $css),
            "generateFontCss() ne produit plus une couleur hexadécimale valide en repli — sanitizeColor() a peut-être changé de comportement"
        );

        return [
            'pass'    => true,
            'message' => "generateFontCss() filtre bien accentColor via sanitizeColor() avant injection CSS, même si la valeur en base est malveillante",
        ];
    } finally {
        Configuration::updateValue('NERIA_COLOR_ACCENT', $original);
    }
}
