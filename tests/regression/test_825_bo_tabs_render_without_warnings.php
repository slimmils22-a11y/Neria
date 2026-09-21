<?php
/**
 * P8a (campagne de tests fonctionnels, 21/09/2026) : les 21 onglets du back-office, rendus avec le vrai Neria::getContent()
 * en français, anglais et arabe, ne doivent produire ni avertissement PHP/Smarty (variable ou clé non définie…), ni clé de
 * traduction brute, ni action inconnue du code. Avant : 21 avertissements distincts (gabarits automations, stats, help,
 * customer_history, translations) affichés à chaque ouverture d'onglet quand PrestaShop est en mode debug.
 * Exécute tests/functional/bo_walk_tabs.php (les dépréciations du cœur PrestaShop y sont classées « I » et ignorées ici).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $script = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/bo_walk_tabs.php', chr(92), '/');
    $json = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/results/P8a_tabs.json', chr(92), '/');
    neria_assert(is_file($script), 'harnais bo_walk_tabs.php introuvable');
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg($script) . ' --lang=fr,en,ar --quiet 2>&1', $out, $rc);
    neria_assert($rc === 0 && is_file($json), 'le parcours des onglets a échoué : ' . implode(' ', array_slice($out, -3)));
    $r = json_decode((string) file_get_contents($json), true);
    neria_assert(is_array($r) && count($r['tabs']) >= 21, 'moins de 21 onglets parcourus');
    $errors = array_filter($r['issues'], static function ($i) {
        return $i['level'] === 'E';
    });
    $msgs = array_map(static function ($i) {
        return $i['tab'] . '/' . $i['code'] . ' : ' . substr($i['msg'], 0, 110);
    }, array_slice(array_values($errors), 0, 4));
    neria_assert(!$errors, count($errors) . ' erreur(s) dans les onglets du back-office — ' . implode(' | ', $msgs));
    return ['pass' => true, 'message' => count($r['tabs']) . ' onglets du back-office rendus en fr/en/ar sans avertissement PHP/Smarty, clé de traduction brute ni action inconnue'];
}
