<?php
/**
 * P8d (21/09/2026) : pour 49 contrôles du Watchdog (réglages corrompus, crons désactivés, clé de chiffrement absente, webhooks/rebonds/files en
 * échec, seuils de statistiques et de volumes, tests A/B, bons orphelins, fidélité négative, ainsi que 10 contrôles STATIQUES éprouvés sur un
 * faux module contenant un fichier fautif), la panne est PROVOQUÉE dans une transaction annulée et le contrôle doit passer de « ok » à l'état
 * attendu puis revenir à « ok » (tests/functional/wd_fault_injection.php). Prouve que ces contrôles détectent réellement ce qu'ils annoncent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $script = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/wd_fault_injection.php', chr(92), '/');
    $json = strtr(_PS_MODULE_DIR_ . 'neria/tests/functional/results/P8d_faults.json', chr(92), '/');
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --quiet 2>&1', $out, $rc);
    neria_assert(is_file($json), "injection de pannes impossible : " . implode(' ', array_slice($out, -2)));
    $r = json_decode((string) file_get_contents($json), true);
    neria_assert(is_array($r) && count($r["rows"]) >= 45, "moins de 45 pannes injectées");
    $missed = array_values(array_filter($r['rows'], static function ($x) {
        return $x['result'] !== 'DÉTECTÉ';
    }));
    neria_assert(!$missed, count($missed) . ' panne(s) non détectée(s) : ' . implode(', ', array_map(static function ($x) {
        return $x['key'] . ' (' . ($x['before'] ?? '?') . '→' . ($x['after'] ?? '?') . '→' . ($x['restored'] ?? '?') . ')';
    }, array_slice($missed, 0, 4))));
    foreach ($r['rows'] as $x) {
        neria_assert($x['restored'] === $x['before'], "contrôle {$x['key']} : l'état n'est pas rétabli après annulation ({$x['before']} → {$x['restored']})");
    }
    return ['pass' => true, 'message' => count($r['rows']) . ' pannes provoquées et détectées par leur contrôle Watchdog, retour à « ok » après annulation'];
}
