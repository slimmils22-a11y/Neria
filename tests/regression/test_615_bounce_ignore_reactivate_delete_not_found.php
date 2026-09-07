<?php
/**
 * Régression : BounceManager::ignoreBounce()/reactivateBounce()/
 * deleteBounce() renvoyaient toujours true, même quand aucune ligne ne
 * correspondait à l'email fourni (faute de frappe, ligne déjà supprimée
 * par un autre onglet BO ouvert en parallèle) — Db::execute()/Db::delete()
 * renvoient true dès que la requête SQL elle-même a réussi, indépendamment
 * du nombre de lignes réellement affectées. Le handler neria.php
 * (actions ignore_bounce/reactivate_bounce/delete_bounce) affichait donc
 * "Bounce ignoré"/"réactivé"/"supprimé" sans jamais vérifier ce retour —
 * succès affiché malgré aucun effet réel, même pattern déjà corrigé de
 * nombreuses fois ailleurs dans ce module.
 *
 * Corrigé le 07/09/2026 (round 315) : ignoreBounce()/reactivateBounce()
 * vérifient désormais l'existence de la ligne AVANT l'UPDATE (pas
 * Affected_Rows() après, qui serait une fausse ambiguïté si l'email est
 * déjà dans l'état cible — même raisonnement que SeasonalCampaignManager::
 * update(), round 311) ; deleteBounce() vérifie Affected_Rows() après le
 * DELETE (fiable ici, pas d'ambiguïté possible pour une suppression).
 * neria.php affiche désormais neria_error (msg.bounce_not_found) au lieu
 * de neria_success quand l'action échoue.
 *
 * Test comportemental réel : insère un vrai bounce en base, vérifie que
 * chacune des 3 méthodes renvoie true sur un email EXISTANT, puis false
 * sur un email INEXISTANT.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db    = neria_test_db();
    $email = 'regtest615_' . substr(uniqid(), -8) . '@example.com';
    $ghost = 'regtest615_ghost_' . substr(uniqid(), -8) . '@example.com';

    $mgr = new BounceManager(neria_test_module());

    try {
        $mgr->addManualBounce($email, 'hard');
        $row = $db->getRow("SELECT * FROM " . _DB_PREFIX_ . "neria_bounces WHERE email = '" . pSQL($email) . "'");
        neria_assert($row !== false, 'addManualBounce() n\'a pas créé la ligne — jeu de test invalide');

        // Email fantôme : les 3 méthodes doivent renvoyer false.
        neria_assert(
            $mgr->ignoreBounce($ghost) === false,
            "ignoreBounce() renvoie true pour un email inexistant ({$ghost}) — régression du bug corrigé le 07/09/2026 (round 315) : le marchand verrait 'Bounce ignoré' alors qu'aucune ligne n'a été modifiée"
        );
        neria_assert(
            $mgr->reactivateBounce($ghost) === false,
            "reactivateBounce() renvoie true pour un email inexistant ({$ghost}) — régression du bug corrigé le 07/09/2026 (round 315)"
        );
        neria_assert(
            $mgr->deleteBounce($ghost) === false,
            "deleteBounce() renvoie true pour un email inexistant ({$ghost}) — régression du bug corrigé le 07/09/2026 (round 315)"
        );

        // Email réel : ignoreBounce() puis reactivateBounce() doivent
        // renvoyer true et produire un effet réel vérifiable en base.
        neria_assert(
            $mgr->ignoreBounce($email) === true,
            "ignoreBounce() renvoie false pour un email réellement présent ({$email}) — jeu de test invalide ou régression"
        );
        $status = $db->getValue("SELECT status FROM " . _DB_PREFIX_ . "neria_bounces WHERE email = '" . pSQL($email) . "'");
        neria_assert($status === 'ignored', "ignoreBounce() n'a pas mis status='ignored' en base (obtenu " . var_export($status, true) . ")");

        neria_assert(
            $mgr->reactivateBounce($email) === true,
            "reactivateBounce() renvoie false pour un email réellement présent ({$email}) — jeu de test invalide ou régression"
        );
        $status2 = $db->getValue("SELECT status FROM " . _DB_PREFIX_ . "neria_bounces WHERE email = '" . pSQL($email) . "'");
        neria_assert($status2 === 'active', "reactivateBounce() n'a pas remis status='active' en base (obtenu " . var_export($status2, true) . ")");

        neria_assert(
            $mgr->deleteBounce($email) === true,
            "deleteBounce() renvoie false pour un email réellement présent ({$email}) — jeu de test invalide ou régression"
        );
        $stillThere = $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "neria_bounces WHERE email = '" . pSQL($email) . "'");
        neria_assert((int) $stillThere === 0, "deleteBounce() n'a pas réellement supprimé la ligne");
    } finally {
        $db->execute("DELETE FROM " . _DB_PREFIX_ . "neria_bounces WHERE email IN ('" . pSQL($email) . "', '" . pSQL($ghost) . "')");
    }

    return [
        'pass'    => true,
        'message' => "BounceManager::ignoreBounce()/reactivateBounce()/deleteBounce() renvoient bien false pour un email inexistant (au lieu de toujours true) — bug corrigé le 07/09/2026 (round 315)",
    ];
}
