<?php
/**
 * Outil de la campagne de tests fonctionnels : tests/functional/matrix_update.php.
 * Bug trouvé le 20/09/2026 en l'utilisant : une référence PHP (`$r = &$rows[$i]`) survivait à la boucle for et,
 * au foreach d'écriture suivant, écrasait la DERNIÈRE ligne de matrix.csv (une table SQL disparaissait sans erreur).
 * Ce test travaille sur une COPIE de la matrice : aucune ligne perdue ni dupliquée, notes appliquées aux seules
 * lignes ciblées, refus d'un ✅ sans preuve, d'un ⏭ sans raison et d'une mise à jour sans filtre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir  = str_replace('\\', '/', _PS_MODULE_DIR_ . 'neria');
    $src  = $dir . '/tests/functional/inventory/matrix.csv';
    $tool = $dir . '/tests/functional/matrix_update.php';
    neria_assert(is_file($src) && is_file($tool), "matrice ou outil introuvable");
    $tmp = sys_get_temp_dir() . '/neria_matrix_test_' . getmypid() . '.csv';
    copy($src, $tmp);
    $run = static function (string $args) use ($tool, $tmp): array {
        $out = [];
        $rc = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --file=' . escapeshellarg($tmp) . ' ' . $args . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    };
    $load = static function (string $f): array {
        $rows = [];
        $fh = fopen($f, 'r');
        while (($r = fgetcsv($fh, 0, ';')) !== false) {
            $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    };
    try {
        $before = $load($tmp);
        [$rc, $o] = $run('--category="Table SQL" --note="essai" --append');
        neria_assert($rc === 0, "mise à jour légitime refusée : {$o}");
        $after = $load($tmp);
        neria_assert(count($after) === count($before), "nombre de lignes modifié : " . count($before) . " → " . count($after));
        $keys = static fn (array $rows): array => array_map(static fn ($r) => $r[1] . '|' . $r[2], array_slice($rows, 1));
        neria_assert($keys($after) === $keys($before), "les lignes (catégorie|élément) ne sont plus identiques après mise à jour — ligne perdue ou dupliquée");
        $changedTables = 0;
        foreach (array_slice($after, 1) as $i => $r) {
            $isSql = $r[1] === 'Table SQL';
            neria_assert($isSql === (strpos($r[8], 'essai') !== false), "note appliquée à une mauvaise ligne : {$r[0]} {$r[1]}");
            $changedTables += $isSql ? 1 : 0;
        }
        neria_assert($changedTables >= 30, "trop peu de tables mises à jour ({$changedTables})");
        $lastBefore = end($before);
        $lastAfter = end($after);
        neria_assert($lastBefore[0] === $lastAfter[0] && $lastBefore[2] === $lastAfter[2], "la dernière ligne a été écrasée : {$lastBefore[0]} devient {$lastAfter[0]}");

        [$rc1] = $run('--category="Table SQL" --status=✅');
        [$rc2] = $run('--category="Table SQL" --status=⏭');
        [$rc3] = $run('--status=✅ --proof=x');
        [$rc4] = $run('--category="Table SQL" --status=BOF --proof=x');
        neria_assert($rc1 !== 0, "✅ sans preuve accepté");
        neria_assert($rc3 !== 0, "mise à jour sans filtre acceptée");
        neria_assert($rc4 !== 0, "statut invalide accepté");
        [$rc5] = $run('--id=DB-001 --status=✅ --proof="test"');
        neria_assert($rc5 === 0, "✅ avec preuve refusé");
        neria_assert($load($tmp)[array_search('DB-001', array_column($load($tmp), 0))][6] === '✅', "statut ✅ non appliqué à DB-001");
        neria_assert(count($load($tmp)) === count($before), "nombre de lignes modifié après changement de statut");
    } finally {
        @unlink($tmp);
    }
    return ['pass' => true, 'message' => "matrix_update.php préserve toutes les lignes, cible uniquement les lignes filtrées et refuse ✅ sans preuve, ⏭ sans raison, sans filtre et les statuts invalides"];
}
