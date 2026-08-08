<?php
/**
 * Régression : SeoApiManager::getLastError()/getLastErrorAt()/recordError()/
 * clearError() doivent tous passer par cacheKey() (scopé par boutique),
 * comme CONFIG_CACHE/CONFIG_CACHE_TIME — pas être globaux à l'installation.
 *
 * Bug réel corrigé le 08/08/2026 (round 133) : contrairement au cache SEO
 * (déjà scopé par boutique, avec un commentaire documentant un bug
 * antérieur de fuite cross-boutique), l'état d'erreur (NERIA_SEO_API_LAST_ERROR)
 * restait global. Sur une install multi-boutiques, une erreur de la
 * Boutique A (ex. "aucune donnée CSV pour ce domaine") pouvait être
 * effacée par un succès de la Boutique B (clearError() appelé après un
 * runCheck() réussi ailleurs), ou inversement une erreur de B s'afficher à
 * tort dans le tableau de bord de A.
 *
 * Test comportemental réel : deux boutiques, pose une erreur sur chacune
 * via recordError() (réflexion), vérifie que chaque boutique lit bien SA
 * propre erreur via getLastError()/getLastErrorAt(), et que clearError()
 * sur une boutique n'efface pas l'erreur de l'autre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeoApiManager.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;

    $originalContext = Shop::getContextShopID(true);

    $record = new ReflectionMethod(SeoApiManager::class, 'recordError');
    $record->setAccessible(true);
    $clear = new ReflectionMethod(SeoApiManager::class, 'clearError');
    $clear->setAccessible(true);

    try {
        Context::getContext()->shop = new Shop($idShopA);
        $mgrA = new SeoApiManager(neria_test_module());
        $record->invoke($mgrA, 'erreur_boutique_A');

        if ($idShopB !== $idShopA) {
            Context::getContext()->shop = new Shop($idShopB);
            $mgrB = new SeoApiManager(neria_test_module());
            $record->invoke($mgrB, 'erreur_boutique_B');

            Context::getContext()->shop = new Shop($idShopA);
            $mgrA2 = new SeoApiManager(neria_test_module());
            neria_assert(
                $mgrA2->getLastError() === 'erreur_boutique_A',
                "SeoApiManager::getLastError() de la boutique A ('{$mgrA2->getLastError()}') a été écrasé par l'erreur de la boutique B — régression du bug corrigé le 08/08/2026 (round 133) : CONFIG_LAST_ERROR redeviendrait global au lieu d'être scopé par boutique"
            );

            // clearError() sur B ne doit pas effacer l'erreur de A.
            Context::getContext()->shop = new Shop($idShopB);
            $mgrB2 = new SeoApiManager(neria_test_module());
            $clear->invoke($mgrB2);

            Context::getContext()->shop = new Shop($idShopA);
            $mgrA3 = new SeoApiManager(neria_test_module());
            neria_assert(
                $mgrA3->getLastError() === 'erreur_boutique_A',
                "clearError() appelé sur la boutique B a effacé l'erreur de la boutique A — régression du bug corrigé le 08/08/2026 (round 133) : les deux boutiques partagent encore le même état d'erreur global"
            );
        } else {
            neria_assert($mgrA->getLastError() === 'erreur_boutique_A', "getLastError() n'a pas résolu l'erreur attendue sur boutique unique");
        }
    } finally {
        foreach (array_unique([$idShopA, $idShopB]) as $s) {
            Configuration::deleteByName('NERIA_SEO_API_LAST_ERROR_' . $s);
            Configuration::deleteByName('NERIA_SEO_API_LAST_ERROR_AT_' . $s);
        }
        Context::getContext()->shop = new Shop($originalContext);
    }

    return [
        'pass'    => true,
        'message' => "SeoApiManager::getLastError()/clearError() sont bien scopés par boutique via cacheKey(), une erreur de boutique A n'est plus effacée/mélangée par un événement sur boutique B",
    ];
}
