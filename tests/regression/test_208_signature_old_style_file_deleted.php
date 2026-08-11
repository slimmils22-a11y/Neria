<?php
/**
 * Régression : régénérer une signature avec un STYLE DIFFÉRENT doit
 * supprimer l'ancien fichier PNG, pas l'accumuler indéfiniment sur disque.
 *
 * Bug réel corrigé le 09/08/2026 (round 145) : SignatureGenerator::delete()
 * existait mais n'était jamais appelée nulle part dans le module.
 * buildFilename() intègre le style dans le nom de fichier
 * (signature_{idShop}_{style}.png) : chaque changement de style créait un
 * nouveau fichier sans jamais supprimer l'ancien — accumulation illimitée
 * sur la durée de vie du module (une seule signature active par boutique
 * en base, mais tous les anciens fichiers physiques restaient sur disque).
 *
 * Test comportemental réel : génère une signature en 'great_vibes', vérifie
 * le fichier créé, puis appelle delete() suivi de generate() en
 * 'dancing_script' (reproduisant exactement le nouvel enchaînement de
 * neria.php::generate_signature) — vérifie que l'ancien fichier
 * 'great_vibes' a bien disparu et que le nouveau existe.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SignatureGenerator.php';

    $module = neria_test_module();
    $gen    = new SignatureGenerator($module);
    $idShop = 999997; // boutique fictive isolée, pas de collision avec une vraie signature

    $pathGreatVibes    = _PS_MODULE_DIR_ . 'neria/data/signatures/signature_' . $idShop . '_great_vibes.png';
    $pathDancingScript = _PS_MODULE_DIR_ . 'neria/data/signatures/signature_' . $idShop . '_dancing_script.png';

    try {
        @unlink($pathGreatVibes);
        @unlink($pathDancingScript);

        $relPath1 = $gen->generate('Regtest Round145', 'Testeur', 'great_vibes', '#b38b59', $idShop);
        neria_assert($relPath1 !== false, "generate() a échoué pour 'great_vibes' — jeu de test invalide (police GD/TTF manquante ?)");
        neria_assert(file_exists($pathGreatVibes), "le fichier great_vibes n'a pas été créé sur disque — jeu de test invalide");

        // Reproduit exactement l'enchaînement de neria.php::generate_signature
        $gen->delete($idShop);
        $relPath2 = $gen->generate('Regtest Round145', 'Testeur', 'dancing_script', '#b38b59', $idShop);
        neria_assert($relPath2 !== false, "generate() a échoué pour 'dancing_script' — jeu de test invalide");

        neria_assert(
            !file_exists($pathGreatVibes),
            "le fichier signature_{$idShop}_great_vibes.png existe encore après changement de style vers 'dancing_script' — régression du bug corrigé le 09/08/2026 (round 145) : les fichiers orphelins s'accumuleraient de nouveau sur disque"
        );
        neria_assert(file_exists($pathDancingScript), "le nouveau fichier dancing_script n'a pas été créé");
    } finally {
        @unlink($pathGreatVibes);
        @unlink($pathDancingScript);
    }

    return [
        'pass'    => true,
        'message' => "Régénérer une signature avec un style différent supprime bien l'ancien fichier PNG (plus d'accumulation d'orphelins)",
    ];
}
