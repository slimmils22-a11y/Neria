<?php
/**
 * Régression : BounceManager::recordBounce() ne touchait jamais la colonne
 * `status` dans son ON DUPLICATE KEY UPDATE — seuls `bounce_count`,
 * `last_bounce_at`, `reason` et `type` étaient mis à jour. Une adresse
 * marquée `ignored` via ignoreBounce() (le marchand a jugé qu'un bounce
 * PRÉCÉDENT était un faux positif) restait `ignored` INDÉFINIMENT, même si
 * de NOUVEAUX bounces réels arrivaient ensuite — isBounced() ne teste que
 * `status = 'active'`, jamais `type`/`bounce_count`, donc un hard bounce
 * authentique postérieur (mailbox inexistante, domaine mort) continuait
 * d'incrémenter bounce_count/mettre à jour reason EN SILENCE sous
 * status='ignored', sans jamais rebloquer l'envoi — risque de
 * réputation/délivrabilité continu vers une adresse pourtant confirmée
 * invalide après coup.
 *
 * Corrigé le 05/09/2026 (round 304) : `status` = IF(VALUES(`type`) =
 * 'hard', 'active', `status`) — un nouveau HARD bounce (signal sans
 * ambiguïté) réactive désormais le filtrage anti-bounce même sur une
 * adresse ignorée. Un nouveau SOFT bounce, lui, ne touche pas `status`
 * (respecte le jugement du marchand sur un incident jugé transitoire).
 *
 * Test comportemental réel : enregistre un soft bounce, l'ignore
 * explicitement (isBounced() doit alors renvoyer false), puis enregistre
 * un hard bounce pour la MÊME adresse — isBounced() doit de nouveau
 * renvoyer true. Vérifie aussi qu'un nouveau SOFT bounce sur une adresse
 * ignorée NE la réactive PAS (comportement volontairement préservé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db    = Db::getInstance();
    $email = 'regtest572_' . uniqid() . '@example.test';
    $cleanup = function () use ($db, $email) {
        $db->execute("DELETE FROM " . _DB_PREFIX_ . "neria_bounces WHERE email = '" . pSQL($email) . "'");
    };
    $cleanup();

    try {
        $mgr = new BounceManager(neria_test_module());

        // 1) Soft bounce puis ignoré explicitement — isBounced() doit
        // renvoyer false.
        $mgr->recordBounce($email, 'soft', 'mailbox full (regtest572)');
        $mgr->ignoreBounce($email);
        neria_assert(
            BounceManager::isBounced($email) === false,
            "isBounced() renvoie true juste après ignoreBounce() — jeu de test invalide"
        );

        // 2) Un nouveau HARD bounce sur cette même adresse doit réactiver
        // le filtrage (status repasse à 'active').
        $mgr->recordBounce($email, 'hard', 'mailbox does not exist (regtest572)');
        $rowAfterHard = $db->getRow(
            "SELECT status, type FROM " . _DB_PREFIX_ . "neria_bounces WHERE email = '" . pSQL($email) . "'"
        );
        neria_assert(
            $rowAfterHard && $rowAfterHard['status'] === 'active',
            "recordBounce('hard') n'a pas remis status='active' sur une adresse 'ignored' (status obtenu : " . var_export($rowAfterHard['status'] ?? null, true) . ") — régression du bug corrigé le 05/09/2026 (round 304)"
        );
        neria_assert(
            BounceManager::isBounced($email) === true,
            "isBounced() renvoie encore false après un nouveau hard bounce sur une adresse précédemment ignorée — régression du bug corrigé le 05/09/2026 (round 304) : le filtrage anti-bounce resterait désactivé en permanence après un seul ignoreBounce(), même face à un hard bounce authentique postérieur"
        );

        // 3) Comportement préservé : un nouveau SOFT bounce sur une
        // adresse ignorée ne doit PAS la réactiver (respect du jugement
        // du marchand sur un incident jugé transitoire).
        $mgr->ignoreBounce($email);
        neria_assert(BounceManager::isBounced($email) === false, "jeu de test invalide : ignoreBounce() n'a pas remis status='ignored'");
        $mgr->recordBounce($email, 'soft', 'mailbox full again (regtest572)');
        neria_assert(
            BounceManager::isBounced($email) === false,
            "isBounced() renvoie true après un nouveau SOFT bounce sur une adresse ignorée — comportement volontairement préservé cassé : un soft bounce ne doit pas outrepasser la décision du marchand"
        );

        return [
            'pass'    => true,
            'message' => "BounceManager::recordBounce() réactive bien le filtrage anti-bounce (status='active') sur un nouveau HARD bounce, même pour une adresse précédemment ignorée, sans affecter ce même comportement pour un nouveau SOFT bounce — bug corrigé le 05/09/2026 (round 304)",
        ];
    } finally {
        $cleanup();
    }
}
