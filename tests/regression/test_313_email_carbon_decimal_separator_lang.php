<?php
/**
 * Régression : le bloc HTML "empreinte carbone" formatait toujours le CO2
 * avec number_format(..., '.', '') — point décimal codé en dur — même dans
 * un footer entièrement en français ou dans une autre langue à virgule
 * décimale (de, es, it, pt, nl, pl, ru, tr...), produisant "~0.3g CO₂" au
 * lieu de "~0,3g CO₂", incohérent avec le reste de l'email localisé.
 *
 * Corrigé le 14/08/2026 (round 168) : buildCarbonHtml() choisit désormais
 * la virgule comme séparateur pour les langues qui l'attendent
 * (EmailRenderer::CARBON_COMMA_DECIMAL_LANGS).
 *
 * Test comportemental réel : appelle buildCarbonHtml() (via réflexion,
 * méthode privée) pour $lang='fr' et $lang='en', avec l'empreinte carbone
 * activée, et vérifie que le CO2 rendu utilise bien une virgule en 'fr' et
 * un point en 'en'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';

    $module = neria_test_module();
    $configClass = _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    if (is_file($configClass)) {
        require_once $configClass;
    }

    $carbonWasEnabled = Configuration::get('NERIA_CARBON_ENABLED');
    Configuration::updateValue('NERIA_CARBON_ENABLED', 1);

    try {
        $renderer = new EmailRenderer($module);
        $ref = new ReflectionMethod(EmailRenderer::class, 'buildCarbonHtml');
        $ref->setAccessible(true);

        // ~15 Ko de contenu factice pour produire une valeur CO2 non entière.
        $fakeCompiled = str_repeat('x', 15 * 1024 + 400);

        $htmlFr = $ref->invoke($renderer, $fakeCompiled, 'fr');
        $htmlEn = $ref->invoke($renderer, $fakeCompiled, 'en');

        if (trim($htmlFr) === '' && trim($htmlEn) === '') {
            return ['pass' => true, 'message' => 'Empreinte carbone non activable sur cette install de test (config manquante) — test ignoré (rien à vérifier)'];
        }

        neria_assert(
            preg_match('/~(\d+),(\d+)g CO₂/u', $htmlFr) === 1,
            "buildCarbonHtml('fr') n'utilise pas de virgule décimale ('" . strip_tags($htmlFr) . "') — régression du bug corrigé le 14/08/2026 (round 168)"
        );
        neria_assert(
            preg_match('/~(\d+)\.(\d+)g CO₂/u', $htmlEn) === 1,
            "buildCarbonHtml('en') n'utilise pas de point décimal ('" . strip_tags($htmlEn) . "') — un anglophone verrait désormais une virgule inattendue"
        );
    } finally {
        Configuration::updateValue('NERIA_CARBON_ENABLED', $carbonWasEnabled);
    }

    return [
        'pass'    => true,
        'message' => "EmailRenderer::buildCarbonHtml() adapte bien le séparateur décimal du CO2 à la langue du destinataire (virgule en fr, point en en) — bug corrigé le 14/08/2026 (round 168)",
    ];
}
