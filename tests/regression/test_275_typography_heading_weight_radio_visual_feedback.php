<?php
/**
 * Régression : le groupe radio "poids des titres" (typography.tpl,
 * heading_weight) utilise la classe .neria-radio-card__input, différente
 * des 3 groupes déjà câblés (.neria-radius-radio/.neria-sep-radio/
 * .neria-shadow-radio) dans initRadioCardGroups() — jamais ajoutée à la
 * liste des sélecteurs écoutés. Le clic était bien enregistré côté
 * navigateur (input radio caché natif) mais rien ne s'affichait à l'écran
 * (pas de bordure/fond mis à jour) ni dans l'aperçu live, sans qu'aucune
 * erreur ne le signale au marchand.
 *
 * Corrigé le 13/08/2026 (round 162) : un listener 'change' dédié écoute
 * désormais .neria-radio-card__input, bascule la classe
 * neria-radio-card--selected sur le <label> parent (closest), et
 * déclenche schedulePreviewUpdate().
 *
 * Test structurel (jsdom/moteur JS non disponible dans cette suite PHP) :
 * vérifie la présence du listener dédié et de son appel à
 * schedulePreviewUpdate().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/js/neria-admin.js');
    neria_assert($src !== false, 'Impossible de lire neria-admin.js');

    $posFn = strpos($src, 'function initRadioCardGroups()');
    neria_assert($posFn !== false, 'initRadioCardGroups() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 3000);

    neria_assert(
        strpos($body, "querySelectorAll('.neria-radio-card__input')") !== false,
        "initRadioCardGroups() n'écoute plus .neria-radio-card__input — régression du bug corrigé le 13/08/2026 (round 162) : le groupe heading_weight redeviendrait sans retour visuel au clic"
    );
    neria_assert(
        substr_count($body, 'schedulePreviewUpdate()') >= 2,
        "Le nouveau bloc .neria-radio-card__input n'appelle plus schedulePreviewUpdate() — l'aperçu live ne se rafraîchirait plus au changement de poids de titre"
    );
    neria_assert(
        strpos($body, "classList.toggle('neria-radio-card--selected'") !== false,
        "Le nouveau bloc ne bascule plus la classe neria-radio-card--selected — régression du bug corrigé le 13/08/2026 (round 162)"
    );

    return [
        'pass'    => true,
        'message' => "initRadioCardGroups() gère bien le groupe heading_weight (.neria-radio-card__input) avec retour visuel et rafraîchissement de l'aperçu — bug corrigé le 13/08/2026 (round 162)",
    ];
}
