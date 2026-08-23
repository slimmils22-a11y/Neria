<?php
/**
 * Régression : UpsellManager::recordClick($idUpsell) ne scopait QUE par
 * id_upsell (clé auto-incrémentée séquentielle), jamais par le client
 * propriétaire du token de tracking ayant déclenché l'appel (track.php).
 *
 * Bug réel identifié le 23/08/2026 (round 187) : n'importe quel destinataire
 * en possession d'UN token de tracking valide pour SA PROPRE adresse pouvait
 * forger track.php?e=click&t=<son token>&url=...?neria_ur=N en faisant varier
 * N pour marquer clicked_at sur les lignes ps_neria_upsell d'AUTRES clients —
 * faussant l'attribution de revenu upsell (checkConversions() attribue
 * ensuite tout achat du client CIBLÉ dans les 7 jours à la suggestion
 * falsifiée) et bloquant définitivement le vrai clic futur de ce client
 * (clause clicked_at IS NULL).
 *
 * Corrigé le 23/08/2026 (round 187) : recordClick() prend désormais un second
 * paramètre $idCustomer (déduit du token déjà validé par l'appelant) et
 * l'ajoute à la clause WHERE.
 *
 * Test comportemental réel : deux lignes upsell, appartenant à deux clients
 * différents. Appeler recordClick() avec l'id_upsell du client B mais
 * l'id_customer du client A (usurpation) ne doit RIEN modifier.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();

    $idCustomerA = 999901;
    $idCustomerB = 999902;

    $db->execute("DELETE FROM {$prefix}neria_upsell WHERE id_customer IN ({$idCustomerA}, {$idCustomerB})");

    $db->execute(
        "INSERT INTO {$prefix}neria_upsell (id_customer, id_shop, id_order_source, id_product_upsell, product_name, tier, reason, sent_at, clicked_at)
         VALUES ({$idCustomerB}, 1, 0, 1, 'Produit test', 'bestseller', 'test', NOW(), NULL)"
    );
    $idUpsellB = (int) $db->Insert_ID();

    try {
        neria_assert($idUpsellB > 0, 'jeu de test invalide : INSERT échoué');

        $mgr = new UpsellManager(neria_test_module());

        // Usurpation : id_upsell appartient au client B, mais l'appelant
        // prétend agir avec l'id_customer du client A (token du client A).
        $mgr->recordClick($idUpsellB, $idCustomerA);

        $clickedAt = $db->getValue("SELECT clicked_at FROM {$prefix}neria_upsell WHERE id_upsell = {$idUpsellB}");
        neria_assert(
            $clickedAt === null || $clickedAt === '0000-00-00 00:00:00',
            "recordClick() a marqué clicked_at sur la ligne upsell du client B ({$idCustomerB}) alors que l'appel prétendait agir au nom du client A ({$idCustomerA}) — régression de l'IDOR corrigé le 23/08/2026 (round 187)"
        );

        // Le vrai propriétaire (client B) doit toujours pouvoir enregistrer son propre clic.
        $mgr->recordClick($idUpsellB, $idCustomerB);
        $clickedAt2 = $db->getValue("SELECT clicked_at FROM {$prefix}neria_upsell WHERE id_upsell = {$idUpsellB}");
        neria_assert(
            $clickedAt2 !== null && $clickedAt2 !== '0000-00-00 00:00:00',
            "recordClick() n'enregistre plus le clic légitime du véritable propriétaire de la ligne upsell — faux positif du correctif round 187"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_upsell WHERE id_customer IN ({$idCustomerA}, {$idCustomerB})");
    }

    return [
        'pass'    => true,
        'message' => "UpsellManager::recordClick() rejette bien un id_upsell n'appartenant pas à l'id_customer appelant — bug corrigé le 23/08/2026 (round 187)",
    ];
}
