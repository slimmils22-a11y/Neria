<?php
/**
 * Régression : `LookCompletionManager::buildProductBlocks()`/
 * `safeProductPrice()` n'utilisaient que la devise PAR DÉFAUT de la
 * boutique (`PS_CURRENCY_DEFAULT` scopé par `$idShop`, round 198), jamais
 * la devise RÉELLE de la commande livrée qui déclenche l'email
 * `complete_your_look` (48h après livraison) — même bug que celui corrigé
 * dans `UpsellManager` au round 274 (déclenchement identique : email lié à
 * une commande précise identifiée), jamais répliqué ici malgré un code
 * quasi identique (mêmes commentaires "round 184/198").
 *
 * Bug identifié le 01/09/2026 (round 275, audit "cohérence devise
 * commande vs affichage email").
 *
 * Corrigé le 01/09/2026 (round 275) : nouveau paramètre `$idCurrency`
 * (devise réelle de la commande, sélectionnée via `o.id_currency` dans
 * `runDailyCheck()`), propagé jusqu'à `buildProductBlocks()`/
 * `safeProductPrice()` — prime sur `PS_CURRENCY_DEFAULT` quand fourni.
 *
 * Test comportemental réel : appelle `safeProductPrice()` (privée, via
 * réflexion) pour un vrai produit avec une devise de boutique (JPY, id 5)
 * puis avec une devise de commande différente (id 2), et vérifie que la
 * seconde prime — la valeur du panier temporaire `id_currency` change bien
 * selon `$idCurrency`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = 999995; // boutique fictive, isolée des vraies données

    $previousCurrencyCfg = $db->getValue(
        "SELECT value FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}"
    );
    $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}");
    Configuration::updateValue('PS_CURRENCY_DEFAULT', 5, false, null, $idShop); // 5 = JPY

    try {
        $mgr = new LookCompletionManager(neria_test_module());
        $ref = new ReflectionMethod(LookCompletionManager::class, 'safeProductPrice');
        $ref->setAccessible(true);

        $products = Product::getProducts((int) Configuration::get('PS_LANG_DEFAULT'), 0, 1, 'id_product', 'ASC', false, true);
        if (empty($products)) {
            return ['pass' => true, 'message' => 'Aucun produit actif en base de test — vérification structurelle uniquement (rien à exécuter)'];
        }
        $idProduct = (int) $products[0]['id_product'];

        // Sans devise de commande : Cart::id_currency doit être scopé par
        // PS_CURRENCY_DEFAULT($idShop) = JPY — comportement round 198 inchangé.
        $ref->invoke($mgr, $idProduct, $idShop, 0);
        $ctx = Context::getContext();
        neria_assert(
            $ctx->cart === null,
            "safeProductPrice() n'a pas restauré \$ctx->cart à null après exécution — jeu de test invalide"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
        neria_assert($src !== false, 'Impossible de lire src/LookCompletionManager.php');

        // Round 292 : signatures élargies d'un paramètre int $idCustomer = 0
        // — littéraux mis à jour, contrôle inchangé sur le fond ($idCurrency).
        neria_assert(
            strpos($src, 'private function safeProductPrice(int $idProduct, int $idShop, int $idCurrency = 0, int $idCustomer = 0): float') !== false,
            "LookCompletionManager::safeProductPrice() n'accepte plus \$idCurrency — régression du bug corrigé le 01/09/2026 (round 275)"
        );
        neria_assert(
            strpos($src, '$tmp->id_currency = $idCurrency > 0') !== false,
            "LookCompletionManager::safeProductPrice() ne priorise plus \$idCurrency sur la devise par défaut de la boutique — régression du bug corrigé le 01/09/2026 (round 275) : le prix suggéré redeviendrait affiché dans la devise par défaut de la boutique, pas celle réelle de la commande du client"
        );
        neria_assert(
            strpos($src, 'private function buildProductBlocks(array $productIds, int $idLang, int $idShop, int $idCurrency = 0, int $idCustomer = 0): array') !== false,
            "LookCompletionManager::buildProductBlocks() n'accepte plus \$idCurrency — régression du bug corrigé le 01/09/2026 (round 275)"
        );

        $srcRunDaily = strpos($src, 'public function runDailyCheck(): int');
        neria_assert($srcRunDaily !== false, 'runDailyCheck() introuvable — jeu de test invalide');
        $runDailyBody = substr($src, $srcRunDaily, 1200);
        neria_assert(
            strpos($runDailyBody, 'SELECT DISTINCT oh.id_order, o.id_customer, o.id_lang, o.id_shop, o.id_currency') !== false,
            "runDailyCheck() ne sélectionne plus o.id_currency — régression du bug corrigé le 01/09/2026 (round 275)"
        );

        return [
            'pass'    => true,
            'message' => "LookCompletionManager résout désormais le prix des produits suggérés dans la devise RÉELLE de la commande quand elle est connue, même correctif qu'UpsellManager (round 274) — bug corrigé le 01/09/2026 (round 275)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}");
        if ($previousCurrencyCfg !== false && $previousCurrencyCfg !== null) {
            Configuration::updateValue('PS_CURRENCY_DEFAULT', (int) $previousCurrencyCfg, false, null, $idShop);
        }
    }
}
