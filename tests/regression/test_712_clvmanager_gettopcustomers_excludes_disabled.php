<?php
/**
 * Régression : ClvManager::getTopCustomers() doit exclure les clients
 * désactivés (active = 0), comme le fait déjà ChurnScoreManager::
 * getHighRiskCustomers()/countHighRisk() (round 209, filtre active=1 AND
 * deleted=0).
 *
 * Bug identifié le 12/09/2026 (round 343, audit ChurnScoreManager/
 * ClvManager) : la requête de pré-sélection de getTopCustomers() filtrait
 * `c.deleted = 0` mais jamais `c.active = 1` — un client désactivé (compte
 * suspendu par le marchand, pas soft-supprimé) pouvait apparaître dans le
 * "Top CLV" du BO (onglet Segments), incohérence avec la liste "clients à
 * risque" du même module qui exclut déjà ce cas depuis le round 209.
 *
 * Test comportemental réel : crée un client de test réel avec une commande
 * valide à fort montant (pour être sûr d'entrer dans le pool de
 * pré-sélection), vérifie qu'il apparaît bien dans getTopCustomers() tant
 * qu'actif, puis le désactive et vérifie qu'il disparaît.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ClvManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'round343clvtest@example.test';
    $idShop = (int) Context::getContext()->shop->id;

    $idCustomer = (int) Customer::customerExists($email, true);
    $idOrder    = null;

    try {
        if (!$idCustomer) {
            $c = new Customer();
            $c->firstname  = 'Regtest';
            $c->lastname   = 'Clvtest';
            $c->email      = $email;
            $c->passwd     = Tools::hash('round343test');
            $c->id_lang    = (int) Configuration::get('PS_LANG_DEFAULT');
            $c->active     = 1;
            $c->add();
            $idCustomer = (int) $c->id;
        } else {
            $db->execute("UPDATE {$prefix}customer SET active = 1 WHERE id_customer = {$idCustomer}");
        }

        // Montant très élevé pour garantir l'entrée dans le pool de
        // pré-sélection (tri par CA brut décroissant, LIMIT 200).
        $db->execute(
            "INSERT INTO {$prefix}orders
                (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
             VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','regtest',1,999999,999999,999999,999999,999999,999999,1, NOW(), NOW())"
        );
        $idOrder = (int) $db->Insert_ID();
        neria_assert($idOrder > 0, "jeu de test invalide : l'INSERT de la commande de test a échoué");

        $mgr = new ClvManager(neria_test_module());

        $topActive = $mgr->getTopCustomers(200);
        $foundActive = false;
        foreach ($topActive as $row) {
            if ((int) $row['id_customer'] === $idCustomer) {
                $foundActive = true;
                break;
            }
        }
        neria_assert(
            $foundActive,
            "jeu de test invalide : le client actif de test n'apparaît pas dans getTopCustomers() malgré une commande valide à fort montant"
        );

        $db->execute("UPDATE {$prefix}customer SET active = 0 WHERE id_customer = {$idCustomer}");

        $topDisabled = $mgr->getTopCustomers(200);
        $foundDisabled = false;
        foreach ($topDisabled as $row) {
            if ((int) $row['id_customer'] === $idCustomer) {
                $foundDisabled = true;
                break;
            }
        }
        neria_assert(
            !$foundDisabled,
            "ClvManager::getTopCustomers() liste encore un client désactivé (active=0) — régression du bug corrigé le 12/09/2026 (round 343) : incohérence avec ChurnScoreManager::getHighRiskCustomers() qui exclut déjà ce cas depuis le round 209"
        );

        return [
            'pass'    => true,
            'message' => "ClvManager::getTopCustomers() exclut désormais bien les clients désactivés (active=0), cohérent avec ChurnScoreManager::getHighRiskCustomers()/countHighRisk() (round 209)",
        ];
    } finally {
        if ($idOrder) {
            $db->execute("DELETE FROM {$prefix}orders WHERE id_order = {$idOrder}");
        }
        if ($idCustomer) {
            $c = new Customer($idCustomer);
            if (Validate::isLoadedObject($c)) {
                $c->deleted = 0;
                $c->delete();
            }
        }
    }
}
