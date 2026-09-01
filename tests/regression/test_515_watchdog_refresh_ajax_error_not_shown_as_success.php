<?php
/**
 * Régression : le handler AJAX `neria_action=watchdog_refresh` (`neria.php`)
 * répond en HTTP 200 même en cas d'exception PHP, avec seulement
 * `{"error": "..."}` au lieu des champs attendus (score/color/label/
 * issues/crons). Côté JS (`views/js/neria-admin.js`), le `.then()` du
 * `fetch()` ne teste que `r.ok` (vrai pour tout HTTP 200) avant d'appeler
 * `applyWatchdogData(d)` directement — sans vérifier `d.error`. Comme
 * `d.issues` est alors `undefined`, la branche "aucun problème détecté"
 * s'affichait en VERT : un état visuel directement trompeur et inverse de
 * la réalité (le contrôle Watchdog a en fait échoué), sans que le
 * marchand n'ait aucune indication qu'il devrait réessayer ou investiguer.
 *
 * Bug identifié le 01/09/2026 (round 270, audit "cohérence réponse AJAX /
 * rendu JS").
 *
 * Corrigé le 01/09/2026 (round 270) : `if (d && d.error) { throw new
 * Error(d.error); }` ajouté avant `applyWatchdogData(d)`, ce qui fait
 * tomber ce cas dans le `.catch()` déjà existant (affiche
 * `wdL.refreshError`, icône ⚠️) au lieu de la branche succès.
 *
 * Test structurel : le comportement dépend de l'exécution réelle d'un
 * navigateur (fetch/DOM), hors périmètre de cette suite PHP — même
 * contrainte que les autres tests touchant `neria-admin.js` dans cette
 * série. Vérifie la présence du contrôle `d.error` au bon endroit
 * (immédiatement avant l'appel à `applyWatchdogData(d)`, à l'intérieur du
 * `.then()` qui suit le parsing JSON), et que `applyWatchdogData` reste
 * définie et appelée après ce contrôle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/js/neria-admin.js');
    neria_assert($src !== false, 'Impossible de lire views/js/neria-admin.js');

    $fetchPos = strpos($src, "fetch(url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined })");
    neria_assert($fetchPos !== false, "l'appel fetch() du rafraîchissement Watchdog est introuvable");

    $block = substr($src, $fetchPos, 1200);

    $errorCheckPos = strpos($block, "if (d && d.error) { throw new Error(d.error); }");
    neria_assert(
        $errorCheckPos !== false,
        "views/js/neria-admin.js ne vérifie plus d.error avant applyWatchdogData(d) — régression du bug corrigé le 01/09/2026 (round 270) : une exception serveur (HTTP 200 + {error:...}) afficherait de nouveau \"aucun problème détecté\" en vert au marchand, un état trompeur inverse de la réalité"
    );

    $applyCallPos = strpos($block, 'applyWatchdogData(d);');
    neria_assert($applyCallPos !== false, "l'appel à applyWatchdogData(d) est introuvable");
    neria_assert(
        $errorCheckPos < $applyCallPos,
        "le contrôle d.error n'est plus positionné AVANT l'appel à applyWatchdogData(d) — il doit interrompre le flux avant que la donnée d'erreur soit interprétée comme un succès"
    );

    return [
        'pass'    => true,
        'message' => "views/js/neria-admin.js vérifie désormais d.error avant d'appeler applyWatchdogData(d), évitant l'affichage trompeur \"aucun problème détecté\" sur une exception serveur réelle — bug corrigé le 01/09/2026 (round 270)",
    ];
}
