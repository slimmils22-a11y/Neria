<?php
/**
 * Régression : ManualSendManager::send()/scheduleManual() doivent résoudre
 * {shop_url}/{history_url} (et isAllowed()/Mail::Send() pour send())
 * d'après la vraie boutique DU CLIENT, pas le contexte BO de l'employé qui
 * déclenche l'envoi.
 *
 * Bug réel corrigé le 06/08/2026 (round 75) : send() n'essayait même pas de
 * dériver la vraie boutique du client (findCustomer() ne sélectionnait pas
 * id_shop) ; scheduleManual() calculait bien $idShopManual mais oubliait de
 * l'utiliser pour {shop_url}/{history_url} — même défaut que
 * CertificateManager corrigé au round 74.
 *
 * Partie comportementale réelle : findCustomer() renvoie bien id_shop ;
 * resolveShopUrl() restaure correctement le contexte après son appel (pas
 * de fuite d'état). Partie structurelle (pas de 2e vraie boutique sur cet
 * environnement de dev pour tester un VRAI changement de domaine) : vérifie
 * que send()/scheduleManual() utilisent bien resolveShopUrl()/getPageLink()
 * avec le bon id_shop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';

    $mgr = new ManualSendManager(neria_test_module());

    // findCustomer() (privée) doit désormais renvoyer id_shop.
    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $email  = (string) $db->getValue("SELECT email FROM {$prefix}customer WHERE active=1 AND deleted=0");
    neria_assert($email !== '', 'Aucun client de test disponible — jeu de test invalide');

    $findCustomer = new ReflectionMethod(ManualSendManager::class, 'findCustomer');
    $findCustomer->setAccessible(true);
    $customer = $findCustomer->invoke($mgr, $email);
    neria_assert($customer !== null, "findCustomer() n'a pas retrouvé le client de test — jeu de test invalide");
    neria_assert(
        array_key_exists('id_shop', $customer),
        "findCustomer() ne sélectionne plus id_shop — régression du bug corrigé le 06/08/2026 (round 75) : send() ne pourrait de nouveau jamais dériver la vraie boutique du client"
    );

    // resolveShopUrl() ne doit pas laisser le contexte altéré après son appel.
    $originalShopId = (int) Context::getContext()->shop->id;
    $resolveShopUrl = new ReflectionMethod(ManualSendManager::class, 'resolveShopUrl');
    $resolveShopUrl->setAccessible(true);
    $url = $resolveShopUrl->invoke($mgr, $originalShopId);
    neria_assert($url !== '', "resolveShopUrl() n'a retourné aucune URL — jeu de test invalide");
    neria_assert(
        (int) Context::getContext()->shop->id === $originalShopId,
        "resolveShopUrl() a laissé le contexte boutique altéré après son appel — fuite d'état pouvant affecter le reste du traitement de la requête"
    );

    // Vérification structurelle : send()/scheduleManual() utilisent bien
    // resolveShopUrl() et getPageLink(..., idShop) plutôt que le contexte brut.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php');
    neria_assert(
        substr_count($src, '$this->resolveShopUrl($idShop)') === 1
        && substr_count($src, '$this->resolveShopUrl($idShopManual)') === 1,
        "send()/scheduleManual() n'utilisent plus tous les deux resolveShopUrl() avec le bon id_shop — régression du bug corrigé le 06/08/2026 (round 75)"
    );
    neria_assert(
        substr_count($src, "getPageLink('history', true, \$idLang, null, false, \$idShop)") === 1
        && substr_count($src, "getPageLink('history', true, \$idLang, null, false, \$idShopManual)") === 1,
        "send()/scheduleManual() ne passent plus tous les deux id_shop au 6e argument de getPageLink() pour {history_url} — régression du bug corrigé le 06/08/2026 (round 75)"
    );

    return [
        'pass'    => true,
        'message' => "findCustomer() renvoie id_shop, resolveShopUrl() ne fuite pas le contexte, et send()/scheduleManual() résolvent bien {shop_url}/{history_url} d'après la boutique du client",
    ];
}
