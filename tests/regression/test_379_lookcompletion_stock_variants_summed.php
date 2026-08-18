<?php
/**
 * Régression : LookCompletionManager::buildProductBlocks() vérifiait la
 * disponibilité via StockAvailable::getQuantityAvailableByProduct($pid,
 * null, $idShop) — le cœur PrestaShop convertit explicitement
 * id_product_attribute=null en 0, donc seule la ligne "sans déclinaison"
 * était lue (quasi toujours à 0 pour un produit à combinaisons). Un
 * produit parfaitement disponible (stock réparti sur ses combinaisons)
 * était donc silencieusement écarté des suggestions "Complétez votre
 * look" — même piège déjà corrigé (round 167) dans
 * WaitlistManager::notifyProduct() mais jamais répliqué ici.
 *
 * Corrigé le 18/08/2026 (round 184) : SUM(quantity) SQL direct sur
 * toutes les lignes stock_available du produit, avec bascule stock
 * partagé/non partagé identique à WaitlistManager.
 *
 * Test hybride : vérification structurelle du remplacement +
 * vérification comportementale réelle de la requête SQL elle-même
 * (reproduite à l'identique) sur un produit fictif dont TOUT le stock
 * est réparti sur des déclinaisons (id_product_attribute > 0, rien sur
 * la ligne id_product_attribute = 0) — confirme que le SUM trouve bien
 * le stock que l'ancienne méthode aurait manqué.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($src !== false, 'Impossible de lire src/LookCompletionManager.php');

    // Note : pas d'assertion négative "ne contient plus getQuantityAvailableByProduct"
    // sur le fichier entier — le commentaire round 184 lui-même mentionne
    // cette méthode en toutes lettres pour expliquer la régression évitée,
    // ce qui ferait échouer à tort une telle assertion (piège déjà connu,
    // cf. test_366).
    neria_assert(
        strpos($src, "SELECT COALESCE(SUM(quantity), 0) FROM `' . \$this->prefix . 'stock_available`") !== false,
        "LookCompletionManager n'utilise plus le SUM(quantity) SQL direct — régression du bug corrigé le 18/08/2026 (round 184)"
    );

    // Vérification comportementale réelle de la requête elle-même.
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = (int) Context::getContext()->shop->id;
    $fakeProductId = 976600;

    $db->execute("DELETE FROM {$prefix}stock_available WHERE id_product = {$fakeProductId}");

    try {
        // Ligne "sans déclinaison" à 0 (ce que l'ancienne méthode aurait lu),
        // + 2 déclinaisons avec du stock réel.
        $db->execute(
            "INSERT INTO {$prefix}stock_available (id_product, id_product_attribute, id_shop, id_shop_group, quantity) VALUES
                ({$fakeProductId}, 0, {$idShop}, 0, 0),
                ({$fakeProductId}, 1, {$idShop}, 0, 5),
                ({$fakeProductId}, 2, {$idShop}, 0, 3)"
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
            $newSum === 8,
            "Le SUM(quantity) réel sur les déclinaisons renvoie {$newSum} au lieu de 8 — jeu de test invalide"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}stock_available WHERE id_product = {$fakeProductId}");
    }

    return [
        'pass'    => true,
        'message' => "LookCompletionManager détecte bien le stock réparti sur les déclinaisons via SUM(quantity), là où StockAvailable::getQuantityAvailableByProduct(null) ne voit que 0 — bug corrigé le 18/08/2026 (round 184)",
    ];
}
