<?php
/**
 * © 2026 Neria.software - All rights reserved
 *
 * Diagnostic de compatibilité — signatures des méthodes de base des
 * contrôleurs front/admin étendus par Neria (ModuleFrontController,
 * ModuleAdminController). Voir CARTOGRAPHY.md, axe 13.
 *
 * Usage identique à ps_core_diff.php.
 *
 * Dernier scan complet : 2026-07-19, PS8 8.1.7 vs PS9 9.0.2 → aucune
 * différence. init/initContent/postProcess identiques sur les deux
 * versions pour les deux classes de base.
 */
require_once __DIR__ . '/config/config.inc.php';

$pairs = [
    ['ModuleFrontController', 'init'],
    ['ModuleFrontController', 'initContent'],
    ['ModuleFrontController', 'postProcess'],
    ['ModuleAdminController', 'init'],
    ['ModuleAdminController', 'initContent'],
    ['ModuleAdminController', 'postProcess'],
];

echo '=== VERSION: ' . (defined('_PS_VERSION_') ? _PS_VERSION_ : 'unknown') . ' ===' . PHP_EOL;

foreach ($pairs as [$class, $method]) {
    if (!class_exists($class)) {
        echo "MISSING_CLASS\t{$class}::{$method}" . PHP_EOL;
        continue;
    }
    if (!method_exists($class, $method)) {
        echo "MISSING_METHOD\t{$class}::{$method}" . PHP_EOL;
        continue;
    }
    $ref = new ReflectionMethod($class, $method);
    $params = [];
    foreach ($ref->getParameters() as $p) {
        $type = $p->getType() ? (string) $p->getType() : '?';
        $params[] = $type . ' $' . $p->getName() . ($p->isOptional() ? '=default' : '');
    }
    $returnType = $ref->getReturnType() ? (string) $ref->getReturnType() : '?';
    echo "OK\t{$class}::{$method}\t(" . implode(', ', $params) . ")\t: {$returnType}" . PHP_EOL;
}
