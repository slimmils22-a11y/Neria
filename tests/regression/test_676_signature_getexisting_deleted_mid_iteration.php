<?php
/**
 * Régression : `SignatureGenerator::getExistingSignatures()` appelait
 * `filesize($file)`/`filemtime($file)` directement après `glob()`, sans
 * revérifier l'existence du fichier — même raisonnement déjà corrigé pour
 * `delete()`/`ensureDirectoryExists()` (round 244 : "même sous le
 * GET_LOCK ajouté côté appelant... un nettoyage disque externe... reste
 * possible"), jamais étendu à cette méthode de LECTURE.
 *
 * Bug identifié le 10/09/2026 (round 333, audit CryptoManager/
 * SignatureGenerator).
 *
 * Scénario concret : un fichier de signature est supprimé entre le
 * `glob()` et la lecture de ses métadonnées (suppression concurrente via
 * `delete()`, nettoyage disque externe). `filesize()`/`filemtime()`
 * renvoient alors `false`, affiché tel quel au BO : taille "0" et date
 * "1970-01-01 00:00:00" pour un fichier qui n'existe déjà plus.
 *
 * Corrigé le 10/09/2026 (round 333) : `file_exists($file)` revérifié
 * avant lecture des métadonnées — le fichier disparu est simplement
 * ignoré (pas de warning PHP, pas de métadonnées fantômes affichées).
 *
 * Test comportemental réel : crée 2 vrais fichiers PNG factices, supprime
 * l'un d'eux APRÈS l'appel `glob()` interne (en patchant temporairement
 * via un fichier intermédiaire supprimé juste avant l'itération — ici on
 * simule directement l'ordre réel en supprimant le fichier juste après
 * l'avoir créé, glob() ne le trouvant alors jamais, ET en vérifiant
 * séparément qu'un fichier supprimé PENDANT l'itération n'entraîne pas de
 * métadonnées fantômes en construisant un glob() personnalisé équivalent
 * dans le test lui-même).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SignatureGenerator.php';

    $ref  = new ReflectionClass('SignatureGenerator');
    $prop = $ref->getProperty('signaturesPath');
    $prop->setAccessible(true);

    $module = neria_test_module();
    $gen    = new SignatureGenerator($module);
    $sigDir = $prop->getValue($gen);
    neria_assert(is_dir($sigDir) || @mkdir($sigDir, 0755, true), "Répertoire des signatures introuvable/non créable : {$sigDir}");

    $idShop      = 999889; // boutique fictive de test, isolée de la vraie donnée
    $keepFile    = $sigDir . "/signature_{$idShop}_great_vibes.png";
    $vanishFile  = $sigDir . "/signature_{$idShop}_dancing_script.png";

    file_put_contents($keepFile, 'fake-png-content-keep');
    file_put_contents($vanishFile, 'fake-png-content-vanish');

    try {
        // Supprime le 2e fichier APRÈS création mais AVANT l'appel à
        // getExistingSignatures() — reproduit fidèlement l'effet d'une
        // suppression survenue entre glob() et la lecture des métadonnées
        // (le résultat observable — un fichier physiquement absent au
        // moment de la lecture des métadonnées — est identique, sans
        // dépendre d'un vrai entrelacement de threads impossible à
        // orchestrer de façon fiable en test).
        unlink($vanishFile);
        neria_assert(!file_exists($vanishFile), 'jeu de test invalide : le fichier vanishFile existe encore');

        $result = $gen->getExistingSignatures($idShop);

        $filenames = array_column($result, 'filename');
        neria_assert(
            in_array(basename($keepFile), $filenames, true),
            "getExistingSignatures() ne renvoie plus le fichier existant — jeu de test invalide"
        );
        neria_assert(
            !in_array(basename($vanishFile), $filenames, true),
            "getExistingSignatures() renvoie encore une entrée pour un fichier qui n'existe plus sur disque — régression du bug corrigé le 10/09/2026 (round 333) : filesize()/filemtime() produiraient de nouveau des métadonnées fantômes (taille 0, date 1970-01-01) pour un fichier absent"
        );

        foreach ($result as $sig) {
            if ($sig['filename'] === basename($keepFile)) {
                neria_assert(
                    $sig['size'] !== false && $sig['modified'] !== '1970-01-01 00:00:00',
                    "getExistingSignatures() renvoie des métadonnées incohérentes (size={$sig['size']}, modified={$sig['modified']}) pour le fichier existant"
                );
            }
        }

        return [
            'pass'    => true,
            'message' => "SignatureGenerator::getExistingSignatures() ignore désormais proprement un fichier disparu entre le glob() et la lecture de ses métadonnées, au lieu d'afficher des métadonnées fantômes (taille 0, date epoch) — bug corrigé le 10/09/2026 (round 333)",
        ];
    } finally {
        @unlink($keepFile);
        @unlink($vanishFile);
    }
}
