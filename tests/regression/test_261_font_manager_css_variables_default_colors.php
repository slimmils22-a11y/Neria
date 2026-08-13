<?php
/**
 * Régression : FontManager::generateCssVariables() appelait
 * NeriaTools::sanitizeColor() SANS second argument (repli) sur les 4
 * couleurs design (fond/conteneur/accent/texte), contrairement à
 * generateFontCss() qui passe explicitement le vrai défaut de marque
 * (#b38b59) pour accentColor. sanitizeColor() retombe alors sur son défaut
 * interne générique #000000 (noir) — si une couleur corrompue atteint la
 * config hors admin (import direct, script d'upgrade, accès DB), les 4
 * couleurs retombaient TOUTES sur le noir au lieu des vraies valeurs de
 * marque (ConfigManager::DEFAULTS), rendant potentiellement le contenu de
 * l'email invisible (fond noir + texte noir).
 *
 * Corrigé le 09/08/2026 (round 159) : chaque appel passe désormais
 * explicitement ConfigManager::DEFAULTS[...] comme repli.
 *
 * Test comportemental réel : corrompt temporairement en base les 4 clés de
 * couleur avec une valeur invalide, appelle generateCssVariables(), et
 * vérifie que le CSS produit contient bien les VRAIES couleurs de marque
 * par défaut (#f4f1eb/#ffffff/#b38b59/#2c2c2c) — jamais #000000.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/FontManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $keys = [
        ConfigManager::KEY_COLOR_BACKGROUND,
        ConfigManager::KEY_COLOR_CONTAINER,
        ConfigManager::KEY_COLOR_ACCENT,
        ConfigManager::KEY_COLOR_TEXT,
    ];
    $saved = [];
    foreach ($keys as $k) {
        $saved[$k] = Configuration::get($k);
    }

    try {
        // Valeur invalide (pas un code couleur hex) — simule une donnée
        // corrompue atteignant la config hors du formulaire admin, qui
        // valide déjà correctement les couleurs à l'écriture.
        foreach ($keys as $k) {
            Configuration::updateValue($k, 'not-a-color');
        }

        $module = neria_test_module();
        $fm     = new FontManager($module);
        $css    = $fm->generateCssVariables('fr');

        neria_assert(
            strpos($css, '#000000') === false,
            "generateCssVariables() retombe encore sur #000000 (défaut générique de sanitizeColor()) au lieu des vraies couleurs de marque — régression du bug corrigé le 09/08/2026 (round 159)"
        );

        foreach ([
            ConfigManager::DEFAULTS[ConfigManager::KEY_COLOR_BACKGROUND],
            ConfigManager::DEFAULTS[ConfigManager::KEY_COLOR_CONTAINER],
            ConfigManager::DEFAULTS[ConfigManager::KEY_COLOR_ACCENT],
            ConfigManager::DEFAULTS[ConfigManager::KEY_COLOR_TEXT],
        ] as $expectedDefault) {
            neria_assert(
                stripos($css, $expectedDefault) !== false,
                "generateCssVariables() ne contient pas la vraie couleur de marque par défaut '{$expectedDefault}' après une valeur config corrompue — régression du bug corrigé le 09/08/2026 (round 159)"
            );
        }

        return [
            'pass'    => true,
            'message' => "FontManager::generateCssVariables() retombe bien sur les vraies couleurs de marque par défaut (pas #000000) quand la config design est corrompue — bug corrigé le 09/08/2026 (round 159)",
        ];
    } finally {
        foreach ($saved as $k => $v) {
            if ($v !== false && $v !== null) {
                Configuration::updateValue($k, $v);
            }
        }
    }
}
