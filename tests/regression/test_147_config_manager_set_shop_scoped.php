<?php
/**
 * Régression : ConfigManager::set() doit transmettre $this->idShop en 5e
 * argument à Configuration::updateValue(), symétriquement à get() (round
 * 132) — pas se fier au contexte statique ambiant.
 *
 * Bug réel corrigé le 08/08/2026 (round 133) : trouvé via
 * NERIA_SENDERS_JSON (multi-expéditeur par langue) — la lecture
 * (ConfigManager::get()) était scopée par idShop depuis le round 132, mais
 * l'écriture (ConfigManager::set()) ne l'était pas, laissant une asymétrie
 * lecture/écriture latente pour tout futur appelant en boucle
 * multi-boutique.
 *
 * Test comportemental réel : deux boutiques, écrit une valeur différente
 * sur chacune via ConfigManager::set() (idShop forcé par réflexion), puis
 * vérifie via Configuration::get(..., $idShop) que chaque boutique a bien
 * reçu SA valeur, pas celle de l'autre.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;

    $key = 'NERIA_TEST_CFG_SET_133';
    $originalContext = Shop::getContextShopID(true);

    try {
        // Le contexte d'exécution ambiant reste sur shop A, mais on force
        // l'instance ConfigManager à cibler shop B — reproduit le piège
        // Shop::$context_id_shop (contexte figé, jamais mis à jour par une
        // réassignation de Context->shop).
        Context::getContext()->shop = new Shop($idShopA);

        $cfgB = new ConfigManager(neria_test_module());
        $refIdShop = new ReflectionProperty('ConfigManager', 'idShop');
        $refIdShop->setAccessible(true);
        $refIdShop->setValue($cfgB, $idShopB);

        $cfgB->set($key, 'value_from_shop_B_instance');

        $storedOnB = (string) Configuration::get($key, null, null, $idShopB);
        neria_assert(
            $storedOnB === 'value_from_shop_B_instance',
            "ConfigManager::set() n'a pas écrit la valeur sous l'idShop explicite de l'instance (\$idShopB={$idShopB}) — régression du bug corrigé le 08/08/2026 (round 133) : Configuration::updateValue() ne reçoit plus l'idShop explicite, l'écriture pollue le contexte ambiant au lieu de la boutique ciblée"
        );

        if ($idShopB !== $idShopA) {
            $storedOnA = (string) Configuration::get($key, null, null, $idShopA);
            neria_assert(
                $storedOnA !== 'value_from_shop_B_instance',
                "ConfigManager::set() a écrit sous shop A (le contexte ambiant) au lieu de shop B (l'idShop explicite de l'instance) — régression du bug corrigé le 08/08/2026 (round 133)"
            );
        }
    } finally {
        Configuration::deleteByName($key);
        Context::getContext()->shop = new Shop($originalContext);
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
    neria_assert(
        strpos($src, '\Configuration::updateValue($key, $value, false, null, $this->idShop)') !== false,
        "ConfigManager::set() n'appelle plus Configuration::updateValue() avec le 5e argument \$this->idShop explicite — régression du bug corrigé le 08/08/2026 (round 133)"
    );

    return [
        'pass'    => true,
        'message' => "ConfigManager::set() écrit bien la configuration via l'idShop explicite de l'instance, symétriquement à get() (round 132)",
    ];
}
