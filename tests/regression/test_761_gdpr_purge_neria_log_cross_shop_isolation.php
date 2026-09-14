<?php
/**
 * Régression : GdprAuditManager::purgeCustomerData() purge neria_log par
 * simple correspondance d'email décodé (message ::i18n:: ou context JSON),
 * SANS AUCUN scoping par boutique — contrairement au bloc neria_webhook_queue
 * juste au-dessus dans la même méthode, qui scope correctement par
 * `$idShop` via sa propre colonne `id_shop`. Un commentaire (round 330)
 * affirmait à tort que neria_log n'avait pas de colonne id_shop et
 * traitait ce défaut comme une "limite structurelle acceptée" — faux :
 * sql/install.sql (TABLE 9) déclare bien `id_shop INT(11) NOT NULL DEFAULT 1`
 * avec `INDEX idx_shop`, déjà exploitée par WatchdogManager::pruneOldLogs()/
 * clearLogs(). Conséquence concrète : un client de la boutique A exerçant
 * son droit à l'effacement RGPD purgeait aussi les entrées de log d'un
 * client de la boutique B partageant le même email (scénario plausible en
 * multi-boutique avec comptes partagés/emails génériques).
 *
 * Bug identifié le 14/09/2026 (round 359, audit dédié GdprAuditManager).
 *
 * Corrigé le 14/09/2026 : `id_shop` ajouté au SELECT, et une condition
 * `if ($matches && $idShop > 0) { $matches = (int) $row['id_shop'] === $idShop; }`
 * ajoutée (même mécanisme que le bloc neria_webhook_queue ci-dessus).
 *
 * Test comportemental réel : 2 entrées de log fictives portant le MÊME
 * email (::i18n:: pour l'une, context JSON pour l'autre) mais des
 * id_shop DIFFÉRENTS (boutiques réelles 1 et 2, même approche que
 * test_666 pour neria_webhook_queue) — vérifie que purgeCustomerData()
 * avec $idShop > 0 ne supprime QUE l'entrée de la boutique ciblée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $table  = $prefix . 'neria_log';

    $email      = 'regtest761@example.invalid';
    $idShopA    = 1;
    $idShopB    = 2;
    $msgA       = WatchdogManager::i18nMsg('watchdog.test_dummy', ['email' => $email]);
    $contextB   = json_encode(['email' => $email], JSON_UNESCAPED_UNICODE);

    $cleanup = function () use ($db, $table, $email) {
        $db->execute("DELETE FROM {$table} WHERE message LIKE '%regtest761%' OR context LIKE '%regtest761%'");
    };
    $cleanup();

    try {
        $db->execute(sprintf(
            "INSERT INTO `%s` (`id_shop`, `level`, `template`, `class`, `message`, `context`, `occurrence_count`, `date_add`)
             VALUES (%d, 'info', '', 'RegTest761', '%s', NULL, 1, NOW())",
            $table, $idShopA, pSQL($msgA)
        ));
        $idLogA = (int) $db->Insert_ID();

        $db->execute(sprintf(
            "INSERT INTO `%s` (`id_shop`, `level`, `template`, `class`, `message`, `context`, `occurrence_count`, `date_add`)
             VALUES (%d, 'info', '', 'RegTest761', 'other message', '%s', 1, NOW())",
            $table, $idShopB, pSQL($contextB)
        ));
        $idLogB = (int) $db->Insert_ID();

        neria_assert($idLogA > 0 && $idLogB > 0, 'Échec de préparation du jeu de test (INSERT neria_log)');

        $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');

        // Purge scopée sur la boutique A uniquement.
        $mgr->purgeCustomerData(0, $email, $idShopA);

        $stillA = (int) $db->getValue("SELECT COUNT(*) FROM `{$table}` WHERE id_log = {$idLogA}");
        $stillB = (int) $db->getValue("SELECT COUNT(*) FROM `{$table}` WHERE id_log = {$idLogB}");

        neria_assert(
            $stillA === 0,
            "purgeCustomerData(idShop=A) n'a pas supprimé l'entrée neria_log de la boutique A elle-même — jeu de test invalide ou régression"
        );
        neria_assert(
            $stillB === 1,
            "purgeCustomerData(idShop=A) a supprimé l'entrée neria_log de la boutique B (même email) — régression du bug corrigé le 14/09/2026 (round 359) : la purge RGPD d'un client de la boutique A efface à tort les logs d'un client homonyme de la boutique B"
        );

        return [
            'pass'    => true,
            'message' => "GdprAuditManager::purgeCustomerData() scope désormais correctement la purge de neria_log par id_shop — bug corrigé le 14/09/2026 (round 359)",
        ];
    } finally {
        $cleanup();
    }
}
