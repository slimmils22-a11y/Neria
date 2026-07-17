<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Lance tous les tests de régression du dossier, chacun dans son propre
 * process PHP CLI (isolation complète, aucune collision de noms de fonction
 * entre fichiers). Affiche un résumé et sort avec le code 1 si un test
 * échoue (utilisable en CI/pré-packaging).
 *
 * Usage : php run_all.php
 */

$phpBin = PHP_BINARY ?: 'php';
$files = glob(__DIR__ . '/test_*.php');
sort($files);

$total = 0;
$passed = 0;
$failures = [];

foreach ($files as $file) {
    $total++;
    $name = basename($file, '.php');

    $runner = tempnam(sys_get_temp_dir(), 'neriatest_') . '.php';
    file_put_contents($runner, "<?php require '" . addslashes($file) . "'; \$r = run_test(); echo json_encode(\$r);");

    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($runner) . ' 2>&1';
    $output = shell_exec($cmd);
    @unlink($runner);

    $decoded = json_decode((string) $output, true);

    if (is_array($decoded) && isset($decoded['pass'])) {
        if ($decoded['pass']) {
            $passed++;
            echo "[PASS] {$name} — {$decoded['message']}\n";
        } else {
            $failures[] = "{$name}: {$decoded['message']}";
            echo "[FAIL] {$name} — {$decoded['message']}\n";
        }
    } else {
        $failures[] = "{$name}: sortie invalide — " . trim((string) $output);
        echo "[FAIL] {$name} — sortie invalide:\n" . trim((string) $output) . "\n";
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed}/{$total} tests reussis\n";

if ($failures) {
    echo "\nECHECS :\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

exit(0);
