<?php
/**
 * Régression : 2 défauts d'accessibilité BO confirmés par audit dédié
 * (round 155) doivent rester corrigés dans _customer_history_content.tpl
 * (bloc "Emails reçus", inclus depuis customer_email_history.tpl et
 * customer_history.tpl) :
 * - modale d'aperçu email (iframe) sans role="dialog"/aria-modal, sans
 *   fermeture au clavier (Échap), sans piège de focus, sans restitution du
 *   focus au déclencheur à la fermeture, bouton fermeture sans aria-label.
 * - les 2 <select> de filtre du tableau complet (template/statut) sans
 *   <label for=...> associé.
 *
 * Test structurel (contenu de template, pas de moteur de rendu Smarty
 * disponible dans cet environnement de test PHP) : vérifie la présence
 * des attributs ajoutés le 09/08/2026 (round 155).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $tpl = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/_customer_history_content.tpl');
    neria_assert($tpl !== false, 'Impossible de lire _customer_history_content.tpl');

    neria_assert(
        strpos($tpl, 'role="dialog"') !== false && strpos($tpl, 'aria-modal="true"') !== false,
        "la modale d'aperçu email n'a plus role=\"dialog\"/aria-modal — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($tpl, "e.key === 'Escape'") !== false,
        "la modale d'aperçu email ne gère plus la fermeture au clavier (Échap) — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($tpl, '_neriaHistoryModalTrigger') !== false,
        "la modale d'aperçu email ne restitue plus le focus au déclencheur à la fermeture — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($tpl, 'id="neria-preview-close" aria-label=') !== false,
        "le bouton de fermeture de la modale d'aperçu email n'a plus d'aria-label — régression du bug corrigé le 09/08/2026 (round 155)"
    );
    neria_assert(
        strpos($tpl, 'for="neria-history-filter-template"') !== false && strpos($tpl, 'for="neria-history-filter-status"') !== false,
        "les 2 filtres (template/statut) du tableau complet n'ont plus de <label for=...> associé — régression du bug corrigé le 09/08/2026 (round 155)"
    );

    return [
        'pass'    => true,
        'message' => "Les 2 défauts d'accessibilité de la modale d'aperçu email et des filtres corrigés le 09/08/2026 (round 155) restent en place",
    ];
}
