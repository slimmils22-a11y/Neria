<?php
/**
 * Régression : PurchaseWindowManager::getPreferredHour() regroupe les
 * commandes par créneau de 2h (round antérieur, cf. commentaire du
 * fichier) mais retournait la BORNE BASSE du créneau gagnant — traitée
 * comme heure cible EXACTE par QueueManager::nextOccurrence()
 * (sprintf('%02d:00:00', $hour), sans tolérance). Un client commandant
 * régulièrement vers 10h58/11h05/11h34 (créneau [10h-12h[) se voyait
 * programmer ses emails à 10h00 PILE, jusqu'à ~2h avant son heure d'achat
 * réelle — contredisant l'intention documentée ("programmer l'envoi dans
 * la bonne fenêtre du client").
 *
 * Corrigé le 09/09/2026 (round 327) : +1 pour retourner le MILIEU du
 * créneau de 2h.
 *
 * Test comportemental réel : 3 vraies commandes insérées à 10h58, 11h05,
 * 11h34 (heures fixes, indépendantes de NOW() pour un test reproductible)
 * pour un client de test, vérifie que getPreferredHour() retourne 11
 * (milieu du créneau [10h-12h[), pas 10 (ancienne borne basse buguée).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PurchaseWindowManager.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    $hours = ['10:58:00', '11:05:00', '11:34:00'];
    $orderIds = [];

    try {
        foreach ($hours as $h) {
            $date = date('Y-m-d') . ' ' . $h;
            $db->execute(
                "INSERT INTO {$prefix}orders
                    (id_shop, id_shop_group, id_customer, id_carrier, id_lang, id_currency, id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real, total_products, total_products_wt, valid, date_add, date_upd)
                 VALUES ({$idShop},1,{$idCustomer},1,1,1,0,0,1,'x','regtest653',1,10,10,10,0,10,10,1,'{$date}','{$date}')"
            );
            $orderIds[] = (int) $db->Insert_ID();
        }

        $mgr = new PurchaseWindowManager();
        $preferredHour = $mgr->getPreferredHour($idCustomer, $idShop);

        neria_assert(
            $preferredHour !== null,
            "getPreferredHour() n'a détecté aucune fenêtre malgré 3 commandes dans le même créneau de 2h — jeu de test invalide"
        );
        neria_assert(
            $preferredHour === 11,
            "getPreferredHour() retourne {$preferredHour} au lieu de 11 (milieu du créneau [10h-12h[) — régression du bug corrigé le 09/09/2026 (round 327) : l'email serait de nouveau programmé jusqu'à ~2h avant l'heure d'achat réelle du client"
        );
    } finally {
        if (!empty($orderIds)) {
            $db->execute("DELETE FROM {$prefix}orders WHERE id_order IN (" . implode(',', $orderIds) . ")");
        }
    }

    return [
        'pass'    => true,
        'message' => "PurchaseWindowManager::getPreferredHour() retourne bien le milieu du créneau de 2h (11), pas sa borne basse (10) — bug corrigé le 09/09/2026 (round 327)",
    ];
}
