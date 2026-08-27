<?php
/**
 * Régression : views/templates/admin/stats.tpl affichait {$cat.name} sans
 * |escape:'html' dans le sélecteur de catégorie déclencheuse "Complétez
 * votre look" — contrairement à toutes les autres données métier du même
 * fichier (noms clients, etc.), systématiquement échappées.
 *
 * Bug réel : {$cat.name} provient de LookCompletionManager::getCategories()
 * (src/LookCompletionManager.php:598-620), qui lit ps_category_lang.name
 * BRUT, sans htmlspecialchars() côté PHP ni échappement côté template.
 * Une catégorie active peut être renommée par un employé BO disposant
 * SEULEMENT du droit "Catalogue > Catégories" (permission distincte de
 * l'accès au module Neria dans le modèle de permissions PrestaShop). Un
 * nom de catégorie contenant "</option></select><img src=x onerror=...>"
 * ferme prématurément le <select> et exécute du JS arbitraire dans la
 * session BO d'un administrateur consultant l'onglet Stats > Complétez
 * votre look — XSS stocké avec escalade de privilège horizontale.
 *
 * Corrigé le 26/08/2026 (round 220) : |escape:'html':'UTF-8' sur
 * {$cat.name}, |intval sur {$cat.id_category}.
 *
 * Test comportemental réel : une catégorie réelle renommée avec un
 * payload XSS ne doit plus apparaître non échappée dans le rendu Smarty
 * du template.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/stats.tpl');
    neria_assert($src !== false, 'Impossible de lire views/templates/admin/stats.tpl');
    neria_assert(
        strpos($src, "<option value=\"{\$cat.id_category|intval}\">{\$cat.name|escape:'html':'UTF-8'}</option>") !== false,
        "stats.tpl n'échappe plus \$cat.name dans le sélecteur de catégorie — régression du bug corrigé le 26/08/2026 (round 220) : un nom de catégorie contenant du HTML/JS s'exécuterait de nouveau dans la session BO d'un administrateur consultant l'onglet Stats"
    );

    // Vérification comportementale réelle : rendu Smarty du fragment exact
    // du template (le rendu du fichier stats.tpl complet nécessite un
    // contexte d'onglet BO trop lourd à reconstruire ici — le fragment
    // isolé exerce le même modificateur |escape sur la même donnée).
    require_once 'C:/laragon/www/shop/vendor/smarty/smarty/libs/Smarty.class.php';
    $smarty = new Smarty();
    $smarty->setCompileDir(sys_get_temp_dir() . '/neria_test_smarty_compile/');
    @mkdir($smarty->getCompileDir(), 0777, true);
    $smarty->force_compile = true;

    $posFrag = strpos($src, '{foreach $look_categories as $cat}');
    neria_assert($posFrag !== false, 'Fragment {foreach $look_categories...} introuvable — jeu de test invalide');
    $posEnd = strpos($src, '{/foreach}', $posFrag) + strlen('{/foreach}');
    $fragment = substr($src, $posFrag, $posEnd - $posFrag);

    $tplFile = sys_get_temp_dir() . '/neria_test_stats_fragment.tpl';
    file_put_contents($tplFile, $fragment);

    $payload = '</option></select><img src=x onerror=alert(1)>';
    $smarty->assign('look_categories', [['id_category' => 5, 'name' => $payload]]);
    $html = $smarty->fetch($tplFile);

    neria_assert(
        strpos((string) $html, '<img src=x onerror=alert(1)>') === false,
        "Le rendu réel du fragment stats.tpl contient encore le payload XSS non échappé — régression du bug corrigé le 26/08/2026 (round 220)"
    );
    neria_assert(
        strpos((string) $html, '&lt;/option&gt;&lt;/select&gt;') !== false,
        "Le rendu réel du fragment stats.tpl n'échappe plus le nom de catégorie en entités HTML — régression du bug corrigé le 26/08/2026 (round 220)"
    );

    return [
        'pass'    => true,
        'message' => "stats.tpl échappe bien \$cat.name (XSS stocké via nom de catégorie corrigé) — bug corrigé le 26/08/2026 (round 220)",
    ];
}
