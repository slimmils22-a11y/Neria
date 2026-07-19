<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Smoke test global — appelle automatiquement (via réflexion) chaque
 * méthode "de lecture" (get, list, count, audit, is, has, find) de chaque
 * Manager de src/, sans argument, et signale toute exception SQL ou PHP.
 *
 * Objectif : détecter une requête SQL cassée (colonne renommée, jointure
 * invalide, typo) qu'un test manuel n'a jamais déclenchée — pas remplacer
 * les tests fonctionnels ciblés, les compléter en largeur.
 *
 * Exclut délibérément toute méthode au nom évoquant une écriture/un envoi
 * (send/delete/purge/disconnect/encrypt/save/update/create/register/
 * trigger/enqueue/process/mark/increment/reset/import/install/uninstall/
 * upgrade/repair/recompute/notify/issue/redownload/handle) — un smoke test
 * ne doit jamais modifier l'état réel du système.
 *
 * Usage : copier à la racine PS, exécuter en CLI ou HTTP avec le chemin
 * racine en argument :
 *   php smoke_test.php /chemin/vers/prestashop
 *
 * Limite connue : ne couvre que les méthodes SANS paramètre obligatoire
 * (les getters simples). Les méthodes nécessitant un ID réel (ex.
 * getCustomerScore(int $id)) ne sont pas appelées — restent couvertes par
 * les tests manuels ciblés existants (voir tests/regression/).
 */

$root = $argv[1] ?? getcwd();
require $root . '/config/config.inc.php';
require $root . '/modules/neria/neria.php';

$employee = new Employee(1);
Context::getContext()->employee = $employee;
Context::getContext()->smarty->assign('token', 'smoke');

$module = new Neria();

$WRITE_VERBS = [
    'send', 'delete', 'purge', 'disconnect', 'encrypt', 'save', 'update',
    'create', 'register', 'trigger', 'enqueue', 'process', 'mark', 'increment',
    'reset', 'import', 'install', 'uninstall', 'upgrade', 'repair', 'recompute',
    'notify', 'issue', 'redownload', 'handle', 'add', 'remove', 'disable',
    'enable', 'set', 'apply', 'run', 'sync', 'generate', 'build', 'schedule',
    'clean', 'purgeCustomerData', 'insert', 'write', 'store', 'cache',
];

function isReadOnlyMethod(string $name, array $writeVerbs): bool
{
    // Autorise explicitement les préfixes de lecture connus…
    $readPrefixes = ['get', 'list', 'count', 'audit', 'is', 'has', 'find', 'check'];
    $isReadPrefixed = false;
    foreach ($readPrefixes as $p) {
        if (stripos($name, $p) === 0) {
            $isReadPrefixed = true;
            break;
        }
    }
    if (!$isReadPrefixed) {
        return false;
    }
    // …puis exclut si un verbe d'écriture apparaît n'importe où dans le nom
    // (ex. "checkBounceMailbox" contient "check" en préfixe mais ouvre une
    // vraie connexion réseau — pas un simple getter, à exclure quand même).
    $lower = strtolower($name);
    foreach ($writeVerbs as $v) {
        if (strpos($lower, strtolower($v)) !== false) {
            return false;
        }
    }
    // Exclusions ad hoc connues (réseau/coûteux malgré un nom "read-only")
    $denylist = ['checkbouncemailbox', 'testimapconnection', 'runcheck', 'runaudit', 'runfullcheck'];
    if (in_array(strtolower($name), $denylist, true)) {
        return false;
    }
    return true;
}

$srcDir = $root . '/modules/neria/src';
$files = glob($srcDir . '/*.php');

$total = 0;
$ok = 0;
$skipped = 0;
$failures = [];

foreach ($files as $file) {
    $className = basename($file, '.php');
    if ($className === 'index') {
        continue; // stub anti-listing (die()), pas une classe — class_exists()
                   // avec autoload=true l'exécuterait et tuerait le script
    }
    fwrite(STDERR, "=== FILE: {$className} ===" . PHP_EOL);
    if (!class_exists($className)) {
        fwrite(STDERR, "  (pas de classe, ignoré)" . PHP_EOL);
        continue;
    }
    $ref = new ReflectionClass($className);
    if ($ref->isAbstract() || $ref->isInterface()) {
        continue;
    }

    // Instanciation : essaie (module), puis (modulePath en string), puis ()
    $instance = null;
    foreach ([[$module], [$root . '/modules/neria'], []] as $args) {
        try {
            $instance = $ref->newInstanceArgs($args);
            break;
        } catch (\Throwable $e) {
            continue;
        }
    }
    if ($instance === null) {
        continue; // constructeur non supporté par ce harnais, pas un échec
    }

    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || $method->isAbstract() || $method->getDeclaringClass()->getName() !== $className) {
            continue;
        }
        $name = $method->getName();
        if (!isReadOnlyMethod($name, $WRITE_VERBS)) {
            continue;
        }
        // N'appelle que les méthodes sans paramètre obligatoire
        $hasRequiredParam = false;
        foreach ($method->getParameters() as $p) {
            if (!$p->isOptional()) {
                $hasRequiredParam = true;
                break;
            }
        }
        if ($hasRequiredParam) {
            $skipped++;
            continue;
        }

        $total++;
        fwrite(STDERR, "→ {$className}::{$name}()" . PHP_EOL);
        try {
            $method->invoke($instance);
            $ok++;
        } catch (\Throwable $e) {
            $failures[] = [
                'class'  => $className,
                'method' => $name,
                'error'  => $e->getMessage(),
                'file'   => $e->getFile() . ':' . $e->getLine(),
            ];
        }
    }
}

echo "=== SMOKE TEST NERIA ===" . PHP_EOL;
echo "Méthodes appelées : {$total}" . PHP_EOL;
echo "OK               : {$ok}" . PHP_EOL;
echo "Échecs           : " . count($failures) . PHP_EOL;
echo "Ignorées (paramètre requis) : {$skipped}" . PHP_EOL;
echo PHP_EOL;

if (!empty($failures)) {
    echo "=== ÉCHECS ===" . PHP_EOL;
    foreach ($failures as $f) {
        echo "{$f['class']}::{$f['method']}() → {$f['error']} @ {$f['file']}" . PHP_EOL;
    }
}
