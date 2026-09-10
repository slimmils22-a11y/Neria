<?php
/**
 * Régression : `UpsellManager::safeProductPrice()` et
 * `LookCompletionManager::safeProductPrice()` basculaient temporairement
 * `$ctx->cart` et `$ctx->currency` (round 305) mais JAMAIS `$ctx->shop` —
 * or `\Product::getPriceStatic()` (cœur PrestaShop) transmet
 * `$context->shop->id` à `priceCalculation()`, qui l'utilise pour résoudre
 * les `specific_price` (promotions) scopées par `id_shop` via
 * `SpecificPrice::getSpecificPrice($id_product, $id_shop, ...)`.
 *
 * Bug identifié le 10/09/2026 (round 331, audit UpsellManager/
 * LookCompletionManager) : un cron démarré en contexte "Boutique A"
 * calculait le prix d'un produit en promotion SPÉCIFIQUEMENT sur la
 * "Boutique B" du client destinataire sans jamais voir cette promotion —
 * le client recevait le prix plein tarif dans l'email (upsell post-achat,
 * "Complétez votre look") alors qu'il payait réellement le prix
 * promotionnel au clic sur la fiche produit (dont l'URL, elle, est déjà
 * scopée par `$idShop` ailleurs dans ces mêmes fichiers).
 *
 * Corrigé le 10/09/2026 (round 331) : `$ctx->shop` est désormais basculé
 * vers `new \Shop($idShop)` le temps du calcul (même pattern try/finally
 * que `$ctx->currency`, round 305), dans les deux fichiers.
 *
 * Test structurel (une fixture multi-boutiques réelle nécessiterait un
 * 2e enregistrement `ps_shop` complet + une specific_price scopée dessus,
 * hors périmètre d'un test isolé — même raisonnement déjà accepté pour ce
 * même groupe de méthodes, cf. test_423/test_380) + comportemental sur le
 * chemin NOMINAL (boutique unique de l'environnement de test) : vérifie
 * que safeProductPrice() calcule toujours un prix correct et cohérent
 * avec Product::getPriceStatic() appelé directement dans le même contexte
 * boutique, garantissant que l'ajout du switch $ctx->shop n'a pas cassé
 * le comportement normal à boutique unique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    // ── Vérification structurelle du correctif dans les 2 fichiers ──
    $usSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($usSrc !== false, 'Impossible de lire src/UpsellManager.php');
    $lcSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php');
    neria_assert($lcSrc !== false, 'Impossible de lire src/LookCompletionManager.php');

    neria_assert(
        strpos($usSrc, "\$ctx->shop   = new \\Shop(\$idShop);") !== false
            && strpos($usSrc, 'if ($shopChanged) {') !== false
            && strpos($usSrc, '$ctx->shop = $originalShop;') !== false,
        "UpsellManager::safeProductPrice() ne bascule plus \$ctx->shop avant Product::getPriceStatic() — régression du bug corrigé le 10/09/2026 (round 331) : une promotion specific_price scopée à la boutique du client destinataire ne serait de nouveau jamais résolue quand le cron démarre dans le contexte d'une autre boutique"
    );
    neria_assert(
        strpos($lcSrc, "\$ctx->shop   = new \\Shop(\$idShop);") !== false
            && strpos($lcSrc, 'if ($shopChanged) {') !== false
            && strpos($lcSrc, '$ctx->shop = $originalShop;') !== false,
        "LookCompletionManager::safeProductPrice() ne bascule plus \$ctx->shop avant Product::getPriceStatic() — régression du bug corrigé le 10/09/2026 (round 331)"
    );

    // ── Vérification comportementale du chemin nominal (boutique unique) ──
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $db       = neria_test_db();
    $prefix   = neria_test_prefix();
    $idShop   = (int) Context::getContext()->shop->id;
    $prodRow  = $db->getRow("SELECT id_product FROM {$prefix}product WHERE active = 1");
    neria_assert($prodRow !== false, 'jeu de test invalide : aucun produit actif disponible en base de test');
    $idProduct = (int) $prodRow['id_product'];

    // Product::getPriceStatic() exige un panier en l'absence d'employé en
    // contexte (comportement cœur PrestaShop) — panier temporaire, comme
    // le fait safeProductPrice() en interne.
    $ctx = \Context::getContext();
    $hadCartForBaseline = \Validate::isLoadedObject($ctx->cart);
    if (!$hadCartForBaseline) {
        $tmpCart              = new \Cart();
        $tmpCart->id_currency = (int) $ctx->currency->id;
        $tmpCart->id_lang     = (int) $ctx->language->id;
        $ctx->cart            = $tmpCart;
    }
    $expectedPrice = (float) \Product::getPriceStatic($idProduct, true);
    if (!$hadCartForBaseline) {
        $ctx->cart = null;
    }

    $usMgr = new UpsellManager(neria_test_module());
    $refUs = new ReflectionMethod(UpsellManager::class, 'safeProductPrice');
    $refUs->setAccessible(true);
    $priceUs = (float) $refUs->invoke($usMgr, $idProduct, (int) Context::getContext()->language->id, 0, $idShop, null);
    neria_assert(
        abs($priceUs - $expectedPrice) < 0.01,
        "UpsellManager::safeProductPrice() calcule un prix incohérent ({$priceUs}) avec Product::getPriceStatic() direct ({$expectedPrice}) sur la boutique unique de l'environnement de test — comportement nominal cassé par l'ajout du switch \$ctx->shop"
    );

    $lcMgr = new LookCompletionManager(neria_test_module());
    $refLc = new ReflectionMethod(LookCompletionManager::class, 'safeProductPrice');
    $refLc->setAccessible(true);
    $priceLc = (float) $refLc->invoke($lcMgr, $idProduct, $idShop, 0, 0);
    neria_assert(
        abs($priceLc - $expectedPrice) < 0.01,
        "LookCompletionManager::safeProductPrice() calcule un prix incohérent ({$priceLc}) avec Product::getPriceStatic() direct ({$expectedPrice}) sur la boutique unique de l'environnement de test — comportement nominal cassé par l'ajout du switch \$ctx->shop"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager/LookCompletionManager::safeProductPrice() basculent désormais \$ctx->shop (comme \$ctx->currency depuis le round 305) avant Product::getPriceStatic() — les promotions specific_price scopées par boutique sont enfin résolues correctement, comportement nominal à boutique unique préservé — bug corrigé le 10/09/2026 (round 331)",
    ];
}
