<?php
/**
 * Régression : BehavioralCronManager::run() doit poser un heartbeat
 * ('error', avec le nombre d'échecs) quand au moins une des 19 étapes a
 * échoué, pas 'ok' inconditionnel.
 *
 * Bug identifié le 14/09/2026 (round 352, audit multi-agents, angle crons
 * comportementaux) : runStep() absorbe systématiquement toute exception
 * (journalisée individuellement, jamais remontée) pour ne pas bloquer les
 * 18 autres tâches — mais cronHeartbeat('behavioral', 'ok') était appelé
 * SANS CONDITION en fin de run(), indépendamment du nombre de tâches ayant
 * réellement échoué. Si les 19 tâches échouaient toutes (ex. table
 * corrompue, régression tierce cassant CartRule::add()), le widget
 * Watchdog du tableau de bord affichait quand même "cron comportemental :
 * OK, exécuté à l'instant" alors qu'aucun email comportemental n'était
 * réellement parti ce jour-là.
 *
 * Corrigé en comptant les échecs réels (runStep() retourne désormais bool,
 * incrémente $stepFailureCount sur échec) et en posant le heartbeat
 * 'error'/'ok' en conséquence, avec le compte réel.
 *
 * Test comportemental réel : appelle runStep() via réflexion avec un
 * callable qui lève une exception, vérifie que $stepFailureCount est bien
 * incrémenté (propriété privée lue via réflexion) et que la méthode
 * retourne bien false.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php';

    $mgr = new BehavioralCronManager(neria_test_module());

    $refMethod = new ReflectionMethod(BehavioralCronManager::class, 'runStep');
    $refMethod->setAccessible(true);
    $refProp = new ReflectionProperty(BehavioralCronManager::class, 'stepFailureCount');
    $refProp->setAccessible(true);

    neria_assert(
        (int) $refProp->getValue($mgr) === 0,
        'Jeu de test invalide : stepFailureCount devrait démarrer à 0'
    );

    $resultOk = $refMethod->invoke($mgr, 'regtest738_ok', function () {});
    neria_assert($resultOk === true, "runStep() ne retourne plus true sur un succès — jeu de test invalide");
    neria_assert(
        (int) $refProp->getValue($mgr) === 0,
        "runStep() incrémente stepFailureCount même en cas de succès — régression"
    );

    $resultFail = $refMethod->invoke($mgr, 'regtest738_fail', function () {
        throw new \RuntimeException('erreur de test round 352');
    });
    neria_assert(
        $resultFail === false,
        "runStep() ne retourne plus false sur un échec — régression du bug corrigé le 14/09/2026 (round 352) : run() ne pourrait plus distinguer un run sain d'un run en échec"
    );
    neria_assert(
        (int) $refProp->getValue($mgr) === 1,
        "runStep() n'incrémente plus stepFailureCount sur un échec — régression du bug corrigé le 14/09/2026 (round 352)"
    );

    // Vérification structurelle complémentaire : run() doit remettre le
    // compteur à 0 en tête, et poser le heartbeat en fonction de sa valeur
    // finale (pas 'ok' inconditionnel).
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
    neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');
    neria_assert(
        strpos($src, '$this->stepFailureCount = 0;') !== false,
        "run() ne remet plus stepFailureCount à 0 en tête d'exécution — régression du bug corrigé le 14/09/2026 (round 352) : les échecs s'accumuleraient de façon incohérente entre deux runs"
    );
    neria_assert(
        strpos($src, "\$this->stepFailureCount > 0 ? 'error' : 'ok'") !== false,
        "run() ne pose plus le heartbeat en fonction des échecs réels — régression du bug corrigé le 14/09/2026 (round 352) : 'ok' redeviendrait inconditionnel même si toutes les tâches ont échoué"
    );

    return [
        'pass'    => true,
        'message' => "BehavioralCronManager::runStep() retourne bien un bool et comptabilise les échecs réels, utilisés par run() pour poser un heartbeat 'error' (pas 'ok' inconditionnel) — bug corrigé le 14/09/2026 (round 352)",
    ];
}
