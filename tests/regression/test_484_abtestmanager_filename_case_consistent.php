<?php
/**
 * Régression round 249 (31/08/2026) : le nom de fichier `src/ABTestManager.php`
 * (casse canonique, celle du fichier réel sur disque et suivie par git) était
 * référencé avec une casse INCOHÉRENTE ailleurs dans le code : `neria.php`
 * et la plupart de HealthCheckManager.php/des tests utilisaient bien
 * 'ABTestManager.php' (AB majuscules), mais `HealthCheckManager.php` (1
 * site) et 6 fichiers de tests de régression (`test_215`, `test_216`,
 * `test_225`, `test_349`, `test_374`, `test_446`) utilisaient
 * une casse avec seulement le premier A en majuscule (pas AB).
 *
 * Windows (environnement de développement/test utilisé pour toute la série
 * de rounds) est INSENSIBLE à la casse des noms de fichier — cette
 * incohérence est restée invisible pendant 69 rounds. Sur le serveur de
 * production (Linux/O2switch, système de fichiers CASSE-SENSIBLE),
 * `require_once` avec la mauvaise casse aurait provoqué une ERREUR FATALE
 * (test_349, test_374), et `file_get_contents` un échec silencieux
 * (les autres) — découvert lors d'une vérification de synchronisation
 * Desktop/Laragon/D:\/GitHub, pas par la chasse aux bugs habituelle.
 *
 * Corrigé le 31/08/2026 (round 249) : toutes les occurrences alignées sur
 * la casse canonique 'ABTestManager.php'.
 *
 * Test structurel exhaustif : balaie TOUS les fichiers src/*.php et
 * tests/regression/*.php (pas seulement les 7 fichiers connus au moment du
 * correctif) et vérifie qu'aucun ne contient la mauvaise casse — protège
 * aussi contre une RÉINTRODUCTION future dans un fichier non encore
 * concerné aujourd'hui.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // Construit par concaténation : la chaîne recherchée ne doit PAS
    // apparaître littéralement, telle quelle, dans ce fichier de test
    // lui-même (qui fait pourtant partie de tests/regression/*.php balayé
    // ci-dessous) — même piège d'auto-référence que le garde-fou Watchdog
    // correspondant dans HealthCheckManager.php (round 246/249).
    $wrongCase = 'A' . 'b' . 'TestManager.php';

    $moduleDir = _PS_MODULE_DIR_ . 'neria/';
    $patterns  = [
        $moduleDir . 'src/*.php',
        $moduleDir . 'tests/regression/*.php',
        $moduleDir . 'neria.php',
    ];

    $offenders = [];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            $content = file_get_contents($file);
            if ($content !== false && strpos($content, $wrongCase) !== false) {
                $offenders[] = basename($file);
            }
        }
    }

    neria_assert(
        empty($offenders),
        'Fichier(s) référençant encore le nom de fichier ABTestManager.php avec un \'b\' minuscule au lieu de \'B\' majuscule (mauvaise casse, cassante sur Linux casse-sensible) : ' . implode(', ', $offenders)
    );

    return [
        'pass'    => true,
        'message' => "Aucun fichier de src/*.php, tests/regression/*.php ou neria.php ne référence plus le nom de fichier ABTestManager.php avec une casse incorrecte — bug corrigé le 31/08/2026 (round 249), invisible sous Windows mais fatal sur Linux (production)",
    ];
}
