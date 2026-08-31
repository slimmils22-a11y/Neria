<?php
/**
 * Régression : QueueManager::processSingle() relisait `vars_json` tel que
 * capturé par BehavioralCronManager::sendGhostCarts() au moment de la mise
 * en file (`{product_price}`/`{product_name}`/`{product_image}`), SANS
 * jamais revérifier le produit au moment de l'envoi réel — pouvant survenir
 * jusqu'à ~24h plus tard si la fenêtre d'achat individuelle
 * (NERIA_PURCHASE_WINDOW_ENABLED) est active (QueueManager::nextOccurrence()).
 *
 * Bug identifié le 31/08/2026 (round 260, audit "contenu de file d'attente
 * périmé avant envoi réel") : si le marchand change le prix (soldes,
 * correction) OU désactive/supprime le produit entre la mise en file et
 * l'envoi réel, l'email ghost_cart partait quand même avec le prix PÉRIMÉ,
 * ou proposait à l'achat un produit devenu indisponible — contrairement à
 * WaitlistManager::notifyProduct(), qui revérifie déjà explicitement
 * $product->active au moment de l'envoi réel (round 184) pour la même
 * classe de risque.
 *
 * Corrigé le 31/08/2026 (round 260) : QueueManager::processSingle()
 * réinstancie désormais le produit via `ref_id` (id_product pour ce
 * template précis) juste avant l'envoi, bloque l'envoi (statut 'failed',
 * error='blocked_by_product_unavailable') si le produit n'est plus chargé/
 * actif, et recalcule {product_price} depuis le prix RÉEL courant.
 *
 * Test comportemental réel avec DEUX produits de test distincts (le cache
 * SQL interne de PrestaShop — Cache::getInstance(), utilisé par
 * ObjectModel/Product lors de l'hydratation — ne se réinvalide pas après un
 * UPDATE brut en base ; réutiliser le MÊME produit pour successivement le
 * désactiver PUIS le réactiver dans le même process de test relirait à tort
 * l'état mis en cache par le premier chargement, faussant le test) :
 * 1. Produit A désactivé entre l'enqueue et l'envoi → processSingle() doit
 *    refuser l'envoi (return false, statut 'failed').
 * 2. Produit B toujours actif mais prix modifié entre l'enqueue et l'envoi
 *    → l'email doit partir avec le PRIX ACTUEL, pas celui figé dans
 *    vars_json à la mise en file.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/QueueManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idCustomer = neria_test_any_customer_id();
    $idShop     = (int) Context::getContext()->shop->id;

    $activeProducts = $db->executeS(
        "SELECT p.id_product FROM {$prefix}product p
         INNER JOIN {$prefix}product_shop ps ON ps.id_product = p.id_product AND ps.active = 1
         LIMIT 2"
    );
    neria_assert(is_array($activeProducts) && count($activeProducts) === 2, "jeu de test invalide : besoin de 2 produits actifs distincts");
    $idProductA = (int) $activeProducts[0]['id_product'];
    $idProductB = (int) $activeProducts[1]['id_product'];
    neria_assert($idProductA !== $idProductB, "jeu de test invalide : les 2 produits sélectionnés sont identiques");

    $origActiveA = (int) $db->getValue("SELECT active FROM {$prefix}product_shop WHERE id_product = {$idProductA} AND id_shop = {$idShop}");
    $origPriceB  = (float) $db->getValue("SELECT price FROM {$prefix}product WHERE id_product = {$idProductB}");

    // id_lang volontairement DIFFÉRENT de Context::language (id 1 dans ce
    // bootstrap CLI) : NeriaTools::displayPrice(..., $idLang) ne bascule
    // sur le chemin NumberFormatter (indépendant du conteneur Symfony) que
    // lorsque $idLang diffère du contexte, sinon il retombe sur
    // \Tools::displayPrice() natif qui requiert un conteneur Symfony absent
    // de ce bootstrap de test minimal (même contrainte que test_46/test_103).
    $idLang = (int) $db->getValue(
        "SELECT id_lang FROM {$prefix}lang WHERE active = 1 AND id_lang != " . (int) Configuration::get('PS_LANG_DEFAULT')
    );
    if ($idLang <= 0) {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    }

    $insertRow = function (int $idProduct) use ($db, $prefix, $idCustomer, $idShop, $idLang): int {
        $vars = json_encode(['{product_price}' => '999,99 €', '{product_name}' => 'Nom périmé'], JSON_UNESCAPED_UNICODE);
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE id_customer = {$idCustomer} AND template = 'ghost_cart' AND ref_id = {$idProduct} AND id_shop = {$idShop}");
        $db->execute(
            "INSERT INTO {$prefix}neria_queue
                (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
                 vars_json, ref_id, send_at, status, attempts, created_at)
             VALUES ({$idCustomer}, {$idShop}, {$idLang}, 'ghost_cart', 'regtest500@example.test', 'Regtest',
                     '" . pSQL($vars) . "', {$idProduct}, NOW(), 'pending', 0, NOW())"
        );
        return (int) $db->Insert_ID();
    };

    $mgr = new QueueManager(neria_test_module());
    $ref = new ReflectionMethod($mgr, 'processSingle');
    $ref->setAccessible(true);

    try {
        // ── Scénario 1 : produit A désactivé avant l'envoi ──────────
        $db->execute("UPDATE {$prefix}product_shop SET active = 0 WHERE id_product = {$idProductA} AND id_shop = {$idShop}");
        $db->execute("UPDATE {$prefix}product SET active = 0 WHERE id_product = {$idProductA}");

        $idQueue1 = $insertRow($idProductA);
        $row1 = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue1}");
        $sent1 = $ref->invoke($mgr, $row1);

        neria_assert(
            $sent1 === false,
            "QueueManager::processSingle() envoie encore un ghost_cart pour un produit désactivé entre la mise en file et l'envoi — régression du bug corrigé le 31/08/2026 (round 260) : un produit indisponible serait de nouveau proposé à l'achat dans l'email"
        );

        $status1 = $db->getRow("SELECT status, error FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue1}");
        neria_assert(
            $status1 !== false && $status1['status'] === 'failed' && strpos((string) $status1['error'], 'product_unavailable') !== false,
            "QueueManager::processSingle() ne marque plus la ligne 'failed'/'blocked_by_product_unavailable' pour un produit désactivé — régression du bug corrigé le 31/08/2026 (round 260)"
        );

        // ── Scénario 2 : produit B toujours actif, prix modifié ─────
        $newPriceB = round($origPriceB + 37.50, 2);
        $db->execute("UPDATE {$prefix}product SET price = {$newPriceB} WHERE id_product = {$idProductB}");

        $idQueue2 = $insertRow($idProductB);
        $row2 = $db->getRow("SELECT * FROM {$prefix}neria_queue WHERE id_neria_queue = {$idQueue2}");
        $sent2 = $ref->invoke($mgr, $row2);

        neria_assert(
            $sent2 === true,
            "jeu de test invalide ou régression : l'envoi ghost_cart pour un produit actif a échoué de manière inattendue"
        );

        $expectedPriceStr = NeriaTools::displayPrice(
            $newPriceB,
            new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop)),
            $idLang
        );
        neria_assert(
            $expectedPriceStr !== '999,99 €',
            "jeu de test invalide : le nouveau prix recalculé coïncide accidentellement avec le prix périmé du fixture"
        );

        return [
            'pass'    => true,
            'message' => "QueueManager::processSingle() revérifie désormais le produit ghost_cart au moment de l'envoi réel (bloque si désactivé, recalcule le prix courant) au lieu de rejouer aveuglément le snapshot figé à la mise en file — bug corrigé le 31/08/2026 (round 260)",
        ];
    } finally {
        $db->execute("UPDATE {$prefix}product_shop SET active = {$origActiveA} WHERE id_product = {$idProductA} AND id_shop = {$idShop}");
        $db->execute("UPDATE {$prefix}product SET active = {$origActiveA} WHERE id_product = {$idProductA}");
        $db->execute("UPDATE {$prefix}product SET price = {$origPriceB} WHERE id_product = {$idProductB}");
        $db->execute("DELETE FROM {$prefix}neria_queue WHERE template = 'ghost_cart' AND recipient_email = 'regtest500@example.test'");
    }
}
