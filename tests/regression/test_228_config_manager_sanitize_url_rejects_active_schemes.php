<?php
/**
 * Régression : ConfigManager::sanitizeUrl() (privée, utilisée par
 * saveSocialConfig()) doit rejeter les schémas actifs (javascript:, data:)
 * et n'accepter que http/https.
 *
 * Bug réel corrigé le 09/08/2026 (round 149) : sanitizeUrl() se contentait
 * de filter_var($url, FILTER_VALIDATE_URL), qui valide la syntaxe générique
 * d'un URI (schema:...) sans exiger http/https — "javascript:alert(1)" ou
 * "data:text/html,..." étaient acceptés comme "URL valide". Cette valeur
 * est ensuite injectée dans un href= par EmailRenderer::injectSocialVars()
 * dans chaque email envoyé : un lien social malveillant pouvait porter un
 * schéma actif exécuté selon le contexte (aperçu BO en iframe, client mail
 * allégé).
 *
 * Test comportemental réel : appelle saveSocialConfig() avec une charge
 * javascript:/data: dans un champ social, vérifie qu'elle est bien rejetée
 * (getSocialLinks() ne la contient pas) — puis vérifie qu'une vraie URL
 * https légitime est bien acceptée (non-régression).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $config = new ConfigManager($module);
    $original = $config->getSocialLinks();

    try {
        $config->saveSocialConfig(['social_twitter' => 'javascript:alert(document.cookie)']);
        $links = $config->getSocialLinks();
        neria_assert(
            empty($links['twitter']) || strpos($links['twitter'], 'javascript:') !== 0,
            "sanitizeUrl() accepte de nouveau un schema 'javascript:' — régression du bug corrigé le 09/08/2026 (round 149) : XSS stocké possible via un lien social dans chaque email envoyé"
        );

        $config->saveSocialConfig(['social_twitter' => 'data:text/html,<script>alert(1)</script>']);
        $links = $config->getSocialLinks();
        neria_assert(
            empty($links['twitter']) || strpos($links['twitter'], 'data:') !== 0,
            "sanitizeUrl() accepte de nouveau un schema 'data:' — régression du bug corrigé le 09/08/2026 (round 149)"
        );

        // Contre-preuve : une vraie URL https doit toujours être acceptée.
        $config->saveSocialConfig(['social_twitter' => 'https://twitter.com/neria_test']);
        $links = $config->getSocialLinks();
        neria_assert(
            ($links['twitter'] ?? '') === 'https://twitter.com/neria_test',
            "sanitizeUrl() rejette a tort une URL https legitime — non-regression cassee"
        );
    } finally {
        $config->saveSocialConfig(['social_twitter' => $original['twitter'] ?? '']);
    }

    return [
        'pass'    => true,
        'message' => "ConfigManager::sanitizeUrl() rejette bien les schemas actifs (javascript:/data:) et accepte toujours http/https",
    ];
}
