<?php
/**
 * Régression : DomainReputationManager::checkPtr() doit respecter le
 * budget de temps DNS partagé, comme checkDkim()/checkBlacklists().
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : checkPtr() utilise
 * gethostbyaddr()/gethostbyname() — deux appels résolveur système
 * BLOQUANTS sans paramètre de timeout applicatif, contrairement à
 * dns_get_record() utilisé ailleurs. Le docblock du budget DNS
 * (DNS_TIME_BUDGET_SECS) justifie son existence précisément par le fait
 * que ce contrôle tourne dans le chemin d'exécution d'un visiteur front —
 * mais checkPtr() n'en tenait aucun compte, pouvant ajouter plusieurs
 * secondes de blocage supplémentaires même quand le budget était déjà
 * épuisé par checkDkim()/checkBlacklists() avant elle.
 *
 * Test comportemental réel (via Reflection — checkPtr() est privée) :
 * appelle checkPtr() avec un $deadline déjà expiré, vérifie qu'elle
 * retourne immédiatement (timed_out=true) SANS appeler gethostbyaddr().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());

    $ref = new ReflectionMethod(DomainReputationManager::class, 'checkPtr');
    $ref->setAccessible(true);

    $expiredDeadline = microtime(true) - 1.0;
    $start = microtime(true);
    $result = $ref->invoke($mgr, '8.8.8.8', $expiredDeadline);
    $elapsed = microtime(true) - $start;

    neria_assert(
        array_key_exists('timed_out', $result) && $result['timed_out'] === true,
        "checkPtr() avec un deadline déjà expiré ne signale pas timed_out=true — régression du bug corrigé le 09/08/2026 (round 144) : checkPtr() ignorerait de nouveau le budget DNS partagé"
    );
    neria_assert(
        $elapsed < 0.5,
        "checkPtr() a mis {$elapsed}s alors que le deadline était déjà expiré — devrait retourner immédiatement sans appeler gethostbyaddr()/gethostbyname()"
    );

    // Non-régression : sans deadline (appel historique), le comportement
    // normal (found=false pour une IP sans PTR, ou found=true) doit rester
    // inchangé — vérifie juste que l'appel ne lève pas d'erreur.
    $resultNoDeadline = $ref->invoke($mgr, '8.8.8.8', null);
    neria_assert(is_array($resultNoDeadline) && array_key_exists('found', $resultNoDeadline), "checkPtr() sans deadline ne renvoie plus une structure valide — non-régression cassée");

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::checkPtr() respecte bien le budget DNS partagé — ne lance plus de résolution bloquante quand le budget est déjà épuisé",
    ];
}
