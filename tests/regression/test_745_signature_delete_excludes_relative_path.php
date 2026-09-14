<?php
/**
 * Régression : SignatureGenerator::delete() doit exclure du nettoyage le
 * fichier fraîchement généré même quand $excludePath est un chemin RELATIF
 * (celui réellement retourné par generate(), cf. neria.php:3635 —
 * `$sigGenerator->delete($idShop, '', $path);` où $path vient de
 * `generate()`), pas seulement un chemin absolu.
 *
 * Bug identifié le 14/09/2026 (round 354, audit multi-agents) : delete()
 * comparait $excludePath via realpath($path) === realpath($excludePath).
 * generate() retourne `self::SIGNATURES_DIR . '/' . $filename` — un chemin
 * RELATIF (ex. "data/signatures/signature_1_great_vibes.png"), sans le
 * préfixe absolu de $signaturesPath. realpath() sur un chemin relatif le
 * résout par rapport au répertoire de travail COURANT du process PHP
 * (racine PrestaShop), jamais modules/neria/data/signatures — il ne
 * correspond donc JAMAIS au fichier réel et retourne false, désactivant
 * systématiquement l'exclusion : la signature qu'on vient de générer était
 * supprimée par le nettoyage qui suit immédiatement dans le même appel
 * (neria.php:3635), reproduit empiriquement avant correctif.
 *
 * L'ancien test_264 ne détectait pas ce bug car il appelait delete() avec
 * un $excludePath déjà ABSOLU (construit depuis la propriété privée
 * $signaturesPath elle-même) — jamais le scénario réel de production.
 *
 * Corrigé le 14/09/2026 : comparaison sur basename() plutôt que realpath()
 * — les 2 chemins (relatif ou absolu) partagent le même nom de fichier, et
 * tous les fichiers de cette méthode vivent dans le même répertoire
 * $signaturesPath, donc comparer les noms suffit sans dépendre du CWD.
 *
 * Test comportemental réel : reproduit exactement le format de chemin
 * retourné par generate() (relatif, "data/signatures/...") et vérifie que
 * delete() l'exclut bien du nettoyage.
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

    $idShop = 999888777; // boutique fictive de test, isolée de la vraie donnée
    $keepFileAbs = $sigDir . "/signature_{$idShop}_great_vibes.png";
    $keepFileRel = "data/signatures/signature_{$idShop}_great_vibes.png"; // exact format retourné par generate()
    $removeFile  = $sigDir . "/signature_{$idShop}_dancing_script.png";

    file_put_contents($keepFileAbs, 'fake-png-content-keep');
    file_put_contents($removeFile, 'fake-png-content-remove');

    try {
        // Simule EXACTEMENT l'appel réel de neria.php:3635 — $path relatif,
        // pas absolu.
        $gen->delete($idShop, '', $keepFileRel);

        neria_assert(
            file_exists($keepFileAbs),
            "SignatureGenerator::delete() a supprimé la signature fraîchement générée quand \$excludePath est relatif (format réel retourné par generate()) — régression du bug corrigé le 14/09/2026 (round 354) : chaque génération de signature effacerait immédiatement le fichier qu'elle vient de créer"
        );
        neria_assert(
            !file_exists($removeFile),
            "SignatureGenerator::delete() n'a pas supprimé l'ancien fichier non exclu — le nettoyage des anciens styles ne fonctionne plus"
        );

        return [
            'pass'    => true,
            'message' => "SignatureGenerator::delete() exclut bien le fichier fraîchement généré même avec un \$excludePath relatif (comparaison basename(), plus dépendante du CWD via realpath()) — bug corrigé le 14/09/2026 (round 354)",
        ];
    } finally {
        @unlink($keepFileAbs);
        @unlink($removeFile);
    }
}
