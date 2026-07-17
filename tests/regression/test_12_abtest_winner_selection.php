<?php
/** Régression : computeSignificance() doit retenir la métrique la plus confiante, pas systématiquement le clic. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';
    $stats = new StatsManager(neria_test_module());

    // Open: A=200/1000 (20%) vs B=240/1000 (24%) -> confiance 95%, gagnant B
    // Click: A=45/1000 (4.5%) vs B=30/1000 (3%) -> confiance 90%, gagnant A
    $a = ['total_sent' => 1000, 'total_open' => 200, 'total_click' => 45];
    $b = ['total_sent' => 1000, 'total_open' => 240, 'total_click' => 30];

    $result = $stats->computeSignificance($a, $b);

    neria_assert($result['open']['confidence'] === 95, "confiance open inattendue ({$result['open']['confidence']}), le scénario de test ne reproduit plus les conditions");
    neria_assert($result['click']['confidence'] === 90, "confiance click inattendue ({$result['click']['confidence']})");
    neria_assert(
        $result['overall_winner'] === 'B',
        "overall_winner={$result['overall_winner']}, attendu 'B' (métrique open, 95% de confiance) — régression du bug corrigé le 17/07/2026 (commit bffc12a), le clic (90%, moins confiant) redevient prioritaire à tort"
    );

    return ['pass' => true, 'message' => 'computeSignificance() retient toujours la métrique la plus confiante'];
}
