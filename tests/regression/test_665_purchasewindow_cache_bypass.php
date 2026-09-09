<?php
/**
 * Régression : `PurchaseWindowManager::getPreferredHour()` /
 * `getWindowCoverageCount()` / `getHourDistribution()` appelaient
 * `Db::getRow()`/`getValue()`/`executeS()` sans jamais passer
 * `$use_cache = false` — même famille de bug systémique que le balayage
 * exhaustif round 223 (`WaitlistManager::isRegistered()` notamment, voir
 * test_463) : le cache SQL de PrestaShop n'est PAS invalidé quand une
 * nouvelle commande est insérée dans `ps_orders`. Un client passant sa 2e
 * commande dans le même créneau horaire de 2h (atteignant enfin
 * `MINIMUM_ORDERS`) pouvait continuer à recevoir `null` d'un appel
 * ultérieur à `getPreferredHour()` avec les mêmes `id_customer`/`id_shop`,
 * si le résultat "insuffisant" d'un appel antérieur était resté en cache —
 * le privant indéfiniment de la fenêtre d'achat pourtant désormais
 * détectable.
 *
 * Bug identifié le 09/09/2026 (round 330, audit PurchaseWindowManager).
 *
 * Corrigé le 09/09/2026 (round 330) : `$use_cache = false` sur les 3
 * méthodes (`getRow(sql, false)`, `getValue(sql, false)`,
 * `executeS(sql, true, false)` — attention au piège de signature round 326
 * sur ce 3e appel : le 2e argument positionnel contrôle le MODE TABLEAU,
 * pas le cache).
 *
 * Test structurel (littéral `false`/`use_cache`) + comportemental réel sur
 * le comportement NOMINAL — même limite déjà rencontrée pour le balayage
 * round 223 (test_463) : `Db::$is_cache_enabled` est vide dans cet
 * environnement de dev/test (vérifié directement), donc `$use_cache` n'a
 * ICI aucun effet observable quel que soit son réglage — la staleness du
 * cache ne peut être reproduite QUE sur un environnement de production où
 * le cache SQL PrestaShop est réellement actif. Le test vérifie donc : (1)
 * structurellement que les 3 appels passent bien `$use_cache=false`, (2)
 * comportementalement qu'insérer une 1re commande (insuffisant) puis une
 * 2e dans le même créneau de 2h fait bien passer `getPreferredHour()` de
 * `null` à la bonne heure détectée — garantit que l'ajout du paramètre n'a
 * pas cassé la logique nominale.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PurchaseWindowManager.php');
    neria_assert($src !== false, 'Impossible de lire PurchaseWindowManager.php');

    $posPreferred = strpos($src, 'function getPreferredHour(');
    neria_assert($posPreferred !== false, 'getPreferredHour() introuvable — jeu de test invalide');
    $bodyPreferred = substr($src, $posPreferred, 2200);
    neria_assert(
        strpos($bodyPreferred, "ORDER BY cnt DESC, h ASC',\n            false\n        );") !== false,
        "getPreferredHour() n'a plus \$use_cache=false sur getRow() — régression du bug corrigé le 09/09/2026 (round 330) : le cache SQL PrestaShop (actif en production) pourrait de nouveau renvoyer un résultat périmé après l'insertion d'une nouvelle commande"
    );

    $posCoverage = strpos($src, 'function getWindowCoverageCount(');
    neria_assert($posCoverage !== false, 'getWindowCoverageCount() introuvable — jeu de test invalide');
    $bodyCoverage = substr($src, $posCoverage, 1300);
    neria_assert(
        strpos($bodyCoverage, "sub',\n            false\n        );") !== false,
        "getWindowCoverageCount() n'a plus \$use_cache=false — régression du bug corrigé le 09/09/2026 (round 330)"
    );

    $posDist = strpos($src, 'function getHourDistribution(');
    neria_assert($posDist !== false, 'getHourDistribution() introuvable — jeu de test invalide');
    $bodyDist = substr($src, $posDist, 900);
    neria_assert(
        strpos($bodyDist, "ORDER BY h ASC',\n            true,\n            false\n        );") !== false,
        "getHourDistribution() n'a plus executeS(sql, true, false) — régression du bug corrigé le 09/09/2026 (round 330) : le 2e argument positionnel true (mode tableau) doit être explicite pour pouvoir passer false en 3e position (bypass cache), piège de signature round 326"
    );

    require_once _PS_MODULE_DIR_ . 'neria/src/PurchaseWindowManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;

    // id_customer synthétique hors plage réelle, distinct des autres tests
    // (round 223 utilise 999960/999961).
    $idCustomer = 999962;

    $baseRow = $db->getRow("SELECT * FROM {$prefix}orders");
    neria_assert($baseRow !== false, "jeu de test invalide : aucune commande existante pour cloner la structure");

    // Nettoyage par id_customer synthétique UNIQUEMENT (pas par référence :
    // `reference` est varchar(9), toute chaîne plus longue est TRONQUÉE
    // silencieusement par MySQL — piège déjà documenté round 327 pour
    // GoldenHourManager — un filtre LIKE sur le préfixe complet ne
    // matcherait alors plus la valeur réellement stockée).
    $cleanup = function () use ($db, $prefix, $idCustomer): void {
        $db->execute("DELETE FROM {$prefix}orders WHERE id_customer = {$idCustomer}");
    };
    $cleanup();

    $insertOrder = function (string $ref, string $dateAdd) use ($db, $prefix, $baseRow, $idCustomer, $idShop): void {
        $db->execute(
            "INSERT INTO {$prefix}orders
                (reference, id_shop_group, id_shop, id_carrier, id_lang, id_customer, id_cart, id_currency,
                 id_address_delivery, id_address_invoice, current_state, secure_key, payment, conversion_rate,
                 module, total_paid, total_paid_tax_incl, total_paid_tax_excl, total_paid_real,
                 total_products, total_products_wt, valid, date_add, date_upd)
             VALUES ('" . pSQL($ref) . "', " . (int) $baseRow['id_shop_group'] . ", {$idShop}, " . (int) $baseRow['id_carrier'] . ",
                 " . (int) $baseRow['id_lang'] . ", {$idCustomer}, " . (int) $baseRow['id_cart'] . ", " . (int) $baseRow['id_currency'] . ",
                 " . (int) $baseRow['id_address_delivery'] . ", " . (int) $baseRow['id_address_invoice'] . ", " . (int) $baseRow['current_state'] . ",
                 '" . pSQL($baseRow['secure_key']) . "', 'REGTEST665', 1.000000,
                 'ps_checkpayment', 10.00, 10.00, 10.00, 10.00, 10.00, 10.00,
                 1, '" . pSQL($dateAdd) . "', '" . pSQL($dateAdd) . "')"
        );
    };

    try {
        // Créneau [10h-12h[ : 10h15 puis 11h45 (même jour du test, peu importe
        // la date calendaire — seule l'HEURE compte pour FLOOR(HOUR/2)*2).
        $today = (new DateTime())->format('Y-m-d');

        $insertOrder('RT665A', "{$today} 10:15:00");

        $mgr = new PurchaseWindowManager();
        $hourBefore = $mgr->getPreferredHour($idCustomer, $idShop);
        neria_assert(
            $hourBefore === null,
            "jeu de test invalide : getPreferredHour() détecte déjà une fenêtre avec une seule commande (obtenu " . var_export($hourBefore, true) . ")"
        );

        // 2e commande dans le MÊME créneau — atteint désormais MINIMUM_ORDERS (2).
        $insertOrder('RT665B', "{$today} 11:45:00");

        $hourAfter = $mgr->getPreferredHour($idCustomer, $idShop);
        neria_assert(
            $hourAfter === 11,
            "getPreferredHour() renvoie " . var_export($hourAfter, true) . " au lieu de 11 (milieu du créneau [10h-12h[) après la 2e commande — comportement nominal cassé par l'ajout de \$use_cache=false"
        );

        // getWindowCoverageCount()/getHourDistribution() : vérification de
        // non-régression basique (pas d'exception, valeurs cohérentes).
        $coverage = $mgr->getWindowCoverageCount($idShop);
        neria_assert(is_int($coverage) && $coverage >= 1, "getWindowCoverageCount() ne détecte plus le client de test après ajout de \$use_cache=false");

        $dist = $mgr->getHourDistribution($idShop);
        neria_assert(is_array($dist) && count($dist) === 24, "getHourDistribution() ne renvoie plus un histogramme de 24 heures après ajout de \$use_cache=false");

        return [
            'pass'    => true,
            'message' => "PurchaseWindowManager::getPreferredHour()/getWindowCoverageCount()/getHourDistribution() bypassent désormais le cache SQL (\$use_cache=false), comportement nominal préservé — bug corrigé le 09/09/2026 (round 330)",
        ];
    } finally {
        $cleanup();
    }
}
