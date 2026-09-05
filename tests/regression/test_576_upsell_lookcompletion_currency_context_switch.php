<?php
/**
 * Régression : UpsellManager::safeProductPrice()/LookCompletionManager::
 * safeProductPrice() basculaient l'id_currency d'un panier TEMPORAIRE
 * ($ctx->cart) pour forcer le calcul du prix dans la devise réelle de la
 * commande ($idCurrency), mais \Product::getPriceStatic() (cœur
 * PrestaShop) ne lit JAMAIS $cart->id_currency pour résoudre la devise de
 * calcul — uniquement $context->currency->id
 * (classes/Product.php::getPriceStatic()). Les correctifs des rounds
 * 198/274/275 (documentés en détail dans le code) n'avaient donc AUCUN
 * effet réel sur le MONTANT calculé malgré leur intention explicite : ils
 * corrigeaient un levier ($cart) totalement ignoré par cette fonction du
 * cœur pour la résolution de devise.
 *
 * Scénario concret : boutique par défaut en EUR, client ayant acheté en
 * USD. Le montant numérique retourné par getPriceStatic() restait calculé
 * selon Context::getContext()->currency (la devise AMBIANTE du process
 * cron, souvent EUR), affiché ensuite avec le symbole $ (résolu séparément
 * par resolveDisplayCurrency()/displayPrice()) — un montant numérique EUR
 * affiché avec le symbole USD, écart réel prix affiché / prix réellement
 * dû.
 *
 * Corrigé le 05/09/2026 (round 305) : $context->currency lui-même est
 * désormais basculé (temporairement, restauré en finally), pas seulement
 * $cart->id_currency — même pattern que Shop::setContext() déjà utilisé
 * ailleurs dans ce module pour la bascule de contexte boutique.
 *
 * Test comportemental réel : appelle safeProductPrice() (privée, via
 * reflection) pour un MÊME produit avec 2 devises différentes (taux de
 * conversion réellement différents en base de test) — les 2 montants
 * numériques retournés doivent différer, proportionnellement au taux de
 * change, prouvant que $context->currency est réellement pris en compte.
 * Vérifie aussi que $context->currency est bien restauré après l'appel
 * (pas de fuite de contexte pour le code appelant suivant).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/LookCompletionManager.php';

    $db = Db::getInstance();
    $idProduct = (int) $db->getValue("SELECT id_product FROM " . _DB_PREFIX_ . "product WHERE active = 1");
    neria_assert($idProduct > 0, "Aucun produit actif trouvé — jeu de test invalide");

    $rates = $db->executeS("SELECT id_currency, conversion_rate FROM " . _DB_PREFIX_ . "currency WHERE id_currency IN (1, 14) AND deleted = 0");
    neria_assert(is_array($rates) && count($rates) === 2, "Devises EUR (1) et USD (14) introuvables ou supprimées — jeu de test invalide (environnement)");
    $rateByCurrency = [];
    foreach ($rates as $r) {
        $rateByCurrency[(int) $r['id_currency']] = (float) $r['conversion_rate'];
    }
    neria_assert(
        abs($rateByCurrency[1] - $rateByCurrency[14]) > 0.01,
        "Les taux de conversion EUR/USD sont identiques en base de test — jeu de test invalide, impossible de distinguer les 2 devises"
    );

    $idShop = (int) Context::getContext()->shop->id;
    $ctx = Context::getContext();
    $originalCurrency = $ctx->currency;

    $mgr = new UpsellManager(neria_test_module());
    $refUpsell = new ReflectionMethod(UpsellManager::class, 'safeProductPrice');
    $refUpsell->setAccessible(true);

    $priceEur = $refUpsell->invoke($mgr, $idProduct, (int) Configuration::get('PS_LANG_DEFAULT'), 0, $idShop, 1);
    $priceUsd = $refUpsell->invoke($mgr, $idProduct, (int) Configuration::get('PS_LANG_DEFAULT'), 0, $idShop, 14);

    neria_assert(
        abs($priceEur - $priceUsd) > 0.001,
        "UpsellManager::safeProductPrice() renvoie le même montant ({$priceEur}) pour EUR et USD malgré des taux de conversion différents — régression du bug corrigé le 05/09/2026 (round 305) : \$context->currency ne serait de nouveau plus réellement basculé, le montant resterait calculé dans la devise ambiante du process quel que soit \$idCurrency transmis"
    );

    // Le ratio des montants doit refléter approximativement le ratio des
    // taux de conversion (à l'arrondi/taxe près).
    $expectedRatio = $rateByCurrency[14] / $rateByCurrency[1];
    $actualRatio = $priceUsd / $priceEur;
    neria_assert(
        abs($actualRatio - $expectedRatio) < 0.05,
        "Le ratio USD/EUR obtenu ({$actualRatio}) ne correspond pas au ratio des taux de conversion attendu ({$expectedRatio}) — le montant ne semble pas calculé dans la devise transmise"
    );

    neria_assert(
        (int) $ctx->currency->id === (int) $originalCurrency->id,
        "Context::getContext()->currency n'a pas été restauré après safeProductPrice() — fuite de contexte pour le code appelant suivant"
    );

    // Même vérification pour LookCompletionManager::safeProductPrice().
    $mgrLc = new LookCompletionManager(neria_test_module());
    $refLc = new ReflectionMethod(LookCompletionManager::class, 'safeProductPrice');
    $refLc->setAccessible(true);
    $priceEurLc = $refLc->invoke($mgrLc, $idProduct, $idShop, 1, 0);
    $priceUsdLc = $refLc->invoke($mgrLc, $idProduct, $idShop, 14, 0);
    neria_assert(
        abs($priceEurLc - $priceUsdLc) > 0.001,
        "LookCompletionManager::safeProductPrice() renvoie le même montant pour EUR et USD — régression du bug corrigé le 05/09/2026 (round 305)"
    );
    neria_assert(
        (int) $ctx->currency->id === (int) $originalCurrency->id,
        "Context::getContext()->currency n'a pas été restauré après LookCompletionManager::safeProductPrice()"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager/LookCompletionManager::safeProductPrice() basculent bien \$context->currency (pas seulement \$cart->id_currency, ignoré par Product::getPriceStatic()) — le montant calculé varie réellement selon la devise transmise, et le contexte est restauré après l'appel — bug corrigé le 05/09/2026 (round 305)",
    ];
}
