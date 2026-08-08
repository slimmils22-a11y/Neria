<?php
/**
 * Régression : generateMilestoneVoucher() ne doit PLUS libérer la
 * réservation anti-doublon (neria_milestone_voucher) quand CartRule::add()
 * échoue — elle doit se contenter de relancer l'exception, laissant
 * checkMilestone() décider seul de la libérer (uniquement si l'envoi de
 * l'email échoue lui-même).
 *
 * Bug réel corrigé le 08/08/2026 (round 133) : avant ce correctif,
 * generateMilestoneVoucher() supprimait sa propre réservation dès l'échec
 * de CartRule::add(), AVANT même que checkMilestone() tente Mail::Send().
 * Si l'email partait quand même avec succès (voucherCode vide), aucune
 * réservation n'était recréée — le palier redevenait totalement "libre"
 * malgré l'email déjà envoyé. Un second déclenchement (hook dupliqué,
 * retraitement de statut de commande) pouvait alors renvoyer l'email UNE
 * SECONDE FOIS et, cette fois, créer un vrai bon — double bon pour un même
 * jalon.
 *
 * Test structurel (forcer un vrai échec de CartRule::add() dépendrait de
 * détails de validation ObjectModel trop fragiles pour un test fiable) :
 * vérifie que le bloc `if (!$cartRule->add())` ne contient plus de DELETE
 * sur neria_milestone_voucher, seulement le throw.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    $posMethod = strpos($src, 'private function generateMilestoneVoucher(');
    neria_assert($posMethod !== false, 'generateMilestoneVoucher() introuvable — régression du bug corrigé le 08/08/2026');

    $posAddCheck = strpos($src, 'if (!$cartRule->add()) {', $posMethod);
    neria_assert($posAddCheck !== false, 'Bloc if (!$cartRule->add()) introuvable dans generateMilestoneVoucher()');

    $posThrow = strpos($src, 'throw new \RuntimeException(', $posAddCheck);
    neria_assert($posThrow !== false && $posThrow < $posAddCheck + 1500, 'throw introuvable après le bloc if (!$cartRule->add())');

    $failureBlock = substr($src, $posAddCheck, $posThrow - $posAddCheck);
    neria_assert(
        strpos($failureBlock, 'DELETE FROM') === false,
        "generateMilestoneVoucher() supprime de nouveau la réservation anti-doublon dans son bloc d'échec de CartRule::add() — régression du bug corrigé le 08/08/2026 (round 133) : le palier redeviendrait totalement libre alors que l'email pourrait déjà être parti, permettant un double envoi/double bon"
    );

    return [
        'pass'    => true,
        'message' => "generateMilestoneVoucher() ne libère plus la réservation anti-doublon en cas d'échec de CartRule::add() — seul checkMilestone() décide de la libérer, selon le résultat réel de l'envoi de l'email",
    ];
}
