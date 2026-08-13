<?php
/**
 * Régression : computeScore() traitait 'skipped' => true identiquement,
 * que ce soit pour une IP privée légitime (environnement de dev/test) OU
 * pour un domaine expéditeur introuvable/non résolvable ($ip === null) —
 * les deux cas produisaient le même tableau et obtenaient les points
 * pleins (5/5 PTR + 25/25 RBL = 30 pts sur 100), alors qu'un domaine cassé
 * ne devrait strictement rien recevoir sur ces composantes.
 *
 * Corrigé le 13/08/2026 (round 165) : runFullCheckLocked() distingue
 * désormais 'ip_missing' (échec réel, 0 pt) de 'skipped' (IP privée
 * légitime, points pleins, comportement inchangé).
 *
 * Test comportemental réel (via Reflection — computeScore() est privée) :
 * compare le score d'un rapport avec ip_missing=true à celui d'un rapport
 * avec skipped=true (IP privée) sur des composantes PTR/RBL par ailleurs
 * identiques — vérifie que le premier obtient bien 30 points de moins.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    $mgr = new DomainReputationManager(neria_test_module());
    $ref = new ReflectionMethod(DomainReputationManager::class, 'computeScore');
    $ref->setAccessible(true);

    $empty = ['found' => false];

    $ptrPrivateIp  = ['found' => false, 'hostname' => null, 'skipped' => true, 'ip_missing' => false];
    $blPrivateIp   = ['checked' => 0, 'hits' => [], 'clean' => 0, 'skipped' => true, 'ip_missing' => false];
    $ptrIpMissing  = ['found' => false, 'hostname' => null, 'skipped' => false, 'ip_missing' => true];
    $blIpMissing   = ['checked' => 0, 'hits' => [], 'clean' => 0, 'skipped' => false, 'ip_missing' => true];

    $scorePrivateIp = (int) $ref->invoke($mgr, $empty, $empty, $empty, $ptrPrivateIp, $blPrivateIp);
    $scoreIpMissing = (int) $ref->invoke($mgr, $empty, $empty, $empty, $ptrIpMissing, $blIpMissing);

    neria_assert(
        $scoreIpMissing === 0,
        "computeScore() attribue {$scoreIpMissing} point(s) alors que le domaine n'a aucune IP résolvable (ip_missing) — régression du bug corrigé le 13/08/2026 (round 165) : un domaine expéditeur cassé obtiendrait de nouveau un plancher de score au lieu de 0"
    );
    neria_assert(
        $scorePrivateIp === 30,
        "computeScore() n'attribue plus les 30 points pleins (5 PTR + 25 RBL) pour une IP privée légitime (skipped) — non-régression cassée : ce cas doit rester inchangé"
    );
    neria_assert(
        $scorePrivateIp - $scoreIpMissing === 30,
        "L'écart entre IP privée légitime (skipped) et domaine sans IP (ip_missing) n'est plus de 30 points — régression du bug corrigé le 13/08/2026 (round 165)"
    );

    return [
        'pass'    => true,
        'message' => "computeScore() distingue bien un domaine sans IP résolvable (0 pt) d'une IP privée légitime (30 pts, inchangé) — bug corrigé le 13/08/2026 (round 165)",
    ];
}
