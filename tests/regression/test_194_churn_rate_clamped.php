<?php
/**
 * Régression : ChurnScoreManager::computeScore() doit clamper rate1/2/3 à
 * 1.0, comme le même calcul ailleurs dans le module (ClvManager).
 *
 * Bug réel corrigé le 09/08/2026 (round 143) : un même email peut générer
 * plusieurs événements 'open' (réouverture, plusieurs appareils/clients
 * mail) pour un seul 'sent' — rate1/2/3 (open÷sent) n'étaient donc pas
 * structurellement bornés à 1.0. Pour la composante "Taux récent"
 * (recentRisk = (1.0 - rate1) * 30), un rate1 > 1 produisait une valeur
 * négative hors de la plage documentée [0, 30] (ex. sent_p1=2, open_p1=10
 * → rate1=5.0 → recentRisk=-120).
 *
 * Test comportemental réel (via Reflection — computeScore() est privée
 * mais ne dépend d'aucun accès DB, seulement de son argument $r) : appelle
 * computeScore() avec open_p1 > sent_p1 (rate1 non borné avant correctif),
 * vérifie que le rate1 renvoyé est bien clampé à 1.0 (pas 5.0), et que le
 * score final reste dans sa plage documentée [0, 100].
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';

    $mgr = new ChurnScoreManager(neria_test_module());

    $ref = new ReflectionMethod(ChurnScoreManager::class, 'computeScore');
    $ref->setAccessible(true);

    $row = [
        'sent_p1'   => 2,
        'open_p1'   => 10, // rate1 = 5.0 sans clamp
        'sent_p2'   => 5,
        'open_p2'   => 3,
        'sent_p3'   => 5,
        'open_p3'   => 4, // rate3 = 0.8, aussi > rate1 non clampé (5.0), ce qui donnerait un decline négatif clampé par max(0.0, ...) — pas le point testé ici
        'last_open' => date('Y-m-d H:i:s', strtotime('-5 days')),
    ];

    [$score, $rate1, $rate2, $rate3] = $ref->invoke($mgr, $row);

    neria_assert(
        $rate1 <= 1.0,
        "computeScore() a renvoyé rate1={$rate1} (non borné, sent_p1=2/open_p1=10) — régression du bug corrigé le 09/08/2026 (round 143) : la composante 'Taux récent' pourrait de nouveau sortir de sa plage documentée [0, 30]"
    );
    neria_assert($rate2 <= 1.0, "rate2={$rate2} n'est pas clampé à 1.0");
    neria_assert($rate3 <= 1.0, "rate3={$rate3} n'est pas clampé à 1.0");

    neria_assert(
        $score >= 0 && $score <= 100,
        "le score final ({$score}) sort de sa plage documentée [0, 100] — non-régression cassée par le clamp"
    );

    return [
        'pass'    => true,
        'message' => "ChurnScoreManager::computeScore() clampe bien rate1/2/3 à 1.0, alignée sur le même pattern déjà présent dans ClvManager",
    ];
}
