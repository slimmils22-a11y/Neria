<?php
/**
 * Régression : `DomainReputationManager` ne mutualisait jamais son cycle
 * DNS/RBL entre deux boutiques envoyant depuis le MÊME domaine expéditeur
 * (sous-domaines partageant SPF/DKIM/DMARC racine, ou même expéditeur
 * transactionnel configuré sur les deux). Cache (`CONFIG_CACHE`/
 * `CONFIG_LAST_CHECK`) ET verrou anti-concurrence (`GET_LOCK`) étaient
 * tous deux scopés PAR BOUTIQUE (`id_shop`), jamais par domaine — deux
 * boutiques au même domaine faisaient donc chacune leur propre
 * vérification complète (jusqu'à 84 requêtes RBL redondantes par cycle),
 * sans coordination entre elles, avec un risque réel de grade/score
 * DIFFÉRENT affiché pour chacune alors que la réputation DNS sous-jacente
 * est objectivement unique.
 *
 * Bug identifié le 04/09/2026 (round 299, audit "réputation de domaine —
 * OAuth freshness et cohérence multi-boutique").
 *
 * Corrigé le 04/09/2026 (round 299) : `findFreshReportForDomain()`
 * cherche, avant toute résolution DNS, si une AUTRE boutique active a déjà
 * un rapport frais pour ce MÊME domaine (comparaison sur `$data['domain']`)
 * et le réutilise directement ; `lockName()` fait basculer le nom du
 * verrou MySQL sur un hash du domaine (au lieu de `id_shop`) pour que deux
 * boutiques au même domaine partagent aussi la protection anti-double-
 * exécution, pas seulement le cache.
 *
 * Test comportemental réel : simule un rapport frais déjà en cache pour
 * une boutique "sœur" (id_shop=999, jamais utilisé en pratique) avec un
 * domaine donné, vérifie que `findFreshReportForDomain()` le retrouve bien
 * pour ce domaine (et retourne bien `null` pour un domaine différent) ;
 * vérifie aussi que `lockName()` bascule bien sur un hash de domaine
 * distinct de l'ancien nom basé sur `id_shop`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $siblingShopId = 999;
    $sharedDomain  = 'regtest557-shared-domain.example.test';

    $fakeReport = [
        'domain'     => $sharedDomain,
        'ip'         => null,
        'spf'        => [], 'dkim' => [], 'dmarc' => [], 'mx' => [],
        'ptr'        => [], 'bimi' => [], 'blacklists' => [],
        'score'      => 42,
        'grade'      => 'B',
        'color'      => '#000000',
        'checked_at' => date('Y-m-d H:i:s'),
        'timestamp'  => time(),
    ];

    Configuration::updateValue(DomainReputationManager::CONFIG_CACHE, json_encode($fakeReport), false, null, $siblingShopId);
    Configuration::updateValue(DomainReputationManager::CONFIG_LAST_CHECK, time(), false, null, $siblingShopId);

    try {
        $mgr = new DomainReputationManager(neria_test_module());

        $refFind = new ReflectionMethod(DomainReputationManager::class, 'findFreshReportForDomain');
        $refFind->setAccessible(true);

        $found = $refFind->invoke($mgr, $sharedDomain, [$siblingShopId]);
        neria_assert(
            is_array($found) && ($found['domain'] ?? null) === $sharedDomain && (int) ($found['score'] ?? -1) === 42,
            "DomainReputationManager::findFreshReportForDomain() ne retrouve plus le rapport frais d'une boutique sœur pour le même domaine — régression du bug corrigé le 04/09/2026 (round 299) : deux boutiques au même domaine relanceraient de nouveau chacune leur propre cycle DNS/RBL complet, avec un risque de grade/score divergent pour la même réputation réelle"
        );

        $notFound = $refFind->invoke($mgr, 'un-domaine-totalement-different.example.test', [$siblingShopId]);
        neria_assert(
            $notFound === null,
            "DomainReputationManager::findFreshReportForDomain() retourne à tort un rapport pour un domaine DIFFÉRENT — régression : deux boutiques à domaines distincts partageraient à tort le même rapport de réputation"
        );

        $refLock = new ReflectionMethod(DomainReputationManager::class, 'lockName');
        $refLock->setAccessible(true);
        $lockDomain = $refLock->invoke($mgr, $sharedDomain);
        $lockEmpty  = $refLock->invoke($mgr, '');
        neria_assert(
            is_string($lockDomain) && strpos($lockDomain, 'neria_domain_reputation_dom_') === 0,
            "DomainReputationManager::lockName() ne bascule plus sur un nom de verrou basé sur le domaine — régression du bug corrigé le 04/09/2026 (round 299) : le verrou anti-concurrence redeviendrait scopé uniquement par id_shop, inefficace entre boutiques partageant le même domaine"
        );
        neria_assert(
            $lockDomain !== $lockEmpty,
            "DomainReputationManager::lockName() ne distingue plus le cas domaine résolu du repli id_shop (domaine vide)"
        );

        return [
            'pass'    => true,
            'message' => "DomainReputationManager mutualise désormais son cache ET son verrou par domaine expéditeur réel, évitant les cycles DNS/RBL redondants et les grades divergents entre boutiques partageant le même domaine — bug corrigé le 04/09/2026 (round 299)",
        ];
    } finally {
        Configuration::deleteFromContext(DomainReputationManager::CONFIG_CACHE, null, $siblingShopId);
        Configuration::deleteFromContext(DomainReputationManager::CONFIG_LAST_CHECK, null, $siblingShopId);
    }
}
