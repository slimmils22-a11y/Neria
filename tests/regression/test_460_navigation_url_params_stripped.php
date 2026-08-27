<?php
/**
 * Régression : views/templates/admin/navigation.tpl appliquait
 * |escape:'html' AVANT les regex_replace censés retirer
 * neria_success/neria_error/neria_action/neria_msg_action (et
 * neria_test_lang pour le sélecteur d'envoi de test) de l'URL courante.
 * L'échappement transformait chaque '&' en '&amp;' avant ces
 * regex_replace, dont les patterns littéraux (ancre & suivie du nom du
 * paramètre) ne matchaient alors plus jamais rien.
 *
 * Bug réel : un marchand activant une fonctionnalité (message de
 * confirmation "Fonctionnalité activée.") voyait ce message rester
 * coincé dans neria_tab_base — qui alimente TOUS les liens du menu de
 * navigation — et réapparaître sur CHAQUE onglet visité ensuite,
 * indéfiniment, jusqu'à un rechargement manuel d'URL propre. Même défaut
 * pour neria_test_lang, causant une possible duplication de paramètre
 * dans le lien d'envoi d'email de test.
 *
 * Corrigé le 26/08/2026 (round 221) : |escape:'html' déplacé après tous
 * les regex_replace de chaque chaîne.
 *
 * Test comportemental réel : rendu Smarty du fragment exact avec une URL
 * contenant les 4 paramètres — aucun ne doit survivre dans le résultat.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/navigation.tpl');
    neria_assert($src !== false, 'Impossible de lire views/templates/admin/navigation.tpl');

    neria_assert(
        strpos($src, "regex_replace:'/&neria_msg_action=[^&]*/':''|escape:'html'}") !== false,
        "navigation.tpl n'applique plus |escape:'html' APRÈS le dernier regex_replace de \$neria_tab_base — régression du bug corrigé le 26/08/2026 (round 221)"
    );
    neria_assert(
        strpos($src, "regex_replace:'/&neria_test_lang=[^&]*/':''|escape:'html'}") !== false,
        "navigation.tpl n'applique plus |escape:'html' APRÈS le regex_replace de \$neria_test_base — régression du bug corrigé le 26/08/2026 (round 221)"
    );

    // Vérification comportementale réelle du fragment $neria_tab_base.
    require_once 'C:/laragon/www/shop/vendor/smarty/smarty/libs/Smarty.class.php';
    $smarty = new Smarty();
    $smarty->setCompileDir(sys_get_temp_dir() . '/neria_test_smarty_nav/');
    @mkdir($smarty->getCompileDir(), 0777, true);
    $smarty->force_compile = true;

    $posStart = strpos($src, '{assign var="neria_tab_base"');
    neria_assert($posStart !== false, 'Bloc neria_tab_base introuvable — jeu de test invalide');
    $posEnd = strpos($src, "\n", strpos($src, "neria_msg_action=[^&]*/':''|escape:'html'}")) + 1;
    $fragment = substr($src, $posStart, $posEnd - $posStart) . "RESULT=[{\$neria_tab_base}]";

    $tplFile = sys_get_temp_dir() . '/neria_test_nav_fragment.tpl';
    file_put_contents($tplFile, $fragment);

    $prevUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/admin/index.php?controller=AdminModules&neria_tab=configure&neria_success=1&neria_error=x&neria_action=toggle&neria_msg_action=y&foo=bar';
    $html = $smarty->fetch($tplFile);
    if ($prevUri !== null) {
        $_SERVER['REQUEST_URI'] = $prevUri;
    } else {
        unset($_SERVER['REQUEST_URI']);
    }

    foreach (['neria_success', 'neria_error', 'neria_action', 'neria_msg_action', 'neria_tab'] as $param) {
        neria_assert(
            strpos((string) $html, $param . '=') === false,
            "\$neria_tab_base contient encore le paramètre '{$param}' après rendu réel — régression du bug corrigé le 26/08/2026 (round 221) : ce message resterait coincé sur tous les onglets du menu"
        );
    }
    neria_assert(
        strpos((string) $html, 'foo=bar') !== false,
        "Le rendu a supprimé un paramètre non ciblé (foo) — jeu de test invalide"
    );

    return [
        'pass'    => true,
        'message' => "navigation.tpl retire bien neria_tab/neria_success/neria_error/neria_action/neria_msg_action de l'URL des liens de menu — bug corrigé le 26/08/2026 (round 221)",
    ];
}
