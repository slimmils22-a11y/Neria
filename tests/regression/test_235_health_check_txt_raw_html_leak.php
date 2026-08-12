<?php
/**
 * Régression : HealthCheckManager::checkTxtRawHtmlLeak() (nouveau contrôle
 * prospectif ajouté le 09/08/2026, round 150 bis, à la demande explicite de
 * l'utilisateur après le correctif du bug HTML/txt) doit rejouer la
 * compilation réelle de TOUS les templates et détecter toute balise HTML
 * brute dans le fichier .txt produit — pas seulement les 15 templates
 * identifiés par l'audit initial.
 *
 * Bug réel trouvé et corrigé le 09/08/2026 (round 150 bis) grâce à ce
 * nouveau contrôle lui-même : return_slip.txt référençait {items} (le
 * fragment HTML brut, dans self::HTML_SAFE_RAW_KEYS) au lieu de
 * {items_txt} (la variante texte auto-dérivée par
 * EmailRenderer::injectTextVariants() depuis le round 149) — bug distinct
 * du correctif history_info/guest_tracking_info/tracking_info du round
 * 150, jamais détecté avant l'ajout de ce contrôle prospectif.
 *
 * Test comportemental réel : invoque checkTxtRawHtmlLeak() (via Reflection)
 * contre le vrai jeu de templates du module, vérifie un statut OK — puis
 * confirme structurellement que return_slip.txt utilise bien {items_txt}
 * et non {items}.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $hc = new HealthCheckManager($module);
    $method = new ReflectionMethod(HealthCheckManager::class, 'checkTxtRawHtmlLeak');
    $method->setAccessible(true);
    $result = $method->invoke($hc);

    neria_assert(
        $result['status'] === 'ok',
        "checkTxtRawHtmlLeak() detecte une fuite de balise HTML brute dans un .txt reel : " . ($result['detail'] ?? '?')
    );

    $returnSlipTxt = file_get_contents(_PS_MODULE_DIR_ . 'neria/mails/themes/neria_global/core/return_slip.txt');
    neria_assert(
        strpos($returnSlipTxt, '{items_txt}') !== false && strpos($returnSlipTxt, '{items}') === false,
        "return_slip.txt reference de nouveau {items} (fragment HTML brut) au lieu de {items_txt} — regression du bug corrige le 09/08/2026 (round 150 bis)"
    );

    return [
        'pass'    => true,
        'message' => "HealthCheckManager::checkTxtRawHtmlLeak() ne detecte aucune fuite de HTML brut sur l'ensemble reel des templates, et return_slip.txt utilise bien {items_txt}",
    ];
}
