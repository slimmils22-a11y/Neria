<?php
/**
 * Régression : BounceManager::reactivateBounce() ne remettait jamais
 * `type` à 'soft' — une adresse en hard bounce (status='active',
 * type='hard') restait dans cet état EXACT après "réactivation" (seuls
 * `status` et `bounce_count` étaient remis à zéro), et isBounced()
 * retourne TOUJOURS true dès que status='active' ET type='hard',
 * indépendamment de bounce_count. Le bouton BO "Réactiver" affichait un
 * succès (l'UPDATE réussit, la ligne existe) alors que l'adresse restait
 * bloquée dès l'envoi suivant — contrairement à ce qu'affirme le
 * commentaire même d'isBounced() ("seule la réactivation manuelle débloque
 * un hard bounce").
 *
 * Bug identifié le 14/09/2026 (round 358, audit dédié BounceManager).
 *
 * Corrigé le 14/09/2026 : reactivateBounce() remet aussi `type` à 'soft'.
 *
 * Test comportemental réel : enregistre un hard bounce réel, vérifie que
 * isBounced() retourne true, appelle reactivateBounce(), vérifie que
 * isBounced() retourne bien false immédiatement après — la promesse
 * fonctionnelle du bouton BO "Réactiver".
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest758-hardbounce@example.invalid';

    $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_bounces (email, type, bounce_count, status, last_bounce_at, date_add)
             VALUES ('" . pSQL($email) . "', 'hard', 1, 'active', NOW(), NOW())"
        );

        neria_assert(
            BounceManager::isBounced($email) === true,
            "isBounced() ne retourne pas true pour un hard bounce actif — jeu de test invalide"
        );

        $mgr = new BounceManager(neria_test_module());
        $result = $mgr->reactivateBounce($email);
        neria_assert($result === true, "reactivateBounce() a retourné false pour une adresse existante — jeu de test invalide");

        neria_assert(
            BounceManager::isBounced($email) === false,
            "isBounced() retourne encore true après reactivateBounce() sur un hard bounce — régression du bug corrigé le 14/09/2026 (round 358) : le bouton BO 'Réactiver' resterait inopérant pour un hard bounce, malgré un message de succès affiché au marchand"
        );

        $row = $db->getRow("SELECT type FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
        neria_assert(
            $row['type'] === 'soft',
            "reactivateBounce() n'a pas remis 'type' à 'soft' (obtenu : '{$row['type']}') — régression du bug corrigé le 14/09/2026 (round 358)"
        );

        return [
            'pass'    => true,
            'message' => "BounceManager::reactivateBounce() débloque désormais réellement une adresse en hard bounce (type remis à 'soft'), cohérent avec le commentaire d'isBounced() — bug corrigé le 14/09/2026 (round 358)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
    }
}
