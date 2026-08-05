<?php
/**
 * Génère un fichier de stubs PHPStan pour les alias de classes PrestaShop
 * (Tools -> ToolsCore, Db -> DbCore, Configuration -> ConfigurationCore...).
 * PrestaShop crée ces alias dynamiquement à l'exécution (class_alias() ou
 * équivalent) — aucun fichier PHP statique ne définit "class Tools", donc
 * les outils d'analyse statique ne les voient pas sans ce stub généré.
 *
 * Usage : php generate-ps-stubs.php <chemin class_index.php> <fichier sortie>
 */
$indexPath = $argv[1] ?? null;
$outPath   = $argv[2] ?? null;

if (!$indexPath || !$outPath || !is_file($indexPath)) {
    fwrite(STDERR, "Usage: php generate-ps-stubs.php <class_index.php> <output.php>\n");
    exit(1);
}

$index = require $indexPath;
if (!is_array($index)) {
    fwrite(STDERR, "class_index.php n'a pas retourné un tableau\n");
    exit(1);
}

$lines = ["<?php", "// Fichier généré automatiquement — ne pas éditer à la main.", "// Régénérer via bin/phpstan/generate-ps-stubs.php", ""];
$count = 0;

foreach ($index as $className => $meta) {
    if (!is_array($meta) || ($meta['path'] ?? null) !== null) {
        continue; // seules les entrées "alias sans fichier propre" nous intéressent
    }
    $coreClass = $className . 'Core';
    if (!isset($index[$coreClass])) {
        continue; // pas de classe Core correspondante, on ne peut pas générer l'alias
    }
    $type = $meta['type'] ?? 'class';
    // Les interfaces ne s'aliasent pas via extends
    if (strpos($type, 'interface') !== false) {
        continue;
    }
    $keyword = strpos($type, 'abstract') !== false ? 'abstract class' : 'class';
    // Une classe abstraite ne peut pas être instanciée par du code appelant
    // "new Tools()" (jamais fait dans ce module de toute façon) — on relâche
    // le mot-clé "abstract" pour l'alias si le Core est abstrait mais que la
    // vraie classe PrestaShop, elle, est concrète en pratique (cas rare ici).
    $lines[] = "if (!class_exists('{$className}', false)) { class {$className} extends {$coreClass} {} }";
    $count++;
}

file_put_contents($outPath, implode("\n", $lines) . "\n");
fwrite(STDOUT, "Généré {$count} alias dans {$outPath}\n");
