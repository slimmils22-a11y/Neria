<?php
/**
 * Régression : CertificateManager::getStats() doit calculer trend_pct sur
 * deux fenêtres de MÊME durée (this_month tronqué à aujourd'hui vs
 * last_month tronqué au même nombre de jours écoulés), pas comparer
 * this_month (partiel) à last_month (mois précédent ENTIER).
 *
 * Bug réel corrigé le 08/08/2026 (round 120) : $thisMonth ne couvre que le
 * 1er du mois à aujourd'hui (fenêtre partielle), $lastMonth (affiché tel
 * quel dans 'last_month') couvre le mois précédent entier — trend_pct
 * comparait directement les deux, ce qui créait une chute artificielle
 * massive en début de mois (~-74% avec un rythme d'émission pourtant
 * stable un 8 du mois) et une hausse trompeuse en toute fin de mois. Même
 * famille de bug que rounds 117/118 (fenêtres temporelles incohérentes
 * dans un ratio).
 *
 * Test fonctionnel réel : insère le même nombre de certificats par jour ce
 * mois-ci (jusqu'à aujourd'hui) et le mois dernier (sur la même plage de
 * jours) — un rythme d'émission parfaitement stable doit produire
 * trend_pct ≈ 0, pas une chute artificielle.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $table  = $prefix . 'neria_certificate';
    $marker = 'RegtestRound120_' . time();

    $realShop = (int) Context::getContext()->shop->id;
    $today    = (int) date('j'); // jour du mois courant (1-31)

    // 1 certificat par jour, du 1er au jour courant, pour CE mois ET le
    // mois dernier — rythme d'émission identique et stable.
    for ($d = 1; $d <= $today; $d++) {
        $dateThis = sprintf('%s-%02d %02d:00:00', date('Y-m'), $d, 10);
        $dateLast = date('Y-m-d H:i:s', strtotime("-1 month", strtotime($dateThis)));

        $db->execute(
            "INSERT INTO {$table}
                (id_shop, id_order, id_product, id_order_detail, serial_number,
                 customer_name, product_name, emailed, date_issued, date_add)
             VALUES
                ({$realShop}, 0, 0, 0, '" . pSQL($marker . '-this-' . $d) . "',
                 'Regtest', '" . pSQL($marker) . "', 0, '{$dateThis}', NOW())"
        );
        $db->execute(
            "INSERT INTO {$table}
                (id_shop, id_order, id_product, id_order_detail, serial_number,
                 customer_name, product_name, emailed, date_issued, date_add)
             VALUES
                ({$realShop}, 0, 0, 0, '" . pSQL($marker . '-last-' . $d) . "',
                 'Regtest', '" . pSQL($marker) . "', 0, '{$dateLast}', NOW())"
        );
    }

    try {
        $mgr   = new CertificateManager(neria_test_module());
        $stats = $mgr->getStats();

        neria_assert(
            $stats['this_month'] >= $today,
            "jeu de test invalide : this_month ({$stats['this_month']}) ne reflète pas les {$today} certificats insérés ce mois-ci"
        );
        neria_assert(
            abs($stats['trend_pct']) <= 15.0,
            "trend_pct = {$stats['trend_pct']}% pour un rythme d'émission identique ce mois-ci et le mois dernier (sur la même plage de {$today} jours) — régression du bug corrigé le 08/08/2026 (round 120) : trend_pct comparerait de nouveau une fenêtre partielle (this_month) à un mois précédent ENTIER (last_month), faussant massivement la tendance affichée en début/fin de mois"
        );
    } finally {
        $db->execute("DELETE FROM {$table} WHERE product_name = '" . pSQL($marker) . "'");
    }

    return [
        'pass'    => true,
        'message' => "CertificateManager::getStats() calcule bien trend_pct sur deux fenêtres de même durée, pas une fenêtre partielle contre un mois précédent entier",
    ];
}
