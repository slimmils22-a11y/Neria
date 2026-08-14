<?php
/**
 * Régression : LoyaltyManager::awardPoints() faisait confiance au $idShop
 * fourni par l'appelant (ou déduit du contexte courant) sans jamais le
 * confronter à la boutique RÉELLE de l'événement (ps_neria_stat.id_shop).
 * La clé UNIQUE (id_stat, event_type) de neria_loyalty_points n'inclut
 * délibérément pas id_shop (un id_stat donné appartient déjà à une seule
 * boutique) — mais un appel avec un $idShop incohérent (contexte BO au
 * moment d'un clawback annulé, par exemple) pouvait faire échouer
 * silencieusement l'INSERT IGNORE pour la boutique attendue sans jamais
 * recréditer la bonne.
 *
 * Corrigé le 14/08/2026 (round 166) : awardPoints() resynchronise
 * désormais systématiquement $idShop sur la boutique réelle de id_stat
 * (ps_neria_stat.id_shop), quel que soit ce qui a été fourni.
 *
 * Test réel : crée un événement de stat pour la boutique 1, appelle
 * awardPoints() avec un $idShop délibérément INCORRECT (999), vérifie que
 * la ligne de points est bien créditée à la boutique RÉELLE (1), pas à
 * celle passée en paramètre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $module     = neria_test_module();

    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
         VALUES
            (1, 'order_conf', 'fr', {$idCustomer}, 0, '" . bin2hex(random_bytes(16)) . "', 'conversion', NOW())"
    );
    $idStat = (int) $db->Insert_ID();

    try {
        $mgr = new LoyaltyManager($module);
        // $idShop = 999 délibérément incohérent — la boutique RÉELLE de
        // id_stat (via ps_neria_stat) est 1.
        $mgr->awardPoints($idCustomer, $idStat, 'conversion', 999);

        $row = $db->getRow(
            "SELECT id_shop FROM {$prefix}neria_loyalty_points WHERE id_stat = {$idStat} AND event_type = 'conversion'"
        );
        neria_assert($row !== false, "awardPoints() n'a créé aucune ligne de points — jeu de test invalide");
        neria_assert(
            (int) $row['id_shop'] === 1,
            "awardPoints() a crédité les points à la boutique {$row['id_shop']} (fournie par l'appelant) au lieu de la boutique réelle 1 de id_stat — régression du bug corrigé le 14/08/2026 (round 166) : un \$idShop incohérent pourrait de nouveau faire échouer silencieusement le recrédit pour la bonne boutique"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_loyalty_points WHERE id_stat = {$idStat}");
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_stat = {$idStat}");
    }

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::awardPoints() resynchronise bien \$idShop sur la boutique réelle de id_stat — bug corrigé le 14/08/2026 (round 166)",
    ];
}
