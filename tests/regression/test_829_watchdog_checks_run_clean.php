<?php
/**
 * P8d (21/09/2026) : chacun des 127 contrôles du Watchdog, exécuté isolément en français et en anglais, ne lève ni exception, ni
 * avertissement PHP, renvoie un statut valide et un détail non vide, sans clé de traduction brute ni {variable} non résolue
 * (tests/functional/wd_run_checks.php ; les 2 contrôles à service externe ne sont pas lancés).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $script = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/wd_run_checks.php', chr(92), '/');
    $json = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/results/P8d_checks.json', chr(92), '/');
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg($script) . ' --lang=fr,en --quiet 2>&1', $out, $rc);
    neria_assert($rc === 0 && is_file($json), 'exécution des contrôles impossible : ' . implode(' ', array_slice($out, -2)));
    $r = json_decode((string) file_get_contents($json), true);
    neria_assert(is_array($r) && count($r['rows']) >= 127, 'moins de 127 contrôles exécutés : ' . count($r['rows'] ?? []));
    $errors = array_values(array_filter($r['issues'], static function ($i) {
        return $i['level'] === 'E';
    }));
    $msgs = array_map(static function ($i) {
        return $i['key'] . '/' . $i['code'] . ' : ' . substr($i['msg'], 0, 100);
    }, array_slice($errors, 0, 4));
    neria_assert(!$errors, count($errors) . ' anomalie(s) de contrôle Watchdog — ' . implode(' | ', $msgs));
    return ['pass' => true, 'message' => count($r['rows']) . ' contrôles du Watchdog exécutés en fr/en : aucune exception, aucun avertissement PHP, statut et détail valides, sans clé brute'];
}
