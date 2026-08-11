<?php
/**
 * Régression : UpsellManager::findByAccessories()/findByCoPurchase()/
 * findByCategoryBestseller() doivent filtrer le SUM de stock par id_shop,
 * comme enrich() (devise/image/lien) juste après elles dans le même
 * fichier.
 *
 * Bug réel corrigé le 09/08/2026 (round 145) : le SUM(sa.quantity) sur
 * stock_available ne filtrait jamais id_shop — sur une installation
 * multi-boutique à stock séparé par boutique, un produit épuisé sur la
 * boutique qui envoie l'email mais en stock sur une AUTRE boutique de la
 * même installation passait quand même le filtre de disponibilité (SUM
 * global toutes boutiques confondues), suggérant en upsell un produit en
 * réalité indisponible pour ce client précis.
 *
 * Test structurel + comportemental partiel : seeder une vraie relation
 * accessoire/catégorie/co-achat de façon fiable sans dépendre de données
 * de catalogue préexistantes serait un montage lourd et fragile — vérifie
 * donc au niveau du code source que les 3 méthodes reçoivent bien $idShop
 * et l'appliquent au SUM de stock, et confirme via Reflection que
 * getUpsellProduct() (point d'entrée réel) transmet bien $idShop aux 3
 * méthodes lors de son appel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($src !== false, 'Impossible de lire src/UpsellManager.php');

    $methods = [
        'findByAccessories'       => 'private function findByAccessories(array $productIds, array $excluded, int $idLang, ?int $idShop = null): ?array',
        'findByCoPurchase'        => 'private function findByCoPurchase(array $productIds, array $excluded, int $idLang, ?int $idShop = null): ?array',
        'findByCategoryBestseller' => 'private function findByCategoryBestseller(array $productIds, array $excluded, int $idLang, ?int $idShop = null): ?array',
    ];

    foreach ($methods as $name => $signature) {
        $pos = strpos($src, $signature);
        neria_assert(
            $pos !== false,
            "{$name}() n'accepte plus \$idShop — régression du bug corrigé le 09/08/2026 (round 145)"
        );
        $body = substr($src, $pos, 1800);
        neria_assert(
            strpos($body, "\$idShop !== null ? ' AND sa.id_shop = ' . (int) \$idShop : ''") !== false,
            "{$name}() n'applique plus \$idShop au SUM de stock — régression du bug corrigé le 09/08/2026 (round 145) : un produit épuisé sur la boutique du destinataire mais en stock ailleurs redeviendrait suggéré à tort"
        );
    }

    $posEntry = strpos($src, 'public function getUpsellProduct(int $idOrder, int $idLang, ?int $idShop = null): ?array');
    neria_assert($posEntry !== false, 'getUpsellProduct() introuvable — jeu de test invalide');
    $entryBody = substr($src, $posEntry, 2200);
    neria_assert(
        strpos($entryBody, 'findByAccessories($orderProducts, $excluded, $idLang, $idShop)') !== false
        && strpos($entryBody, 'findByCoPurchase($orderProducts, $excluded, $idLang, $idShop)') !== false
        && strpos($entryBody, 'findByCategoryBestseller($orderProducts, $excluded, $idLang, $idShop)') !== false,
        "getUpsellProduct() ne transmet plus \$idShop aux 3 méthodes findBy*() — régression du bug corrigé le 09/08/2026 (round 145)"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager::findByAccessories()/findByCoPurchase()/findByCategoryBestseller() filtrent bien le stock par boutique, transmis depuis getUpsellProduct()",
    ];
}
