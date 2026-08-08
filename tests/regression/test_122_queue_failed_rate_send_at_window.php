<?php
/**
 * Régression : QueueManager::getStats()/HealthCheckManager::checkQueueFailedRate()
 * doivent filtrer les lignes 'failed' sur send_at (proxy de la date d'échec
 * réelle), pas created_at (date de mise en file, potentiellement très
 * antérieure pour un envoi programmé à l'avance via scheduleManual()).
 *
 * Bug réel corrigé le 08/08/2026 (round 118) : même famille que le round
 * 117 (numérateur/dénominateur d'un ratio calculés sur deux fenêtres
 * temporelles non homogènes). Un envoi mis en file il y a 45 jours
 * (created_at ancien) puis en échec définitif AUJOURD'HUI (3 tentatives
 * épuisées, send_at figé sur la dernière tentative très récente) était
 * invisible dans failed30d filtré sur created_at >= NOW()-30j, alors qu'il
 * représente un échec bien réel et récent — faussant le taux d'échec
 * affiché au marchand (BO + Watchdog health check), pouvant masquer un vrai
 * incident de délivrabilité.
 *
 * Test fonctionnel réel : insère une ligne 'failed' avec created_at à J-45
 * (hors fenêtre 30j) mais send_at à J-1 (dans la fenêtre), et vérifie
 * qu'elle est bien comptée dans failed_30d.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $createdAtOld = date('Y-m-d H:i:s', strtotime('-45 days'));
    $sendAtRecent = date('Y-m-d H:i:s', strtotime('-1 day'));
    $template     = 'neria_test_queue_failed_' . time();

    $db->execute(
        "INSERT INTO {$prefix}neria_queue
            (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
             vars_json, ref_id, send_at, status, attempts, error, created_at, sent_at)
         VALUES
            ({$idCustomer}, 1, 1, '" . pSQL($template) . "', 'test@example.com', 'Test',
             '{}', NULL, '{$sendAtRecent}', 'failed', 3, 'SMTP down', '{$createdAtOld}', NULL)"
    );

    try {
        $mgr   = new QueueManager(neria_test_module());
        $stats = $mgr->getStats();

        neria_assert(
            $stats['failed_30d'] >= 1,
            "QueueManager::getStats() ne compte pas dans failed_30d une ligne 'failed' dont created_at est hors fenêtre 30j mais send_at est dedans — régression du bug corrigé le 08/08/2026 (round 118) : un échec récent (send_at) mais mis en file il y a longtemps (created_at) redeviendrait invisible dans le taux d'échec"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE template = '" . pSQL($template) . "'");
    }

    // Garde-fou structurel complémentaire pour HealthCheckManager (health
    // check non instanciable isolément dans ce jeu de tests).
    $hcSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php');
    neria_assert($hcSrc !== false, 'Impossible de lire src/HealthCheckManager.php');
    neria_assert(
        strpos($hcSrc, "AND `status` = \\'failed\\' AND `send_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)") !== false,
        "HealthCheckManager::checkQueueFailedRate() ne filtre plus failed30d sur send_at — régression du bug corrigé le 08/08/2026 (round 118)"
    );

    return [
        'pass'    => true,
        'message' => "QueueManager::getStats()/HealthCheckManager::checkQueueFailedRate() comptent bien failed_30d via send_at, pas created_at",
    ];
}
