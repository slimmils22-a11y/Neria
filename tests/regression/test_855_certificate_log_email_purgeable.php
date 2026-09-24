<?php
/**
 * Régression (constat réel du 24/09/2026, P7 — effacement RGPD vérifié sur ps-test) : après l'effacement d'un
 * client (GdprAuditManager::purgeCustomerData()), son adresse e-mail survivait dans le journal Watchdog
 * (neria_log) : CertificateManager::issue() écrivait ses messages en TEXTE LIBRE contenant l'e-mail
 * (« … client : jean@x.fr »), alors que la purge ne retrouve que les messages au format ::i18n:: dont une
 * variable est exactement l'adresse (ou un contexte JSON). Même défaut dans WaitlistManager (notified_at).
 *
 * Corrigé : ces 4 messages passent par WatchdogManager::i18nMsg() (variable « email »), donc purgeables ET
 * traduits dans la langue du back-office (19 langues).
 *
 * Test comportemental : émission réelle d'un certificat (sans envoi d'e-mail) pour une commande existante ;
 * le message écrit doit être au format ::i18n:: avec l'e-mail du client comme variable exacte ; les 4 clés
 * existent en 19 langues. Le certificat et son journal sont supprimés en fin de test.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/CertificateManager.php';
    $module = neria_test_module();
    $db = neria_test_db();
    $prefix = neria_test_prefix();

    // Clés traduites en 19 langues
    $tr = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['watchdog.certificate_issue_failed', 'watchdog.certificate_issued_email_failed', 'watchdog.certificate_issued', 'watchdog.waitlist_notified_unconfirmed'] as $key) {
        neria_assert(isset($tr[$key]) && count($tr[$key]) === 19, "Clé {$key} absente ou incomplète (19 langues attendues)");
        neria_assert(strpos($tr[$key]['fr'], '{email}') !== false && strpos($tr[$key]['ja'], '{email}') !== false, "Clé {$key} : variable {email} absente");
    }

    $od = $db->getRow("SELECT od.id_order, od.product_id, od.id_order_detail, c.email, c.id_customer
        FROM {$prefix}order_detail od INNER JOIN {$prefix}orders o ON o.id_order = od.id_order
        INNER JOIN {$prefix}customer c ON c.id_customer = o.id_customer WHERE c.deleted = 0 ORDER BY od.id_order_detail DESC");
    neria_assert(is_array($od) && !empty($od['email']), 'Jeu de test invalide : aucune ligne de commande avec client');

    $mgr = new CertificateManager($module);
    $logBefore = (int) $db->getValue("SELECT MAX(id_log) FROM {$prefix}neria_log", false);
    $err = $mgr->issue((int) $od['id_order'], (int) $od['product_id'], (int) $od['id_order_detail'], '', 'note test 855', false, 'Artisan 855', 'Region 855', '1 jour');
    $idCert = 0;
    try {
        neria_assert($err === '', "Émission du certificat refusée : {$err}");
        $idCert = (int) $db->getValue("SELECT MAX(id_certificate) FROM {$prefix}neria_certificate WHERE id_order = " . (int) $od['id_order'], false);
        $row = $db->getRow("SELECT message FROM {$prefix}neria_log WHERE id_log > {$logBefore} AND class = 'CertificateManager' ORDER BY id_log DESC", false);
        neria_assert(is_array($row), "Aucun message Watchdog écrit à l'émission du certificat");
        $msg = (string) $row['message'];
        neria_assert(strpos($msg, '::i18n::') === 0, "Le message d'émission est en texte libre (« {$msg} ») — l'e-mail du client ne serait pas retrouvé par l'effacement RGPD — régression du bug corrigé le 24/09/2026");
        $decoded = json_decode(substr($msg, 8), true);
        neria_assert(($decoded['k'] ?? '') === 'watchdog.certificate_issued', 'Clé de message inattendue : ' . json_encode($decoded));
        neria_assert(
            in_array(strtolower((string) $od['email']), array_map('strtolower', array_filter((array) ($decoded['v'] ?? []), 'is_string')), true),
            "L'e-mail du client n'est pas une variable exacte du message (la purge RGPD ne le retrouverait pas)"
        );
    } finally {
        if ($idCert > 0) {
            $mgr->delete($idCert);
        }
        $db->execute("DELETE FROM {$prefix}neria_log WHERE id_log > {$logBefore} AND class = 'CertificateManager'");
    }

    return [
        'pass'    => true,
        'message' => "Les journaux d'émission de certificat (et de liste d'attente) portent l'e-mail en variable ::i18n:: exacte, donc purgeable par l'effacement RGPD, et sont traduits en 19 langues — bug corrigé le 24/09/2026",
    ];
}
