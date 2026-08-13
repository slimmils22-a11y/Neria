<?php
/**
 * Régression : design.tpl teste `{if isset($design.logo_url) && $design.logo_url}`
 * pour afficher le panneau "Logo actuel", mais ConfigManager::getDesignConfig()
 * ne renvoie que 'logo_path' (chemin relatif brut, jamais résolu en URL
 * publique) — le panneau ne s'affichait donc jamais, même après un upload
 * de logo réussi. Fonctionnalité silencieusement morte depuis son
 * introduction : le marchand n'avait aucun moyen de vérifier visuellement
 * son logo dans le BO sans ouvrir un aperçu email.
 *
 * Corrigé le 13/08/2026 (round 164) : neria.php calcule désormais
 * 'logo_url' (via getModuleUrl(), même logique que
 * EmailRenderer::resolveLogoUrl()) et l'ajoute au tableau 'design' avant
 * assignation Smarty — vide si aucun logo n'a été uploadé (le panneau
 * reste alors masqué, comportement attendu du {if} existant).
 *
 * Test réel : uploade un vrai chemin de logo factice via ConfigManager
 * (contournant l'upload de fichier réel, non nécessaire pour ce test),
 * vérifie via Reflection sur la logique de calcul que logo_url est bien
 * dérivée de logo_path quand celui-ci est non vide.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    neria_assert(class_exists('ConfigManager'), 'Classe ConfigManager introuvable');

    $config = new ConfigManager($module);
    $original = (string) Configuration::get('NERIA_LOGO_PATH');
    $testPath = 'signatures/logo_test_round164_' . uniqid() . '.png';

    try {
        Configuration::updateValue('NERIA_LOGO_PATH', $testPath);

        $designConfig = $config->getDesignConfig();
        neria_assert(
            ($designConfig['logo_path'] ?? '') === $testPath,
            "getDesignConfig() ne reflète pas la valeur de test — jeu de test invalide"
        );

        // Reproduit exactement la logique ajoutée dans neria.php (même
        // condition et même appel), sans rendre getContentImpl() en entier.
        $logoUrl = !empty($designConfig['logo_path']) ? $module->getModuleUrl($designConfig['logo_path']) : '';
        neria_assert(
            $logoUrl !== '' && strpos($logoUrl, $testPath) !== false,
            "logo_url n'est pas correctement dérivée de logo_path — régression du bug corrigé le 13/08/2026 (round 164)"
        );
    } finally {
        Configuration::updateValue('NERIA_LOGO_PATH', $original);
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');
    neria_assert(
        strpos($src, "\$designConfig['logo_url'] = !empty(\$designConfig['logo_path'])") !== false,
        "neria.php ne calcule plus 'logo_url' à partir de 'logo_path' — régression du bug corrigé le 13/08/2026 (round 164) : le panneau 'Logo actuel' redeviendrait invisible même après un upload réussi"
    );

    return [
        'pass'    => true,
        'message' => "neria.php calcule bien 'logo_url' à partir de 'logo_path', permettant au panneau 'Logo actuel' de s'afficher — bug corrigé le 13/08/2026 (round 164)",
    ];
}
