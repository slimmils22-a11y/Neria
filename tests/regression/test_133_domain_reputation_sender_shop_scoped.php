<?php
/**
 * Régression : DomainReputationManager::getSenderDomain() doit résoudre
 * NERIA_SENDERS_JSON et PS_SHOP_EMAIL via $this->idShop explicite — comme
 * le reste du fichier (cache CONFIG_CACHE/CONFIG_LAST_CHECK déjà scopé).
 *
 * Bug réel corrigé le 08/08/2026 (round 129) : ces deux Configuration::get()
 * n'étaient PAS scopés, contrairement au cache qui l'est. Réaffecter
 * Context::getContext()->shop dans la boucle multi-boutique du cron
 * (neria.php) ne met PAS à jour Shop::$context_id_shop (seul
 * Shop::setContext() le fait) — Configuration::get() sans id_shop explicite
 * retombait donc sur la boutique "ambiante" figée au bootstrap du process.
 * Le cache était bien isolé par boutique, mais stockait la mauvaise donnée
 * source : le rapport SPF/DKIM/DMARC/RBL d'une boutique B pouvait concerner
 * le domaine expéditeur d'une boutique A.
 *
 * Test comportemental réel : deux valeurs PS_SHOP_EMAIL différentes sur deux
 * id_shop différents (le vrai + un factice), vérifie que getSenderDomain()
 * appelée avec idShop=factice résout bien le domaine de CE shop, pas celui
 * du contexte réel actif.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $realShop = (int) Context::getContext()->shop->id;
    $fakeShop = $realShop + 555;

    $module = neria_test_module();
    require_once _PS_MODULE_DIR_ . 'neria/src/DomainReputationManager.php';

    Configuration::updateValue('PS_SHOP_EMAIL', 'contact@boutique-reelle-regtest.example.com', false, null, $realShop);
    Configuration::updateValue('PS_SHOP_EMAIL', 'contact@boutique-factice-regtest.example.com', false, null, $fakeShop);

    try {
        $mgr = new DomainReputationManager($module);
        $refIdShop = new ReflectionProperty($mgr, 'idShop');
        $refIdShop->setAccessible(true);
        $refIdShop->setValue($mgr, $fakeShop);

        $refMethod = new ReflectionMethod($mgr, 'getSenderDomain');
        $refMethod->setAccessible(true);
        $domain = $refMethod->invoke($mgr);

        neria_assert(
            strpos($domain, 'boutique-factice-regtest') !== false,
            "getSenderDomain() a résolu '{$domain}' au lieu du domaine de la boutique factice (idShop={$fakeShop}) — régression du bug corrigé le 08/08/2026 (round 129) : le contexte statique Shop::\$context_id_shop reprend le dessus sur l'idShop explicite du manager"
        );

        return [
            'pass'    => true,
            'message' => "DomainReputationManager::getSenderDomain() résout toujours le domaine expéditeur via l'idShop explicite du manager, pas le contexte ambiant",
        ];
    } finally {
        Db::getInstance()->execute(
            "DELETE FROM " . neria_test_prefix() . "configuration WHERE name='PS_SHOP_EMAIL' AND id_shop={$fakeShop}"
        );
    }
}
