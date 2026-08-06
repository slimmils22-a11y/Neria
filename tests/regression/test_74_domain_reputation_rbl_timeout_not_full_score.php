<?php
/**
 * Régression : DomainReputationManager::checkBlacklists() doit distinguer
 * un budget DNS épuisé (aucune RBL réellement interrogée) d'un "0 hit après
 * vérification complète", et computeScore() ne doit pas accorder les
 * points pleins (25/25) sur la composante blacklist dans le premier cas.
 *
 * Bug réel corrigé le 06/08/2026 (round 71) : runFullCheck() partage un
 * budget de temps DNS unique entre checkDkim() puis checkBlacklists(). Si
 * checkDkim() épuise tout le budget, checkBlacklists() sort dès la
 * première itération (checked=0, hits=[]) — indiscernable pour
 * computeScore() d'un "42/42 RBL interrogées, aucun hit" (également
 * hits=[]). Un domaine réellement blacklisté au moment du check pouvait
 * ainsi obtenir un score parfait sur cette composante, sans aucune alerte.
 *
 * Test comportemental réel : appelle checkBlacklists() avec un deadline
 * déjà expiré (aucune requête DNS réelle n'est donc jamais tentée — la
 * boucle sort dès la 1re itération), puis computeScore() avec le résultat,
 * et vérifie que le score de la composante blacklist n'est plus 25/25.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());

    $checkBlacklists = new ReflectionMethod(DomainReputationManager::class, 'checkBlacklists');
    $checkBlacklists->setAccessible(true);
    $computeScore = new ReflectionMethod(DomainReputationManager::class, 'computeScore');
    $computeScore->setAccessible(true);

    $expiredDeadline = microtime(true) - 1.0;
    $bl = $checkBlacklists->invoke($mgr, '1.2.3.4', $expiredDeadline);

    neria_assert($bl['checked'] === 0, "checkBlacklists() a interrogé au moins 1 RBL malgré un deadline déjà expiré — jeu de test invalide");
    neria_assert($bl['hits'] === [], "jeu de test invalide : hits non vide");
    neria_assert(
        $bl['timed_out'] === true,
        "checkBlacklists() ne renvoie plus 'timed_out' => true quand le budget DNS est épuisé avant toute vérification — régression du bug corrigé le 06/08/2026 (round 71)"
    );

    // Composants neutres/vides pour isoler la contribution de $bl au score.
    $empty = ['found' => false];
    $scoreWithTimeout = $computeScore->invoke($mgr, $empty, $empty, $empty, $empty, $bl);

    $blClean = ['checked' => 42, 'hits' => [], 'clean' => 42, 'timed_out' => false];
    $scoreClean = $computeScore->invoke($mgr, $empty, $empty, $empty, $empty, $blClean);

    neria_assert(
        $scoreWithTimeout < $scoreClean,
        "computeScore() attribue le même score (ou plus) qu'une vérification RBL réellement complète et propre alors que le budget DNS était épuisé (timeout={$scoreWithTimeout}, propre={$scoreClean}) — régression du bug corrigé le 06/08/2026 (round 71) : un domaine réellement blacklisté sur une RBL jamais interrogée obtiendrait de nouveau un score parfait sur cette composante"
    );

    return [
        'pass'    => true,
        'message' => "computeScore() n'accorde plus les points pleins de la composante blacklist quand la vérification RBL a été tronquée par le budget DNS (score={$scoreWithTimeout} < propre={$scoreClean})",
    ];
}
