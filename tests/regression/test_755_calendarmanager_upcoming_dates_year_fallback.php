<?php
/**
 * Régression : CalendarManager::getUpcomingDates() ne basculait pas sur
 * $year+1 quand la date d'envoi calculée pour $year était déjà passée —
 * contrairement à getEventDisplayInfo() (round 142, même fichier) qui
 * applique déjà ce repli, et à processEvent() (envoi réel) qui teste
 * systématiquement [$year, $year+1]. Un simple `continue` faisait
 * DISPARAÎTRE l'occasion du panneau BO « Prochaines occasions » jusqu'au
 * 1er janvier suivant, pour la quasi-totalité des occasions du module
 * (Noël, Nouvel An, Saint-Valentin, Halloween, Pâques, fêtes des mères
 * FR/US, Nowruz, Setsubun, Hanami).
 *
 * Bug identifié le 14/09/2026 (round 357, audit dédié CalendarManager).
 * N'affecte QUE l'affichage BO — processEvent() (envoi réel) a sa propre
 * boucle [$year, $year+1] correcte, non affectée.
 *
 * Corrigé le 14/09/2026 : bascule sur $year+1 avant de continue, même
 * logique que getEventDisplayInfo().
 *
 * Test structurel + comportemental réel (impossible de manipuler "today"
 * dans getUpcomingDates(), calculé en interne via `new DateTime('today')`
 * — comme pour d'autres méthodes de calcul de date de ce fichier) :
 * vérifie la présence du repli dans le code source, ET qu'un événement
 * dont la date de calcul est déjà passée cette année (Noël, hors saison
 * pour la plupart des exécutions de la suite de tests) n'empêche jamais
 * getEventDate() lui-même de résoudre une date valide pour $year+1 quand
 * on le lui demande directement — la brique sous-jacente du correctif.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CalendarManager.php';

    // ── Partie 1 : structurel — le repli $year+1 est bien en place ──────
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CalendarManager.php');

    $posMethod = strpos($src, 'public function getUpcomingDates(): array');
    neria_assert($posMethod !== false, 'getUpcomingDates() introuvable — jeu de test invalide');

    $body = substr($src, $posMethod, 2500);

    neria_assert(
        substr_count($body, "getEventDate(\$event['event_key'], \$year + 1)") >= 2,
        "getUpcomingDates() n'appelle plus getEventDate(...,\$year + 1) au moins 2 fois (résolution initiale + repli après échec du sendDate) — régression du bug corrigé le 14/09/2026 (round 357)"
    );
    neria_assert(
        strpos($body, 'if ($sendDate < $today) {') !== false
            && substr_count($body, 'if ($sendDate < $today) {') >= 1,
        "getUpcomingDates() ne teste plus \$sendDate < \$today — jeu de test invalide ou régression"
    );

    // ── Partie 2 : comportemental réel — la brique getEventDate($year+1) ──
    $mgr = new CalendarManager(neria_test_module());

    $thisYear = (int) (new DateTime('today'))->format('Y');
    $christmasThisYear = $mgr->getEventDate('christmas', $thisYear);
    $christmasNextYear = $mgr->getEventDate('christmas', $thisYear + 1);

    neria_assert(
        $christmasThisYear !== null && $christmasNextYear !== null,
        "getEventDate('christmas', ...) ne résout plus de date pour \$year ou \$year+1 — jeu de test invalide"
    );
    neria_assert(
        $christmasNextYear->format('Y') === (string) ($thisYear + 1),
        "getEventDate('christmas', \$year+1) ne retourne pas une date de l'année \$year+1 — brique sous-jacente du repli cassée"
    );

    return [
        'pass'    => true,
        'message' => "CalendarManager::getUpcomingDates() bascule bien sur \$year+1 quand la date d'envoi de \$year est déjà passée (structurel) et getEventDate(\$year+1) résout correctement la date de repli (comportemental) — bug corrigé le 14/09/2026 (round 357)",
    ];
}
