<?php
/**
 * Régression : neria-admin.js::initDesignReset() doit désactiver le bouton
 * avant de soumettre le formulaire, pour empêcher une double soumission
 * (double-clic sur la confirmation).
 *
 * Bug réel corrigé le 09/08/2026 (round 147) : même défaut que
 * initTranslationsReset() (test_217, même round) — le callback de
 * confirmation de initDesignReset() construisait et soumettait un
 * formulaire sans jamais désactiver le bouton. Un double-clic sur le
 * bouton de confirmation de la modale pouvait créer et soumettre deux
 * formulaires, générant deux requêtes POST neria_action=reset_design
 * concurrentes.
 *
 * Test structurel (pas de moteur JS disponible dans cet environnement de
 * test PHP) : vérifie que le garde-fou anti-double-clic est bien présent
 * dans le callback de confirmation, avant la construction du formulaire.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/js/neria-admin.js');
    neria_assert($src !== false, 'Impossible de lire views/js/neria-admin.js');

    $posFn = strpos($src, 'function initDesignReset()');
    neria_assert($posFn !== false, 'initDesignReset() introuvable — jeu de test invalide');

    $posSubmit = strpos($src, 'form.submit();', $posFn);
    neria_assert($posSubmit !== false, 'form.submit() introuvable dans initDesignReset() — jeu de test invalide');

    $body = substr($src, $posFn, $posSubmit - $posFn);

    neria_assert(
        strpos($body, 'if (btn.disabled) return;') !== false && strpos($body, 'btn.disabled = true;') !== false,
        "initDesignReset() ne désactive plus le bouton avant de soumettre le formulaire — régression du bug corrigé le 09/08/2026 (round 147) : un double-clic pourrait de nouveau générer deux requêtes POST concurrentes"
    );

    return [
        'pass'    => true,
        'message' => "neria-admin.js::initDesignReset() désactive bien le bouton avant soumission, empêchant une double soumission par double-clic",
    ];
}
