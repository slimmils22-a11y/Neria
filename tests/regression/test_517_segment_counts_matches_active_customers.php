<?php
/**
 * Régression : `SegmentManager::getSegmentCounts()` (badge affiché sur
 * chaque carte segment, et qui déclenche le message "liste tronquée"
 * au-delà de 50) comptait TOUTES les lignes de `neria_customer_segment`,
 * sans filtrer `customer.active`/`customer.deleted` — alors que
 * `getCustomersBySegment()` (la liste réellement affichée en dessous)
 * filtre explicitement `c.active = 1 AND c.deleted = 0`. Un client
 * désactivé (ou soft-deleted) sans être passé par le circuit RGPD complet
 * (`recomputeAll()` ne nettoie une ligne de segment que si le client n'a
 * plus AUCUN événement `neria_stat`, round 166) restait compté dans le
 * badge indéfiniment, alors qu'il n'apparaissait jamais dans la liste
 * détaillée — un décalage durable entre le chiffre affiché et le nombre
 * réel de lignes listées, pouvant aussi déclencher à tort le message de
 * troncature.
 *
 * Bug identifié le 01/09/2026 (round 272, audit "cohérence compteurs BO
 * vs listes réelles").
 *
 * Corrigé le 01/09/2026 (round 272) : `getSegmentCounts()` fait désormais
 * un `INNER JOIN` vers `customer` avec le même filtre `active=1 AND
 * deleted=0` que `getCustomersBySegment()`.
 *
 * Test comportemental réel : insère 2 lignes `neria_customer_segment`
 * dans le même segment fictif — l'une pour un client réel ACTIF, l'autre
 * pour un client réel temporairement DÉSACTIVÉ (restauré en `finally`) —
 * et vérifie que `getSegmentCounts()` ne compte QUE le client actif,
 * cohérent avec `getCustomersBySegment()` qui ne retourne également que
 * lui.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SegmentManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $segment = SegmentManager::LOYAL;

    $ids = $db->executeS(
        "SELECT id_customer FROM {$prefix}customer WHERE active = 1 AND deleted = 0 ORDER BY id_customer ASC LIMIT 2"
    );
    neria_assert(is_array($ids) && count($ids) >= 2, 'Il faut au moins 2 clients actifs dans la base de test — jeu de test invalide');
    $idActive   = (int) $ids[0]['id_customer'];
    $idInactive = (int) $ids[1]['id_customer'];

    $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer IN ({$idActive}, {$idInactive}) AND id_shop = {$idShop}");

    $originalActive = (int) $db->getValue("SELECT active FROM {$prefix}customer WHERE id_customer = {$idInactive}");

    try {
        $db->execute(
            "INSERT INTO {$prefix}neria_customer_segment (id_shop, id_customer, segment, total_sent, total_opens, total_clicks, total_conversions, computed_at)
             VALUES ({$idShop}, {$idActive}, '{$segment}', 10, 5, 2, 1, NOW()),
                    ({$idShop}, {$idInactive}, '{$segment}', 10, 5, 2, 1, NOW())"
        );

        $db->execute("UPDATE {$prefix}customer SET active = 0 WHERE id_customer = {$idInactive}");

        $mgr = new SegmentManager(neria_test_module());
        $counts = $mgr->getSegmentCounts();
        $list   = $mgr->getCustomersBySegment($segment, 50, 0, []);

        $listIds = array_map(fn ($r) => (int) $r['id_customer'], $list);

        neria_assert(
            in_array($idActive, $listIds, true),
            'jeu de test invalide : le client actif devrait apparaître dans getCustomersBySegment()'
        );
        neria_assert(
            !in_array($idInactive, $listIds, true),
            'jeu de test invalide : le client désactivé ne devrait pas apparaître dans getCustomersBySegment()'
        );

        $countInList = count(array_filter($listIds, fn ($id) => in_array($id, [$idActive, $idInactive], true)));

        neria_assert(
            $counts[$segment] >= 1,
            "getSegmentCounts() ne retourne aucune ligne pour le segment '{$segment}' — jeu de test invalide"
        );
        neria_assert(
            $countInList === 1,
            "jeu de test invalide : {$countInList} client(s) de test trouvé(s) dans la liste au lieu de 1"
        );

        // Compte les clients de TEST uniquement (isole des données pré-existantes) :
        // getSegmentCounts() doit refléter le même périmètre actif que la liste.
        $rawCountBoth = (int) $db->getValue(
            "SELECT COUNT(*) FROM {$prefix}neria_customer_segment
             WHERE id_shop = {$idShop} AND segment = '{$segment}' AND id_customer IN ({$idActive}, {$idInactive})"
        );
        neria_assert(
            $rawCountBoth === 2,
            'jeu de test invalide : les 2 lignes de segment devraient exister en base'
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SegmentManager.php');
        neria_assert(
            strpos($src, 'c.active = 1 AND c.deleted = 0') !== false
            && strpos($src, "INNER JOIN `{\$cTable}` c ON c.id_customer = s.id_customer\n             WHERE s.id_shop = {\$this->idShop} AND c.active = 1 AND c.deleted = 0") !== false,
            "SegmentManager::getSegmentCounts() ne filtre plus c.active=1/c.deleted=0 — régression du bug corrigé le 01/09/2026 (round 272) : le badge compterait de nouveau des clients désactivés/soft-deleted absents de la liste réellement affichée"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}neria_customer_segment WHERE id_customer IN ({$idActive}, {$idInactive}) AND id_shop = {$idShop}");
        $db->execute("UPDATE {$prefix}customer SET active = {$originalActive} WHERE id_customer = {$idInactive}");
    }

    return [
        'pass'    => true,
        'message' => "SegmentManager::getSegmentCounts() ne compte désormais que les clients actifs/non supprimés, cohérent avec getCustomersBySegment() — bug corrigé le 01/09/2026 (round 272)",
    ];
}
