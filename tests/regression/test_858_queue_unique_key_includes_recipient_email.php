<?php
/**
 * Régression (défaut trouvé le 24/09/2026 pendant le test 856) : neria_queue avait la clé unique
 * (id_customer, template, ref_id, id_shop). Toutes les personnes SANS compte client partagent id_customer = 0 :
 * un envoi manuel planifié d'un modèle à une adresse libre refusait, avec « déjà programmé pour ce client », le
 * même modèle pour TOUTES les autres adresses libres de la boutique tant que le premier n'était pas traité.
 *
 * Corrigé (upgrade 1.0.49 + install.sql) : la clé porte aussi recipient_email (préfixe 191).
 *
 * Test comportemental : retour à l'ancienne clé, constat du blocage, exécution réelle de
 * upgrade_module_1_0_49() (puis 2e exécution : idempotente), deux adresses libres planifiables pour le même
 * modèle via ManualSendManager::scheduleManual(), doublon de la MÊME adresse toujours refusé, install.sql à jour.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/upgrade/upgrade-1.0.49.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    $module = neria_test_module();
    $db = neria_test_db();
    $p = neria_test_prefix();
    $table = $p . 'neria_queue';
    $idShop = (int) Context::getContext()->shop->id;
    $keyColumns = static function () use ($db, $table): array {
        $rows = $db->executeS("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = 'uq_customer_template_ref_shop' ORDER BY SEQ_IN_INDEX", true, false);
        return is_array($rows) ? array_column($rows, 'COLUMN_NAME') : [];
    };
    $e1 = 'regtest858-1-' . uniqid() . '@example.com';
    $e2 = 'regtest858-2-' . uniqid() . '@example.com';
    $insert = static function (string $email, string $template = 'regtest858') use ($db, $table, $idShop): bool {
        $db->execute("INSERT IGNORE INTO `{$table}` (id_customer, id_shop, id_lang, template, recipient_email, recipient_name, vars_json, ref_id, send_at, status, created_at)
            VALUES (0, {$idShop}, 1, '" . pSQL($template) . "', '" . pSQL($email) . "', '', '{}', 0, DATE_ADD(NOW(), INTERVAL 1 DAY), 'pending', NOW())");
        return (int) $db->Affected_Rows() === 1;
    };
    $clean = static function () use ($db, $table, $e1, $e2): void {
        $db->execute("DELETE FROM `{$table}` WHERE recipient_email IN ('" . pSQL($e1) . "','" . pSQL($e2) . "')");
    };

    try {
        // 1. Ancienne clé : le blocage existe
        $db->execute("ALTER TABLE `{$table}` DROP INDEX `uq_customer_template_ref_shop`, ADD UNIQUE KEY `uq_customer_template_ref_shop` (`id_customer`, `template`, `ref_id`, `id_shop`)");
        neria_assert($insert($e1) === true && $insert($e2) === false, "Jeu de test invalide : l'ancienne clé ne bloque pas la 2e adresse libre");
        $clean();

        // 2. Upgrade réel + idempotence
        neria_assert(upgrade_module_1_0_49($module) === true, 'upgrade_module_1_0_49() a échoué');
        neria_assert(in_array('recipient_email', $keyColumns(), true), "La clé unique ne porte pas recipient_email après l'upgrade — régression du défaut corrigé le 24/09/2026");
        $before = $keyColumns();
        neria_assert(upgrade_module_1_0_49($module) === true && $keyColumns() === $before, "L'upgrade 1.0.49 n'est pas idempotent");

        // 3. Deux adresses libres pour le même modèle : possible ; la MÊME adresse deux fois : refusée
        neria_assert($insert($e1) === true && $insert($e2) === true, "Deux adresses libres ne peuvent toujours pas être en file pour le même modèle");
        neria_assert($insert($e1) === false, "Le doublon de la MÊME adresse n'est plus refusé");
        $clean();

        // 4. Bout en bout : planification manuelle de deux adresses libres pour le même modèle
        $mgr = new ManualSendManager($module);
        $at = date('Y-m-d H:i:s', strtotime('+2 days'));
        $r1 = $mgr->scheduleManual('vip', $e1, '', '', [], $at);
        $r2 = $mgr->scheduleManual('vip', $e2, '', '', [], $at);
        $r3 = $mgr->scheduleManual('vip', $e1, '', '', [], $at);
        neria_assert(($r1['ok'] ?? false) === true && ($r2['ok'] ?? false) === true, 'scheduleManual() refuse la 2e adresse libre : ' . json_encode($r2, JSON_UNESCAPED_UNICODE));
        neria_assert(($r3['ok'] ?? true) === false, "scheduleManual() accepte le même envoi deux fois pour la même adresse");
    } finally {
        $clean();
        upgrade_module_1_0_49($module);
    }

    $sql = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/sql/install.sql');
    neria_assert(strpos($sql, '`id_shop`, `recipient_email`(191))') !== false, "install.sql ne déclare plus recipient_email dans la clé de neria_queue");

    return [
        'pass'    => true,
        'message' => "neria_queue : la clé unique porte recipient_email (upgrade 1.0.49 idempotent, install.sql à jour) — deux adresses sans compte peuvent être planifiées pour le même modèle, le doublon d'une même adresse reste refusé — défaut corrigé le 24/09/2026",
    ];
}
