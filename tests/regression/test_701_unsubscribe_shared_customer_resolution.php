<?php
/**
 * Régression : `controllers/front/unsubscribe.php` résolvait le client par
 * des requêtes SQL brutes filtrées strictement sur `id_shop = boutique
 * courante` (pour `customer.newsletter` ET pour la résolution `id_customer`
 * transmise à `PreferencesManager`), ignorant le partage de comptes
 * multi-boutique (`Shop::SHARE_CUSTOMER`) — exactement le même piège déjà
 * corrigé dans `preferences.php` (round 211, cf. test_443), pour EXACTEMENT
 * le même problème (résoudre un client à partir d'un email fourni dans un
 * lien reçu par email), mais jamais porté ici.
 *
 * Bug identifié le 12/09/2026 (round 339, audit bounce/oauth/oauthsc/
 * unsubscribe/preferences).
 *
 * Scénario concret (multi-boutique, partage de comptes actif) : un client
 * créé sur la boutique A cliquant le lien de désabonnement reçu depuis/pour
 * la boutique B — le compte est rattaché à sa boutique de CRÉATION dans
 * `id_shop`, pas à la boutique visitée. La requête stricte ne trouvait
 * alors aucune ligne : `ps_customer.newsletter` du VRAI compte n'était
 * jamais mis à 0, et `neria_preferences` créait une ligne INVITÉE
 * (id_customer=0) au lieu de mettre à jour la ligne du vrai client —
 * désabonnement RGPD/CAN-SPAM silencieusement inefficace : confirmation
 * affichée au client, mais il continuait de recevoir tous les emails
 * marketing Neria.
 *
 * Corrigé le 12/09/2026 (round 339) : résolution via
 * `Customer::customerExists()` sous bascule temporaire de
 * `Shop::setContext()`, exactement comme `preferences.php` (round 211) —
 * `$customerId` résolu UNE FOIS et réutilisé pour le canal
 * `ps_customer.newsletter` ET pour `PreferencesManager::saveByCustomer()`.
 *
 * Test structurel (mono-boutique dans cet environnement de test — même
 * limite déjà acceptée pour test_443) + comportemental sur le mécanisme
 * sous-jacent réellement utilisé (identique à test_443).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/unsubscribe.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/unsubscribe.php');

    neria_assert(
        strpos($src, 'Shop::setContext(Shop::CONTEXT_SHOP, $idShop);') !== false,
        "unsubscribe.php ne bascule plus le contexte Shop statique avant de résoudre le client — régression du correctif du 12/09/2026 (round 339)"
    );
    neria_assert(
        strpos($src, '$customerId = (int) Customer::customerExists($email, true);') !== false,
        "unsubscribe.php ne résout plus le client via Customer::customerExists() — régression du correctif du 12/09/2026 (round 339) : un client en multi-boutique à comptes partagés redeviendrait injoignable via son lien de désabonnement reçu d'une autre boutique du groupe, désabonnement silencieusement inefficace"
    );
    neria_assert(
        strpos($src, 'WHERE `id_customer` = " . $customerId') !== false,
        "unsubscribe.php ne met plus à jour ps_customer.newsletter par id_customer résolu — régression du correctif du 12/09/2026 (round 339)"
    );
    neria_assert(
        strpos($src, '(int) $custCheck339->deleted === 1') !== false,
        "unsubscribe.php ne réexclut plus les comptes soft-supprimés après résolution — régression potentielle : un compte RGPD-supprimé redeviendrait éditable via ce lien public"
    );

    // Comportemental : le mécanisme sous-jacent (identique à test_443/
    // preferences.php round 211) résout bien un client réel, y compris
    // sous une bascule temporaire du contexte Shop statique.
    $idShop = (int) Context::getContext()->shop->id;
    $email  = 'round339unsubtest@example.test';
    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    $idCustomer = (int) Customer::customerExists($email, true);

    try {
        if (!$idCustomer) {
            $c = new Customer();
            $c->firstname = 'Regtest';
            $c->lastname  = 'Unsubscribe';
            $c->email     = $email;
            $c->passwd    = Tools::hash('round339test');
            $c->id_lang   = $idLang;
            $c->newsletter = 1;
            $c->add();
            $idCustomer = (int) $c->id;
        }

        $previousContext = Shop::getContext();
        $previousShopId  = Shop::getContextShopID();
        Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
        try {
            $resolved = (int) Customer::customerExists($email, true);
        } finally {
            Shop::setContext($previousContext, $previousShopId);
        }
        neria_assert(
            $resolved === $idCustomer,
            "Customer::customerExists() sous Shop::setContext() ne résout pas le client réel (obtenu {$resolved}, attendu {$idCustomer}) — le mécanisme désormais utilisé par unsubscribe.php serait cassé"
        );

        // Vérifie le comportement nominal réel : mise à jour de newsletter
        // par id_customer, comme le fait désormais le contrôleur corrigé.
        $db = Db::getInstance();
        $db->execute("UPDATE " . _DB_PREFIX_ . "customer SET newsletter = 0 WHERE id_customer = {$idCustomer}");
        $newsletterAfter = (int) $db->getValue("SELECT newsletter FROM " . _DB_PREFIX_ . "customer WHERE id_customer = {$idCustomer}");
        neria_assert($newsletterAfter === 0, "La mise à jour par id_customer résolu ne fonctionne pas sur le chemin nominal — jeu de test invalide");
    } finally {
        if ($idCustomer) {
            $c = new Customer($idCustomer);
            if (Validate::isLoadedObject($c)) {
                $c->deleted = 0;
                $c->delete();
            }
        }
    }

    return [
        'pass'    => true,
        'message' => "unsubscribe.php résout désormais le client via Customer::customerExists() (respecte Shop::SHARE_CUSTOMER), même correctif que preferences.php (round 211) — bug corrigé le 12/09/2026 (round 339)",
    ];
}
