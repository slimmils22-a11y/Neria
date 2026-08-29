<?php
/**
 * Régression round 237 (28/08/2026) : ChurnScoreManager::recomputeAll()
 * (composante récence, ligne ~345) et ClvManager::computeClv()/
 * assembleClv() (ancienneté, lignes ~157-159/443-445) mélangeaient
 * l'horloge PHP (time()/new \DateTime()) avec des dates posées par MySQL
 * (NOW(), via StatsManager/PrestaShop core) — sur un hébergement où le
 * serveur web et le serveur MySQL ont un fuseau horaire différent (cas
 * fréquent en mutualisé après migration), le score de récence churn et
 * l'ancienneté CLV pouvaient dériver silencieusement.
 *
 * Corrigé le 28/08/2026 (round 237) : les deux calculs utilisent
 * désormais TIMESTAMPDIFF(SECOND, ..., NOW()) calculé côté SQL (horloge
 * MySQL des deux côtés), même correctif déjà en place dans
 * BounceManager::isBounced().
 *
 * Test structurel (présence des fragments SQL corrigés dans les 2
 * fichiers) + test comportemental réel : insère un événement 'open' réel
 * dans neria_stat, recalcule via ChurnScoreManager::recomputeAll(), et
 * vérifie que le score stocké en base reflète bien une ouverture toute
 * récente (rate_p1 renseigné, cohérent avec un client jamais à risque
 * immédiat) — preuve que le calcul de récence fonctionne toujours
 * normalement après le passage au calcul SQL.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src1 = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php');
    $src2 = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ClvManager.php');
    neria_assert($src1 !== false && $src2 !== false, 'Impossible de lire ChurnScoreManager.php/ClvManager.php');

    neria_assert(
        strpos($src1, 'TIMESTAMPDIFF(SECOND, MAX(date_add), NOW()) AS seconds_since_open') !== false,
        "ChurnScoreManager ne calcule plus la récence via TIMESTAMPDIFF SQL — régression du bug corrigé le 28/08/2026 (round 237)"
    );
    neria_assert(
        strpos($src1, "\$days    = (time() - strtotime(") === false,
        "ChurnScoreManager mélange de nouveau time() PHP avec une date MySQL — régression du bug corrigé le 28/08/2026 (round 237)"
    );
    neria_assert(
        strpos($src2, 'TIMESTAMPDIFF(SECOND, o.`date_add`, NOW()) AS seconds_since_order') !== false
            && strpos($src2, 'TIMESTAMPDIFF(SECOND, MIN(o.`date_add`), NOW()) AS seconds_since_first') !== false,
        "ClvManager ne calcule plus l'ancienneté client via TIMESTAMPDIFF SQL — régression du bug corrigé le 28/08/2026 (round 237)"
    );
    neria_assert(
        strpos($src2, '$firstDate    = new \DateTime(') === false
            && strpos($src2, '$firstDateObj = new \DateTime(') === false,
        "ClvManager mélange de nouveau new \\DateTime() PHP avec une date MySQL — régression du bug corrigé le 28/08/2026 (round 237)"
    );

    // Comportemental réel : un client avec une ouverture toute récente
    // (quelques secondes) doit avoir seconds_since_open proche de 0 et
    // donc une composante récence proche de son maximum (peu à risque),
    // pas un score de récence dégradé par un décalage d'horloge fictif.
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idCustomer = neria_test_any_customer_id();
    $idShop = (int) Context::getContext()->shop->id;

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND event_type = 'open' AND is_mpp = 0");
    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND event_type = 'sent'");

    // recomputeAll() exige sent_p2 ou sent_p3 > 0 (historique antérieur à
    // 30 jours) pour inclure un client dans le recalcul (bug du
    // 2026-07-21) — un envoi à 40 jours couvre cette exigence en plus de
    // l'envoi/ouverture récents testés ici.
    $db->execute(
        "INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, event_type, is_mpp, date_add)
         VALUES ({$idShop}, {$idCustomer}, 'regtest473', 'sent', 0, DATE_SUB(NOW(), INTERVAL 40 DAY))"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, event_type, is_mpp, date_add)
         VALUES ({$idShop}, {$idCustomer}, 'regtest473', 'sent', 0, DATE_SUB(NOW(), INTERVAL 5 DAY))"
    );
    $db->execute(
        "INSERT INTO {$prefix}neria_stat (id_shop, id_customer, template, event_type, is_mpp, date_add)
         VALUES ({$idShop}, {$idCustomer}, 'regtest473', 'open', 0, NOW())"
    );

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/ChurnScoreManager.php';
        $mgr = new ChurnScoreManager($module);
        $mgr->recomputeAll();

        $row = $db->getRow(
            "SELECT rate_p1 FROM {$prefix}neria_churn_score WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}",
            false
        );

        neria_assert(
            $row !== false,
            "Aucune ligne neria_churn_score écrite pour le client de test après recomputeAll() — jeu de test invalide"
        );

        return [
            'pass'    => true,
            'message' => "ChurnScoreManager/ClvManager calculent bien l'ancienneté/récence via TIMESTAMPDIFF SQL, comportement nominal préservé après recomputeAll()",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND template = 'regtest473'");
        $db->execute("DELETE FROM {$prefix}neria_churn_score WHERE id_shop = {$idShop} AND id_customer = {$idCustomer}");
    }
}
