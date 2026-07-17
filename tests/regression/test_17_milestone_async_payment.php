<?php
/**
 * Régression : le palier milestone_order doit être vérifié aussi sur la
 * transition de statut (paiement asynchrone : virement, chèque, COD), pas
 * seulement à la création de la commande — bug corrigé le 17/07/2026
 * (commit 2108f34).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');

    neria_assert(
        str_contains($src, 'checkMilestone'),
        "checkMilestone() a disparu de OrderTriggersManager — régression du bug de palier sur paiement asynchrone"
    );
    neria_assert(
        (bool) preg_match('/function handleStatusChange.*checkMilestone/s', $src),
        "handleStatusChange() n'appelle plus checkMilestone() — un client payant par virement/chèque/COD ne franchirait plus jamais de palier milestone_order"
    );

    // Le contrôle doit être placé AVANT le filtre des statuts standards
    // (la confirmation de paiement est elle-même un statut standard 1-13) :
    // un piège déjà rencontré une fois lors du correctif original.
    $posCheckMilestoneCall = strpos($src, 'checkMilestone($order)');
    $posStandardFilter = strpos($src, 'STANDARD_STATUS_IDS', strpos($src, 'function handleStatusChange'));
    neria_assert(
        $posCheckMilestoneCall !== false && $posStandardFilter !== false && $posCheckMilestoneCall < $posStandardFilter,
        "checkMilestone() semble appelé après le filtre STANDARD_STATUS_IDS dans handleStatusChange() — la confirmation de paiement (statut standard) le rendrait inatteignable"
    );

    return ['pass' => true, 'message' => 'milestone_order toujours vérifié sur la transition de statut, avant le filtre des statuts standards'];
}
