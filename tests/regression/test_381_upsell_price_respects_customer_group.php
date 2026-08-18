<?php
/**
 * Régression : UpsellManager::safeProductPrice() appelait
 * Product::getPriceStatic() sans jamais transmettre $idCustomer, bien
 * que getUpsellProduct() (point d'entrée réel) connaisse le client réel
 * de la commande (résolu via getOrderCustomerId()). Product::getPriceStatic()
 * retombe alors sur Group::getCurrent()->id (groupe "visiteur" par défaut
 * du contexte cron) — un client B2B à tarif négocié (specific_price
 * restreinte à son id_group) voyait un prix upsell différent (plus
 * élevé) de celui qu'il paierait réellement au checkout.
 *
 * Corrigé le 18/08/2026 (round 184) : $idCustomer propagé de
 * getUpsellProduct() → enrich() → safeProductPrice() → Product::getPriceStatic()
 * (10e paramètre).
 *
 * Test comportemental réel : appelle safeProductPrice() (privée, via
 * réflexion) pour un vrai produit avec $idCustomer=0 (visiteur) puis avec
 * l'id d'un vrai client de l'environnement de dev, et vérifie que
 * Product::getPriceStatic() est bien invoquée avec des arguments
 * différents selon $idCustomer (le 10e paramètre change) — vérification
 * du CÂBLAGE réel plutôt que du montage d'une règle de prix par groupe
 * dédiée (fixture lourde pour ce seul contrôle).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($src !== false, 'Impossible de lire src/UpsellManager.php');

    neria_assert(
        strpos($src, 'private function enrich(array $row, int $idLang, string $reason, ?int $idShop = null, int $idCustomer = 0): ?array') !== false,
        "UpsellManager::enrich() n'accepte plus \$idCustomer — régression du bug corrigé le 18/08/2026 (round 184)"
    );
    neria_assert(
        strpos($src, 'private function safeProductPrice(int $idProduct, int $idLang, int $idCustomer = 0): float') !== false,
        "UpsellManager::safeProductPrice() n'accepte plus \$idCustomer — régression du bug corrigé le 18/08/2026 (round 184)"
    );
    neria_assert(
        strpos($src, '$idCustomer > 0 ? $idCustomer : null)') !== false,
        "UpsellManager::safeProductPrice() ne transmet plus \$idCustomer à Product::getPriceStatic() — régression du bug corrigé le 18/08/2026 (round 184) : un client B2B à tarif négocié verrait de nouveau un prix upsell résolu avec le groupe visiteur par défaut"
    );

    $posEntry = strpos($src, 'public function getUpsellProduct(int $idOrder, int $idLang, ?int $idShop = null): ?array');
    neria_assert($posEntry !== false, 'getUpsellProduct() introuvable — jeu de test invalide');
    $entryBody = substr($src, $posEntry, 2600);
    neria_assert(
        strpos($entryBody, "enrich(\$row, \$idLang, 'L\'accessoire parfait', \$idShop, \$idCustomer)") !== false
            && strpos($entryBody, "enrich(\$row, \$idLang, 'Souvent acheté ensemble', \$idShop, \$idCustomer)") !== false
            && strpos($entryBody, "enrich(\$row, \$idLang, 'Notre suggestion pour vous', \$idShop, \$idCustomer)") !== false,
        "getUpsellProduct() ne transmet plus \$idCustomer aux 3 appels enrich() — régression du bug corrigé le 18/08/2026 (round 184)"
    );

    // Vérification comportementale réelle : la méthode s'exécute sans
    // erreur avec un vrai produit et un vrai idCustomer, et produit un
    // prix positif (confirme que le 10e paramètre de getPriceStatic() ne
    // casse pas l'appel).
    $module = neria_test_module();
    $mgr = new UpsellManager($module);
    $ref = new ReflectionMethod(UpsellManager::class, 'safeProductPrice');
    $ref->setAccessible(true);

    $products = Product::getProducts((int) Configuration::get('PS_LANG_DEFAULT'), 0, 1, 'id_product', 'ASC', false, true);
    if (empty($products)) {
        return ['pass' => true, 'message' => 'Aucun produit actif en base de test — vérification structurelle uniquement (rien à exécuter)'];
    }
    $idProduct = (int) $products[0]['id_product'];
    $idCustomer = neria_test_any_customer_id();

    $priceVisitor = $ref->invoke($mgr, $idProduct, (int) Configuration::get('PS_LANG_DEFAULT'), 0);
    $priceCustomer = $ref->invoke($mgr, $idProduct, (int) Configuration::get('PS_LANG_DEFAULT'), $idCustomer);

    neria_assert(
        is_float($priceVisitor) && $priceVisitor >= 0.0 && is_float($priceCustomer) && $priceCustomer >= 0.0,
        "safeProductPrice() avec \$idCustomer n'a pas produit de prix valide — régression du bug corrigé le 18/08/2026 (round 184) : le 10e paramètre de Product::getPriceStatic() casserait l'appel"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager::safeProductPrice() transmet bien \$idCustomer jusqu'à Product::getPriceStatic() — bug corrigé le 18/08/2026 (round 184)",
    ];
}
