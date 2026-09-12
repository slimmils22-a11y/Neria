<?php
/**
 * Régression : DomainReputationManager::resolveIp() doit distinguer une
 * panne DNS transitoire (dns_get_record() === false, erreur réseau/
 * résolveur) d'un NXDOMAIN confirmé (aucune IP réellement attribuée à ce
 * domaine), et computeScore() ne doit accorder un score de 0 sur les
 * composantes PTR/blacklist que dans le second cas — pas dans le premier.
 *
 * Bug identifié le 12/09/2026 (round 342, audit DomainReputationManager) :
 * resolveIp() ne distinguait jamais ces deux cas (contrairement à
 * checkSpf()/checkDkim()/checkDmarc(), déjà corrigés au round 177 pour
 * exactement ce piège) — une panne DNS transitoire au moment précis du
 * check faisait perdre 30 points (PTR 5 + blacklists 25, cf.
 * computeScore()) à un domaine parfaitement sain, résultat mis en cache
 * 24h — traité à tort comme "confirmé sans IP" au lieu de "vérification
 * non aboutie".
 *
 * Test comportemental réel : appelle resolveIp() sur un domaine réellement
 * inexistant (NXDOMAIN — dns_get_record() renvoie [], pas false) pour
 * confirmer que le chemin nominal (échec réel) reste inchangé (dns_error
 * === false), PUIS teste computeScore() directement sur les 2 scénarios
 * (ip_missing seul vs ip_missing + dns_error) pour prouver la différence
 * de score — reproduire une VRAIE panne réseau/résolveur DNS n'est pas
 * fiable en test (dépend de l'infrastructure DNS locale), donc ce second
 * volet est structurel sur des tableaux fabriqués, même contrainte que
 * test_74 (round 71) pour le même fichier.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());

    $resolveIp = new ReflectionMethod(DomainReputationManager::class, 'resolveIp');
    $resolveIp->setAccessible(true);
    $computeScore = new ReflectionMethod(DomainReputationManager::class, 'computeScore');
    $computeScore->setAccessible(true);

    // Chemin nominal : un domaine réellement inexistant (NXDOMAIN) ne doit
    // JAMAIS être signalé comme dns_error — seul un vrai échec réseau/
    // résolveur (dns_get_record() === false) doit l'être.
    $dnsError = true; // valeur volontairement fausse au départ pour prouver que resolveIp() la réinitialise
    $ip = $resolveIp->invokeArgs($mgr, ['neria-round342-nxdomain-test.invalid', null, &$dnsError]);
    neria_assert($ip === null, "jeu de test invalide : neria-round342-nxdomain-test.invalid a résolu une IP réelle");
    neria_assert(
        $dnsError === false,
        "resolveIp() signale à tort dns_error=true pour un NXDOMAIN confirmé — régression du correctif du 12/09/2026 (round 342)"
    );

    // Composants neutres/vides pour isoler la contribution de $ptr/$bl au score.
    $empty = ['found' => false];

    $ptrRealFailure = ['found' => false, 'hostname' => null, 'skipped' => false, 'ip_missing' => true, 'dns_error' => false];
    $blRealFailure  = ['checked' => 0, 'hits' => [], 'clean' => 0, 'skipped' => false, 'ip_missing' => true, 'dns_error' => false];
    $scoreRealFailure = $computeScore->invoke($mgr, $empty, $empty, $empty, $ptrRealFailure, $blRealFailure);

    $ptrDnsError = ['found' => false, 'hostname' => null, 'skipped' => false, 'ip_missing' => true, 'dns_error' => true];
    $blDnsError  = ['checked' => 0, 'hits' => [], 'clean' => 0, 'skipped' => false, 'ip_missing' => true, 'dns_error' => true];
    $scoreDnsError = $computeScore->invoke($mgr, $empty, $empty, $empty, $ptrDnsError, $blDnsError);

    neria_assert(
        $scoreRealFailure === 0,
        "computeScore() n'attribue plus 0 point pour un NXDOMAIN confirmé (ip_missing sans dns_error) — régression du comportement établi round 165 (score obtenu : {$scoreRealFailure})"
    );
    neria_assert(
        $scoreDnsError > $scoreRealFailure,
        "computeScore() attribue le même score (ou moins) pour une panne DNS transitoire que pour un NXDOMAIN confirmé (dns_error={$scoreDnsError}, échec réel={$scoreRealFailure}) — régression du correctif du 12/09/2026 (round 342) : un domaine sain victime d'une panne DNS passagère perdrait de nouveau 30 points à tort"
    );
    neria_assert(
        $scoreDnsError === 14,
        "computeScore() n'attribue plus le score neutre attendu (2 PTR + 12 blacklist = 14) pour une panne DNS transitoire — obtenu : {$scoreDnsError}"
    );

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::resolveIp() distingue bien une panne DNS transitoire d'un NXDOMAIN confirmé, et computeScore() applique désormais un score neutre (14 pts) au lieu de 0 dans le premier cas — bug corrigé le 12/09/2026 (round 342)",
    ];
}
