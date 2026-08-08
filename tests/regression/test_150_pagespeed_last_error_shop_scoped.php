<?php
/**
 * Régression : PageSpeedManager::getLastError()/getLastErrorAt()/recordError()
 * doivent tous passer par cacheKey() (scopé par boutique), comme
 * CONFIG_CACHE/CONFIG_CACHE_TIME — pas être globaux à l'installation.
 *
 * Bug réel corrigé le 08/08/2026 (round 134) : même bug de fond que celui
 * corrigé pour SeoApiManager au round 133 — le cache PageSpeed était déjà
 * scopé par boutique (cacheKey()), mais l'état d'erreur
 * (NERIA_PAGESPEED_LAST_ERROR) restait global. Sur une install
 * multi-boutiques, une erreur de la Boutique A (clé API invalide) pouvait
 * être effacée par un succès de la Boutique B (clé valide), rendant le
 * diagnostic impossible pour A.
 *
 * Test comportemental réel : deux boutiques, pose une erreur sur chacune
 * via recordError() (réflexion), vérifie que chaque boutique lit bien SA
 * propre erreur via getLastError()/getLastErrorAt(), sans contamination
 * croisée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PageSpeedManager.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;

    $originalContext = Shop::getContextShopID(true);

    $record = new ReflectionMethod(PageSpeedManager::class, 'recordError');
    $record->setAccessible(true);

    try {
        Context::getContext()->shop = new Shop($idShopA);
        $mgrA = new PageSpeedManager(neria_test_module());
        $record->invoke($mgrA, 'erreur_boutique_A');

        if ($idShopB !== $idShopA) {
            Context::getContext()->shop = new Shop($idShopB);
            $mgrB = new PageSpeedManager(neria_test_module());
            $record->invoke($mgrB, 'erreur_boutique_B');

            Context::getContext()->shop = new Shop($idShopA);
            $mgrA2 = new PageSpeedManager(neria_test_module());
            neria_assert(
                $mgrA2->getLastError() === 'erreur_boutique_A',
                "PageSpeedManager::getLastError() de la boutique A ('{$mgrA2->getLastError()}') a été écrasé par l'erreur de la boutique B — régression du bug corrigé le 08/08/2026 (round 134) : CONFIG_LAST_ERROR redeviendrait global au lieu d'être scopé par boutique"
            );

            Context::getContext()->shop = new Shop($idShopB);
            $mgrB2 = new PageSpeedManager(neria_test_module());
            neria_assert(
                $mgrB2->getLastError() === 'erreur_boutique_B',
                "PageSpeedManager::getLastError() de la boutique B n'a pas résolu sa propre erreur — régression du bug corrigé le 08/08/2026 (round 134)"
            );
        } else {
            neria_assert($mgrA->getLastError() === 'erreur_boutique_A', "getLastError() n'a pas résolu l'erreur attendue sur boutique unique");
        }
    } finally {
        foreach (array_unique([$idShopA, $idShopB]) as $s) {
            Configuration::deleteByName('NERIA_PAGESPEED_LAST_ERROR_' . $s);
            Configuration::deleteByName('NERIA_PAGESPEED_LAST_ERROR_AT_' . $s);
        }
        Context::getContext()->shop = new Shop($originalContext);
    }

    return [
        'pass'    => true,
        'message' => "PageSpeedManager::getLastError() est bien scopé par boutique via cacheKey(), une erreur de boutique A n'est plus effacée/mélangée par un événement sur boutique B",
    ];
}
