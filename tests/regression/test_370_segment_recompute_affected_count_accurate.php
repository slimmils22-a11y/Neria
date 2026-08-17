<?php
/**
 * Régression : SegmentManager::recomputeAll() loggait dans Watchdog (et
 * retournait) le résultat brut de Db::Affected_Rows() après un
 * INSERT ... ON DUPLICATE KEY UPDATE. Sans MYSQLI_CLIENT_FOUND_ROWS
 * (non activé côté core PrestaShop), mysqli compte 1 par ligne insérée
 * mais 2 par ligne mise à jour dont une colonne change réellement de
 * valeur — le recalcul quotidien traitant très majoritairement des
 * UPDATE (clients déjà segmentés dont les stats évoluent d'un jour à
 * l'autre), le chiffre pouvait quasiment doubler le nombre réel de
 * clients traités, rendant la métrique Watchdog inexploitable pour
 * détecter une vraie régression de volume.
 *
 * Corrigé le 17/08/2026 (round 181) : $affected recompté via un
 * SELECT COUNT(*) sur la même sous-requête/WHERE que l'INSERT, reflétant
 * le nombre réel de clients concernés quel que soit INSERT ou UPDATE.
 *
 * Test réel : crée un événement 'sent' pour un client de test (1er appel
 * = INSERT, un seul client), puis ajoute un événement 'open' pour ce
 * même client (2e appel = UPDATE réel de la ligne, un seul client). Avant
 * le correctif, Affected_Rows() aurait retourné 2 lors du 2e appel pour
 * un seul client réellement traité.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $module     = neria_test_module();

    // Id de test dédié, hors plage des vrais clients et des autres tests
    // de ce fichier (test_292 utilise 900000+id%1000).
    $testCustomerId = 970000 + ($idCustomer % 1000);

    $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$testCustomerId}");
    $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$testCustomerId}");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, date_add)
             VALUES
                (1, 'order_conf', 'fr', {$testCustomerId}, 0, '" . bin2hex(random_bytes(16)) . "', 'sent', DATE_SUB(NOW(), INTERVAL 60 DAY))"
        );

        $mgr = new SegmentManager($module);
        $r1  = $mgr->recomputeAll();
        neria_assert(
            $r1 >= 1,
            "Premier recomputeAll() (INSERT) n'a pas compté au moins 1 client — jeu de test invalide (r1={$r1})"
        );

        // Ajoute un événement 'open' pour ce client : au 2e appel, sa ligne
        // neria_customer_segment existante change réellement de valeur
        // (total_opens, segment) — UPDATE réel côté ON DUPLICATE KEY UPDATE.
        $db->execute(
            "INSERT INTO {$prefix}neria_stat
                (id_shop, template, lang, id_customer, id_order, tracking_token, event_type, is_mpp, date_add)
             VALUES
                (1, 'order_conf', 'fr', {$testCustomerId}, 0, '" . bin2hex(random_bytes(16)) . "', 'open', 0, DATE_SUB(NOW(), INTERVAL 30 DAY))"
        );

        $r2 = $mgr->recomputeAll();

        // Compte réel indépendant, calculé avec le MÊME critère
        // d'éligibilité que recomputeAll() (WHERE NOT grâce nouveau client)
        // mais SANS dépendre du mécanisme Affected_Rows() en cause dans le
        // bug : nombre réel de clients du shop 1 qui devraient apparaître
        // dans neria_customer_segment après ce recalcul. recomputeAll()
        // traite ici l'intégralité des clients de la boutique 1 (pas
        // seulement le client de test), donc ce nombre reflète la
        // popuplation réelle de l'environnement de dev, pas juste 1 — ce
        // qui est justement ce qui rend le bug (doublement quasi-total sur
        // un recalcul majoritairement composé d'UPDATE) significatif en
        // production.
        $realCount = (int) $db->getValue(
            "SELECT COUNT(*) FROM (
                SELECT m.id_customer
                FROM (
                    SELECT
                        id_customer,
                        SUM(event_type = 'sent')        AS total_sent,
                        SUM(event_type = 'open' AND is_mpp = 0)        AS total_opens,
                        MAX(CASE WHEN event_type = 'conversion' THEN date_add END) AS last_conv,
                        MIN(CASE WHEN event_type = 'sent'       THEN date_add END) AS first_sent
                    FROM {$prefix}neria_stat
                    WHERE id_shop = 1 AND id_customer > 0
                    GROUP BY id_customer
                ) m
                WHERE NOT (
                    m.total_opens = 0
                    AND COALESCE(m.first_sent, '1970-01-01') >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                )
            ) counted"
        );

        neria_assert(
            $r2 === $realCount,
            "SegmentManager::recomputeAll() a retourné {$r2} au lieu du nombre réel de clients éligibles ({$realCount}) — régression du bug corrigé le 17/08/2026 (round 181) : Affected_Rows() compte 2 par ligne UPDATE réellement modifiée (cas majoritaire ici, la plupart des clients ayant déjà une ligne de segment d'un recalcul précédent), gonflant le chiffre loggué dans Watchdog bien au-delà du nombre réel de clients traités"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_stat WHERE id_customer = {$testCustomerId}");
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer = {$testCustomerId}");
    }

    return [
        'pass'    => true,
        'message' => "SegmentManager::recomputeAll() retourne bien le nombre réel de clients traités, pas Affected_Rows() brut — bug corrigé le 17/08/2026 (round 181)",
    ];
}
