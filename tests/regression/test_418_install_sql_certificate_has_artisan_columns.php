<?php
/**
 * Régression : sql/install.sql (exécuté par install() sur une install
 * VRAIMENT neuve) définissait neria_certificate SANS artisan_name/region/
 * weaving_duration, alors qu'upgrade-1.0.42.php les ajoute pour les
 * installs déjà existantes — une dérive install.sql vs upgrade-script.
 *
 * Bug réel identifié le 23/08/2026 (round 196) : le garde-fou round-161
 * (needUpgrade()+runUpgradeModule() après install()) ne rattrape ce cas
 * QUE si le module était déjà partiellement installé (tables laissées par
 * une suppression FTP sans désinstallation propre) — sur une install
 * VRAIMENT neuve, needUpgrade() ne trouve rien à faire (ps_module.version
 * déjà à jour) et upgrade-1.0.42.php ne s'exécute jamais. Dès la première
 * émission de certificat, CertificateManager::insert() échouait avec
 * "Unknown column 'artisan_name' in 'field list'".
 *
 * Corrigé le 23/08/2026 (round 196) : les 3 colonnes ajoutées directement
 * à install.sql, dans le même ordre qu'upgrade-1.0.42.php ("AFTER
 * product_name").
 *
 * Test structurel (recréer une install fraîche complète dans le même
 * process qu'une suite de tests serait risqué — DROP/CREATE de TOUTES les
 * tables du module) : vérifie que install.sql définit bien les 3 colonnes
 * sur neria_certificate.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $sql = file_get_contents(_PS_MODULE_DIR_ . 'neria/sql/install.sql');
    neria_assert($sql !== false, 'Impossible de lire sql/install.sql');

    $posTable = strpos($sql, 'CREATE TABLE IF NOT EXISTS `PREFIX_neria_certificate`');
    neria_assert($posTable !== false, 'Table neria_certificate introuvable dans install.sql — jeu de test invalide');

    $posEnd = strpos($sql, ') ENGINE=InnoDB', $posTable);
    neria_assert($posEnd !== false, 'Fin de la définition de neria_certificate introuvable — jeu de test invalide');

    $tableDef = substr($sql, $posTable, $posEnd - $posTable);

    foreach (['artisan_name', 'region', 'weaving_duration'] as $column) {
        neria_assert(
            strpos($tableDef, "`{$column}`") !== false,
            "install.sql ne définit plus la colonne `{$column}` sur neria_certificate — régression du bug corrigé le 23/08/2026 (round 196) : une install VRAIMENT neuve (pas une réconciliation post-suppression-FTP) planterait de nouveau à la première émission de certificat avec une erreur SQL 'Unknown column'"
        );
    }

    return [
        'pass'    => true,
        'message' => "install.sql définit bien artisan_name/region/weaving_duration sur neria_certificate, cohérent avec upgrade-1.0.42.php — bug corrigé le 23/08/2026 (round 196)",
    ];
}
