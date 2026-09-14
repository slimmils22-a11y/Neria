<?php
/**
 * Régression : un second remboursement légitime sur une commande déjà
 * réconciliée doit reprogrammer une nouvelle séquence de réconciliation
 * (J+1/J+3/J+7) quand le cycle précédent est terminé (annulé, ou
 * intégralement envoyé) — jusqu'ici l'INSERT IGNORE sur
 * `neria_reconciliation` (UNIQUE KEY uniq_order) ignorait silencieusement
 * TOUT second INSERT sur la même commande, peu importe l'état du cycle
 * précédent.
 *
 * Corrigé hors round le 14/09/2026 (scheduling explicite utilisateur,
 * "Réconciliation second remboursement jamais reprogrammé", round 353) via
 * un ON DUPLICATE KEY UPDATE qui ne réouvre la ligne QUE si status <>
 * 'active' OU sent_3 = 1 (cycle terminé) — une ligne réellement 'active' et
 * en cours (sent_3 = 0) reste inchangée, préservant la déduplication
 * d'origine pour le cas des avoirs multiples sur UN SEUL remboursement.
 *
 * Test comportemental réel : reproduit l'exact UPSERT du correctif sur un
 * id_order fictif (hors plage réelle), dans les 2 scénarios (cycle annulé
 * → réouvert ; cycle actif en cours → protégé), nettoie la ligne de test
 * dans tous les cas.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    $idOrder    = 999999995;
    $idCustomer = (int) $db->getValue("SELECT id_customer FROM {$prefix}customer WHERE active=1 AND deleted=0");
    neria_assert($idCustomer > 0, 'Jeu de test invalide : aucun client actif trouvé');

    $upsertSql = function () use ($prefix, $idOrder, $idCustomer, $idShop): string {
        return "INSERT INTO `{$prefix}neria_reconciliation`
             (id_order, id_customer, id_shop, send_1_date, send_2_date, send_3_date, sent_1, sent_2, sent_3, status, date_add)
             VALUES (
                 {$idOrder},
                 {$idCustomer},
                 {$idShop},
                 DATE_ADD(CURDATE(), INTERVAL 1 DAY),
                 DATE_ADD(CURDATE(), INTERVAL 3 DAY),
                 DATE_ADD(CURDATE(), INTERVAL 7 DAY),
                 0, 0, 0, 'active', NOW()
             )
             ON DUPLICATE KEY UPDATE
                 send_1_date = IF(status <> 'active' OR sent_3 = 1, VALUES(send_1_date), send_1_date),
                 send_2_date = IF(status <> 'active' OR sent_3 = 1, VALUES(send_2_date), send_2_date),
                 send_3_date = IF(status <> 'active' OR sent_3 = 1, VALUES(send_3_date), send_3_date),
                 sent_1      = IF(status <> 'active' OR sent_3 = 1, 0, sent_1),
                 sent_2      = IF(status <> 'active' OR sent_3 = 1, 0, sent_2),
                 sent_3      = IF(status <> 'active' OR sent_3 = 1, 0, sent_3),
                 status      = IF(status <> 'active' OR sent_3 = 1, 'active', status),
                 date_add    = IF(status <> 'active' OR sent_3 = 1, VALUES(date_add), date_add)";
    };

    try {
        $db->delete('neria_reconciliation', 'id_order = ' . $idOrder);

        // ── Scénario 1 : cycle précédent ANNULÉ (client a recommandé) ──
        $db->execute($upsertSql());
        $db->execute("UPDATE `{$prefix}neria_reconciliation` SET status = 'cancelled', sent_1 = 1
                       WHERE id_order = {$idOrder}");

        $db->execute($upsertSql());
        $row1 = $db->getRow("SELECT status, sent_1, sent_2, sent_3 FROM `{$prefix}neria_reconciliation` WHERE id_order = {$idOrder}");
        neria_assert(
            $row1['status'] === 'active' && (int) $row1['sent_1'] === 0,
            "Second remboursement sur un cycle 'cancelled' n'a pas réouvert la séquence (status={$row1['status']}, sent_1={$row1['sent_1']}) — régression du correctif du 14/09/2026"
        );

        // ── Scénario 2 : cycle précédent TERMINÉ (sent_3 = 1, jamais annulé) ──
        $db->execute("UPDATE `{$prefix}neria_reconciliation` SET sent_1 = 1, sent_2 = 1, sent_3 = 1
                       WHERE id_order = {$idOrder}");

        $db->execute($upsertSql());
        $row2 = $db->getRow("SELECT status, sent_1, sent_2, sent_3 FROM `{$prefix}neria_reconciliation` WHERE id_order = {$idOrder}");
        neria_assert(
            (int) $row2['sent_3'] === 0,
            "Second remboursement sur un cycle terminé (sent_3=1) n'a pas réouvert la séquence (sent_3={$row2['sent_3']}) — régression du correctif du 14/09/2026"
        );

        // ── Scénario 3 : cycle RÉELLEMENT actif et en cours (sent_3 = 0, status = 'active') ──
        // doit rester protégé — même avoir créé deux fois pour le MÊME
        // remboursement (dédup d'origine, ne doit pas être cassée).
        $db->execute($upsertSql());
        $db->execute($upsertSql());
        $row3 = $db->getRow("SELECT status, sent_1, sent_2, sent_3, id_reconciliation FROM `{$prefix}neria_reconciliation` WHERE id_order = {$idOrder}");
        $countRows = (int) $db->getValue("SELECT COUNT(*) FROM `{$prefix}neria_reconciliation` WHERE id_order = {$idOrder}");
        neria_assert(
            $countRows === 1 && $row3['status'] === 'active' && (int) $row3['sent_1'] === 0,
            "Un cycle réellement actif et en cours n'est plus protégé de la déduplication (lignes={$countRows}, status={$row3['status']}) — régression de la dédup d'origine (avoirs multiples sur un même remboursement)"
        );

        return [
            'pass'    => true,
            'message' => "L'UPSERT neria_reconciliation réouvre bien un cycle terminé (annulé ou entièrement envoyé) tout en protégeant un cycle réellement actif — correctif du 14/09/2026 validé",
        ];
    } finally {
        $db->delete('neria_reconciliation', 'id_order = ' . $idOrder);
    }
}
