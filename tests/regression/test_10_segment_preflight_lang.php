<?php
/** Régression : SegmentManager::preflightCheck() doit détecter un fichier template manquant dans la vraie langue du client. */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $prefix = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();

    $origLang = (int) $db->getValue("SELECT id_lang FROM {$prefix}customer WHERE id_customer={$idCustomer}");
    $db->execute("UPDATE {$prefix}customer SET id_lang=4 WHERE id_customer={$idCustomer}"); // 4 = de
    $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer={$idCustomer} AND id_shop=1");
    $db->execute("INSERT INTO {$prefix}neria_customer_segment (id_shop, id_customer, segment, total_sent, total_opens, total_clicks, total_conversions, computed_at)
        VALUES (1, {$idCustomer}, 'ambassador', 5, 5, 2, 1, NOW())");

    $deFile = _PS_MODULE_DIR_ . 'neria/mails/de/vip.html';
    $renamed = false;
    if (is_file($deFile)) {
        rename($deFile, $deFile . '.regtest_bak');
        $renamed = true;
    }

    try {
        require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';
        $mgr = new SegmentManager(neria_test_module());
        $result = $mgr->preflightCheck('ambassador', 'vip');

        $flagged = false;
        foreach ($result['issues'] as $issue) {
            if (stripos($issue, 'de') !== false) { $flagged = true; break; }
        }
        neria_assert($flagged, "preflightCheck() n'a pas détecté le fichier de/vip.html manquant pour un client réellement en allemand — régression du bug corrigé le 17/07/2026 (commit cccb4d8)");

        return ['pass' => true, 'message' => 'preflightCheck() détecte toujours les templates manquants dans la vraie langue'];
    } finally {
        if ($renamed) {
            rename($deFile . '.regtest_bak', $deFile);
        }
        $db->execute("UPDATE {$prefix}customer SET id_lang={$origLang} WHERE id_customer={$idCustomer}");
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer={$idCustomer} AND id_shop=1 AND segment='ambassador'");
    }
}
