<?php
/**
 * Régression : 6 défauts d'accessibilité BO confirmés par audit dédié
 * (round 154) doivent rester corrigés :
 * - navigation.tpl : modale de confirmation globale (utilisée par toutes
 *   les actions destructrices) sans role="dialog"/aria-modal/piège de
 *   focus/Échap.
 * - send.tpl : autocomplétion client inutilisable au clavier (pas de
 *   role listbox/option ni navigation flèches/Entrée).
 * - send.tpl : champs de contenu dynamiques sans <label> associé.
 * - send.tpl : panneau "Planification différée" sans role="button" ni
 *   support clavier.
 * - send.tpl : bouton de fermeture de la prévisualisation sans aria-label.
 * - stats.tpl : 4 images produit/client sans attribut alt.
 *
 * Test structurel (contenu de template, pas de moteur de rendu Smarty
 * disponible dans cet environnement de test PHP) : vérifie la présence
 * des attributs ARIA/labels/alt ajoutés le 09/08/2026.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $nav = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/navigation.tpl');
    neria_assert($nav !== false, 'Impossible de lire navigation.tpl');
    neria_assert(
        strpos($nav, 'role="dialog"') !== false && strpos($nav, 'aria-modal="true"') !== false,
        "la modale de confirmation globale n'a plus role=\"dialog\"/aria-modal — régression du bug corrigé le 09/08/2026 (round 154)"
    );
    neria_assert(
        strpos($nav, "e.key === 'Escape'") !== false,
        "la modale de confirmation ne gère plus la fermeture au clavier (Échap) — régression du bug corrigé le 09/08/2026 (round 154)"
    );
    neria_assert(
        strpos($nav, '_neriaModalTrigger') !== false,
        "la modale ne restitue plus le focus a l'element declencheur a la fermeture — régression du bug corrigé le 09/08/2026 (round 154)"
    );

    $send = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/send.tpl');
    neria_assert($send !== false, 'Impossible de lire send.tpl');
    neria_assert(
        strpos($send, 'role="listbox"') !== false && strpos($send, "setAttribute('role', 'option')") !== false,
        "l'autocompletion client n'a plus role=\"listbox\"/role=\"option\" — régression du bug corrigé le 09/08/2026 (round 154) : de nouveau inutilisable au clavier"
    );
    neria_assert(
        strpos($send, "e.key === 'ArrowDown'") !== false,
        "l'autocompletion client ne gere plus la navigation clavier (flèches) — régression du bug corrigé le 09/08/2026 (round 154)"
    );
    neria_assert(
        strpos($send, 'for="neria-send-var-') !== false,
        "les champs de contenu dynamiques n'ont plus de <label for=...> associé — régression du bug corrigé le 09/08/2026 (round 154)"
    );
    neria_assert(
        strpos($send, 'role="button" tabindex="0" aria-expanded="false" aria-controls="neria-schedule-body"') !== false,
        "le panneau 'Planification différée' n'a plus role=\"button\"/tabindex — régression du bug corrigé le 09/08/2026 (round 154)"
    );
    neria_assert(
        strpos($send, "e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar'") !== false,
        "le panneau 'Planification différée' ne gère plus l'ouverture au clavier (Entrée/Espace) — régression du bug corrigé le 09/08/2026 (round 154)"
    );
    neria_assert(
        strpos($send, 'id="neria-preview-close" aria-label=') !== false,
        "le bouton de fermeture de la prévisualisation n'a plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 154)"
    );

    $stats = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/stats.tpl');
    neria_assert($stats !== false, 'Impossible de lire stats.tpl');
    $altCount = substr_count($stats, ' alt="" ') + substr_count($stats, "' alt=\"\" ");
    neria_assert(
        $altCount >= 4,
        "moins de 4 images produit/client ont un attribut alt (trouve {$altCount}/4) — régression du bug corrigé le 09/08/2026 (round 154)"
    );

    return [
        'pass'    => true,
        'message' => "Les 6 défauts d'accessibilité BO corrigés le 09/08/2026 (round 154) restent en place",
    ];
}
