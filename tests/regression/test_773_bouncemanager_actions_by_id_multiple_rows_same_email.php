<?php
/**
 * Régression : depuis que `neria_bounces` peut contenir PLUSIEURS lignes
 * pour la même adresse email (une globale id_shop=0 + une ou plusieurs
 * scopées à une boutique), BounceManager::ignoreBounce()/
 * reactivateBounce()/deleteBounce() opèrent désormais sur `id` (clé
 * primaire réelle), plus sur `email` — agir "par email" serait devenu
 * ambigu (quelle ligne ignorer/réactiver/supprimer si plusieurs existent
 * pour la même adresse ?).
 *
 * Correctif du 15/09/2026 (bloc d de la feuille de route Addons, 2e
 * arbitrage produit).
 *
 * Test comportemental réel : insère 2 lignes pour la MÊME adresse (une
 * globale, une scopée à une boutique fictive) et vérifie que chacune des
 * 3 méthodes n'affecte QUE la ligne dont l'id est passé en argument,
 * jamais l'autre ligne de la même adresse.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/BounceManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest773-' . uniqid() . '@example.com';
    $fakeShop = 999997713;

    $cleanup = function () use ($db, $prefix, $email) {
        $db->execute("DELETE FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
    };
    $cleanup();

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_bounces (email, id_shop, type, bounce_count, last_bounce_at, status, date_add)
             VALUES ('" . pSQL($email) . "', 0, 'hard', 1, NOW(), 'active', NOW())"
        );
        $idGlobal = (int) $db->Insert_ID();

        $db->execute(
            "INSERT INTO {$prefix}neria_bounces (email, id_shop, type, bounce_count, last_bounce_at, status, date_add)
             VALUES ('" . pSQL($email) . "', {$fakeShop}, 'soft', 5, NOW(), 'active', NOW())"
        );
        $idScoped = (int) $db->Insert_ID();

        neria_assert($idGlobal !== $idScoped && $idGlobal > 0 && $idScoped > 0, 'jeu de test invalide : les 2 lignes insérées pour le même email devraient avoir des id distincts');

        $mgr = new BounceManager(neria_test_module());

        // ignoreBounce() sur la ligne globale ne doit PAS affecter la
        // ligne scopée de la même adresse.
        neria_assert($mgr->ignoreBounce($idGlobal) === true, "ignoreBounce({$idGlobal}) a renvoyé false — jeu de test invalide");
        $statusGlobal = $db->getValue("SELECT status FROM {$prefix}neria_bounces WHERE id = {$idGlobal}");
        $statusScoped = $db->getValue("SELECT status FROM {$prefix}neria_bounces WHERE id = {$idScoped}");
        neria_assert($statusGlobal === 'ignored', "ignoreBounce({$idGlobal}) n'a pas mis status='ignored' sur la ligne globale");
        neria_assert(
            $statusScoped === 'active',
            "ignoreBounce({$idGlobal}) a affecté à tort la ligne SCOPÉE (id={$idScoped}) de la même adresse (status obtenu : " . var_export($statusScoped, true) . ") — régression du correctif du 15/09/2026 : agir par email au lieu de par id redeviendrait ambigu dès qu'un email a plusieurs lignes"
        );

        // reactivateBounce() sur la ligne scopée ne doit pas toucher la
        // ligne globale (restée 'ignored' depuis l'étape précédente).
        neria_assert($mgr->reactivateBounce($idScoped) === true, "reactivateBounce({$idScoped}) a renvoyé false — jeu de test invalide");
        $statusGlobalAfter = $db->getValue("SELECT status FROM {$prefix}neria_bounces WHERE id = {$idGlobal}");
        neria_assert(
            $statusGlobalAfter === 'ignored',
            "reactivateBounce({$idScoped}) a affecté à tort la ligne GLOBALE (id={$idGlobal}) de la même adresse (status obtenu : " . var_export($statusGlobalAfter, true) . ") — régression du correctif du 15/09/2026"
        );

        // deleteBounce() sur la ligne globale ne doit supprimer QUE celle-ci.
        neria_assert($mgr->deleteBounce($idGlobal) === true, "deleteBounce({$idGlobal}) a renvoyé false — jeu de test invalide");
        $remaining = $db->executeS("SELECT id FROM {$prefix}neria_bounces WHERE email = '" . pSQL($email) . "'");
        neria_assert(
            is_array($remaining) && count($remaining) === 1 && (int) $remaining[0]['id'] === $idScoped,
            "deleteBounce({$idGlobal}) a supprimé plus/moins que la seule ligne globale — régression du correctif du 15/09/2026 : la ligne scopée (id={$idScoped}) de la même adresse devrait être totalement épargnée"
        );

        return [
            'pass'    => true,
            'message' => "BounceManager::ignoreBounce()/reactivateBounce()/deleteBounce() opèrent bien par id, isolant correctement plusieurs lignes de la même adresse (globale + scopée) — correctif du 15/09/2026 (bloc d)",
        ];
    } finally {
        $cleanup();
    }
}
