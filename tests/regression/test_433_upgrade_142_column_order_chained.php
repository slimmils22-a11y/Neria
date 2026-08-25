<?php
/**
 * Régression : upgrade-1.0.42.php ajoutait les 3 colonnes
 * artisan_name/region/weaving_duration à neria_certificate en utilisant
 * "AFTER product_name" pour LES TROIS ALTER TABLE, au lieu de chaîner
 * chaque colonne après la précédente.
 *
 * Bug réel identifié le 24/08/2026 (round 204) : comme les 3 ALTER TABLE
 * s'exécutent tous avec "AFTER product_name", chaque nouvelle colonne est
 * insérée physiquement juste après product_name, repoussant la
 * précédente — l'ordre physique final obtenu par upgrade successif
 * (weaving_duration, region, artisan_name) était l'INVERSE de celui
 * d'une install fraîche via install.sql (artisan_name, region,
 * weaving_duration). Sans impact fonctionnel tant que le code accède aux
 * colonnes par nom (c'est le cas), mais une divergence de schéma
 * silencieuse entre install fraîche et upgrade successif, contraire à
 * l'effort de convergence explicite déjà documenté dans install.sql
 * (round 196).
 *
 * Corrigé le 24/08/2026 (round 204) : chaque colonne est désormais ajoutée
 * AFTER la précédente de la liste (artisan_name AFTER product_name,
 * region AFTER artisan_name, weaving_duration AFTER region), produisant
 * le même ordre physique qu'une install fraîche.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.42.php');
    neria_assert($src !== false, 'Impossible de lire upgrade/upgrade-1.0.42.php');

    neria_assert(
        strpos($src, "'artisan_name'     => ['def' => 'VARCHAR(255) DEFAULT NULL', 'after' => 'product_name']") !== false,
        "upgrade-1.0.42.php n'ajoute plus artisan_name juste après product_name — régression du bug corrigé le 24/08/2026 (round 204)"
    );
    neria_assert(
        strpos($src, "'region'           => ['def' => 'VARCHAR(255) DEFAULT NULL', 'after' => 'artisan_name']") !== false,
        "upgrade-1.0.42.php ne chaîne plus region après artisan_name — régression du bug corrigé le 24/08/2026 (round 204) : l'ordre physique divergerait de nouveau entre install fraîche et upgrade"
    );
    neria_assert(
        strpos($src, "'weaving_duration' => ['def' => 'VARCHAR(255) DEFAULT NULL', 'after' => 'region']") !== false,
        "upgrade-1.0.42.php ne chaîne plus weaving_duration après region — régression du bug corrigé le 24/08/2026 (round 204)"
    );
    neria_assert(
        strpos($src, "ALTER TABLE `{\$table}` ADD COLUMN `{\$column}` {\$spec['def']} AFTER `{\$spec['after']}`") !== false,
        "upgrade-1.0.42.php n'utilise plus \$spec['after'] dynamique dans la clause ALTER TABLE — régression structurelle"
    );

    // Cohérence avec install.sql : même ordre déclaré.
    $installSql = file_get_contents(_PS_MODULE_DIR_ . 'neria/sql/install.sql');
    $posArtisan = strpos($installSql, '`artisan_name`');
    $posRegion  = strpos($installSql, '`region`');
    $posDuration = strpos($installSql, '`weaving_duration`');
    neria_assert(
        $posArtisan !== false && $posRegion !== false && $posDuration !== false
        && $posArtisan < $posRegion && $posRegion < $posDuration,
        "install.sql ne déclare plus artisan_name/region/weaving_duration dans cet ordre — jeu de test à adapter si l'ordre a changé intentionnellement"
    );

    return [
        'pass'    => true,
        'message' => "upgrade-1.0.42.php chaîne bien les clauses AFTER pour neria_certificate, produisant le même ordre physique de colonnes qu'une install fraîche — bug corrigé le 24/08/2026 (round 204)",
    ];
}
