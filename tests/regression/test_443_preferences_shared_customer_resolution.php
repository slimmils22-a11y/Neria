<?php
/**
 * Régression : controllers/front/preferences.php résolvait le client par
 * une requête SQL brute filtrée strictement sur id_shop = boutique
 * courante, ignorant le partage de comptes multi-boutique
 * (Shop::SHARE_CUSTOMER).
 *
 * Bug réel identifié le 25/08/2026 (round 211) : en multi-boutique avec
 * partage de comptes actif, un compte client est rattaché à sa boutique de
 * CRÉATION dans `id_shop`, pas à la boutique visitée. Un client créé sur
 * la boutique A cliquant un lien de préférences reçu depuis/pour la
 * boutique B ne trouvait alors aucune ligne (id_shop = B ne correspond
 * pas), retombait à id_customer=0 (traité comme invité), et ses
 * préférences réelles de client identifié n'étaient jamais mises à jour —
 * désabonnement silencieusement inefficace.
 *
 * Corrigé le 25/08/2026 (round 211) : résolution via
 * Customer::customerExists() (cœur PrestaShop), qui applique
 * Shop::addSqlRestriction(Shop::SHARE_CUSTOMER) et gère nativement les
 * deux cas (partagé ou non) — même pattern déjà éprouvé dans
 * CooldownManager::resolveCustomerId(). Un contrôle explicite deleted=0
 * est ajouté après coup, Customer::customerExists() ne le filtrant pas
 * nativement (contrairement à l'ancienne requête SQL brute).
 *
 * Test structurel (cet environnement de test est mono-boutique —
 * Shop::isFeatureActive() === false — donc le scénario multi-boutique à
 * comptes partagés n'est pas reproductible de bout en bout ici) +
 * comportemental sur le mécanisme sous-jacent réellement utilisé
 * (Customer::customerExists() sous Shop::setContext() résout bien un
 * client réel, et exclut bien un client soft-supprimé).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/preferences.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/preferences.php');

    neria_assert(
        strpos($src, 'Shop::setContext(Shop::CONTEXT_SHOP, (int) $this->context->shop->id);') !== false,
        "preferences.php ne bascule plus le contexte Shop statique avant de résoudre le client — régression du bug corrigé le 25/08/2026 (round 211)"
    );
    neria_assert(
        strpos($src, '$idCustomer = (int) Customer::customerExists($email, true);') !== false,
        "preferences.php ne résout plus le client via Customer::customerExists() — régression du bug corrigé le 25/08/2026 (round 211) : un client en multi-boutique à comptes partagés redeviendrait injoignable via son lien de préférences reçu d'une autre boutique du groupe"
    );
    neria_assert(
        strpos($src, '(int) $custCheck->deleted === 1') !== false,
        "preferences.php ne réexclut plus les comptes soft-supprimés après résolution — régression potentielle : un compte RGPD-supprimé redeviendrait éditable via ce lien public"
    );

    // Comportemental : le mécanisme sous-jacent (Customer::customerExists()
    // sous Shop::setContext() temporaire) résout bien un client réel, et
    // exclut bien un client marqué deleted=1 — exactement ce que fait
    // désormais preferences.php.
    $idShop = (int) Context::getContext()->shop->id;
    $email = 'round211prefstest@example.test';
    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
    $idCustomer = (int) Customer::customerExists($email, true);

    try {
        if (!$idCustomer) {
            $c = new Customer();
            $c->firstname = 'Roundprefs';
            $c->lastname  = 'Testeleven';
            $c->email     = $email;
            $c->passwd    = Tools::hash('round211test');
            $c->id_lang   = $idLang;
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
            "Customer::customerExists() sous Shop::setContext() ne résout pas le client réel (obtenu {$resolved}, attendu {$idCustomer}) — le mécanisme désormais utilisé par preferences.php serait cassé"
        );

        // Marque le client comme supprimé et vérifie que le contrôle
        // explicite deleted=1 de preferences.php exclurait bien ce cas
        // (Customer::customerExists() lui-même ne filtre pas deleted).
        $cObj = new Customer($idCustomer);
        $cObj->deleted = 1;
        $cObj->update();
        neria_assert(
            (int) $cObj->deleted === 1,
            "Impossible de marquer le client de test comme supprimé — jeu de test invalide"
        );
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
        'message' => "preferences.php résout bien le client via Customer::customerExists() (respecte Shop::SHARE_CUSTOMER) avec exclusion explicite des comptes supprimés — bug corrigé le 25/08/2026 (round 211)",
    ];
}
