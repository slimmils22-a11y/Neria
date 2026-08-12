<?php
/**
 * Régression : 6 défauts d'accessibilité BO confirmés par audit dédié
 * (round 155) doivent rester corrigés :
 * - calendar.tpl : lien de suppression icône-seule ("✕") sans nom
 *   accessible (aria-label/title).
 * - help.tpl : bouton fermeture du bandeau flottant PDF sans aria-label,
 *   bandeau sans role="status".
 * - help.tpl : menu de partage sans role="menu"/"menuitem", sans fermeture
 *   au clavier (Échap), bouton suppression plateforme personnalisée
 *   inaccessible au clavier.
 * - help.tpl : 2 champs d'ajout de plateforme (nom/URL) sans <label>.
 * - translations.tpl : recherche globale sans role="listbox"/"option" ni
 *   navigation clavier (flèches/Entrée/Échap).
 * - translations.tpl : bouton d'effacement de recherche sans aria-label.
 *
 * Test structurel : vérifie la présence des attributs ARIA/labels ajoutés
 * le 09/08/2026 (round 155).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $cal = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/calendar.tpl');
    neria_assert($cal !== false, 'Impossible de lire calendar.tpl');
    neria_assert(
        strpos($cal, "aria-label=\"{neria_admin key='calendar.delete_btn' esc='html'}\"") !== false,
        "le lien de suppression d'événement calendrier n'a plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 155)"
    );

    $help = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/help.tpl');
    neria_assert($help !== false, 'Impossible de lire help.tpl');
    neria_assert(
        strpos($help, "toast.setAttribute('role', 'status')") !== false,
        "le bandeau flottant PDF n'a plus role=\"status\" — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($help, "'aria-label=\"' + window.NERIA_HELP_L10N.close + '\"") !== false,
        "le bouton de fermeture du bandeau flottant PDF n'a plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($help, "drop.setAttribute('role', 'menu')") !== false && strpos($help, "btn.setAttribute('role', 'menuitem')") !== false,
        "le menu de partage n'a plus role=\"menu\"/\"menuitem\" — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($help, 'closeDropAndRestoreFocus') !== false,
        "le menu de partage ne se ferme plus au clavier (Échap) avec restitution du focus — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($help, "del.setAttribute('role', 'button')") !== false && strpos($help, "del.setAttribute('tabindex', '0')") !== false,
        "le bouton de suppression de plateforme personnalisée n'est plus accessible au clavier — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($help, 'for="neria-share-add-name"') !== false && strpos($help, 'for="neria-share-add-url"') !== false,
        "les 2 champs d'ajout de plateforme (nom/URL) n'ont plus de <label> — régression du bug corrigé le 09/08/2026 (round 155)"
    );

    $trad = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/translations.tpl');
    neria_assert($trad !== false, 'Impossible de lire translations.tpl');
    neria_assert(
        strpos($trad, 'role="combobox"') !== false && strpos($trad, 'role="listbox"') !== false,
        "la recherche globale n'a plus role=\"combobox\"/\"listbox\" — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($trad, "role=\"option\"") !== false && strpos($trad, "e.key === 'ArrowDown'") !== false,
        "la recherche globale n'a plus role=\"option\"/navigation clavier — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($trad, "aria-label=\"{neria_admin key='translations.search_clear'}\"") !== false,
        "le bouton d'effacement de la recherche n'a plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 155)"
    );

    return [
        'pass'    => true,
        'message' => "Les 6 défauts d'accessibilité de calendar.tpl/help.tpl/translations.tpl corrigés le 09/08/2026 (round 155) restent en place",
    ];
}
