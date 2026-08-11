<?php
/**
 * Régression : DomainReputationManager::checkDkim() doit signaler
 * `timed_out` quand le budget DNS est épuisé, comme checkBlacklists()
 * (round 74) — et computeScore() doit accorder un score neutre (12/25)
 * dans ce cas, pas 0.
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : checkDkim() cassait sa
 * boucle de la même façon que checkBlacklists() quand le budget DNS
 * (DNS_TIME_BUDGET_SECS) était épuisé, mais son tableau de retour ne
 * comportait aucune trace de cette troncature — computeScore() traitait ce
 * cas exactement comme "DKIM absent après vérification complète des 17
 * sélecteurs" (0 point), faussant silencieusement le score sur un
 * résolveur DNS lent alors que le domaine peut avoir du DKIM configuré sur
 * un sélecteur non encore atteint.
 *
 * Test comportemental réel (via Reflection — checkDkim() est privée mais
 * ne dépend d'aucun état d'instance) : appelle checkDkim() avec un
 * $deadline déjà expiré (microtime(true) - 1), vérifie que 'timed_out'
 * vaut true et 'found' vaut false sans avoir interrogé aucun sélecteur DNS
 * (retour immédiat).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());

    $ref = new ReflectionMethod(DomainReputationManager::class, 'checkDkim');
    $ref->setAccessible(true);

    $expiredDeadline = microtime(true) - 1.0;
    $start = microtime(true);
    $result = $ref->invoke($mgr, 'example.com', $expiredDeadline);
    $elapsed = microtime(true) - $start;

    neria_assert(
        array_key_exists('timed_out', $result),
        "checkDkim() ne renvoie plus la clé 'timed_out' — régression du bug corrigé le 09/08/2026 (round 144)"
    );
    neria_assert(
        $result['timed_out'] === true,
        "checkDkim() avec un deadline déjà expiré ne signale pas timed_out=true (obtenu : " . var_export($result['timed_out'], true) . ") — régression du bug corrigé le 09/08/2026 (round 144) : computeScore() traiterait de nouveau une troncature DNS comme un DKIM absent après vérification complète"
    );
    neria_assert($result['found'] === false, "found devrait être false quand le budget est épuisé avant toute vérification");
    neria_assert($elapsed < 1.0, "checkDkim() a mis {$elapsed}s alors que le deadline était déjà expiré — devrait retourner immédiatement sans interroger aucun sélecteur DNS");

    // computeScore() doit accorder 12 pts (neutre), pas 0, quand DKIM a timed_out
    $refScore = new ReflectionMethod(DomainReputationManager::class, 'computeScore');
    $refScore->setAccessible(true);
    $spf  = ['found' => false, 'policy' => null];
    $dmarc = ['found' => false, 'policy' => null];
    $ptr  = ['found' => false, 'skipped' => false, 'valid' => false];
    $bl   = ['hits' => [], 'checked' => 0, 'clean' => 0];
    $scoreTimedOut = $refScore->invoke($mgr, $spf, $result, $dmarc, $ptr, $bl);
    $scoreAbsent = $refScore->invoke($mgr, $spf, ['found' => false, 'selector' => null, 'record' => null, 'timed_out' => false], $dmarc, $ptr, $bl);

    neria_assert(
        $scoreTimedOut > $scoreAbsent,
        "computeScore() n'accorde pas un score plus élevé pour un DKIM tronqué (timed_out={$scoreTimedOut}) que pour un DKIM absent après vérification complète ({$scoreAbsent}) — régression du bug corrigé le 09/08/2026 (round 144)"
    );

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::checkDkim() signale bien la troncature par budget DNS, et computeScore() n'accorde plus les points pleins de perte dans ce cas",
    ];
}
