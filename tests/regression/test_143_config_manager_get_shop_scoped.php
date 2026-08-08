<?php
/**
 * Régression : ConfigManager::get() doit transmettre $this->idShop en 4e
 * argument à Configuration::get(), pas se fier au contexte statique
 * ambiant (Shop::$context_id_shop, jamais mis à jour par une réassignation
 * de Context->shop dans une boucle multi-boutique).
 *
 * Bug réel corrigé le 08/08/2026 (round 132) : un ConfigManager instancié
 * à l'intérieur de la boucle multi-boutique de
 * BehavioralCronManager::run() (ex. sendBirthdays()) pouvait lire les
 * réglages (bon de fidélité, plafond, signature, réseaux sociaux) d'une
 * AUTRE boutique que celle réellement traitée — le contexte statique
 * restant figé sur la boutique visitée au bootstrap du process PHP.
 *
 * Test comportemental réel : deux boutiques avec une valeur différente
 * pour la même clé de config, vérifie que ConfigManager::get() résout
 * bien la valeur de la boutique demandée au constructeur, indépendamment
 * du contexte d'exécution ambiant.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;

    $key = 'NERIA_TEST_CFG_SCOPE_132';
    $originalContext = Shop::getContextShopID(true);

    // Pose une valeur différente sur chaque boutique (globale si une seule
    // boutique existe sur cet environnement — le test reste valide, il
    // vérifie alors simplement que get() ne casse rien).
    Configuration::updateValue($key, 'value_shop_A', false, null, $idShopA);
    if ($idShopB !== $idShopA) {
        Configuration::updateValue($key, 'value_shop_B', false, null, $idShopB);
    }

    try {
        // Simule le piège réel : le contexte d'exécution "ambiant" reste sur
        // shop A (comme le contexte HTTP d'origine d'un process cron), mais
        // on demande explicitement les réglages de shop B au constructeur.
        Context::getContext()->shop = new Shop($idShopA);

        $cfgB = new ConfigManager(neria_test_module());
        $refIdShop = new ReflectionProperty('ConfigManager', 'idShop');
        $refIdShop->setAccessible(true);
        $refIdShop->setValue($cfgB, $idShopB);

        $resolved = $cfgB->get($key);

        if ($idShopB !== $idShopA) {
            neria_assert(
                $resolved === 'value_shop_B',
                "ConfigManager::get() a résolu '{$resolved}' au lieu de 'value_shop_B' — régression du bug corrigé le 08/08/2026 (round 132) : Configuration::get() ne reçoit plus l'idShop explicite, la valeur du contexte ambiant (shop A) pollue la lecture de shop B"
            );
        } else {
            neria_assert($resolved === 'value_shop_A', "ConfigManager::get() n'a pas résolu la valeur attendue sur boutique unique");
        }
    } finally {
        Configuration::deleteByName($key);
        Context::getContext()->shop = new Shop($originalContext);
    }

    // Vérification structurelle complémentaire : le 4e argument doit être
    // présent dans le code source, pas seulement fonctionner par coïncidence
    // sur cet environnement mono-boutique.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
    neria_assert(
        strpos($src, '\Configuration::get($key, null, null, $this->idShop)') !== false,
        "ConfigManager::get() n'appelle plus Configuration::get() avec le 4e argument \$this->idShop — régression du bug corrigé le 08/08/2026 (round 132)"
    );

    return [
        'pass'    => true,
        'message' => "ConfigManager::get() résout bien la configuration via l'idShop explicite de l'instance, pas le contexte d'exécution ambiant",
    ];
}
