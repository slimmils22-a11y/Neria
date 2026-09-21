<?php
/**
 * P8c (21/09/2026) : audit des éléments cliquables des 21 onglets du back-office sur le rendu réel (bo_buttons_audit.php) :
 * aucun bouton mort, aucun onclick vers une fonction inexistante, aucune neria_action inconnue, aucune ancre cassée ; la suppression
 * d'une règle de la liste noire demande une confirmation ; les liens target=_blank portent rel=noopener.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $script = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/bo_buttons_audit.php', chr(92), '/');
    $json = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/results/P8c_buttons.json', chr(92), '/');
    neria_assert(is_file($script), 'bo_buttons_audit.php introuvable');
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg($script) . ' --quiet 2>&1', $out, $rc);
    neria_assert($rc === 0 && is_file($json), "l'audit des boutons a échoué : " . implode(' ', array_slice($out, -3)));
    $r = json_decode((string) file_get_contents($json), true);
    neria_assert(is_array($r) && count($r['items']) >= 300, 'trop peu d\'éléments cliquables audités : ' . count($r['items'] ?? []));
    $errors = array_values(array_filter($r['issues'], static function ($i) {
        return $i['level'] === 'E';
    }));
    $msgs = array_map(static function ($i) {
        return $i['tab'] . '/' . $i['code'] . ' « ' . $i['label'] . ' » : ' . substr($i['msg'], 0, 90);
    }, array_slice($errors, 0, 4));
    neria_assert(!$errors, count($errors) . ' anomalie(s) de bouton — ' . implode(' | ', $msgs));
    foreach ($r['issues'] as $i) {
        neria_assert($i['code'] !== 'B008', 'lien target=_blank sans rel=noopener : ' . $i['label']);
        neria_assert(!($i['code'] === 'B007' && strpos($i['msg'], 'remove_blacklist') !== false), 'suppression d\'une règle de la liste noire sans confirmation');
    }
    return ['pass' => true, 'message' => count($r['items']) . ' éléments cliquables des onglets du back-office : aucun bouton mort, gestionnaire manquant, action inconnue ni ancre cassée ; liste noire confirmée ; liens externes sécurisés'];
}
