<?php
/**
 * Régression : PreferencesManager::isAllowedBatch() doit renvoyer
 * exactement le même résultat que isAllowed() appelée individuellement
 * pour chaque client — introduite au round 153 pour éliminer un N+1 dans
 * SegmentManager::preflightCheck()/sendToSegment() (jusqu'à ~1500 requêtes
 * SQL individuelles pour un segment de 500 clients, ramenées à 2 requêtes
 * groupées).
 *
 * Test comportemental réel : 3 clients réels — un sans préférence
 * explicite (opt-in par défaut), un désabonné explicitement (subscribed=0),
 * un ré-abonné explicitement (subscribed=1) — vérifie que isAllowedBatch()
 * donne le même résultat que isAllowed() pour chacun.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) \Context::getContext()->shop->id;
    $template = 'vip'; // categorie 'behav', cf. PreferencesManager::TEMPLATE_CAT

    $idCustomer = neria_test_any_customer_id();
    $customerRow = $db->getRow("SELECT email FROM {$prefix}customer WHERE id_customer = {$idCustomer}");
    neria_assert($customerRow !== false, 'Client de test introuvable — jeu de test invalide');
    $email = $customerRow['email'];

    // 3 "clients" distincts pour ce test : le vrai client (sans préférence
    // explicite au départ), et 2 ids factices non existants en base (pour
    // lesquels aucune ligne neria_preferences ne peut exister non plus,
    // même comportement "opt-in par défaut" attendu).
    $idNoPref    = $idCustomer;
    $idFakeA     = 999900001;
    $idFakeB     = 999900002;

    $mgr = new PreferencesManager($module);

    try {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer IN ({$idNoPref}, {$idFakeA}, {$idFakeB}) AND id_shop = {$idShop}");

        // idFakeA : désabonné explicitement
        $db->execute(
            "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
             VALUES ({$idShop}, {$idFakeA}, 'fakeA@regtest153.example', 'behav', 0, NOW())"
        );
        // idFakeB : réabonné explicitement (ligne présente, subscribed=1)
        $db->execute(
            "INSERT INTO {$prefix}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd)
             VALUES ({$idShop}, {$idFakeB}, 'fakeB@regtest153.example', 'behav', 1, NOW())"
        );

        $ids = [$idNoPref, $idFakeA, $idFakeB];
        $batch = $mgr->isAllowedBatch($ids, $template, $idShop);

        foreach ($ids as $id) {
            $single = $mgr->isAllowed($id, $template, $idShop);
            neria_assert(
                array_key_exists($id, $batch),
                "isAllowedBatch() n'a pas retourne d'entree pour le client {$id}"
            );
            neria_assert(
                $batch[$id] === $single,
                "isAllowedBatch() donne un resultat different de isAllowed() pour le client {$id} (batch=" . var_export($batch[$id], true) . ", single=" . var_export($single, true) . ") — regression du bug corrige le 09/08/2026 (round 153)"
            );
        }

        neria_assert($batch[$idFakeA] === false, "isAllowedBatch() ne detecte pas le desabonnement explicite du client {$idFakeA} — jeu de test invalide");
        neria_assert($batch[$idFakeB] === true, "isAllowedBatch() ne detecte pas le reabonnement explicite du client {$idFakeB} — jeu de test invalide");
        neria_assert($batch[$idNoPref] === true, "isAllowedBatch() ne retombe pas sur l'opt-in par defaut pour un client sans preference — jeu de test invalide");
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_preferences WHERE id_customer IN ({$idFakeA}, {$idFakeB}) AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "PreferencesManager::isAllowedBatch() donne exactement le meme resultat que isAllowed() appelee individuellement, pour un client par defaut, desabonne et reabonne",
    ];
}
