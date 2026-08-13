<?php
/**
 * Régression : neria.php::uninstall() ne nettoyait explicitement que
 * var/cache/neria_previews/ (hors modules/neria/). Les dossiers
 * certificates/ et mails/ (PDF nominatifs — nom/adresse client, cf.
 * CertificateManager/MonthlyReportManager), eux À L'INTÉRIEUR de
 * modules/neria/, ne survivaient QUE si le marchand décochait « supprimer
 * les fichiers du module » — cas où ils restaient indéfiniment sur le
 * disque, orphelins de toute ligne DB (tables droppées par uninstall.sql).
 *
 * Corrigé le 13/08/2026 (round 161) : nouvelle méthode privée
 * cleanupNominativeFiles(), appelée pour 'certificates' et 'mails' dans
 * uninstall(), indépendamment du choix "supprimer les fichiers".
 *
 * Test réel : crée un faux PDF dans chacun des 2 dossiers via l'API
 * publique cleanupNominativeFiles() (accessible par Reflection, méthode
 * privée), vérifie qu'il est bien supprimé et que le dossier lui-même
 * (pouvant contenir des fichiers non gérés par Neria) est préservé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();

    $ref = new ReflectionMethod($module, 'cleanupNominativeFiles');
    $ref->setAccessible(true);

    $dirs = [
        'certificates' => _PS_MODULE_DIR_ . 'neria/certificates/',
        'mails'        => _PS_MODULE_DIR_ . 'neria/mails/',
    ];

    $testFiles = [];
    foreach ($dirs as $subdir => $dir) {
        neria_assert(is_dir($dir), "Le dossier {$dir} n'existe pas — jeu de test invalide");
        $f = $dir . 'test_round161_' . uniqid() . '.pdf';
        neria_assert(file_put_contents($f, '%PDF-1.4 fake test file') !== false, "Impossible de créer le fichier de test {$f}");
        $testFiles[$subdir] = $f;
    }

    try {
        foreach ($dirs as $subdir => $dir) {
            $ref->invoke($module, $subdir, '*.pdf');
        }

        foreach ($testFiles as $subdir => $f) {
            neria_assert(
                !file_exists($f),
                "cleanupNominativeFiles('{$subdir}', '*.pdf') n'a pas supprimé le fichier de test — régression du bug corrigé le 13/08/2026 (round 161) : un PDF nominatif survivrait à la désinstallation"
            );
        }

        foreach ($dirs as $subdir => $dir) {
            neria_assert(is_dir($dir), "cleanupNominativeFiles() a supprimé le dossier {$subdir}/ lui-même — ne devrait supprimer que les fichiers correspondant au motif");
        }
    } finally {
        foreach ($testFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');
    neria_assert(
        strpos($src, "cleanupNominativeFiles('certificates'") !== false && strpos($src, "cleanupNominativeFiles('mails'") !== false,
        "uninstall() n'appelle plus cleanupNominativeFiles() pour 'certificates' et 'mails' — régression du bug corrigé le 13/08/2026 (round 161)"
    );

    return [
        'pass'    => true,
        'message' => "uninstall() nettoie bien les PDF nominatifs de certificates/ et mails/ indépendamment de l'option 'supprimer les fichiers' — bug corrigé le 13/08/2026 (round 161)",
    ];
}
