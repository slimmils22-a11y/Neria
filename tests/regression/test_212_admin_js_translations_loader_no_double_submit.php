<?php
/**
 * Régression : neria-admin.js::initTranslationsLoader() doit désactiver le
 * bouton avant de soumettre le formulaire, pour empêcher une double
 * soumission (double-clic).
 *
 * Bug réel corrigé le 09/08/2026 (round 146) : le clic sur "Charger les
 * traductions" construisait un <form> et appelait form.submit()
 * immédiatement, sans jamais désactiver le bouton ni poser de flag
 * anti-relance. Un double-clic (réflexe courant en l'absence de tout
 * retour visuel avant que la navigation ne parte réellement) créait et
 * soumettait deux formulaires, générant potentiellement deux requêtes POST
 * neria_action=load_translations concurrentes.
 *
 * Test structurel (le JS ne peut pas être exécuté dans ce jeu de tests PHP
 * — pas de moteur JS disponible dans cet environnement de test) : vérifie
 * que le garde-fou anti-double-clic est bien présent dans le code source,
 * positionné avant la construction/soumission du formulaire.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/js/neria-admin.js');
    neria_assert($src !== false, 'Impossible de lire views/js/neria-admin.js');

    $posFn = strpos($src, 'function initTranslationsLoader()');
    neria_assert($posFn !== false, 'initTranslationsLoader() introuvable — jeu de test invalide');

    $posSubmit = strpos($src, 'form.submit();', $posFn);
    neria_assert($posSubmit !== false, 'form.submit() introuvable dans initTranslationsLoader() — jeu de test invalide');

    $body = substr($src, $posFn, $posSubmit - $posFn);

    neria_assert(
        strpos($body, 'if (btn.disabled) return;') !== false && strpos($body, 'btn.disabled = true;') !== false,
        "initTranslationsLoader() ne désactive plus le bouton avant de soumettre le formulaire — régression du bug corrigé le 09/08/2026 (round 146) : un double-clic pourrait de nouveau générer deux requêtes POST concurrentes"
    );

    return [
        'pass'    => true,
        'message' => "neria-admin.js::initTranslationsLoader() désactive bien le bouton avant soumission, empêchant une double soumission par double-clic",
    ];
}
