<?php
/**
 * Met à jour des lignes de tests/functional/inventory/matrix.csv (statut, preuve, notes) sans jamais toucher au reste.
 *
 *   php tests/functional/matrix_update.php --category="Template e-mail" --note="P2a : rendu 19/19 OK" [--append]
 *   php tests/functional/matrix_update.php --id=BO-001,BO-002 --status=✅ --proof="capture ps-test 20/09" --note=...
 *   php tests/functional/matrix_update.php --element="quote_add" --status=🔧 --proof="commit abc123"
 *
 * Filtres : --id (liste), --category (texte exact), --element (sous-chaîne), --phase (sous-chaîne).
 * Champs   : --status (⬜ ✅ 🔧 ⚠ ⏭), --proof, --note ; --append ajoute la note à l'existante au lieu de la remplacer.
 * Règles   : ✅/🔧 exigent une preuve, ⏭ une raison en note (sinon refus).
 */
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/su', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$path = isset($opts['file']) ? (string) $opts['file'] : dirname(__DIR__) . '/functional/inventory/matrix.csv'; // --file : travailler sur une copie (tests)
if (!is_file($path)) {
    fwrite(STDERR, "matrix.csv introuvable\n");
    exit(2);
}
$valid = ['⬜', '✅', '🔧', '⚠', '⏭'];
if (isset($opts['status']) && !in_array($opts['status'], $valid, true)) {
    fwrite(STDERR, "statut invalide (attendu : " . implode(' ', $valid) . ")\n");
    exit(2);
}
$ids = isset($opts['id']) ? array_map('trim', explode(',', (string) $opts['id'])) : null;

$fh = fopen($path, 'r');
$rows = [];
while (($r = fgetcsv($fh, 0, ';')) !== false) {
    $rows[] = $r;
}
fclose($fh);
if (isset($rows[0][0])) {
    $rows[0][0] = ltrim($rows[0][0], "\xEF\xBB\xBF");
}
$head = $rows[0];
$col = array_flip($head);
$changed = 0;
for ($i = 1; $i < count($rows); $i++) {
    $r = &$rows[$i];
    if ($ids !== null && !in_array($r[$col['id']], $ids, true)) {
        continue;
    }
    if (isset($opts['category']) && $r[$col['categorie']] !== $opts['category']) {
        continue;
    }
    if (isset($opts['element']) && mb_strpos($r[$col['element']], (string) $opts['element']) === false) {
        continue;
    }
    if (isset($opts['phase']) && mb_strpos($r[$col['phase']], (string) $opts['phase']) === false) {
        continue;
    }
    if ($ids === null && !isset($opts['category']) && !isset($opts['element']) && !isset($opts['phase'])) {
        fwrite(STDERR, "aucun filtre : refus de modifier toute la matrice\n");
        exit(2);
    }
    if (isset($opts['status'])) {
        $r[$col['statut']] = $opts['status'];
    }
    if (isset($opts['proof'])) {
        $r[$col['preuve']] = $opts['proof'];
    }
    if (isset($opts['note'])) {
        $r[$col['notes']] = isset($opts['append']) && trim($r[$col['notes']]) !== '' ? $r[$col['notes']] . ' | ' . $opts['note'] : $opts['note'];
    }
    $st = $r[$col['statut']];
    if (in_array($st, ['✅', '🔧'], true) && trim($r[$col['preuve']]) === '') {
        fwrite(STDERR, "refus : {$r[$col['id']]} passe à {$st} sans preuve (--proof)\n");
        exit(2);
    }
    if ($st === '⏭' && trim($r[$col['notes']]) === '') {
        fwrite(STDERR, "refus : {$r[$col['id']]} passe à ⏭ sans raison (--note)\n");
        exit(2);
    }
    $changed++;
}
unset($r); // la référence de la boucle for ne doit PAS survivre au foreach d'écriture ci-dessous (sinon la dernière ligne est écrasée)
$out = fopen($path, 'w');
fwrite($out, "\xEF\xBB\xBF");
foreach ($rows as $r) {
    fputcsv($out, $r, ';');
}
fclose($out);
echo "{$changed} ligne(s) mise(s) à jour\n";
