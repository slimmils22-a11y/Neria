<?php
/**
 * Régression : UpsellManager::resolveDisplayCurrency() (extraite de
 * enrich()) doit résoudre la devise du prix affiché via PS_CURRENCY_DEFAULT
 * scopé par $idShop (la boutique du CLIENT), pas via
 * $this->context->currency (devise du contexte d'EXÉCUTION courant) — même
 * correctif déjà appliqué dans CollectionManager::processCollection() pour
 * {missing_price}.
 *
 * Bug réel corrigé le 07/08/2026 (round 87) : sur une installation
 * multi-boutiques avec des devises distinctes, un email envoyé (ou traité
 * par cron, contexte resté sur la 1re boutique) pour un client d'une
 * boutique B affichait le bloc upsell dans la devise de la boutique A. Le
 * lien produit ({product_url}) souffrait du même problème : getProductLink()
 * était appelé sans $idShop dans enrich(), alors que $idShop est reçu en
 * paramètre et déjà utilisé pour getProductImageUrl() — corrigé en même
 * temps (passage de $idShop en 6e argument), non re-testé isolément ici
 * (getProductLink() n'a pas d'effet observable simple hors contexte HTTP
 * multi-domaine réel).
 *
 * Test comportemental réel : configure PS_CURRENCY_DEFAULT=JPY (id 5, très
 * distincte visuellement d'EUR) pour une boutique fictive (id_shop=999997),
 * appelle resolveDisplayCurrency() via réflexion, et vérifie que la devise
 * retournée est bien JPY et non celle du contexte (EUR par défaut en dev).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = 999997; // boutique fictive, isolée des vraies données

    $previousCurrencyCfg = $db->getValue(
        "SELECT value FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}"
    );
    $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}");
    Configuration::updateValue('PS_CURRENCY_DEFAULT', 5, false, null, $idShop); // 5 = JPY

    try {
        $mgr = new UpsellManager(neria_test_module());
        $resolve = new ReflectionMethod(UpsellManager::class, 'resolveDisplayCurrency');
        $resolve->setAccessible(true);

        $currency = $resolve->invoke($mgr, $idShop);
        neria_assert(
            (int) $currency->id === 5,
            "UpsellManager::resolveDisplayCurrency() ne résout plus la devise via PS_CURRENCY_DEFAULT scopé par \$idShop (obtenu id_currency=" . (int) $currency->id . ", attendu 5=JPY) — régression du bug corrigé le 07/08/2026 (round 87) : le bloc upsell afficherait de nouveau un prix dans la devise du contexte d'exécution courant, pas celle de la boutique du client"
        );

        // idShop=null (pas de contexte multi-boutique connu) doit retomber
        // sur la devise du contexte courant, sans planter.
        $fallback = $resolve->invoke($mgr, null);
        neria_assert(
            $fallback instanceof Currency,
            "UpsellManager::resolveDisplayCurrency(null) ne retombe plus sur une devise valide"
        );

        return [
            'pass'    => true,
            'message' => "UpsellManager::resolveDisplayCurrency() résout bien la devise du prix affiché via la boutique du client (\$idShop), pas celle du contexte d'exécution courant",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}");
        if ($previousCurrencyCfg !== false && $previousCurrencyCfg !== null) {
            Configuration::updateValue('PS_CURRENCY_DEFAULT', (int) $previousCurrencyCfg, false, null, $idShop);
        }
    }
}
