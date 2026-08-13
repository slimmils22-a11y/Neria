<?php
/**
 * Régression : neria.php appelait SignatureGenerator::delete($idShop) AVANT
 * generate() — si generate() échouait après la suppression (GD indisponible
 * temporairement, police manquante, disque plein), la signature FONCTIONNELLE
 * précédente venait d'être effacée du disque, mais la ligne neria_signature
 * en base (is_active=1, image_path vers ce fichier maintenant inexistant)
 * n'était pas mise à jour — tous les emails envoyés ensuite affichaient une
 * image de signature cassée (404) jusqu'à une régénération réussie, sans
 * que l'admin n'ait d'indication autre qu'un message d'erreur générique.
 *
 * Corrigé le 09/08/2026 (round 160) : delete() s'exécute désormais APRÈS
 * generate() (uniquement si succès), avec le nouveau fichier généré exclu
 * du nettoyage (SignatureGenerator::delete() accepte un 3e paramètre
 * $excludePath).
 *
 * Test structurel (neria.php) : vérifie que generate() précède delete()
 * dans le code source, et que delete() reçoit bien $path en 3e argument.
 * Test comportemental réel (SignatureGenerator::delete()) : crée 2 vrais
 * fichiers PNG factices sur disque, appelle delete($idShop, '', $exclude)
 * et vérifie que le fichier exclu survit tandis que l'autre est bien
 * supprimé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Partie 1 : structurel — ordre generate() puis delete() ──────────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posGenerate = strpos($src, '$path = $sigGenerator->generate($sigName, $sigTitle, $sigStyle, $sigColor, $idShop, $resolvedSigStyle);');
    $posDelete   = strpos($src, "\$sigGenerator->delete(\$idShop, '', \$path);");
    neria_assert(
        $posGenerate !== false && $posDelete !== false && $posGenerate < $posDelete,
        "neria.php n'appelle plus generate() AVANT delete(\$idShop, '', \$path) — régression du bug corrigé le 09/08/2026 (round 160) : une régénération échouée effacerait de nouveau la signature fonctionnelle précédente sans jamais mettre à jour la base"
    );

    // ── Partie 2 : comportemental réel — SignatureGenerator::delete() exclut bien $excludePath
    require_once _PS_MODULE_DIR_ . 'neria/src/SignatureGenerator.php';

    $ref  = new ReflectionClass('SignatureGenerator');
    $prop = $ref->getProperty('signaturesPath');
    $prop->setAccessible(true);

    $module = neria_test_module();
    $gen    = new SignatureGenerator($module);
    $sigDir = $prop->getValue($gen);
    neria_assert(is_dir($sigDir) || @mkdir($sigDir, 0755, true), "Répertoire des signatures introuvable/non créable : {$sigDir}");

    $idShop     = 999888; // boutique fictive de test, isolée de la vraie donnée
    $keepFile   = $sigDir . "/signature_{$idShop}_great_vibes.png";
    $removeFile = $sigDir . "/signature_{$idShop}_dancing_script.png";

    file_put_contents($keepFile, 'fake-png-content-keep');
    file_put_contents($removeFile, 'fake-png-content-remove');

    try {
        $gen->delete($idShop, '', $keepFile);

        neria_assert(
            file_exists($keepFile),
            "SignatureGenerator::delete() a supprimé le fichier exclu (\$excludePath) — régression du bug corrigé le 09/08/2026 (round 160) : le fichier fraîchement généré se supprimerait lui-même"
        );
        neria_assert(
            !file_exists($removeFile),
            "SignatureGenerator::delete() n'a pas supprimé l'ancien fichier non exclu — le nettoyage des anciens styles ne fonctionne plus"
        );

        return [
            'pass'    => true,
            'message' => "neria.php génère bien la nouvelle signature AVANT de supprimer l'ancienne (delete() exclut le fichier fraîchement créé) — bug corrigé le 09/08/2026 (round 160)",
        ];
    } finally {
        @unlink($keepFile);
        @unlink($removeFile);
    }
}
