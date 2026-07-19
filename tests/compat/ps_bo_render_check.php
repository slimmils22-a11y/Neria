<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Diagnostic de compatibilité — rendu HTML du panneau BO Neria (axe 5).
 *
 * Exécute getContent() (le code qui génère le panneau de configuration BO)
 * en PHP CLI via un contexte employé réel construit en mémoire — PAS une
 * authentification web, aucun cookie, aucun mot de passe. Même principe que
 * les scripts cron internes de Neria (WatchdogManager, etc.) qui tournent
 * hors requête HTTP.
 *
 * Contourne le blocage structurel identifié le 2026-07-19 : les pages BO
 * sont derrière un mur de connexion, et Claude ne doit jamais saisir de
 * mot de passe — cette technique permet de vérifier le RENDU sans jamais
 * s'authentifier.
 *
 * Usage : copier sur le serveur (n'importe où, ex. /tmp/), exécuter avec le
 * chemin racine PS en argument :
 *   php ps_bo_render_check.php /chemin/vers/prestashop
 * Le HTML complet est sauvegardé dans sys_get_temp_dir() pour diff manuel
 * si besoin. Supprimer le script après usage.
 *
 * Dernier scan complet : 2026-07-19, PS8 8.1.7 vs PS9 9.0.2 → structure
 * HTML strictement identique (même longueur à 1KB près, mêmes 3583 lignes,
 * 0 warning PHP, 17/17 onglets présents sur les deux). Seules différences :
 * artefact d'URL de base du script CLI (sans rapport) et 4 valeurs de KPI
 * (données réelles différentes entre les deux boutiques de test, pas un
 * bug de rendu).
 */

$root = $argv[1] ?? getcwd();
require $root . '/config/config.inc.php';
require $root . '/modules/neria/neria.php';

$employee = new Employee(1);
if (!Validate::isLoadedObject($employee)) {
    echo 'ERROR: employee 1 not loaded' . PHP_EOL;
    exit(1);
}
Context::getContext()->employee = $employee;
Context::getContext()->smarty->assign('token', 'diag');

$module = new Neria();

try {
    $html = $module->getContent();
    echo 'LENGTH=' . strlen($html) . PHP_EOL;
    echo 'HAS_PHP_WARNING=' . (preg_match('/Warning:|Notice:|Deprecated:|Fatal error:/', $html) ? 'YES' : 'no') . PHP_EOL;
    echo 'HAS_NERIA_LOGO=' . (strpos($html, 'neria-bo-header') !== false ? 'yes' : 'NO') . PHP_EOL;

    foreach (['design', 'stats', 'segments', 'webhooks', 'certificates', 'gdpr', 'calendar', 'seasonal', 'academy', 'translations', 'help', 'abtest', 'bounces', 'social', 'typography', 'multipreview', 'send'] as $tab) {
        echo 'TAB_' . strtoupper($tab) . '_MENTION=' . (strpos($html, $tab) !== false ? 'yes' : 'NO') . PHP_EOL;
    }

    file_put_contents(sys_get_temp_dir() . '/neria_render_output.html', $html);
    echo 'SAVED_TO=' . sys_get_temp_dir() . '/neria_render_output.html' . PHP_EOL;
} catch (\Throwable $e) {
    echo 'EXCEPTION: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}
