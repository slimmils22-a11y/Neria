<?php
/**
 * Régression : `StatsManager::zTestProportions()` ne vérifiait que
 * `SIG_MIN_SAMPLE` (100 envois minimum par variante) avant de calculer un
 * test z sur proportions — suffisant pour le taux d'OUVERTURE (~20-30%,
 * n·p̄ largement > 5 dès 100 envois), mais pas pour le taux de CLIC,
 * souvent 1-3% en e-commerce. La règle usuelle de validité de
 * l'approximation normale (n·p̄ ET n·(1-p̄) ≥ 5 dans CHAQUE groupe)
 * n'était jamais vérifiée.
 *
 * Exemple concret avant correctif : n1=n2=100 (minimum autorisé),
 * x1=4/x2=0 (p̄=0,02) — n·p̄=2 < 5 des deux côtés, mais le z-score
 * (≈2,02) franchissait quand même le seuil 90% et déclarait un "gagnant"
 * statistiquement injustifié, appliqué automatiquement via
 * `apply_abtest_winner` sans aucun garde-fou ni avertissement.
 *
 * Bug identifié et corrigé le 04/09/2026 (round 300, audit "A/B testing —
 * significativité statistique").
 *
 * Corrigé le 04/09/2026 (round 300) : `sufficient = false` retourné (pas
 * de gagnant déclaré) quand `n1·p̄ < 5 || n2·p̄ < 5 || n1·(1-p̄) < 5 ||
 * n2·(1-p̄) < 5`.
 *
 * Test comportemental réel : appelle `zTestProportions()` (via réflexion)
 * avec le jeu de données exact décrit ci-dessus (n·p̄=2, insuffisant) et
 * vérifie qu'aucun gagnant n'est plus déclaré — puis contre-épreuve avec
 * un taux plus élevé (n·p̄=20, largement suffisant) où un gagnant doit
 * toujours être déclaré normalement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $sm  = new StatsManager(neria_test_module());
    $ref = new ReflectionMethod(StatsManager::class, 'zTestProportions');
    $ref->setAccessible(true);

    // n1=n2=100, x1=4, x2=0 -> pPool=0,02, n·p̄=2 < 5.
    $insufficient = $ref->invoke($sm, 4, 100, 0, 100);
    neria_assert(
        $insufficient['sufficient'] === false && $insufficient['winner'] === null,
        "zTestProportions() déclare encore un gagnant (winner=" . var_export($insufficient['winner'], true) . ", sufficient=" . var_export($insufficient['sufficient'], true) . ") pour un échantillon où n·p̄ < 5 — régression du bug corrigé le 04/09/2026 (round 300) : l'approximation normale du test z n'est pas valide dans ce cas, la confiance affichée serait de nouveau trompeuse"
    );

    // n1=n2=100, x1=30, x2=10 -> pPool=0,20, n·p̄=20 >= 5 : doit rester
    // fonctionnel (pas de régression du chemin nominal).
    $sufficient = $ref->invoke($sm, 30, 100, 10, 100);
    neria_assert(
        $sufficient['sufficient'] === true && $sufficient['winner'] !== null,
        "zTestProportions() ne déclare plus de gagnant pour un échantillon largement suffisant (n·p̄=20) — régression : la nouvelle règle np≥5 bloquerait à tort un cas parfaitement valide"
    );

    return [
        'pass'    => true,
        'message' => "StatsManager::zTestProportions() applique désormais la règle n·p̄≥5 (et n·(1-p̄)≥5) avant de déclarer un gagnant A/B, sans régression sur les échantillons réellement suffisants — bug corrigé le 04/09/2026 (round 300)",
    ];
}
