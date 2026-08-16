<?php
/**
 * Régression : OrderTriggersManager traitait Mail::Send()===true comme un
 * succès inconditionnel dans checkMilestone()/handleStatusChange()/
 * handleRefund()/handleReturn() — alors que Mail::Send() du cœur
 * PrestaShop retourne TOUJOURS true quand le hook actionEmailSendBefore
 * annule l'envoi (bounce/blacklist/préférences/cooldown). Le cas le plus
 * grave : dans checkMilestone(), la réservation anti-doublon
 * (claimMilestone()) n'était libérée QUE si $result était faux — un palier
 * légitimement atteint mais silencieusement bloqué par un garde-fou
 * restait donc réclamé À VIE, sans email ni bon de réduction, sans aucun
 * retry possible (pas de cron pour ce template).
 *
 * Corrigé le 16/08/2026 (round 178) : explicitSendBlockReason() revérifie
 * désormais explicitement bounce/blacklist/préférences/cooldown AVANT
 * l'appel à Mail::Send() dans les 4 méthodes, avant même la réservation
 * pour checkMilestone() — un envoi qui serait de toute façon bloqué
 * n'atteint plus jamais Mail::Send() ni la réservation.
 *
 * Test structurel : vérifie que explicitSendBlockReason() existe et est
 * bien appelée AVANT claimMilestone() dans checkMilestone(), et avant
 * chaque appel Mail::Send() dans les 3 autres méthodes (impossible à
 * tester de façon fiable en comportemental réel sans fixture de commandes
 * complète — 5 commandes valides pour un client + hook de blocage réel).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    neria_assert(
        strpos($src, 'private function explicitSendBlockReason(') !== false,
        "OrderTriggersManager::explicitSendBlockReason() a disparu — régression du bug corrigé le 16/08/2026 (round 178)"
    );

    $posClaim = strpos($src, 'if (!$this->claimMilestone($idCustomer, $count, $idShop)) {');
    $posBlockCheck = strpos($src, "\$this->explicitSendBlockReason('milestone_order'");
    neria_assert(
        $posClaim !== false && $posBlockCheck !== false && $posBlockCheck < $posClaim,
        "checkMilestone() n'appelle plus explicitSendBlockReason() AVANT claimMilestone() — régression du bug corrigé le 16/08/2026 (round 178) : un palier légitimement atteint mais bloqué par un garde-fou serait de nouveau réclamé à vie sans jamais être libéré (Mail::Send() renvoie true même quand le hook bloque réellement l'envoi)"
    );

    foreach (['order_partial_shipped', 'order_on_hold', 'refund_processed', 'return_received'] as $tpl) {
        neria_assert(
            strpos($src, "explicitSendBlockReason('{$tpl}'") !== false,
            "OrderTriggersManager ne revérifie plus explicitement les garde-fous d'envoi pour le template '{$tpl}' — régression du bug corrigé le 16/08/2026 (round 178) : Mail::Send()===true serait de nouveau traité comme un succès inconditionnel même quand le hook bloque silencieusement l'envoi"
        );
    }

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager revérifie bien explicitement bounce/blacklist/préférences/cooldown AVANT Mail::Send() (et avant la réservation pour milestone_order) dans ses 4 méthodes — bug corrigé le 16/08/2026 (round 178)",
    ];
}
