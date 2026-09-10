<?php
/**
 * Régression : `DeliverabilityScorer::$dnsCache` (propriété STATIC, donc
 * persistante au-delà d'une seule requête HTTP tant que le worker PHP-FPM
 * n'est pas recyclé — piège déjà documenté ailleurs dans le module, "État
 * static PHP-FPM ≠ portée requête") n'avait AUCUNE expiration. Un marchand
 * corrigeant son enregistrement SPF/DKIM/DMARC chez son registrar puis
 * relançant l'analyse "Délivrabilité" quelques minutes après pouvait se
 * voir réafficher l'ANCIEN résultat (pénalité DNS obsolète) si la requête
 * retombait sur le même worker — potentiellement pendant des heures selon
 * `pm.max_requests`, sans qu'aucune action du marchand ne le rafraîchisse.
 *
 * Bug identifié le 10/09/2026 (round 331, audit DeliverabilityScorer/
 * DomainReputationManager).
 *
 * Corrigé le 10/09/2026 (round 331) : TTL de 5 minutes
 * (DNS_CACHE_TTL_SECS=300) — chaque entrée du cache porte désormais un
 * horodatage `cached_at`, ignoré (et donc réévalué en base réelle) au-delà
 * du TTL.
 *
 * Test comportemental réel : injecte directement (via Reflection sur la
 * propriété statique) une entrée de cache FRAÎCHE avec un résultat
 * factice distinguable, vérifie qu'elle est bien servie telle quelle
 * (cache HIT) ; injecte ensuite une entrée PÉRIMÉE (cached_at au-delà du
 * TTL) avec le même résultat factice, vérifie qu'elle N'EST PLUS servie
 * (nouvelle résolution DNS réelle déclenchée, résultat factice absent).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    $scorer = new DeliverabilityScorer();
    $method = new ReflectionMethod(DeliverabilityScorer::class, 'getDnsStatus');
    $method->setAccessible(true);

    $cacheProp = new ReflectionProperty(DeliverabilityScorer::class, 'dnsCache');
    $cacheProp->setAccessible(true);
    $originalCache = $cacheProp->getValue();

    $testDomain = 'regtest669-fake-domain.example';
    // Résultat factice distinguable (spf=true impossible pour un domaine
    // qui n'existe pas réellement — preuve que le cache, pas une vraie
    // résolution DNS, a produit la réponse).
    $fakeResult = ['spf' => true, 'dmarc' => true, 'dkim' => true, 'timed_out' => false, 'regtest_marker' => true];

    try {
        // ── Cas 1 : entrée FRAÎCHE (cached_at = maintenant) → cache HIT attendu ──
        $cacheProp->setValue(null, [$testDomain => ['result' => $fakeResult, 'cached_at' => microtime(true)]]);
        $resultFresh = $method->invoke($scorer, $testDomain);
        neria_assert(
            ($resultFresh['regtest_marker'] ?? false) === true,
            "getDnsStatus() ne sert plus une entrée de cache FRAÎCHE (cached_at récent) — jeu de test invalide ou régression du comportement de cache normal"
        );

        // ── Cas 2 : entrée PÉRIMÉE (cached_at il y a 10 minutes, TTL=5 minutes) → cache MISS attendu ──
        $cacheProp->setValue(null, [$testDomain => ['result' => $fakeResult, 'cached_at' => microtime(true) - 600]]);
        $resultStale = $method->invoke($scorer, $testDomain);
        neria_assert(
            ($resultStale['regtest_marker'] ?? false) !== true,
            "getDnsStatus() sert encore une entrée de cache PÉRIMÉE (cached_at > TTL) au lieu de relancer une résolution DNS réelle — régression du bug corrigé le 10/09/2026 (round 331) : un marchand corrigeant SPF/DKIM/DMARC verrait l'ancien résultat survivre indéfiniment tant que le worker PHP-FPM n'est pas recyclé"
        );
    } finally {
        $cacheProp->setValue(null, $originalCache);
    }

    return [
        'pass'    => true,
        'message' => "DeliverabilityScorer::getDnsStatus() applique désormais un TTL de 5 minutes sur son cache statique — une entrée périmée déclenche une vraie résolution DNS au lieu de servir indéfiniment l'ancien résultat — bug corrigé le 10/09/2026 (round 331)",
    ];
}
