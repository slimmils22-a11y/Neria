<?php
/**
 * Régression : DomainReputationManager::computeScore() écrasait
 * INCONDITIONNELLEMENT le score blacklist ($blScore) par une valeur
 * neutre (12/25) dès qu'un timeout DNS survenait pendant le balayage des
 * RBL — y compris quand des hits AVAIENT déjà été confirmés AVANT
 * l'épuisement du budget DNS. Un hit RBL confirmé est une preuve
 * positive, jamais une incertitude : un domaine à 5 hits confirmés +
 * timeout affichait un score neutre de 12/25, MEILLEUR que le score réel
 * de 0/25 pourtant déjà établi par ces hits — inversant silencieusement
 * la logique de pénalisation pour le cas le plus grave (déjà confirmé
 * blacklisté sur plusieurs RBL).
 *
 * Corrigé le 05/09/2026 (round 305) : la neutralisation à 12 ne
 * s'applique désormais que si hits=0 (vérification réellement
 * incomplète, aucune preuve dans un sens ou l'autre). Si des hits ont
 * déjà été confirmés avant le timeout, le score calculé à partir de ces
 * hits réels est conservé tel quel, jamais remonté artificiellement.
 *
 * Test comportemental réel : appelle computeScore() (privée, via
 * reflection) avec un tableau $bl simulant 5 hits confirmés PUIS un
 * timeout — le score blacklist doit refléter les 5 hits (0/25), pas la
 * valeur neutre 12/25. Vérifie aussi que le cas "0 hit + timeout" garde
 * bien le score neutre 12/25 (comportement préservé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());
    $ref = new ReflectionMethod(DomainReputationManager::class, 'computeScore');
    $ref->setAccessible(true);

    $spf  = ['valid' => true];
    $dkim = ['valid' => true];
    $dmarc = ['valid' => true];
    $ptr  = ['valid' => true];

    // Cas 1 : 5 hits confirmés + timeout — le score blacklist doit rester
    // basé sur les 5 hits réels (max(0, 25 - 5*5) = 0), pas 12.
    $blWithHits = ['hits' => ['rbl1', 'rbl2', 'rbl3', 'rbl4', 'rbl5'], 'timed_out' => true];
    $scoreWithHits = $ref->invoke($mgr, $spf, $dkim, $dmarc, $ptr, $blWithHits);

    // Cas 2 : 0 hit + timeout — comportement neutre préservé (blScore=12).
    $blNoHits = ['hits' => [], 'timed_out' => true];
    $scoreNoHits = $ref->invoke($mgr, $spf, $dkim, $dmarc, $ptr, $blNoHits);

    $diff = $scoreNoHits - $scoreWithHits;

    neria_assert(
        $diff === 12,
        "computeScore() : l'écart entre 0-hit-timeout (blScore=12) et 5-hits-timeout (blScore=0 attendu) devrait être exactement 12 points, écart obtenu : {$diff} — régression du bug corrigé le 05/09/2026 (round 305) : un timeout DNS écraserait de nouveau des hits RBL réellement confirmés par un score neutre plus optimiste que le score réel"
    );

    // Vérification structurelle complémentaire.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php');
    neria_assert($src !== false, 'Impossible de lire src/DomainReputationManager.php');
    neria_assert(
        strpos($src, "elseif (!empty(\$bl['timed_out']) && \$hits === 0) {") !== false,
        "DomainReputationManager::computeScore() ne restreint plus la neutralisation \$blScore=12 au cas hits=0 — régression du bug corrigé le 05/09/2026 (round 305)"
    );

    return [
        'pass'    => true,
        'message' => "DomainReputationManager::computeScore() ne neutralise plus le score blacklist quand des hits RBL ont déjà été confirmés avant un timeout DNS (score=0 pour 5 hits confirmés < score=12 pour 0 hit+timeout) — bug corrigé le 05/09/2026 (round 305)",
    ];
}
