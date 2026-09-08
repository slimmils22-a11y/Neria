<?php
/**
 * Régression : CertificateManager::getStats() calculait `lastMonthComparable`
 * (fenêtre "mois précédent, mêmes N jours écoulés que le mois courant", pour
 * comparer des durées égales dans trend_pct — round 120) via :
 *   DATE_ADD(DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m-01'),
 *            INTERVAL DAY(NOW()) DAY)
 *
 * Quand le mois PRÉCÉDENT est plus court que DAY(NOW()) (ex. le 31 octobre —
 * DAY(NOW())=31, septembre=30 jours), MySQL tronque DATE_SUB(NOW(),1 MONTH)
 * au dernier jour de septembre (comportement documenté de débordement de
 * date), donc DATE_FORMAT(...,'%Y-%m-01') retombe bien sur 2026-09-01 — mais
 * DATE_ADD('2026-09-01', INTERVAL 31 DAY) = 2026-10-02, une borne haute qui
 * DÉBORDE dans le mois COURANT. La fenêtre "mois précédent comparable"
 * devenait [2026-09-01, 2026-10-02[, incluant à tort les certificats émis
 * les 1er et 2 octobre — déjà comptés dans $thisMonth (>= 2026-10-01) —
 * faussant trend_pct par double comptage à chaque fin de mois où le mois
 * précédent est plus court (mars/mai/juillet/octobre/décembre).
 *
 * Corrigé le 08/09/2026 (round 326) : LEAST() plafonne la borne haute au 1er
 * du mois courant, empêchant tout débordement.
 *
 * Test : reproduit exactement les deux requêtes (bornée et non plafonnée)
 * avec une date "NOW()" simulée fixée littéralement au 31/10/2026 (jour
 * réel non maîtrisable en test), sur de vraies lignes neria_certificate
 * insérées le 30/09 et 01/10 — vérifie que la version plafonnée exclut bien
 * la ligne d'octobre (que la version non plafonnée incluait à tort) +
 * vérification structurelle que le LEAST() est bien présent dans le code
 * réel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $serialPrefix = 'REGTEST649-' . uniqid();

    $rows = [
        ['date' => '2026-09-30 10:00:00', 'label' => 'sept30'],
        ['date' => '2026-10-01 08:00:00', 'label' => 'oct01'],
    ];

    try {
        foreach ($rows as $r) {
            $db->execute(
                "INSERT INTO {$prefix}neria_certificate
                    (id_shop, id_customer, id_order, id_product, serial_number, customer_name, product_name, date_issued, date_add)
                 VALUES
                    ({$idShop}, 0, 0, 0, '{$serialPrefix}-{$r['label']}', 'Regtest649', 'Regtest649', '{$r['date']}', '{$r['date']}')"
            );
        }
        neria_assert(
            (int) $db->getValue("SELECT COUNT(*) FROM {$prefix}neria_certificate WHERE serial_number LIKE '{$serialPrefix}%'") === 2,
            'jeu de test invalide : les 2 insertions de certificats ont échoué'
        );

        $simulatedNow = '2026-10-31 12:00:00';

        // Réplique la requête CORRIGÉE (avec LEAST), NOW() remplacé par le
        // littéral simulé.
        $fixedCount = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_certificate
             WHERE serial_number LIKE '{$serialPrefix}%'
               AND date_issued >= DATE_FORMAT(DATE_SUB('{$simulatedNow}', INTERVAL 1 MONTH), '%Y-%m-01')
               AND date_issued <  LEAST(
                       DATE_ADD(DATE_FORMAT(DATE_SUB('{$simulatedNow}', INTERVAL 1 MONTH), '%Y-%m-01'), INTERVAL DAY('{$simulatedNow}') DAY),
                       DATE_FORMAT('{$simulatedNow}', '%Y-%m-01')
                   )"
        );
        // Réplique la requête BUGUÉE (sans LEAST), pour prouver que le
        // débordement se produit réellement avec ce jeu de données.
        $buggyCount = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_certificate
             WHERE serial_number LIKE '{$serialPrefix}%'
               AND date_issued >= DATE_FORMAT(DATE_SUB('{$simulatedNow}', INTERVAL 1 MONTH), '%Y-%m-01')
               AND date_issued <  DATE_ADD(DATE_FORMAT(DATE_SUB('{$simulatedNow}', INTERVAL 1 MONTH), '%Y-%m-01'), INTERVAL DAY('{$simulatedNow}') DAY)"
        );

        neria_assert(
            $buggyCount === 2,
            "jeu de test invalide : la requête non plafonnée ne déborde pas comme attendu (buggyCount={$buggyCount}, attendu 2 — sept30+oct01)"
        );
        neria_assert(
            $fixedCount === 1,
            "la requête plafonnée (LEAST) inclut encore le certificat d'octobre — régression du bug corrigé le 08/09/2026 (round 326) : fixedCount={$fixedCount}, attendu 1 (sept30 seulement)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_certificate WHERE serial_number LIKE '{$serialPrefix}%'");
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CertificateManager.php');
    neria_assert(
        strpos($src, "LEAST(") !== false && strpos($src, "DATE_FORMAT(NOW(), '%Y-%m-01')\n                   )") !== false,
        "CertificateManager::getStats() ne plafonne plus la borne haute de lastMonthComparable via LEAST() — régression du bug corrigé le 08/09/2026 (round 326)"
    );

    return [
        'pass'    => true,
        'message' => "CertificateManager::getStats() plafonne bien la borne haute de lastMonthComparable au 1er du mois courant (LEAST), évitant le double comptage de fin de mois — bug corrigé le 08/09/2026 (round 326)",
    ];
}
