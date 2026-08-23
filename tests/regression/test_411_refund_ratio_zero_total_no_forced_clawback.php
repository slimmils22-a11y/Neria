<?php
/**
 * Régression : OrderTriggersManager::handleRefund() calculait
 * $refundRatio = orderTotal > 0 ? (totalRefunded/orderTotal) : 1.0 — une
 * commande entièrement couverte par un bon d'achat (orderTotal=0) qui
 * recevait ensuite N'IMPORTE QUEL avoir (même une correction triviale sans
 * lien avec un vrai remboursement) était traitée comme un remboursement à
 * 100% et déclenchait le retrait COMPLET des points de fidélité, alors
 * qu'il n'y a rien à rembourser proportionnellement sur une commande déjà
 * à 0€.
 *
 * Bug réel identifié le 23/08/2026 (round 192).
 *
 * Corrigé le 23/08/2026 (round 192) : repli à 0.0 (pas de clawback) au lieu
 * de 1.0 pour une commande à orderTotal=0.
 *
 * Test structurel (une vraie fixture Order+order_slip+LoyaltyManager
 * nécessiterait une commande réelle complète avec points de fidélité déjà
 * crédités, hors périmètre d'un test isolé) : vérifie par lecture directe
 * du source que le repli est bien 0.0, pas 1.0.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/OrderTriggersManager.php');
    neria_assert($src !== false, 'Impossible de lire src/OrderTriggersManager.php');

    $posRatio = strpos($src, '$refundRatio  = $orderTotal > 0 ? ($totalRefunded / $orderTotal) :');
    neria_assert($posRatio !== false, "Calcul de \$refundRatio introuvable — jeu de test invalide (la méthode a peut-être été restructurée)");

    $line = substr($src, $posRatio, 100);
    neria_assert(
        strpos($line, ': 0.0;') !== false,
        "\$refundRatio ne replie plus sur 0.0 pour une commande à orderTotal=0 — régression du bug corrigé le 23/08/2026 (round 192) : une commande entièrement couverte par un bon d'achat recevant un avoir trivial déclencherait de nouveau un clawback COMPLET des points de fidélité"
    );
    neria_assert(
        strpos($line, ': 1.0;') === false,
        "\$refundRatio replie de nouveau sur 1.0 (100%) pour une commande à orderTotal=0 — régression du bug corrigé le 23/08/2026 (round 192)"
    );

    return [
        'pass'    => true,
        'message' => "OrderTriggersManager::handleRefund() ne force plus un clawback complet sur une commande à orderTotal=0 — bug corrigé le 23/08/2026 (round 192)",
    ];
}
