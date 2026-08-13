<?php
/**
 * Régression : initWatchdogAnalyze() n'avait ni AbortController ni délai
 * maximal sur son fetch(). Une requête restée bloquée (backend lent,
 * connexion coupée sans erreur HTTP explicite) laissait le bouton
 * désactivé et l'icône tourner indéfiniment, sans aucun message d'erreur,
 * jusqu'à l'abandon éventuel du navigateur lui-même.
 *
 * Corrigé le 13/08/2026 (round 162) : un AbortController avec délai de
 * 15s déclenche désormais le même chemin .catch() qu'une erreur réseau
 * classique — dégradation gracieuse si AbortController est indisponible
 * (très vieux navigateurs).
 *
 * Test structurel : vérifie la présence de l'AbortController, du timeout,
 * et que le signal est bien transmis au fetch().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/js/neria-admin.js');
    neria_assert($src !== false, 'Impossible de lire neria-admin.js');

    $posFn = strpos($src, 'function initWatchdogAnalyze()');
    neria_assert($posFn !== false, 'initWatchdogAnalyze() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 2200);

    neria_assert(
        strpos($body, 'new AbortController()') !== false,
        "initWatchdogAnalyze() n'utilise plus AbortController — régression du bug corrigé le 13/08/2026 (round 162) : une requête bloquée laisserait de nouveau le bouton désactivé indéfiniment"
    );
    neria_assert(
        strpos($body, 'controller.abort()') !== false && strpos($body, '15000') !== false,
        "Le timeout de 15s (controller.abort()) a disparu de initWatchdogAnalyze() — régression du bug corrigé le 13/08/2026 (round 162)"
    );
    neria_assert(
        strpos($body, 'signal: controller') !== false,
        "Le signal de l'AbortController n'est plus transmis au fetch() — le timeout serait défini mais sans effet"
    );

    return [
        'pass'    => true,
        'message' => "initWatchdogAnalyze() applique bien un timeout de 15s via AbortController sur son fetch() — bug corrigé le 13/08/2026 (round 162)",
    ];
}
