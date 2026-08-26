<?php
/**
 * Régression : ChurnScoreManager::countHighRisk() ne filtrait pas les
 * clients désactivés/supprimés, contrairement à getHighRiskCustomers()
 * (jointure customer + active=1/deleted=0). neria_churn_score n'est
 * jamais purgée quand un client est désactivé ou soft-supprimé (RGPD) —
 * seulement par la fenêtre glissante de 90 jours de recomputeAll(),
 * indépendante du statut du client.
 *
 * Bug réel identifié le 25/08/2026 (round 209) : le compteur "atRisk" du
 * résumé de cron (watchdog.churn_score_summary, appelé depuis
 * recomputeAll()) pouvait dépasser le nombre de clients réellement
 * listés/actionnables dans getHighRiskCustomers() sur la fiche BO —
 * écart trompeur pour le marchand, particulièrement marqué sur une
 * boutique à fort taux de suppression de comptes.
 *
 * Corrigé le 25/08/2026 (round 209) : countHighRisk() applique désormais
 * le même filtre (jointure customer, active=1, deleted=0).
 *
 * Test comportemental réel : seed 2 clients à score élevé (>= 70), l'un
 * actif, l'autre désactivé (active=0) — vérifie que countHighRisk() ne
 * compte que le client actif, cohérent avec getHighRiskCustomers().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';

    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $mgr = new ChurnScoreManager($module);
    $db = Db::getInstance();

    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    $emailActive   = 'round209activehighrisk@example.test';
    $emailDisabled = 'round209disabledhighrisk@example.test';

    $idCustActive   = (int) Customer::customerExists($emailActive, true);
    $idCustDisabled = (int) Customer::customerExists($emailDisabled, true);

    try {
        if (!$idCustActive) {
            $c = new Customer();
            $c->firstname = 'RoundActive';
            $c->lastname  = 'Testround';
            $c->email     = $emailActive;
            $c->passwd    = Tools::hash('round209test');
            $c->id_lang   = $idLang;
            $c->active    = 1;
            $c->deleted   = 0;
            $c->add();
            $idCustActive = (int) $c->id;
        }
        if (!$idCustDisabled) {
            $c2 = new Customer();
            $c2->firstname = 'RoundDisabled';
            $c2->lastname  = 'Testround';
            $c2->email     = $emailDisabled;
            $c2->passwd    = Tools::hash('round209test');
            $c2->id_lang   = $idLang;
            $c2->active    = 0;
            $c2->deleted   = 0;
            $c2->add();
            $idCustDisabled = (int) $c2->id;
        } else {
            $cExisting = new Customer($idCustDisabled);
            $cExisting->active = 0;
            $cExisting->update();
        }

        // Seed un score de risque élevé (>= 70) pour les DEUX clients.
        $now = date('Y-m-d H:i:s');
        foreach ([$idCustActive, $idCustDisabled] as $idCust) {
            $db->execute(sprintf(
                "INSERT INTO `%sneria_churn_score`
                    (id_shop, id_customer, score, rate_p1, rate_p2, rate_p3, computed_at)
                 VALUES (%d, %d, 85, 0, 0, 0, '%s')
                 ON DUPLICATE KEY UPDATE score = 85, computed_at = '%s'",
                _DB_PREFIX_, $idShop, $idCust, $now, $now
            ));
        }

        $count = $mgr->countHighRisk();
        $list  = $mgr->getHighRiskCustomers(500);
        $listIds = array_column($list, 'id_customer');

        neria_assert(
            in_array($idCustActive, $listIds, true),
            "Le client actif à score élevé n'apparaît pas dans getHighRiskCustomers() — jeu de test invalide"
        );
        neria_assert(
            !in_array($idCustDisabled, $listIds, true),
            "Le client désactivé apparaît à tort dans getHighRiskCustomers() — jeu de test invalide (comportement de référence déjà cassé)"
        );
        neria_assert(
            $count === count($listIds),
            "ChurnScoreManager::countHighRisk() (={$count}) diverge de count(getHighRiskCustomers())" .
            " (=" . count($listIds) . ") — régression du bug corrigé le 25/08/2026 (round 209) : le client désactivé serait de nouveau compté à tort dans le résumé de cron"
        );
    } finally {
        $db->execute("DELETE FROM `" . _DB_PREFIX_ . "neria_churn_score` WHERE id_customer IN ({$idCustActive}, {$idCustDisabled})");
        if ($idCustActive) {
            $c = new Customer($idCustActive);
            if (Validate::isLoadedObject($c)) { $c->delete(); }
        }
        if ($idCustDisabled) {
            $c2 = new Customer($idCustDisabled);
            if (Validate::isLoadedObject($c2)) { $c2->delete(); }
        }
    }

    return [
        'pass'    => true,
        'message' => "ChurnScoreManager::countHighRisk() exclut bien les clients désactivés/supprimés, cohérent avec getHighRiskCustomers() — bug corrigé le 25/08/2026 (round 209)",
    ];
}
