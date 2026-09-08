<?php
/**
 * Régression : suite au correctif round 323 (OrderTriggersManager), un
 * balayage exhaustif de tous les autres appels GET_LOCK() du module a
 * révélé le MÊME défaut dans 7 autres fichiers : un cast direct
 * `(int) $this->db->getValue("SELECT GET_LOCK(...)", false) !== 1` ne
 * distingue jamais NULL (erreur MySQL réelle — verrou système
 * indisponible, nombre de locks nommés simultanés dépassé) de 0 (verrou
 * déjà détenu par un autre processus — blocage anti-doublon NORMAL,
 * volontairement silencieux), et aucune trace Watchdog n'existait pour
 * le cas d'échec technique réel :
 * - CalendarManager::runDailyCheck() (neria_calendar_check_{idShop})
 * - LicenseManager::validateLicense() (neria_license_validate)
 * - MonthlyReportManager::checkAndSend() (neria_monthly_report_check)
 * - MonthlyReportManager::deliverReport() (neria_monthly_report_deliver)
 * - QueueManager::processQueue() (neria_queue_process_queue)
 * - UpsellManager::checkConversions() (neria_upsell_check_conversions)
 * - WaitlistManager::notifyProduct() (neria_waitlist_notify_*)
 * - WebhookManager::processQueue() (neria_webhook_process_queue_{idShop})
 *
 * Corrigé le 08/09/2026 (round 324) : même correctif que round 323 dans
 * chacun des 7 fichiers — la valeur brute de GET_LOCK() est capturée
 * AVANT le cast, un warning Watchdog est journalisé UNIQUEMENT si elle
 * vaut littéralement NULL (jamais pour le cas 0, qui reste silencieux
 * comme voulu).
 *
 * Test structurel (provoquer une vraie erreur MySQL GET_LOCK() sur 7
 * fichiers différents nécessiterait de saturer artificiellement les
 * verrous MySQL du serveur de test, risqué pour le reste de la suite —
 * même limitation documentée par test_641) : vérifie la présence du
 * garde-fou dans chacun des 7 fichiers.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $expected = [
        'src/CalendarManager.php'      => "GET_LOCK() a échoué (verrou système indisponible) pour neria_calendar_check_",
        'src/LicenseManager.php'       => "GET_LOCK() a échoué (verrou système indisponible) pour neria_license_validate",
        'src/MonthlyReportManager.php' => "GET_LOCK() a échoué (verrou système indisponible) pour neria_monthly_report_check",
        'src/QueueManager.php'         => "GET_LOCK() a échoué (verrou système indisponible) pour neria_queue_process_queue",
        'src/UpsellManager.php'        => "GET_LOCK() a échoué (verrou système indisponible) pour neria_upsell_check_conversions",
        'src/WaitlistManager.php'      => "GET_LOCK() a échoué (verrou système indisponible) pour ' . \$lockName",
        'src/WebhookManager.php'       => "GET_LOCK() a échoué (verrou système indisponible) pour ' . \$lockName",
    ];

    foreach ($expected as $relPath => $needle) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/' . $relPath);
        neria_assert($src !== false, "Impossible de lire {$relPath}");
        neria_assert(
            strpos($src, $needle) !== false,
            "{$relPath} ne distingue plus NULL (erreur GET_LOCK() réelle) de 0 (blocage anti-doublon normal) — régression du bug corrigé le 08/09/2026 (round 324) : un email/traitement légitime pourrait de nouveau être perdu silencieusement sur une panne MySQL réelle, sans aucune trace Watchdog"
        );
    }

    // MonthlyReportManager a 2 occurrences (checkAndSend + deliverReport).
    $mrmSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert(
        substr_count($mrmSrc, 'GET_LOCK() a échoué (verrou système indisponible) pour neria_monthly_report') === 2,
        "MonthlyReportManager ne journalise plus les 2 échecs GET_LOCK() attendus (checkAndSend + deliverReport) — régression du bug corrigé le 08/09/2026 (round 324)"
    );

    return [
        'pass'    => true,
        'message' => "Les 7 fichiers du balayage GET_LOCK() (CalendarManager, LicenseManager, MonthlyReportManager x2, QueueManager, UpsellManager, WaitlistManager, WebhookManager) distinguent bien NULL (erreur réelle) de 0 (dédup normale) — bug corrigé le 08/09/2026 (round 324)",
    ];
}
