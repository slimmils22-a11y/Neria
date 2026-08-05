<?php
/**
 * Régression : BounceManager::reactivateBounce() doit remettre bounce_count
 * à 0, pas seulement repasser status à 'active'.
 *
 * Bug réel corrigé le 05/08/2026 (round 51) : une adresse ayant dépassé le
 * seuil (ex. bounce_count=5, seuil=3) repassait "active" via
 * reactivateBounce() mais gardait bounce_count=5. Le tout premier nouveau
 * soft bounce (panne transitoire, boîte pleine un instant) le faisait
 * remonter à 6 via recordBounce() (bounce_count = bounce_count + 1), et
 * isBounced() rebloquait aussitôt l'adresse — la réactivation manuelle
 * était donc pratiquement inopérante pour toute adresse au-dessus du seuil.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest-bounce-' . uniqid() . '@example.com';

    // Adresse bloquée (soft bounce, bounce_count=5 au-dessus du seuil par
    // défaut de 3, status='active' = état bloquant pour isBounced()) — état
    // réaliste avant que le marchand ne clique "Réactiver".
    $db->execute(
        "INSERT INTO {$prefix}neria_bounces
            (email, type, reason, source, bounce_count, last_bounce_at, status, date_add)
         VALUES ('" . pSQL($email) . "', 'soft', 'regtest', 'manual', 5, NOW(), 'active', NOW())"
    );
    $idBounce = (int) $db->Insert_ID();

    try {
        $mgr = new BounceManager(neria_test_module());
        neria_assert(BounceManager::isBounced($email) === true, "jeu de test invalide : l'adresse ne semble pas bloquée avant réactivation (bounce_count=5, seuil par défaut 3)");

        $mgr->reactivateBounce($email);

        $row = $db->getRow(
            "SELECT status, bounce_count FROM {$prefix}neria_bounces WHERE id = {$idBounce}"
        );

        neria_assert(
            $row !== false && (int) $row['bounce_count'] === 0,
            "reactivateBounce() ne remet plus bounce_count à 0 (obtenu : " . ($row['bounce_count'] ?? 'ligne absente') . ") — régression du bug corrigé le 05/08/2026 : le premier soft bounce suivant rebloquerait aussitôt l'adresse"
        );
        neria_assert(
            $row['status'] === 'active',
            "reactivateBounce() ne remet plus status à 'active'"
        );

        // Un soft bounce qui suit une réactivation ne doit PAS rebloquer
        // immédiatement l'adresse (bounce_count repart de 0, pas de 5).
        $mgr->recordBounce($email, 'soft', 'nouveau rebond après réactivation');
        $isBouncedAfterOne = BounceManager::isBounced($email);
        neria_assert(
            $isBouncedAfterOne === false,
            "un seul nouveau soft bounce après réactivation rebloque déjà l'adresse — bounce_count n'est pas reparti de 0"
        );

        return [
            'pass'    => true,
            'message' => 'reactivateBounce() remet bien bounce_count à 0 ; un soft bounce isolé ensuite ne rebloque pas l\'adresse',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE id = {$idBounce}");
    }
}
