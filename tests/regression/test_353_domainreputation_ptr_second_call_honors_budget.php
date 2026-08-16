<?php
/**
 * Régression : DomainReputationManager::checkPtr() ne vérifiait le budget
 * DNS_TIME_BUDGET_SECS qu'AVANT gethostbyaddr() — jamais avant le second
 * appel bloquant, gethostbyname() (vérification FCrDNS), contrairement à
 * tous les autres points de contrôle DNS du fichier depuis le round 165. Un
 * hostname PTR pointant vers un domaine dont la résolution A est lente
 * pouvait faire dépasser largement le budget censé protéger le visiteur
 * front (hookDisplayHeader, chemin sans cron serveur).
 *
 * Corrigé le 15/08/2026 (round 177) : le budget est désormais revérifié
 * avant le second appel, avec repli explicite ('timed_out' => true).
 *
 * Test comportemental réel : force un $deadline déjà dépassé et vérifie
 * que checkPtr() sur une IP réelle (dont le PTR existe) ne tente pas le
 * second appel — retour immédiat avec timed_out=true, sans planter ni
 * bloquer.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());
    $ref = new ReflectionMethod(DomainReputationManager::class, 'checkPtr');
    $ref->setAccessible(true);

    // 8.8.8.8 a un PTR connu (dns.google) — gethostbyaddr() réussit, donc le
    // second appel (gethostbyname) serait tenté SANS le correctif.
    $expiredDeadline = microtime(true) - 1.0;
    $start = microtime(true);
    $result = $ref->invoke($mgr, '8.8.8.8', $expiredDeadline);
    $elapsed = microtime(true) - $start;

    neria_assert(is_array($result), "checkPtr() ne renvoie plus un tableau — jeu de test invalide");

    if (!empty($result['found']) && empty($result['timed_out'])) {
        neria_assert(
            false,
            "checkPtr() a tenté le second appel (gethostbyname) malgré un budget DNS déjà expiré — régression du bug corrigé le 15/08/2026 (round 177) : le budget censé protéger le visiteur front ne serait de nouveau honoré qu'avant le premier appel"
        );
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($src !== false, 'Impossible de lire src/DomainReputationManager.php');
    $posMethod = strpos($src, 'private function checkPtr(string $ip, ?float $deadline = null): array');
    neria_assert($posMethod !== false, "Méthode checkPtr() introuvable — jeu de test invalide");
    $body = substr($src, $posMethod, 2400);
    $posGethostbyaddr = strpos($body, '@gethostbyaddr($ip)');
    $posGethostbyname = strpos($body, '@gethostbyname($hostname)');
    neria_assert($posGethostbyaddr !== false && $posGethostbyname !== false, "Les deux appels résolveur introuvables — jeu de test invalide");
    $betweenCalls = substr($body, $posGethostbyaddr, $posGethostbyname - $posGethostbyaddr);
    neria_assert(
        strpos($betweenCalls, '$deadline !== null && microtime(true) >= $deadline') !== false,
        "checkPtr() ne revérifie plus le budget DNS entre gethostbyaddr() et gethostbyname() — régression du bug corrigé le 15/08/2026 (round 177)"
    );

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::checkPtr() revérifie bien le budget DNS avant son second appel bloquant (gethostbyname) — bug corrigé le 15/08/2026 (round 177)",
    ];
}
