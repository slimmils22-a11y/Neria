<?php
/**
 * Vérificateur de couverture de la campagne de tests fonctionnels (P1).
 *
 *   php tests/functional/check_matrix.php            # écarts + avancement par phase
 *   php tests/functional/check_matrix.php --strict   # échoue aussi tant qu'une ligne reste ⬜ (clôture de phase)
 *
 * Régénère un inventaire dans un dossier temporaire (rien n'est écrasé) puis le compare à
 * tests/functional/inventory/matrix.csv :
 *   - éléments NOUVEAUX dans le code et absents de la matrice (fonctionnalité ajoutée sans être testée) ;
 *   - éléments de la matrice qui n'existent plus dans le code (à retirer ou renommer) ;
 *   - statuts invalides, et lignes ✅ sans preuve ;
 *   - avancement par phase et par statut.
 * Statuts : ⬜ à tester · ✅ OK · 🔧 corrigé · ⚠ à arbitrer (attend une décision) · ⏭ non testable (raison en notes).
 */
$root = str_replace('\\', '/', dirname(__DIR__, 2));
$strict = in_array('--strict', $argv ?? [], true);
$matrix = $root . '/tests/functional/inventory/matrix.csv';
if (!is_file($matrix)) {
    fwrite(STDERR, "matrix.csv introuvable — lancez d'abord build_inventory.php\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . '/neria_inv_' . getmypid();
@mkdir($tmp, 0777, true);
putenv('NERIA_INVENTORY_OUT=' . $tmp);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build_inventory.php') . ' 2>&1';
$out = [];
$rc = 0;
exec($cmd, $out, $rc);
if ($rc !== 0 || !is_file($tmp . '/matrix.csv')) {
    fwrite(STDERR, "régénération de l'inventaire échouée :\n" . implode("\n", array_slice($out, -10)) . "\n");
    exit(2);
}

$load = static function (string $path): array {
    $rows = [];
    $fh = fopen($path, 'r');
    $head = fgetcsv($fh, 0, ';');
    if (isset($head[0])) {
        $head[0] = ltrim($head[0], "\xEF\xBB\xBF");
    }
    while (($r = fgetcsv($fh, 0, ';')) !== false) {
        if (count($r) < count($head)) {
            continue;
        }
        $row = array_combine($head, array_slice($r, 0, count($head)));
        $rows[$row['categorie'] . '|' . $row['element']] = $row;
    }
    fclose($fh);
    return $rows;
};
$current = $load($matrix);
$fresh   = $load($tmp . '/matrix.csv');

$new     = array_diff_key($fresh, $current);
$removed = array_diff_key($current, $fresh);
$valid   = ['⬜', '✅', '🔧', '⚠', '⏭'];
$bad     = [];
$noProof = [];
$byPhase = [];
$byStatus = array_fill_keys($valid, 0);
foreach ($current as $k => $r) {
    $st = trim($r['statut']);
    if (!in_array($st, $valid, true)) {
        $bad[] = $k . ' (« ' . $st . ' »)';
        continue;
    }
    $byStatus[$st]++;
    $ph = $r['phase'];
    $byPhase[$ph][$st] = ($byPhase[$ph][$st] ?? 0) + 1;
    if (in_array($st, ['✅', '🔧'], true) && trim($r['preuve']) === '') {
        $noProof[] = $r['id'] . ' ' . $r['element'];
    }
    if ($st === '⏭' && trim($r['notes']) === '') {
        $noProof[] = $r['id'] . ' ' . $r['element'] . ' (⏭ sans raison en notes)';
    }
}
@unlink($tmp . '/inventory.json');
@unlink($tmp . '/matrix.csv');
@unlink($tmp . '/INVENTORY.md');
@rmdir($tmp);

$total = count($current);
$done  = $byStatus['✅'] + $byStatus['🔧'] + $byStatus['⏭'];
echo "Matrice : {$total} lignes — ✅ {$byStatus['✅']} · 🔧 {$byStatus['🔧']} · ⚠ {$byStatus['⚠']} · ⏭ {$byStatus['⏭']} · ⬜ {$byStatus['⬜']}  (traitées : {$done}/{$total})\n";
foreach ($byPhase as $ph => $st) {
    $t = array_sum($st);
    echo sprintf("  %-32s %4d lignes — ⬜ %d\n", $ph, $t, $st['⬜'] ?? 0);
}
$fail = false;
if ($new) {
    $fail = true;
    echo "\nNOUVEAUX éléments dans le code, absents de la matrice (" . count($new) . ") :\n";
    foreach (array_slice($new, 0, 40) as $k => $r) {
        echo "  + {$k}\n";
    }
}
if ($removed) {
    $fail = true;
    echo "\nÉléments de la matrice qui n'existent plus dans le code (" . count($removed) . ") :\n";
    foreach (array_slice($removed, 0, 40) as $k => $r) {
        echo "  - {$r['id']} {$k}\n";
    }
}
if ($bad) {
    $fail = true;
    echo "\nStatuts invalides (" . count($bad) . ") : " . implode(' ; ', array_slice($bad, 0, 10)) . "\n";
}
if ($noProof) {
    $fail = true;
    echo "\nLignes closes sans preuve/raison (" . count($noProof) . ") : " . implode(' ; ', array_slice($noProof, 0, 10)) . "\n";
}
if ($strict && $byStatus['⬜'] > 0) {
    $fail = true;
    echo "\n--strict : {$byStatus['⬜']} ligne(s) encore à tester.\n";
}
echo $fail ? "\nÉCART(S) DÉTECTÉ(S)\n" : "\nMatrice cohérente avec le code.\n";
exit($fail ? 1 : 0);
