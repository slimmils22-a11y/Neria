<?php
/**
 * Régression : 2 règles CSS directionnelles codées en dur (margin-left,
 * text-align:left) oubliées du correctif RTL (audit dédié round 156),
 * alors que leur classe jumelle avait déjà été corrigée dans le même
 * bloc CSS :
 * - .neria-table thead th : text-align:left non inversé en RTL,
 *   contrairement à .neria-stats-table th (composant générique utilisé
 *   sur 8+ écrans admin — translations.tpl, webhooks.tpl, calendar.tpl...).
 * - .neria-abtest-variant__metrics : margin-left:auto non inversé en RTL,
 *   contrairement à .neria-abtest-variant__rate (écran A/B testing,
 *   abtest.tpl).
 *
 * Corrigé le 09/08/2026 (round 156) en ajoutant les 2 règles manquantes
 * au bloc RTL existant (views/css/neria-admin.css), même style que les
 * règles déjà en place.
 *
 * Test structurel (pas de moteur de rendu CSS disponible dans cet
 * environnement de test PHP) : vérifie la présence des 2 nouvelles règles
 * dans le bloc RTL.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $css = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/css/neria-admin.css');
    neria_assert($css !== false, 'Impossible de lire neria-admin.css');

    $posRtl = strpos($css, '.neria-bo-wrap[dir="rtl"] { text-align:right; }');
    neria_assert($posRtl !== false, 'Bloc RTL introuvable — jeu de test invalide');
    $rtlBlock = substr($css, $posRtl, 1000);

    neria_assert(
        strpos($rtlBlock, '.neria-bo-wrap[dir="rtl"] .neria-table thead th { text-align:right; }') !== false,
        ".neria-table thead th n'est plus inversé en RTL — régression du bug corrigé le 09/08/2026 (round 156) : les en-têtes de tableau traduits en arabe redeviendraient mal alignés sur 8+ écrans admin"
    );
    neria_assert(
        strpos($rtlBlock, '.neria-bo-wrap[dir="rtl"] .neria-abtest-variant__metrics { margin-left:0; margin-right:auto; }') !== false,
        ".neria-abtest-variant__metrics n'est plus inversé en RTL — régression du bug corrigé le 09/08/2026 (round 156) : le bloc de métriques A/B testing redeviendrait mal aligné en arabe"
    );

    return [
        'pass'    => true,
        'message' => "Les 2 règles CSS RTL oubliées (tableau générique, métriques A/B testing) corrigées le 09/08/2026 (round 156) restent en place",
    ];
}
