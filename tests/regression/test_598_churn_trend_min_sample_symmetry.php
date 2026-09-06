<?php
/**
 * Régression : ChurnScoreManager::computeScore() n'appliquait le garde-fou
 * MIN_SAMPLE_SENDS (introduit round 257) qu'à la composante "Taux récent"
 * ($recentRisk), jamais à la composante "Tendance" ($trend) — alors que
 * cette dernière réutilise pourtant $rate1 (via $decline = ($rate3 -
 * $rate1) / $rate3) et souffre exactement du même défaut : avec 1 ou 2
 * envois récents (sent_p1 < MIN_SAMPLE_SENDS), $rate1 vaut mécaniquement
 * 0.0 ou 1.0 sur un échantillon non significatif, poussant $trend à son
 * extrême (30 pts) même pour un client par ailleurs très engagé
 * (rate3=1.0 sur un historique ancien suffisant). Le commentaire de
 * $recentRisk affirmait pourtant explicitement "même traitement que la
 * composante Tendance" — jamais implémenté.
 *
 * Scénario concret corrigé : client avec bon historique ancien (sent_p3=5,
 * open_p3=5 → rate3=1.0) mais un seul envoi récent resté sans ouverture
 * (sent_p1=1, open_p1=0 → rate1=0.0, échantillon insuffisant). Avant
 * correctif : trend=30.0 (maximum). Après correctif : trend=15.0 (neutre,
 * même valeur que recentRisk dans ce cas).
 *
 * Corrigé le 06/09/2026 (round 311) : garde symétrique ajoutée sur
 * sent_p1 < MIN_SAMPLE_SENDS dans la composante Tendance.
 *
 * Test comportemental réel : appelle computeScore() (méthode privée, via
 * Reflection) avec ce jeu de données précis et vérifie le score exact
 * attendu (43, pas 58).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';

    $mgr = new ChurnScoreManager(neria_test_module());
    $ref = new ReflectionMethod($mgr, 'computeScore');
    $ref->setAccessible(true);

    $r = [
        'last_open'           => 1,
        'seconds_since_open'  => 30 * 86400, // 30 jours -> recency = 40/3 ≈ 13.33
        'sent_p1' => 1, 'open_p1' => 0, // rate1 = 0.0, échantillon insuffisant (< 3)
        'sent_p2' => 0, 'open_p2' => 0,
        'sent_p3' => 5, 'open_p3' => 5, // rate3 = 1.0, échantillon suffisant
    ];

    $result = $ref->invoke($mgr, $r);
    $score  = $result[0];

    neria_assert(
        $score === 43,
        "ChurnScoreManager::computeScore() renvoie un score de {$score} au lieu de 43 attendu pour un client à bon historique ancien mais un seul envoi récent non ouvert — régression du bug corrigé le 06/09/2026 (round 311) : la composante Tendance redeviendrait poussée à son maximum (30 pts) sur un échantillon d'1 envoi non significatif, au lieu du traitement neutre (15 pts) déjà appliqué à la composante Taux récent pour ce même cas"
    );

    return [
        'pass'    => true,
        'message' => "ChurnScoreManager::computeScore() applique bien le garde-fou MIN_SAMPLE_SENDS de façon symétrique aux composantes Taux récent ET Tendance — bug corrigé le 06/09/2026 (round 311)",
    ];
}
