<?php
/**
 * Régression : sendShippedDelayAlerts() (order_shipped_delay, « expédié depuis
 * 7 jours sans livraison ») excluait via `order_state.delivery = 1` les
 * commandes déjà livrées. Or ce drapeau PrestaShop signifie « bon de livraison
 * imprimable » et vaut 1 pour Préparation en cours, Expédié ET Livré : toute
 * commande passée par la préparation était exclue — la relance ne partait
 * JAMAIS.
 *
 * Bug trouvé le 23/09/2026 (round 366, campagne P4 sur ps-test : commande
 * expédiée depuis 8 jours jamais relancée).
 *
 * Corrigé : exclusion via l'état PS_OS_DELIVERED (ou annulé).
 *
 * Test comportemental réel : 2 commandes jetables passées par Préparation puis
 * Expédié il y a 8 jours ; la 2e passe ensuite à Livré. Après sendShippedDelayAlerts(),
 * la 1re a sa ligne neria_behavioral_sent (order_shipped_delay), la 2e non.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $p  = neria_test_prefix();
    $module = neria_test_module();
    $idCustomer = neria_test_any_customer_id();
    neria_assert($idCustomer > 0, 'Aucun client actif disponible');
    $idShop = (int) $db->getValue("SELECT id_shop FROM {$p}customer WHERE id_customer={$idCustomer}") ?: 1;
    $custLang = (int) $db->getValue("SELECT id_lang FROM {$p}customer WHERE id_customer={$idCustomer}") ?: 1;
    $delivered = (int) Configuration::get('PS_OS_DELIVERED') ?: 5;
    $ids = [];
    $ctx = Context::getContext();
    $origShop = $ctx->shop;
    $origFlag = Configuration::getGlobalValue('NERIA_SHIPPED_DELAY_ENABLED');
    Configuration::updateGlobalValue('NERIA_SHIPPED_DELAY_ENABLED', 1);
    try {
        $ctx->shop = new Shop($idShop);
        $old = date('Y-m-d H:i:s', strtotime('-8 days'));
        foreach ([false, true] as $isDelivered) {
            $db->execute("INSERT INTO {$p}orders (reference,id_shop_group,id_shop,id_carrier,id_lang,id_customer,id_cart,id_currency,id_address_delivery,id_address_invoice,current_state,secure_key,payment,module,valid,date_add,date_upd,total_paid,total_paid_tax_incl,total_paid_tax_excl,total_paid_real,total_products,total_products_wt,conversion_rate)
                          VALUES ('T840" . ($isDelivered ? 'D' : 'S') . "',1,{$idShop},1,{$custLang},{$idCustomer},0,1,0,0,4,'x','t','ps_wirepayment',1,'{$old}','{$old}',10,10,10,10,10,10,1)");
            $idOrder = (int) $db->Insert_ID();
            $ids[] = $idOrder;
            $db->execute("INSERT INTO {$p}order_history (id_employee,id_order,id_order_state,date_add) VALUES (0,{$idOrder},3,'{$old}'),(0,{$idOrder},4,'{$old}')");
            if ($isDelivered) {
                $db->execute("INSERT INTO {$p}order_history (id_employee,id_order,id_order_state,date_add) VALUES (0,{$idOrder},{$delivered},'{$old}')");
            }
        }
        [$idShipped, $idDelivered] = $ids;

        $m = new ReflectionMethod(BehavioralCronManager::class, 'sendShippedDelayAlerts');
        $m->setAccessible(true);
        $m->invoke(new BehavioralCronManager($module));

        // Envoyé tout de suite OU programmé dans la file (fenêtre d'achat
        // individuelle activée : l'heure d'envoi dépend du moment du test).
        $sent = static function (int $idOrder) use ($db, $p): int {
            return (int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_behavioral_sent WHERE template='order_shipped_delay' AND ref_id={$idOrder}", false)
                + (int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_queue WHERE template='order_shipped_delay' AND ref_id={$idOrder}", false);
        };
        neria_assert(
            $sent($idShipped) === 1,
            "La commande expédiée depuis 8 jours et jamais livrée n'a pas reçu order_shipped_delay — régression du bug corrigé le 23/09/2026 (round 366) : order_state.delivery=1 (bon de livraison) exclurait toute commande passée par la préparation"
        );
        neria_assert(
            $sent($idDelivered) === 0,
            "La commande livrée a reçu à tort order_shipped_delay (l'état Livré doit exclure la relance)"
        );

        return [
            'pass'    => true,
            'message' => "order_shipped_delay part pour une commande expédiée depuis 8 jours non livrée (même passée par Préparation), et pas pour une commande livrée — bug corrigé le 23/09/2026 (round 366)",
        ];
    } finally {
        $ctx->shop = $origShop;
        if ($origFlag === false || $origFlag === null) {
            Configuration::deleteByName('NERIA_SHIPPED_DELAY_ENABLED');
        } else {
            Configuration::updateGlobalValue('NERIA_SHIPPED_DELAY_ENABLED', $origFlag);
        }
        foreach ($ids as $idOrder) {
            $db->execute("DELETE FROM {$p}neria_behavioral_sent WHERE template='order_shipped_delay' AND ref_id={$idOrder}");
            $db->execute("DELETE FROM {$p}neria_queue WHERE template='order_shipped_delay' AND ref_id={$idOrder}");
            $db->execute("DELETE FROM {$p}order_history WHERE id_order={$idOrder}");
            $db->execute("DELETE FROM {$p}orders WHERE id_order={$idOrder}");
        }
    }
}
