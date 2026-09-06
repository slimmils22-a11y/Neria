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

    // Round 308 bis (06/09/2026) : plus de fichier temporaire écrit sur
    // disque à chaque test (tempnam() + file_put_contents()) — le code est
    // désormais passé directement en ligne de commande via `php -r`. Sur
    // cet environnement, Windows Defender (protection temps réel) scanne
    // systématiquement tout NOUVEAU fichier écrit avant de le laisser
    // s'exécuter, ajoutant ~11-12s de latence à CHAQUE test (mesuré : la
    // suite complète est passée de ~5-10 min avant le round 306 à ~2h
    // ensuite, sans changement du nombre de tests ni du code testé —
    // uniquement un changement de comportement de l'antivirus/dossier temp
    // système). Isolation par process toujours garantie (aucun changement
    // de comportement des tests eux-mêmes), simplement sans passage par le
    // système de fichiers.
    $code = "require '" . addslashes($file) . "'; \$r = run_test(); echo json_encode(\$r);";
    $cmd = escapeshellarg($phpBin) . ' -r ' . escapeshellarg($code) . ' 2>&1';
    $output = shell_exec($cmd);

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
