<?php
/**
 * Régression : UpsellManager::recordClick() doit distinguer un double-clic
 * légitime (clicked_at déjà posé — cas normal, aucune alerte) d'une ligne
 * (id_upsell, id_customer) qui n'existe même pas en base (token
 * corrompu/forgé ou échec d'écriture antérieur — alerte Watchdog).
 *
 * Bug réel corrigé le 12/09/2026 (round 340) : l'UPDATE n'était jamais
 * vérifié (ni Affected_Rows(), ni aucune trace en cas d'échec). Un clic
 * légitime qui échouait silencieusement (contention, timeout) ne laissait
 * aucune trace, alors que checkConversions() s'appuie ensuite sur
 * `clicked_at IS NOT NULL` pour attribuer les conversions.
 *
 * Test comportemental réel en 2 temps :
 *   1. Premier clic sur une ligne réelle : clicked_at est bien posé, aucune
 *      alerte (0 == cas normal de succès, pas testé ici directement mais
 *      implicite : le test échouerait si une alerte polluait le comportement
 *      attendu — non vérifiable sans mocker Watchdog, donc on vérifie l'état
 *      réel de la ligne).
 *   2. Second clic sur la MÊME ligne (déjà cliquée) : ne doit PAS déclencher
 *      d'alerte "ligne manquante" — c'est un cas normal, pas un échec. Puis
 *      un clic sur un id_upsell totalement inexistant : la méthode doit
 *      journaliser watchdog.upsell_click_row_missing (vérifié
 *      structurellement, cf. UpsellManager::recordSuggestion() ci-dessus
 *      pour la même limite de vérification directe de l'écriture Watchdog).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;
    $mgr        = new UpsellManager(neria_test_module());

    $insertedId = null;

    try {
        $upsell = [
            'id_product'  => 1,
            'name'        => 'Regtest Round340 Click',
            'reason'      => 'Notre suggestion pour vous',
            'product_url' => 'http://example.test/produit',
        ];
        $insertedId = $mgr->recordSuggestion($idCustomer, 0, $upsell, $idShop);
        neria_assert($insertedId > 0, "jeu de test invalide : recordSuggestion() a échoué");

        // 1. Premier clic — doit poser clicked_at.
        $mgr->recordClick($insertedId, $idCustomer);

        $row = $db->getRow(
            "SELECT clicked_at FROM {$prefix}neria_upsell WHERE id_upsell = {$insertedId}"
        );
        neria_assert(
            $row !== false && $row['clicked_at'] !== null,
            "recordClick() n'a pas posé clicked_at sur le premier clic légitime — jeu de test invalide ou régression"
        );
        $firstClickedAt = $row['clicked_at'];

        // 2. Second clic (idempotent, cas NORMAL) — clicked_at ne doit pas
        // changer (WHERE clicked_at IS NULL empêche la ré-écriture), et
        // aucune exception ne doit être levée.
        $mgr->recordClick($insertedId, $idCustomer);
        $row2 = $db->getRow(
            "SELECT clicked_at FROM {$prefix}neria_upsell WHERE id_upsell = {$insertedId}"
        );
        neria_assert(
            $row2 !== false && $row2['clicked_at'] === $firstClickedAt,
            "un second clic idempotent a modifié clicked_at — comportement inattendu"
        );

        // 3. Clic sur un id_upsell totalement inexistant — vérification
        // structurelle du garde-fou (raison du choix structurel : la
        // capture directe de l'écriture Watchdog n'est pas fiable sans
        // mocker la couche, cf. test_704).
        $mgr->recordClick(999999999, $idCustomer);

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
        $posMethod = strpos($src, 'public function recordClick(');
        neria_assert($posMethod !== false, 'recordClick() introuvable — jeu de test invalide');

        $methodBody = substr($src, $posMethod, 2000);

        neria_assert(
            strpos($methodBody, 'Affected_Rows()') !== false,
            "UpsellManager::recordClick() ne vérifie plus Affected_Rows() après l'UPDATE — régression du bug corrigé le 12/09/2026 (round 340)"
        );
        neria_assert(
            strpos($methodBody, 'watchdog.upsell_click_row_missing') !== false,
            "UpsellManager::recordClick() ne journalise plus d'alerte Watchdog quand la ligne (id_upsell, id_customer) n'existe pas — régression du bug corrigé le 12/09/2026 (round 340)"
        );
        // Le garde-fou distinctif : ne doit PAS alerter sur un simple
        // double-clic (la ligne EXISTE), seulement quand elle n'existe pas.
        neria_assert(
            strpos($methodBody, 'SELECT 1 FROM') !== false,
            "UpsellManager::recordClick() ne vérifie plus l'existence de la ligne avant d'alerter — régression du bug corrigé le 12/09/2026 (round 340) : un simple double-clic légitime déclencherait de nouveau une fausse alerte Watchdog"
        );

        return [
            'pass'    => true,
            'message' => "UpsellManager::recordClick() pose bien clicked_at au premier clic, reste idempotent sur un second clic légitime (comportemental), et distingue ce cas normal d'une ligne manquante via Affected_Rows()+vérification d'existence avant alerte (structurel)",
        ];
    } finally {
        if ($insertedId) {
            $db->execute("DELETE FROM {$prefix}neria_upsell WHERE id_upsell = {$insertedId}");
        }
    }
}
