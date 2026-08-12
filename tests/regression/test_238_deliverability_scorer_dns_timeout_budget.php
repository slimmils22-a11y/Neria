<?php
/**
 * Régression : DeliverabilityScorer::getDnsStatus() doit avoir un budget de
 * temps DNS (comme DomainReputationManager::checkDkim(), round 144) et
 * distinguer un échec DNS/réseau réel d'un domaine sans SPF/DMARC/DKIM —
 * score() ne doit plus pénaliser (-24 points) une simple panne transitoire.
 *
 * Bug réel corrigé le 09/08/2026 (round 151) : getDnsStatus() enchaînait
 * jusqu'à 14 requêtes DNS synchrones (SPF + DMARC + 12 sélecteurs DKIM)
 * sans AUCUNE limite de temps, dans le thread de la requête HTTP BO — un
 * résolveur DNS lent pouvait bloquer la page "Analyser la délivrabilité"
 * plusieurs dizaines de secondes. De plus, toute exception (timeout,
 * réseau) retombait silencieusement sur spf=false/dmarc=false/dkim=false,
 * indiscernable d'une vraie absence de configuration — un simple incident
 * réseau faisait chuter le score de 24 points avec un faux diagnostic
 * "SPF/DMARC/DKIM non configuré".
 *
 * Test comportemental réel (getDnsStatus, un vrai domaine rapide) + test
 * structurel (budget de temps + neutralité du score en cas de timed_out,
 * difficiles à déclencher de façon fiable sans mocker le réseau).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php';

    $scorer = new DeliverabilityScorer();
    $method = new ReflectionMethod(DeliverabilityScorer::class, 'getDnsStatus');
    $method->setAccessible(true);

    // Comportemental : un domaine reel et rapide (google.com, DNS quasi
    // instantane) ne doit jamais etre marque timed_out en usage normal.
    $result = $method->invoke($scorer, 'google.com');
    neria_assert(
        is_array($result) && array_key_exists('timed_out', $result),
        "getDnsStatus() ne renvoie plus la cle 'timed_out' — regression du bug corrige le 09/08/2026 (round 151)"
    );
    neria_assert(
        $result['timed_out'] === false,
        "getDnsStatus() marque a tort 'timed_out' sur une resolution DNS normale et rapide (google.com) — jeu de test invalide ou regression"
    );

    // Structurel : verifie la presence du budget de temps et de la
    // neutralisation du score en cas de timeout.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DeliverabilityScorer.php');
    neria_assert(
        strpos($src, 'private const DNS_TIME_BUDGET_SECS = 8.0;') !== false,
        "DeliverabilityScorer n'a plus de budget de temps DNS (DNS_TIME_BUDGET_SECS) — regression du bug corrige le 09/08/2026 (round 151) : jusqu'a 14 requetes DNS synchrones sans limite redeviendraient possibles dans le thread BO"
    );
    neria_assert(
        strpos($src, "if (microtime(true) >= \$deadline) {") !== false,
        "getDnsStatus() ne verifie plus le budget de temps dans la boucle des selecteurs DKIM — regression du bug corrige le 09/08/2026 (round 151)"
    );
    neria_assert(
        strpos($src, "if (!empty(\$dns['timed_out'])) {") !== false,
        "score() ne neutralise plus la penalite SPF/DMARC/DKIM en cas de verification DNS interrompue — regression du bug corrige le 09/08/2026 (round 151) : une panne reseau transitoire ferait de nouveau chuter le score de 24 points a tort"
    );

    return [
        'pass'    => true,
        'message' => "DeliverabilityScorer::getDnsStatus() a bien un budget de temps DNS et score() neutralise la penalite en cas de verification interrompue",
    ];
}
