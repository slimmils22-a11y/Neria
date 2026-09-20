<?php
/**
 * P1 (campagne de tests fonctionnels exhaustifs, 20/09/2026) — la matrice de suivi
 * (tests/functional/inventory/matrix.csv) doit rester alignée sur le code : toute fonctionnalité ajoutée
 * (action du back-office, bouton, template d'e-mail, tâche planifiée, hook, contrôleur front, contrôle
 * Watchdog, interrupteur du Centre de contrôle, table SQL) sans ligne correspondante fait échouer ce test,
 * et toute ligne dont l'élément a disparu du code aussi. Ainsi rien n'échappe silencieusement à la campagne.
 *
 * Régénération après un ajout légitime : `php tests/functional/build_inventory.php` (écrit matrix.fresh.csv à
 * côté de matrix.csv sans écraser les résultats) puis reporter les nouvelles lignes dans matrix.csv.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $dir = str_replace('\\', '/', _PS_MODULE_DIR_ . 'neria');
    $script = $dir . '/tests/functional/check_matrix.php';
    neria_assert(is_file($script), "check_matrix.php introuvable");
    neria_assert(is_file($dir . '/tests/functional/inventory/matrix.csv'), "matrix.csv introuvable");

    $out = [];
    $rc  = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
    $text = implode("\n", $out);
    neria_assert($rc === 0, "la matrice de tests fonctionnels n'est plus alignée sur le code :\n" . mb_substr($text, -1500));
    neria_assert(preg_match('/Matrice : (\d+) lignes/', $text, $m) === 1 && (int) $m[1] >= 800, "nombre de lignes de matrice inattendu : " . mb_substr($text, 0, 200));

    return ['pass' => true, 'message' => "la matrice de la campagne de tests fonctionnels ({$m[1]} lignes) couvre toutes les fonctionnalités du code, sans ligne orpheline"];
}
