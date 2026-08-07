<?php
/**
 * Régression : BounceManager::recordBounce() doit remettre bounce_count à 1
 * (pas l'incrémenter) quand le soft bounce précédent a expiré (aucun nouveau
 * bounce depuis >= NERIA_BOUNCE_SOFT_EXPIRY_MONTHS), au lieu de repartir du
 * compteur historique jamais remis à zéro en base.
 *
 * Bug réel corrigé le 07/08/2026 (round 84) : isBounced() traite bien
 * l'expiration comme une réhabilitation transitoire (retourne false sans
 * bloquer), mais recordBounce() faisait toujours bounce_count = bounce_count
 * + 1 sans jamais tester l'expiration. Une adresse débloquée depuis des mois
 * (ex. 3 soft bounces en janvier, expiry 6 mois) se faisait donc rebloquer
 * immédiatement par un seul nouveau soft bounce en septembre, niant l'effet
 * de la réhabilitation automatique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest-bounce-expiry-' . uniqid() . '@example.com';

    // 3 soft bounces il y a 7 mois (dépasse l'expiry par défaut de 6 mois),
    // seuil par défaut 3 : adresse "historiquement" au seuil mais expirée.
    $db->execute(
        "INSERT INTO {$prefix}neria_bounces
            (email, type, reason, source, bounce_count, last_bounce_at, status, date_add)
         VALUES ('" . pSQL($email) . "', 'soft', 'regtest', 'manual', 3, DATE_SUB(NOW(), INTERVAL 7 MONTH), 'active', DATE_SUB(NOW(), INTERVAL 7 MONTH))"
    );
    $idBounce = (int) $db->Insert_ID();

    try {
        $mgr = new BounceManager(neria_test_module());

        neria_assert(
            BounceManager::isBounced($email) === false,
            "jeu de test invalide : l'adresse devrait déjà être réhabilitée par expiration (dernier bounce il y a 7 mois, expiry 6 mois)"
        );

        // Un seul nouveau soft bounce après expiration : ne doit PAS
        // rebloquer immédiatement l'adresse (bounce_count doit repartir de
        // 1, pas de l'ancien 3+1=4).
        $mgr->recordBounce($email, 'soft', 'nouveau rebond isolé après expiration');

        $row = $db->getRow(
            "SELECT bounce_count FROM {$prefix}neria_bounces WHERE id = {$idBounce}"
        );
        neria_assert(
            $row !== false && (int) $row['bounce_count'] === 1,
            "recordBounce() ne remet plus bounce_count à 1 après expiration (obtenu : " . ($row['bounce_count'] ?? 'ligne absente') . ") — régression du bug corrigé le 07/08/2026 (round 84)"
        );

        $isBouncedAfterOne = BounceManager::isBounced($email);
        neria_assert(
            $isBouncedAfterOne === false,
            "un seul nouveau soft bounce après expiration rebloque déjà l'adresse — bounce_count n'est pas reparti de 1, régression du bug corrigé le 07/08/2026 (round 84)"
        );

        return [
            'pass'    => true,
            'message' => 'recordBounce() remet bien bounce_count à 1 après expiration du soft bounce précédent ; un seul nouveau bounce ne rebloque pas l\'adresse',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE id = {$idBounce}");
    }
}
