<?php
/**
 * Régression : ConfigManager::saveTypographyConfig() validait chaque
 * police soumise contre TOUT FontManager::FONT_CATALOG (tous scripts
 * confondus), pas contre les seules polices du script correspondant à la
 * clé POST (ex. font_arabic devrait n'accepter que les polices
 * script==='arabic'). Un appel POST direct (contournant typography.tpl,
 * qui filtre déjà correctement via getFontsForScript($script)) pouvait
 * ainsi assigner une police d'un script totalement différent — la valeur
 * passait la whitelist globale, puis FontManager::getFontNameForLang() la
 * retrouvait telle quelle dans le catalogue et l'injectait dans le CSS des
 * emails de ce script, sans aucun avertissement.
 *
 * Corrigé le 19/08/2026 (round 186) : la whitelist est désormais construite
 * via FontManager::getFontsForScript($script), $script dérivé du postKey.
 *
 * Test comportemental réel : soumet une police JAPONAISE réelle du
 * catalogue pour la clé font_arabic — doit être rejetée (ignorée, config
 * inchangée). Puis soumet une police ARABE réelle pour la même clé —
 * doit être acceptée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/FontManager.php';

    $module = neria_test_module();
    $config = new ConfigManager($module);
    $fontMgr = new FontManager($module);

    $japaneseFonts = array_keys($fontMgr->getFontsForScript('japanese'));
    $arabicFonts   = array_keys($fontMgr->getFontsForScript('arabic'));
    neria_assert(!empty($japaneseFonts), "Aucune police japonaise dans le catalogue — jeu de test invalide");
    neria_assert(!empty($arabicFonts), "Aucune police arabe dans le catalogue — jeu de test invalide");

    $originalArabicFont = $config->get(ConfigManager::KEY_FONT_ARABIC, '');

    try {
        // Sentinelle connue pour détecter une non-modification.
        $config->set(ConfigManager::KEY_FONT_ARABIC, '__regtest_391_sentinel__');

        // Tentative de POST direct avec une police japonaise pour font_arabic.
        $config->saveTypographyConfig(['font_arabic' => $japaneseFonts[0]]);
        $afterWrongScript = $config->get(ConfigManager::KEY_FONT_ARABIC, '');
        neria_assert(
            $afterWrongScript === '__regtest_391_sentinel__',
            "saveTypographyConfig() a accepté '{$japaneseFonts[0]}' (police japonaise) pour font_arabic — régression du bug corrigé le 19/08/2026 (round 186) : une police d'un autre script pourrait de nouveau être injectée dans le CSS des emails arabes sans avertissement"
        );

        // Une vraie police arabe doit, elle, être acceptée normalement.
        $config->saveTypographyConfig(['font_arabic' => $arabicFonts[0]]);
        $afterRightScript = $config->get(ConfigManager::KEY_FONT_ARABIC, '');
        neria_assert(
            $afterRightScript === $arabicFonts[0],
            "saveTypographyConfig() a rejeté à tort '{$arabicFonts[0]}' (police arabe légitime) pour font_arabic — le correctif round 186 est trop restrictif"
        );
    } finally {
        $config->set(ConfigManager::KEY_FONT_ARABIC, $originalArabicFont);
    }

    return [
        'pass'    => true,
        'message' => "ConfigManager::saveTypographyConfig() valide bien chaque police contre le script correspondant à sa clé POST — bug corrigé le 19/08/2026 (round 186)",
    ];
}
