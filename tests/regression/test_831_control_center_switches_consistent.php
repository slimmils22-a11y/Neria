<?php
/**
 * P8d (21/09/2026) : les 43 interrupteurs du Centre de contrôle (registre ConfigManager::CONTROL_CENTER_REGISTRY) : libellé présent dans les
 * 19 langues, onglet et ancre de section existants, réglage connu et modifiable depuis le back-office, état affiché = état réel du
 * réglage (tests/functional/cc_audit.php).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $script = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/cc_audit.php', chr(92), '/');
    $json = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/results/P8d_control_center.json', chr(92), '/');
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
    neria_assert(is_file($json), "audit du centre de contrôle impossible : " . implode(' ', array_slice($out, -2)));
    $r = json_decode((string) file_get_contents($json), true);
    neria_assert(is_array($r) && count($r['rows']) >= 43, 'moins de 43 interrupteurs audités');
    $bad = array_values(array_filter($r['issues'], static function ($i) {
        return $i['level'] === 'E' || $i['code'] === 'C005';
    }));
    neria_assert(!$bad, count($bad) . ' anomalie(s) — ' . implode(' | ', array_map(static function ($i) {
        return $i['key'] . '/' . $i['code'] . ' : ' . substr($i['msg'], 0, 90);
    }, array_slice($bad, 0, 4))));
    return ['pass' => true, 'message' => count($r['rows']) . ' interrupteurs du Centre de contrôle cohérents : libellés 19 langues, cibles existantes, réglages modifiables, état affiché conforme'];
}
