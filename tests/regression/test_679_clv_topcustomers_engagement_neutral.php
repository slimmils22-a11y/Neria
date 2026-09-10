<?php
/**
 * Régression : `ClvManager::getTopCustomers()` (classement batch Top CLV)
 * recalculait `$engagementRate` en dur (lignes 413-415) au lieu de
 * réutiliser `getEngagementRate()` — pour un client sans aucun email
 * envoyé sur les 30 derniers jours (`sent=0`), le calcul batch renvoyait
 * `0.0` alors que `getEngagementRate()` (chemin fiche client individuelle)
 * renvoie `ENGAGEMENT_MEDIUM` depuis le round 328. Deux vues du même
 * module affichaient donc un multiplicateur d'engagement contradictoire
 * pour le même client sur la même période : `engagement_label='low'`
 * (pénalité -15%) dans le Top 20, `'medium'` (neutre) sur sa fiche —
 * pouvant l'exclure à tort du classement.
 *
 * Bug identifié le 10/09/2026 (round 334, audit ChurnScoreManager/
 * ClvManager).
 *
 * Corrigé le 10/09/2026 (round 334) : le calcul batch retombe désormais
 * lui aussi sur `self::ENGAGEMENT_MEDIUM` pour `sent=0`, symétrique à
 * `getEngagementRate()`.
 *
 * Test comportemental réel : garantit `sent=0` pour un client réel ayant
 * au moins une commande valide (condition d'inclusion dans le pool de
 * `getTopCustomers()`), appelle `getTopCustomers()`, retrouve ce client
 * dans le résultat et vérifie que `engagement_label` vaut `'medium'`
 * (pas `'low'`) — cohérent avec `getEngagementRate()` appelée directement
 * sur ce même client.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ClvManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    // Un client ayant réellement au moins une commande valide sur cette
    // boutique — condition d'inclusion dans le pool de getTopCustomers().
    $orderRow = $db->getRow(
        "SELECT o.id_customer FROM {$prefix}orders o
         INNER JOIN {$prefix}customer c ON c.id_customer = o.id_customer
         WHERE o.id_shop = {$idShop} AND o.valid = 1 AND c.deleted = 0"
    );
    neria_assert($orderRow !== false, 'jeu de test invalide : aucune commande valide disponible en base de test');
    $idCustomer = (int) $orderRow['id_customer'];

    // Garantit sent=0 : aucune ligne neria_stat récente (30j) pour ce client.
    $db->execute(
        "DELETE FROM {$prefix}neria_stat WHERE id_customer = {$idCustomer} AND id_shop = {$idShop} AND date_add >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );

    $mgr = new ClvManager(neria_test_module());

    // Le Top 20 réel ne contient pas forcément ce client précis si la
    // boutique de test a plus de 20 clients à CA plus élevé — on demande
    // donc un $limit large pour garantir sa présence dans le résultat,
    // sans changer le comportement testé (la boucle de calcul s'applique
    // à TOUS les candidats du pool, pas seulement au top affiché).
    $top = $mgr->getTopCustomers(500);

    $found = null;
    foreach ($top as $row) {
        if ((int) $row['id_customer'] === $idCustomer) {
            $found = $row;
            break;
        }
    }
    neria_assert($found !== null, "Le client de test (id_customer={$idCustomer}) n'apparaît pas dans getTopCustomers() — jeu de test invalide");

    neria_assert(
        $found['engagement_label'] === 'medium',
        "getTopCustomers() renvoie engagement_label='{$found['engagement_label']}' pour un client sans aucun email envoyé (sent=0), attendu 'medium' — régression du bug corrigé le 10/09/2026 (round 334) : une pénalité -15% (engagement 'low') serait de nouveau infligée à tort dans le classement batch, en contradiction avec la fiche individuelle du même client (getEngagementRate(), déjà neutre depuis le round 328)"
    );

    // Contre-vérification de cohérence : getEngagementRate() (chemin
    // individuel) doit produire exactement le même label pour ce client.
    $ref = new ReflectionMethod(ClvManager::class, 'getEngagementRate');
    $ref->setAccessible(true);
    $individualRate = $ref->invoke($mgr, $idCustomer);
    $refConst = new ReflectionClassConstant(ClvManager::class, 'ENGAGEMENT_MEDIUM');
    neria_assert(
        abs($individualRate - $refConst->getValue()) < 0.0001,
        "jeu de test invalide : getEngagementRate() individuel ne renvoie pas ENGAGEMENT_MEDIUM pour ce client — incohérence de jeu de données"
    );

    return [
        'pass'    => true,
        'message' => "ClvManager::getTopCustomers() renvoie désormais un engagement_label neutre ('medium') pour un client sans aucun email envoyé, cohérent avec getEngagementRate() (fiche individuelle) — bug corrigé le 10/09/2026 (round 334)",
    ];
}
