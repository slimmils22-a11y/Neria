<?php
/**
 * Régression : UpsellManager::findUpsellForCustomer() (utilisée par
 * renderUpsellBlock()/renderUpsellBlockTxt(), appelées par
 * SeasonalCampaignManager pour les campagnes "idées cadeaux") doit lire
 * id_currency de la dernière commande du client et le transmettre à
 * getUpsellProduct(), exactement comme déjà fait pour le chemin
 * BehavioralCronManager (round 274).
 *
 * Bug identifié le 13/09/2026 (round 348, audit multi-agents, angle
 * cohérence multi-devise) : findUpsellForCustomer() ne sélectionnait que
 * `id_order` (pas `id_currency`) et appelait getUpsellProduct() sans son 4e
 * paramètre optionnel $idCurrency. resolveDisplayCurrency($idShop, null)
 * retombait alors sur PS_CURRENCY_DEFAULT de la boutique au lieu de la
 * devise RÉELLE dans laquelle ce client a payé sa dernière commande — sur
 * une boutique multi-devises, le bloc upsell d'une campagne saisonnière
 * pouvait afficher un prix dans une devise différente de celle que ce même
 * client verrait en revenant sur le site (cookie de devise front).
 *
 * Test comportemental réel : crée une commande fictive isolée (id_shop
 * inexistant 999997, table orders réelle) avec un id_currency distinctif
 * (5 = JPY), appelle findUpsellForCustomer() via réflexion, et vérifie que
 * la requête SQL a bien remonté et transmis cette devise à
 * getUpsellProduct() — observé indirectement via resolveDisplayCurrency(),
 * qui est le seul point où $idCurrency a un effet visible, en interceptant
 * l'appel via une sous-classe de test (le produit suggéré lui-même dépend
 * du catalogue réel, non maîtrisable ici : seule la PROPAGATION de la
 * devise est testée, pas le résultat final de la sélection produit).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/UpsellManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $idShop = 999997; // boutique fictive, isolée des vraies données
    $idCustomer = neria_test_any_customer_id();

    // Sous-classe de test : intercepte l'appel à getUpsellProduct() pour
    // capturer les arguments réellement reçus, sans dépendre du catalogue.
    $spyClass = new class(neria_test_module()) extends UpsellManager {
        public ?array $lastArgs = null;
        public function getUpsellProduct(int $idOrder, int $idLang, ?int $idShop = null, ?int $idCurrency = null): ?array
        {
            $this->lastArgs = [$idOrder, $idLang, $idShop, $idCurrency];
            return null;
        }
    };

    $fakeOrderId = null;
    try {
        $db->execute(
            "INSERT INTO `{$prefix}orders`
                (id_customer, id_shop, id_shop_group, id_currency, id_lang, id_carrier,
                 id_address_delivery, id_address_invoice, current_state, secure_key,
                 payment, conversion_rate, total_paid, total_paid_tax_incl, total_paid_tax_excl,
                 total_paid_real, total_products, total_products_wt, valid, reference, date_add, date_upd)
             VALUES
                ({$idCustomer}, {$idShop}, 1, 5, 1, 1,
                 0, 0, 1, 'test325',
                 'test', 1, 10, 10, 10,
                 10, 10, 10, 1, 'TEST348UP', NOW(), NOW())"
        );
        $fakeOrderId = (int) $db->Insert_ID();
        neria_assert($fakeOrderId > 0, "Impossible de créer la commande fictive de test");

        $mgr = new $spyClass(neria_test_module());
        $find = new ReflectionMethod(UpsellManager::class, 'findUpsellForCustomer');
        $find->setAccessible(true);
        $find->invoke($mgr, $idCustomer, 1, $idShop);

        neria_assert($mgr->lastArgs !== null, "findUpsellForCustomer() n'a jamais appelé getUpsellProduct() — jeu de test invalide (commande fictive non retrouvée par la requête id_customer/id_shop/valid)");
        neria_assert(
            $mgr->lastArgs[0] === $fakeOrderId,
            "findUpsellForCustomer() n'a pas sélectionné la bonne commande de test"
        );
        neria_assert(
            $mgr->lastArgs[3] === 5,
            "findUpsellForCustomer() ne transmet plus id_currency (obtenu " . var_export($mgr->lastArgs[3], true) . ", attendu 5=JPY) à getUpsellProduct() — régression du bug corrigé le 13/09/2026 (round 348) : le bloc upsell d'une campagne saisonnière afficherait de nouveau un prix dans la devise par défaut de la boutique, pas celle réellement payée par ce client"
        );

        return [
            'pass'    => true,
            'message' => "UpsellManager::findUpsellForCustomer() transmet bien id_currency de la dernière commande du client à getUpsellProduct() — bug corrigé le 13/09/2026 (round 348)",
        ];
    } finally {
        if ($fakeOrderId) {
            $db->execute("DELETE FROM `{$prefix}orders` WHERE id_order = {$fakeOrderId}");
        }
    }
}
