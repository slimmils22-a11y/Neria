<?php
/**
 * Régression : ClvManager::getTopCustomers() sommait TOUS les avoirs
 * (order_slip) d'un client dans son agrégat de remboursements batch, sans
 * filtre `o.valid = 1` — contrairement à ordersAgg juste au-dessus (déjà
 * filtré) et à computeClv() (vue individuelle, qui restreint déjà les
 * remboursements à $orderIds, lui-même filtré valid=1).
 *
 * Bug réel corrigé le 15/08/2026 (round 174) : un avoir lié à une commande
 * ANNULÉE (valid=0, donc jamais compté dans total_revenue) était quand même
 * soustrait du CLV batch — le client pouvait être artificiellement
 * sous-classé (voire écrasé à 0 CLV via le max(0.0, ...) d'assembleClv())
 * dans le Top 20 marketing, alors que sa fiche individuelle
 * (getCustomerClv(), non affectée par ce bug) affichait un CLV correct —
 * incohérence directe entre les deux vues du même client.
 *
 * Test structurel : vérifie que la requête refundAgg de getTopCustomers()
 * filtre bien `o.valid = 1`, symétrique à ordersAgg.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ClvManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ClvManager.php');

    $posRefundAgg = strpos($src, '// ── 1 requête : remboursements (avoirs) pour TOUS les candidats');
    neria_assert($posRefundAgg !== false, "Bloc refundAgg introuvable dans ClvManager.php — jeu de test invalide");
    $block = substr($src, $posRefundAgg, 1800);

    $posWhere = strpos($block, 'WHERE o.`id_customer` IN');
    neria_assert($posWhere !== false, "Clause WHERE de refundAgg introuvable — jeu de test invalide");
    $whereClause = substr($block, $posWhere, 200);

    neria_assert(
        strpos($whereClause, 'o.`valid` = 1') !== false,
        "ClvManager::getTopCustomers() ne filtre plus refundAgg par o.valid = 1 — régression du bug corrigé le 15/08/2026 (round 174) : un avoir lié à une commande annulée serait de nouveau soustrait à tort du CLV batch d'un client, alors que le revenu correspondant n'a jamais été compté"
    );

    return [
        'pass'    => true,
        'message' => "ClvManager::getTopCustomers() filtre bien refundAgg par o.valid = 1, symétrique à ordersAgg — bug corrigé le 15/08/2026 (round 174)",
    ];
}
