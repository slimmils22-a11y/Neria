<?php
/**
 * Régression : ManualSendManager::checkDuplicate() doit sommer
 * occurrence_count (pas COUNT(*) sur les lignes) pour détecter un envoi
 * manuel en double.
 *
 * Bug réel corrigé le 05/08/2026 (round 51) : WatchdogManager::record()
 * consolide toute entrée de log identique (même message) survenue dans la
 * dernière heure en incrémentant occurrence_count sur la ligne existante
 * au lieu d'insérer une nouvelle ligne. Deux envois manuels du même
 * template au même client dans la même heure produisent le même message
 * ('watchdog.manual_send_ok') et sont donc consolidés en UNE seule ligne
 * avec occurrence_count=2 — un COUNT(*) simple sur les lignes renvoyait
 * alors 1 malgré 2 envois réels, ne détectant jamais le doublon.
 *
 * Ce test seed directement une ligne neria_log avec occurrence_count=2
 * (simule la consolidation Watchdog) plutôt que de déclencher 2 vrais
 * envois — plus rapide et sans effet de bord (pas de vrai email envoyé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = 'regtest-dup-' . uniqid() . '@example.com';

    $db->execute(
        "INSERT INTO {$prefix}neria_log
            (id_shop, level, template, class, message, context, date_add, occurrence_count)
         VALUES (1, 'info', 'vip', 'ManualSendManager',
                 'Email vip envoyé avec succès à {$email}', NULL, NOW(), 2)"
    );
    $idLog = (int) $db->Insert_ID();

    try {
        $mgr    = new ManualSendManager(neria_test_module());
        $result = $mgr->checkDuplicate($email, 'vip');

        neria_assert(
            $result['message'] !== '' && strpos($result['message'], '2') !== false,
            "checkDuplicate() ne détecte plus le doublon consolidé (occurrence_count=2) — régression du bug COUNT(*) vs SUM(occurrence_count) corrigé le 05/08/2026 (message reçu : '" . $result['message'] . "')"
        );

        return [
            'pass'    => true,
            'message' => 'checkDuplicate() détecte bien 2 envois via occurrence_count consolidé',
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_log WHERE id_log = {$idLog}");
    }
}
