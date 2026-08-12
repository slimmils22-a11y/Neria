<?php
/**
 * Régression : 2 défauts d'accessibilité BO confirmés par audit dédié
 * (round 155) doivent rester corrigés dans academy.tpl :
 * - les 8 cartes de sélection de guide (<div onclick="naShow(...)">) sans
 *   role="button"/tabindex/support clavier — inaccessibles au clavier.
 * - les 8 cases RGPD custom (<div onclick="naToggleCheck(this)">) sans
 *   role="checkbox"/aria-checked/support clavier.
 *
 * Test structurel : vérifie la présence des attributs ARIA ajoutés le
 * 09/08/2026 (round 155) sur les 16 éléments concernés, et de l'écouteur
 * clavier délégué (Entrée/Espace) ainsi que la synchronisation
 * aria-checked dans naToggleCheck().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $tpl = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/academy.tpl');
    neria_assert($tpl !== false, 'Impossible de lire academy.tpl');

    $cardCount = substr_count($tpl, 'class="na-guide-card"') && substr_count($tpl, 'role="button" tabindex="0"');
    neria_assert(
        substr_count($tpl, 'role="button" tabindex="0"') >= 8,
        "moins de 8 cartes de sélection de guide ont role=\"button\"/tabindex=\"0\" — régression du bug corrigé le 09/08/2026 (round 155) : inaccessibles au clavier"
    );
    neria_assert(
        substr_count($tpl, 'role="checkbox" tabindex="0" aria-checked="false"') >= 8,
        "moins de 8 cases RGPD custom ont role=\"checkbox\"/aria-checked — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($tpl, "e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar'") !== false,
        "l'écouteur clavier délégué (Entrée/Espace) pour les cartes de guide et cases RGPD a disparu — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($tpl, "el.setAttribute('aria-checked', state.checks[key] ? 'true' : 'false')") !== false,
        "naToggleCheck() ne synchronise plus aria-checked — régression du bug corrigé le 09/08/2026 (round 155)"
    );

    return [
        'pass'    => true,
        'message' => "Les 2 défauts d'accessibilité clavier des cartes de guide et de la checklist RGPD corrigés le 09/08/2026 (round 155) restent en place",
    ];
}
