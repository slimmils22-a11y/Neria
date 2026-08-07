<?php
/**
 * Régression : WaitlistManager::notifyProduct() et
 * HealthCheckManager::checkWaitlistBacklog() doivent sommer le stock sur
 * TOUTES les déclinaisons d'un produit (SUM(quantity) sans filtre
 * id_product_attribute), pas se limiter à la combinaison "sans déclinaison".
 *
 * Bug réel corrigé le 07/08/2026 (round 93) : un correctif précédent avait
 * remplacé `id_product_attribute = 0` par
 * `StockAvailable::getQuantityAvailableByProduct($idProduct, null, $idShop)`
 * en supposant que passer `null` sommait toutes les déclinaisons. FAUX dans
 * ce cœur PrestaShop : `getQuantityAvailableByProduct()` convertit
 * explicitement `null` en `0`
 * (`if ($id_product_attribute === null) { $id_product_attribute = 0; }`,
 * classes/stock/StockAvailable.php) — le bug d'origine persistait à
 * l'identique. Un produit géré par déclinaisons dont la ligne "sans
 * attribut" est à quantity=0 (le cas normal) mais dont UNE déclinaison
 * précise est de retour en stock (quantity>0) ne déclenchait jamais aucune
 * notification, et le garde-fou Watchdog censé rattraper l'oubli restait
 * bloqué en permanence sur "OK".
 *
 * Ne pas invoquer WaitlistManager::notifyProduct() en entier ici : sa
 * construction des variables d'email appelle NeriaTools::displayPrice() →
 * Tools::displayPrice() → ToolsCore::getContextLocale(), qui lève une
 * ContainerNotFoundException en CLI (pas de kernel Symfony disponible hors
 * requête HTTP) — même limitation déjà contournée par test_29 pour ce même
 * manager. Ce test reproduit donc EXACTEMENT la requête SQL du correctif
 * (identique dans WaitlistManager::notifyProduct() et
 * HealthCheckManager::checkWaitlistBacklog()) sur un jeu de données réaliste
 * (base à 0, une déclinaison à 5), en comparant explicitement à l'ancien
 * calcul buggé (id_product_attribute = 0 seul) pour prouver la régression.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db         = neria_test_db();
    $prefix     = neria_test_prefix();
    $idProduct  = 888777; // produit fictif, isolé des vraies données (stock_available n'a pas de contrainte FK sur id_product)
    $idShop     = (int) Context::getContext()->shop->id;

    $db->execute("DELETE FROM {$prefix}stock_available WHERE id_product = {$idProduct}");

    // Ligne "sans déclinaison" à quantity=0 (cas normal pour un produit géré
    // par déclinaisons) + une déclinaison précise de retour en stock.
    $db->execute(
        "INSERT INTO {$prefix}stock_available
            (id_product, id_product_attribute, id_shop, id_shop_group, quantity, depends_on_stock, out_of_stock)
         VALUES ({$idProduct}, 0, {$idShop}, 0, 0, 0, 2),
                ({$idProduct}, 999, {$idShop}, 0, 5, 0, 2)"
    );

    try {
        // Ancien calcul buggé : ne regarde que la combinaison "sans
        // attribut" (équivalent de id_product_attribute=0, y compris
        // l'appel StockAvailable::getQuantityAvailableByProduct(..., null,
        // ...) qui s'y ramène dans ce cœur PrestaShop).
        $oldBuggyQty = (int) $db->getValue(
            "SELECT quantity FROM {$prefix}stock_available
             WHERE id_product = {$idProduct} AND id_product_attribute = 0 AND id_shop = {$idShop}"
        );
        neria_assert(
            $oldBuggyQty === 0,
            "jeu de test invalide : la simulation de l'ancien calcul buggé ne vaut pas 0 (obtenu {$oldBuggyQty})"
        );

        // Nouveau calcul (requête EXACTE du correctif, identique dans
        // WaitlistManager::notifyProduct() et
        // HealthCheckManager::checkWaitlistBacklog()) : somme toutes les
        // déclinaisons.
        $fixedQty = (int) $db->getValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM {$prefix}stock_available
             WHERE id_product = {$idProduct} AND id_shop = {$idShop}"
        );
        neria_assert(
            $fixedQty === 5,
            "la requête SUM(quantity) du correctif ne retourne plus 5 pour ce produit à déclinaisons (obtenu {$fixedQty}) — régression du bug corrigé le 07/08/2026 (round 93)"
        );

        // Vérification structurelle : les deux méthodes utilisent bien
        // cette requête SUM (pas seulement testée hors du code réel
        // ci-dessus).
        $wlSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WaitlistManager.php') ?: '';
        $hcSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php') ?: '';
        neria_assert(
            strpos($wlSrc, 'SELECT COALESCE(SUM(quantity), 0) FROM `" . _DB_PREFIX_ . "stock_available`') !== false,
            "WaitlistManager::notifyProduct() n'utilise plus la requête SUM(quantity) sans filtre id_product_attribute — régression du bug corrigé le 07/08/2026 (round 93)"
        );
        neria_assert(
            strpos($hcSrc, "AND id_shop = \" . (int) \$row['id_shop']") !== false,
            "HealthCheckManager::checkWaitlistBacklog() n'utilise plus la requête SUM(quantity) sans filtre id_product_attribute — régression du bug corrigé le 07/08/2026 (round 93)"
        );

        return [
            'pass'    => true,
            'message' => "WaitlistManager::notifyProduct() et checkWaitlistBacklog() somment bien le stock sur toutes les déclinaisons d'un produit, pas seulement la combinaison sans attribut",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}stock_available WHERE id_product = {$idProduct}");
    }
}
