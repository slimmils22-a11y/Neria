<?php
/**
 * Constat F-004 (campagne de tests fonctionnels, 20/09/2026) : le module annonce PrestaShop >= 8.0 (PHP 7.4 possible)
 * mais utilisait `match` (9 fois), `?->` (2 fois), un argument nommé et array_is_list() → erreur fatale au chargement.
 * (1) Le détecteur (lexeur PHP) repère chacune de ces constructions dans un faux module, ignore commentaires et chaînes.
 * (2) Le vrai module n'en contient plus. (3) Si un PHP 7.4 est disponible (C:\tmp\php74\php.exe ou NERIA_PHP74),
 *     `php -l` valide réellement chaque fichier. (4) Les fonctions réécrites rendent les mêmes valeurs qu'avant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'HealthCheckManager', 'DomainReputationManager'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }
    if (!defined('T_MATCH')) {
        return ['pass' => true, 'message' => 'PHP < 8 : le lexeur ne connaît pas ces jetons, contrôle sans objet (php -l suffit)'];
    }
    $name = 'regtest820';
    $root = _PS_MODULE_DIR_ . $name;
    @mkdir($root . '/src', 0777, true);
    $fake = clone neria_test_module();
    $fake->name = $name;
    $m = new ReflectionMethod(HealthCheckManager::class, 'findPhp8OnlySyntax');
    $m->setAccessible(true);
    $hm = new HealthCheckManager($fake);
    try {
        file_put_contents($root . '/src/Ok.php', "<?php\n// match (\$x) { } et \$a?->b dans un commentaire\n\$s = 'match (1) {} ?-> array_is_list';\nfunction f(\$t){ return preg_match('/a/', \$t); }\n");
        neria_assert($m->invoke($hm, $root) === [], 'faux positif : commentaires/chaînes/preg_match signalés');
        file_put_contents($root . '/src/Bad.php', "<?php\n\$a = match (\$x) { 1 => 'a', default => 'b' };\n\$b = \$o?->p;\n\$c = array_is_list(\$z);\n");
        $r = $m->invoke($hm, $root);
        neria_assert(count($r) === 3 && strpos(implode(',', $r), '(match)') !== false && strpos(implode(',', $r), '(?->)') !== false && strpos(implode(',', $r), 'array_is_list') !== false, 'construction PHP 8 non détectée : ' . implode(',', $r));
    } finally {
        @unlink($root . '/src/Ok.php');
        @unlink($root . '/src/Bad.php');
        @rmdir($root . '/src');
        @rmdir($root);
    }
    $real = $m->invoke(new HealthCheckManager(neria_test_module()), _PS_MODULE_DIR_ . 'neria');
    neria_assert($real === [], 'syntaxe PHP 8+ dans le module : ' . implode(', ', $real));

    $lint = getenv('NERIA_PHP74') ?: 'C:/tmp/php74/php.exe';
    $linted = 0;
    if (is_file($lint)) {
        $dir = _PS_MODULE_DIR_ . 'neria';
        foreach (array_merge(glob($dir . '/*.php'), glob($dir . '/src/*.php'), glob($dir . '/controllers/*/*.php'), glob($dir . '/upgrade/*.php')) as $f) {
            $out = [];
            exec(escapeshellarg($lint) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
            neria_assert($rc === 0, 'php 7.4 -l échoue sur ' . basename($f) . ' : ' . implode(' ', $out));
            $linted++;
        }
    }

    $d = new DomainReputationManager(neria_test_module());
    neria_assert([$d->computeGrade(95), $d->computeGrade(75), $d->computeGrade(50), $d->computeGrade(25), $d->computeGrade(24)] === ['A', 'B', 'C', 'D', 'F'], 'computeGrade() modifié');
    neria_assert($d->gradeColor('A') === '#1a7a40' && $d->gradeColor('F') === '#7b241c' && $d->gradeColor('?') === '#888', 'gradeColor() modifié');
    return ['pass' => true, 'message' => "aucune syntaxe PHP 8+ (match, ?->, array_is_list) dans le module, détecteur validé sur faux module, {$linted} fichier(s) validés par php 7.4 -l, notes de délivrabilité inchangées"];
}
