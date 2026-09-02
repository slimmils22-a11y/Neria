<?php
/**
 * Régression : `UpsellManager::resolveDisplayCurrency()`/`safeProductPrice()`
 * n'utilisaient QUE la devise PAR DÉFAUT de la boutique (`PS_CURRENCY_DEFAULT`
 * scopé par `$idShop`, round 87/198), jamais la devise RÉELLE de la
 * commande qui déclenche la suggestion upsell — sur une boutique
 * multi-devises (plusieurs devises actives au checkout, pas seulement
 * multi-boutiques), un client ayant acheté dans une devise secondaire
 * recevait le prix du produit suggéré (email `post_purchase_review`)
 * affiché dans la devise par défaut de la boutique, à un taux non lié à
 * celui réellement appliqué à sa commande — incohérent avec le reste du
 * même email.
 *
 * Bug identifié le 01/09/2026 (round 274, audit "cohérence devise
 * commande vs affichage email").
 *
 * Corrigé le 01/09/2026 (round 274) : nouveau paramètre optionnel
 * `$idCurrency` sur `getUpsellProduct()`/`enrich()`/`resolveDisplayCurrency()`/
 * `safeProductPrice()`, qui prime sur la devise par défaut de la boutique
 * quand fourni. `BehavioralCronManager::sendPostPurchase()` sélectionne
 * désormais `o.id_currency` et le transmet.
 *
 * Test comportemental réel : configure PS_CURRENCY_DEFAULT=JPY (id 5) pour
 * une boutique fictive, appelle `resolveDisplayCurrency($idShop, $idCurrency)`
 * avec une devise différente (id 2) et vérifie qu'elle prime sur la devise
 * par défaut de la boutique — puis vérifie l'absence de régression quand
 * `$idCurrency` est `null`/0 (repli sur le comportement round 87/198
 * inchangé). Complété par une vérification structurelle du câblage dans
 * `BehavioralCronManager::sendPostPurchase()`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = 999996; // boutique fictive, isolée des vraies données

    $previousCurrencyCfg = $db->getValue(
        "SELECT value FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}"
    );
    $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}");
    Configuration::updateValue('PS_CURRENCY_DEFAULT', 5, false, null, $idShop); // 5 = JPY (devise PAR DÉFAUT de la boutique)

    try {
        $mgr = new UpsellManager(neria_test_module());
        $resolve = new ReflectionMethod(UpsellManager::class, 'resolveDisplayCurrency');
        $resolve->setAccessible(true);

        // La devise RÉELLE de la commande (id 2) doit primer sur la devise
        // par défaut de la boutique (JPY, id 5) configurée ci-dessus.
        $currencyWithOrder = $resolve->invoke($mgr, $idShop, 2);
        neria_assert(
            (int) $currencyWithOrder->id === 2,
            "UpsellManager::resolveDisplayCurrency(\$idShop, \$idCurrency) ne priorise plus \$idCurrency sur la devise par défaut de la boutique (obtenu id_currency=" . (int) $currencyWithOrder->id . ", attendu 2) — régression du bug corrigé le 01/09/2026 (round 274) : le prix suggéré serait de nouveau affiché dans la devise par défaut de la boutique plutôt que celle réelle de la commande du client"
        );

        // Sans devise de commande connue (null), repli inchangé sur la
        // devise par défaut de la boutique (round 87/198, non régressé).
        $currencyWithoutOrder = $resolve->invoke($mgr, $idShop, null);
        neria_assert(
            (int) $currencyWithoutOrder->id === 5,
            "UpsellManager::resolveDisplayCurrency(\$idShop, null) ne retombe plus sur PS_CURRENCY_DEFAULT scopé par \$idShop (obtenu id_currency=" . (int) $currencyWithoutOrder->id . ", attendu 5=JPY) — régression du bug corrigé le 07/08/2026 (round 87)/24/08/2026 (round 198)"
        );

        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/BehavioralCronManager.php');
        neria_assert($src !== false, 'Impossible de lire src/BehavioralCronManager.php');
        $posFn = strpos($src, 'private function sendPostPurchase(string $template, int $days): void');
        neria_assert($posFn !== false, 'sendPostPurchase() introuvable — jeu de test invalide');
        $body = substr($src, $posFn, 3400);
        neria_assert(
            strpos($body, 'o.id_order, o.id_customer, o.id_shop, o.id_currency,') !== false,
            "sendPostPurchase() ne sélectionne plus o.id_currency — régression du bug corrigé le 01/09/2026 (round 274)"
        );
        neria_assert(
            strpos($body, "getUpsellProduct(\$idOrder, \$idLang, (int) \$r['id_shop'], (int) \$r['id_currency']);") !== false,
            "sendPostPurchase() ne transmet plus id_currency à getUpsellProduct() — régression du bug corrigé le 01/09/2026 (round 274) : le prix suggéré redeviendrait incohérent avec la devise réelle de la commande sur une boutique multi-devises"
        );

        return [
            'pass'    => true,
            'message' => "UpsellManager résout désormais le prix suggéré dans la devise RÉELLE de la commande quand elle est connue, pas seulement la devise par défaut de la boutique — bug corrigé le 01/09/2026 (round 274)",
        ];
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = 'PS_CURRENCY_DEFAULT' AND id_shop = {$idShop}");
        if ($previousCurrencyCfg !== false && $previousCurrencyCfg !== null) {
            Configuration::updateValue('PS_CURRENCY_DEFAULT', (int) $previousCurrencyCfg, false, null, $idShop);
        }
    }
}
