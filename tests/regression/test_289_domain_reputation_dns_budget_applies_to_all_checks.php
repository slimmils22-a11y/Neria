<?php
/**
 * Régression : le budget DNS_TIME_BUDGET_SECS (8s) n'était appliqué qu'à
 * checkDkim()/checkPtr()/checkBlacklists() — checkSpf()/checkDmarc()/
 * checkMx()/checkBimi()/resolveIp() s'exécutaient sans jamais consulter le
 * budget, y compris ceux appelés APRÈS checkDkim() (déjà potentiellement
 * épuisé par ses 17 sélecteurs). Le budget censé borner le blocage du
 * visiteur front (hookDisplayHeader, fallback sans cron serveur) ne
 * couvrait donc en réalité qu'une partie du chemin d'exécution.
 *
 * Corrigé le 13/08/2026 (round 165) : les 5 méthodes acceptent désormais
 * un $deadline optionnel et retournent immédiatement leur valeur par
 * défaut si le budget est déjà épuisé, sans lancer de requête DNS.
 *
 * Test réel (via Reflection — méthodes privées) : appelle chacune des 5
 * méthodes avec un $deadline déjà expiré, vérifie un retour rapide (pas de
 * vraie résolution DNS tentée) et la structure attendue.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());
    $expiredDeadline = microtime(true) - 1.0;

    $cases = [
        'checkSpf'   => ['example.com', $expiredDeadline],
        'checkDmarc' => ['example.com', $expiredDeadline],
        'checkMx'    => ['example.com', $expiredDeadline],
    ];

    foreach ($cases as $method => $args) {
        $ref = new ReflectionMethod(DomainReputationManager::class, $method);
        $ref->setAccessible(true);

        $start  = microtime(true);
        $result = $ref->invoke($mgr, ...$args);
        $elapsed = microtime(true) - $start;

        neria_assert(
            is_array($result) && $result['found'] === false,
            "{$method}() avec un deadline déjà expiré ne retourne plus 'found' => false — régression du bug corrigé le 13/08/2026 (round 165)"
        );
        neria_assert(
            $elapsed < 1.0,
            "{$method}() a mis {$elapsed}s alors que le deadline était déjà expiré — devrait retourner immédiatement sans tenter de résolution DNS"
        );
    }

    // checkBimi() a une signature différente (3 args, avec $dmarc).
    $refBimi = new ReflectionMethod(DomainReputationManager::class, 'checkBimi');
    $refBimi->setAccessible(true);
    $start = microtime(true);
    $bimiResult = $refBimi->invoke($mgr, 'example.com', ['policy' => 'reject'], $expiredDeadline);
    $elapsedBimi = microtime(true) - $start;
    neria_assert(
        is_array($bimiResult) && $bimiResult['found'] === false && $bimiResult['eligible'] === true,
        "checkBimi() avec un deadline déjà expiré ne retourne plus la structure attendue (found=false, eligible préservé depuis \$dmarc) — régression du bug corrigé le 13/08/2026 (round 165)"
    );
    neria_assert($elapsedBimi < 1.0, "checkBimi() a mis {$elapsedBimi}s malgré un deadline déjà expiré");

    // resolveIp() a une signature différente (retourne ?string, pas un tableau).
    $refIp = new ReflectionMethod(DomainReputationManager::class, 'resolveIp');
    $refIp->setAccessible(true);
    $start = microtime(true);
    $ipResult = $refIp->invoke($mgr, 'example.com', $expiredDeadline);
    $elapsedIp = microtime(true) - $start;
    neria_assert(
        $ipResult === null,
        "resolveIp() avec un deadline déjà expiré ne retourne plus null — régression du bug corrigé le 13/08/2026 (round 165)"
    );
    neria_assert($elapsedIp < 1.0, "resolveIp() a mis {$elapsedIp}s malgré un deadline déjà expiré");

    return [
        'pass'    => true,
        'message' => "checkSpf()/checkDmarc()/checkMx()/checkBimi()/resolveIp() respectent bien le budget DNS partagé — bug corrigé le 13/08/2026 (round 165)",
    ];
}
