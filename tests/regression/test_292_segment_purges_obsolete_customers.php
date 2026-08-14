<?php
/**
 * Régression : SegmentManager::recomputeAll() ne purgeait jamais les
 * lignes neria_customer_segment des clients sortis du périmètre de
 * calcul — contrairement à ChurnScoreManager::recomputeAll() et
 * PropensityScoreManager::recalculateAll(), qui suppriment tous deux les
 * lignes des clients qui n'apparaissent plus dans leur recalcul respectif.
 * Un client purgé RGPD (ps_neria_stat vidée) gardait donc indéfiniment
 * son ancien segment (ex. 'ambassador'), continuant d'apparaître dans
 * getCustomersBySegment() et pouvant recevoir des campagnes ciblées sans
 * plus aucune donnée réelle.
 *
 * Corrigé le 14/08/2026 (round 166) : recomputeAll() supprime désormais
 * les lignes des clients qui n'ont plus AUCUN événement dans neria_stat
 * pour cette boutique (LEFT JOIN … IS NULL).
 *
 * Test réel : crée un événement 'sent' de test, appelle recomputeAll()
 * (crée une ligne de segment pour ce client), supprime l'événement
 * (simule une purge RGPD), rappelle recomputeAll(), vérifie que la ligne
 * de segment a bien disparu.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $module     = neria_test_module();

    // Un id_customer de test dédié, hors plage des vrais clients, pour ne
    // jamais interférer avec un segment déjà calculé pour un vrai client.
    $testCustomerId = 900000 + ($idCustomer % 1000);

    $db->execute(
        "INSERT INTO {$prefix}neria_stat
            (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
         VALUES
            (1, 'order_conf', 'fr', {$testCustomerId}, 0, '" . bin2hex(random_bytes(16)) . "', 'sent', DATE_SUB(NOW(), INTERVAL 60 DAY))"
    );

    try {
        $mgr = new SegmentManager($module);
        $mgr->recomputeAll();

        $segmentExists = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_customer_segment WHERE id_shop = 1 AND id_customer = {$testCustomerId}"
        );
        neria_assert($segmentExists > 0, "recomputeAll() n'a pas créé de ligne de segment pour le client de test — jeu de test invalide");

        // Simule une purge RGPD : plus aucun événement pour ce client.
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$testCustomerId}");

        $mgr->recomputeAll();

        $segmentStillExists = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_customer_segment WHERE id_shop = 1 AND id_customer = {$testCustomerId}"
        );
        neria_assert(
            $segmentStillExists === 0,
            "SegmentManager::recomputeAll() n'a pas purgé la ligne de segment d'un client sans plus aucun événement — régression du bug corrigé le 14/08/2026 (round 166) : un client purgé RGPD garderait indéfiniment son ancien segment"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$testCustomerId}");
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$testCustomerId}");
    }

    return [
        'pass'    => true,
        'message' => "SegmentManager::recomputeAll() purge bien les segments des clients sans plus aucun événement — bug corrigé le 14/08/2026 (round 166)",
    ];
}
