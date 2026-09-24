<?php
/**
 * Régression (constat réel du 24/09/2026, P4/P5 sur ps-test) : avec la fenêtre d'achat individuelle
 * (NERIA_PURCHASE_WINDOW_ENABLED), un e-mail comportemental est programmé à l'heure d'achat préférée du
 * client ; si cette heure est déjà passée, QueueManager::nextOccurrence() le reporte à DEMAIN. Pour un
 * modèle lié à une date (anniversaire, anniversaire de relation), l'e-mail arrivait donc le lendemain du
 * jour concerné (queue : birthday send_at 2026-09-24 07:00 pour un anniversaire du 23/09).
 *
 * Corrigé : BehavioralCronManager::DAY_BOUND_TEMPLATES — si l'heure préférée est passée, envoi immédiat.
 *
 * Test comportemental : client jetable avec 2 commandes valides à 00h30 (heure préférée 1 h), fenêtre
 * d'achat activée, heure courante > 1 h. birthday → envoyé tout de suite (aucune ligne en file, ligne
 * neria_behavioral_sent) ; témoin reorder_reminder → programmé le lendemain (ligne en file).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $p  = neria_test_prefix();
    $module = neria_test_module();
    $idCustomer = neria_test_any_customer_id();
    $row = $db->getRow("SELECT id_customer, email, firstname, lastname, id_lang, id_shop FROM {$p}customer WHERE id_customer={$idCustomer}");
    $idShop = (int) $row['id_shop'] ?: 1;
    if ((int) $db->getValue('SELECT HOUR(NOW())', false) < 2) {
        return ['pass' => true, 'message' => "Test sauté : entre 00h et 02h l'heure préférée simulée (1 h) n'est pas encore passée."];
    }

    $orig = Configuration::getGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED');
    $ctx = Context::getContext(); $origShop = $ctx->shop;
    $orderIds = [];
    try {
        $ctx->shop = new Shop($idShop);
        Configuration::updateGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED', 1);
        $today = (string) $db->getValue('SELECT CURDATE()', false);
        for ($i = 0; $i < 2; $i++) {
            $db->execute("INSERT INTO {$p}orders (reference,id_shop_group,id_shop,id_carrier,id_lang,id_customer,id_cart,id_currency,id_address_delivery,id_address_invoice,current_state,secure_key,payment,module,valid,date_add,date_upd,total_paid,total_paid_tax_incl,total_paid_tax_excl,total_paid_real,total_products,total_products_wt,conversion_rate)
                          VALUES ('T849" . $i . "',1,{$idShop},1," . (int) $row['id_lang'] . ",{$idCustomer},0,1,0,0,5,'x','t','ps_wirepayment',1,'{$today} 00:30:00','{$today} 00:30:00',10,10,10,10,10,10,1)");
            $orderIds[] = (int) $db->Insert_ID();
        }
        neria_assert((new PurchaseWindowManager())->getPreferredHour($idCustomer, $idShop) === 1, "Précondition : l'heure préférée simulée doit valoir 1 (obtenu " . var_export((new PurchaseWindowManager())->getPreferredHour($idCustomer, $idShop), true) . ')');

        $cron = new BehavioralCronManager($module);
        $m = new ReflectionMethod(BehavioralCronManager::class, 'send');
        $m->setAccessible(true);
        $queued = static function (string $tpl, int $ref) use ($db, $p, $idCustomer): int {
            return (int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_queue WHERE id_customer={$idCustomer} AND template='{$tpl}' AND ref_id={$ref}", false);
        };
        $sentRow = static function (string $tpl, int $ref) use ($db, $p, $idCustomer): int {
            return (int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_behavioral_sent WHERE id_customer={$idCustomer} AND template='{$tpl}' AND ref_id={$ref}", false);
        };
        $db->execute("DELETE FROM {$p}neria_stat WHERE id_customer={$idCustomer} AND event_type='sent' AND template IN ('birthday','reorder_reminder') AND date_add > DATE_SUB(NOW(), INTERVAL 60 MINUTE)");

        $m->invoke($cron, 'birthday', $row, ['{voucher_code}' => 'REGTEST849', '{shop_url}' => 'https://example.test'], 998849);
        neria_assert($queued('birthday', 998849) === 0, "L'e-mail d'anniversaire a été programmé (le lendemain) au lieu d'être envoyé tout de suite — régression du bug corrigé le 24/09/2026");
        neria_assert($sentRow('birthday', 998849) === 1, "L'e-mail d'anniversaire n'a pas été envoyé immédiatement (aucune ligne neria_behavioral_sent)");

        $m->invoke($cron, 'reorder_reminder', $row, ['{product_name}' => 'Regtest', '{shop_url}' => 'https://example.test'], 998850);
        neria_assert($queued('reorder_reminder', 998850) === 1, "Témoin : un modèle NON lié à une date doit rester programmé à l'heure préférée (fenêtre d'achat)");
    } finally {
        $ctx->shop = $origShop;
        if ($orig === false || $orig === null) { Configuration::deleteByName('NERIA_PURCHASE_WINDOW_ENABLED'); } else { Configuration::updateGlobalValue('NERIA_PURCHASE_WINDOW_ENABLED', $orig); }
        foreach ($orderIds as $id) { $db->execute("DELETE FROM {$p}orders WHERE id_order=" . (int) $id); }
        $db->execute("DELETE FROM {$p}neria_queue WHERE id_customer={$idCustomer} AND ref_id IN (998849,998850)");
        $db->execute("DELETE FROM {$p}neria_behavioral_sent WHERE id_customer={$idCustomer} AND ref_id IN (998849,998850)");
    }

    return [
        'pass'    => true,
        'message' => "Avec la fenêtre d'achat et une heure préférée déjà passée, l'e-mail d'anniversaire part immédiatement (plus reporté au lendemain) alors qu'un modèle non daté reste programmé — round 375",
    ];
}
