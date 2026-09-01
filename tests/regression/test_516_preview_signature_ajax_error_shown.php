<?php
/**
 * Régression : le handler AJAX `neria_action=preview_signature` (`neria.php`)
 * répond en HTTP 200 même en cas d'exception PHP, avec
 * `{preview: null, error: "..."}`. Côté JS (`views/js/neria-admin.js`), le
 * `.then()` du `fetch()` ne testait QUE `data.preview` avant de mettre à
 * jour le conteneur d'aperçu — sans jamais vérifier `data.error`. Comme
 * `data.preview` était alors `null`, l'aperçu n'était ni mis à jour ni
 * signalé en erreur, et le `.catch()` (qui affiche pourtant le message
 * "Error generating preview" déjà prévu dans le code) n'était jamais
 * atteint puisque la requête réseau avait réussi (HTTP 200). Le marchand
 * ne voyait ni le nouvel aperçu ni la moindre indication d'échec — même
 * défaut racine que `watchdog_refresh` (round 270) : `data.error` n'était
 * jamais testé avant de décider de l'état à présenter.
 *
 * Bug identifié le 01/09/2026 (round 271, audit "généralisation du défaut
 * AJAX round 270 à tous les handlers du module").
 *
 * Corrigé le 01/09/2026 (round 271) : la branche `else` (quand
 * `data.preview` est absent) affiche désormais le même message d'erreur
 * que le `.catch()`, réutilisant `window.NERIA_UI.sigPreviewError`.
 *
 * Test structurel : le comportement dépend de l'exécution réelle d'un
 * navigateur (fetch/DOM), hors périmètre de cette suite PHP — même
 * contrainte que test_515 (round 270) pour ce même fichier JS. Vérifie la
 * présence de la branche `else` avec le message d'erreur, positionnée
 * juste après le test `if (data.preview)`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/js/neria-admin.js');
    neria_assert($src !== false, 'Impossible de lire views/js/neria-admin.js');

    $ifPos = strpos($src, 'if (data.preview) {');
    neria_assert($ifPos !== false, "le test if (data.preview) est introuvable");

    $block = substr($src, $ifPos, 1800);

    neria_assert(
        strpos($block, '} else {') !== false,
        "views/js/neria-admin.js n'a plus de branche else après if (data.preview) dans preview_signature — régression du bug corrigé le 01/09/2026 (round 271) : une exception serveur (HTTP 200 + {preview:null,error:...}) ne serait de nouveau signalée par aucun message d'erreur au marchand"
    );

    $elsePos = strpos($block, '} else {');
    $elseBlock = substr($block, $elsePos, 1400);
    neria_assert(
        strpos($elseBlock, 'sigPreviewError') !== false
        && strpos($elseBlock, 'neria-signature-preview__placeholder') !== false,
        "la branche else de preview_signature n'affiche plus le message d'erreur (sigPreviewError) dans le placeholder attendu — régression du bug corrigé le 01/09/2026 (round 271)"
    );

    return [
        'pass'    => true,
        'message' => "views/js/neria-admin.js affiche désormais un message d'erreur explicite quand preview_signature échoue côté serveur, au lieu de laisser l'aperçu silencieusement obsolète ou vide — bug corrigé le 01/09/2026 (round 271)",
    ];
}
