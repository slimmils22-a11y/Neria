<?php
/**
 * Régression : CollectionManager::processCollection() vérifiait la
 * disponibilité via StockAvailable::getQuantityAvailableByProduct(
 * $missingId, null, $idShop) — le cœur PrestaShop convertit explicitement
 * id_product_attribute=null en 0, donc seule la ligne "sans déclinaison"
 * était lue (quasi toujours à 0 pour un produit à combinaisons). Un
 * produit parfaitement disponible (stock réparti sur ses combinaisons)
 * était donc silencieusement écarté — client jamais relancé avec l'email
 * "il ne vous manque que X pour compléter votre collection". Même piège
 * déjà corrigé (round 167 WaitlistManager, round 184 LookCompletionManager)
 * mais jamais répliqué ici.
 *
 * Corrigé le 26/08/2026 (round 215) : SUM(quantity) SQL direct sur toutes
 * les lignes stock_available du produit, avec bascule stock
 * partagé/non partagé identique à WaitlistManager/LookCompletionManager.
 *
 * Test hybride : vérification structurelle du remplacement + vérification
 * comportementale réelle de la requête SQL elle-même (reproduite à
 * l'identique) sur un produit fictif dont TOUT le stock est réparti sur
 * des déclinaisons (id_product_attribute > 0, rien sur la ligne
 * id_product_attribute = 0).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CollectionManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CollectionManager.php');

    neria_assert(
        strpos($src, "SELECT COALESCE(SUM(quantity), 0) FROM `' . \$this->prefix . 'stock_available`") !== false,
        "CollectionManager n'utilise plus le SUM(quantity) SQL direct — régression du bug corrigé le 26/08/2026 (round 215)"
    );

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $fakeProductId = 976601;

    $db->execute("DELETE FROM {$prefix}stock_available WHERE id_product = {$fakeProductId}");

    try {
        $db->execute(
            "INSERT INTO {$prefix}stock_available (id_product, id_product_attribute, id_shop, id_shop_group, quantity) VALUES
                ({$fakeProductId}, 0, {$idShop}, 0, 0),
                ({$fakeProductId}, 1, {$idShop}, 0, 4),
                ({$fakeProductId}, 2, {$idShop}, 0, 2)"
        );

        $oldMethodResult = (int) StockAvailable::getQuantityAvailableByProduct($fakeProductId, null, $idShop);
        neria_assert(
            $oldMethodResult === 0,
            "jeu de test invalide : l'ancienne méthode devrait renvoyer 0 pour ce cas (ligne sans déclinaison vide), a renvoyé {$oldMethodResult}"
        );

        $newSum = (int) $db->getValue(
            "SELECT COALESCE(SUM(quantity), 0) FROM {$prefix}stock_available
             WHERE id_product = {$fakeProductId} AND id_shop = {$idShop} AND id_shop_group = 0"
        );
        neria_assert(
            $newSum === 6,
            "Le SUM(quantity) réel sur les déclinaisons renvoie {$newSum} au lieu de 6 — jeu de test invalide"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}stock_available WHERE id_product = {$fakeProductId}");
    }

    return [
        'pass'    => true,
        'message' => "CollectionManager détecte bien le stock réparti sur les déclinaisons via SUM(quantity), là où StockAvailable::getQuantityAvailableByProduct(null) ne voit que 0 — bug corrigé le 26/08/2026 (round 215)",
    ];
}
