<?php
/**
 * Régression : ChurnScoreManager::computeScore() plaçait ses composantes
 * "Taux récent" (recentRisk) et "Tendance" (trend) à leur EXTRÊME (30/30
 * chacune) dès qu'un client avait ne serait-ce qu'1 seul email envoyé dans
 * la période concernée (sent_p1 ou sent_p3 = 1), qu'il soit ouvert ou non
 * — un taux de 0% ou 100% calculé sur un échantillon d'1 email n'est pas
 * statistiquement significatif, contrairement au reste du fichier qui
 * applique scrupuleusement des gardes de volume minimum (round 143 pour
 * sent_p1/sent_p3 === 0, seuils MIN_SENDS ailleurs dans le module).
 *
 * Scénario concret : client avec sent_p1=1/open_p1=0 (1 email récent non
 * ouvert) et sent_p3=1/open_p3=1 (1 email ancien ouvert) obtenait un score
 * ≈100 ("risque de désabonnement élevé"), déclenchant une alerte visible
 * sur sa fiche client BO, alors que la donnée réelle (2 emails au total)
 * est bien trop maigre pour justifier un tel signal.
 *
 * Bug identifié le 31/08/2026 (round 257, audit "ratios/scores trompeurs
 * sur petit échantillon"). Corrigé le 31/08/2026 (round 257) : nouvelle
 * constante MIN_SAMPLE_SENDS=3 ; en dessous de ce volume, recentRisk/trend
 * retombent sur leur valeur "risque modéré par défaut" déjà utilisée pour
 * le cas sent_pX === 0, plutôt que d'extrapoler un extrême sur 1-2 emails.
 *
 * Test comportemental réel : invoque la VRAIE méthode privée
 * computeScore() par Reflection avec la fixture exacte du scénario ci-
 * dessus, vérifie que le score n'atteint plus l'extrême précédent.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';

    $mgr = new ChurnScoreManager(neria_test_module());
    $ref = new ReflectionMethod(ChurnScoreManager::class, 'computeScore');
    $ref->setAccessible(true);

    // sent_p1=1 (non ouvert), sent_p3=1 (ouvert), jamais ouvert récemment.
    $fixtureTinySample = [
        'sent_p1' => 1, 'open_p1' => 0,
        'sent_p2' => 0, 'open_p2' => 0,
        'sent_p3' => 1, 'open_p3' => 1,
        'last_open' => null,
        'seconds_since_open' => 0,
    ];

    $result = $ref->invoke($mgr, $fixtureTinySample);
    $score  = $result[0] ?? null;
    neria_assert(is_int($score), "computeScore() ne retourne plus [score, rate1, rate2, rate3] exploitable — jeu de test invalide");

    neria_assert(
        $score < 90,
        "ChurnScoreManager::computeScore() renvoie encore un score extrême ({$score}/100) sur un échantillon d'1 seul email par période (sent_p1=1, sent_p3=1) — régression du bug corrigé le 31/08/2026 (round 257) : une alerte 'client à risque' trompeuse serait affichée en BO sur une donnée non significative"
    );

    // Non-régression : un vrai historique conséquent (30 envois, jamais
    // ouverts récemment, ouvrait beaucoup avant) doit toujours produire un
    // score élevé — le correctif ne doit pas neutraliser la détection sur
    // un échantillon réellement significatif.
    $fixtureRealSample = [
        'sent_p1' => 20, 'open_p1' => 0,
        'sent_p2' => 15, 'open_p2' => 1,
        'sent_p3' => 20, 'open_p3' => 18,
        'last_open' => '2026-01-01 00:00:00',
        'seconds_since_open' => 86400 * 200,
    ];
    $resultReal = $ref->invoke($mgr, $fixtureRealSample);
    $scoreReal  = $resultReal[0] ?? null;
    neria_assert(
        is_int($scoreReal) && $scoreReal >= 70,
        "ChurnScoreManager::computeScore() ne détecte plus un vrai risque de désabonnement sur un échantillon significatif (20+ envois par période) — le correctif du volume minimum (round 257) aurait dû rester sans effet ici, score obtenu = " . var_export($scoreReal, true)
    );

    return [
        'pass'    => true,
        'message' => "ChurnScoreManager::computeScore() n'extrapole plus un score extrême sur un échantillon d'1-2 emails (MIN_SAMPLE_SENDS=3), tout en détectant toujours correctement un vrai risque sur un échantillon significatif — bug corrigé le 31/08/2026 (round 257)",
    ];
}
