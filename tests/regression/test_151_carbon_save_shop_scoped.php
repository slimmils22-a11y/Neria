<?php
/**
 * Régression : l'action BO save_carbon (neria.php) doit écrire
 * NERIA_CARBON_ENABLED/NERIA_CARBON_LINK via ConfigManager::set() (scopé
 * par idShop), pas Configuration::updateValue() en direct.
 *
 * Bug réel corrigé le 08/08/2026 (round 134) : contrairement à save_social
 * (juste après dans neria.php, qui passait déjà par ConfigManager),
 * save_carbon appelait Configuration::updateValue() directement sans
 * id_shop — alors que la lecture (isCarbonEnabled()/getCarbonLink() via
 * ConfigManager::get()) est scopée par $this->idShop depuis le round 132.
 * Le bloc CO₂ pouvait ne jamais apparaître (ou apparaître à tort) selon la
 * boutique réellement traitée à l'envoi.
 *
 * Test structurel (déclencher une vraie requête POST BO serait
 * disproportionné) : vérifie que neria.php utilise bien ConfigManager::set()
 * pour les deux clés carbone, pas Configuration::updateValue() en direct.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posAction = strpos($src, "'neria_action') === 'save_carbon'");
    neria_assert($posAction !== false, "Action save_carbon introuvable dans neria.php");

    $block = substr($src, $posAction, 900);

    neria_assert(
        strpos($block, '$carbonMgr->set(ConfigManager::KEY_CARBON_ENABLED') !== false
        && strpos($block, '$carbonMgr->set(ConfigManager::KEY_CARBON_LINK') !== false,
        "L'action save_carbon n'appelle plus ConfigManager::set() pour les deux clés carbone — régression du bug corrigé le 08/08/2026 (round 134)"
    );

    // Vérification comportementale complémentaire : ConfigManager::set()
    // lui-même doit toujours transmettre l'idShop (testé en détail par
    // test_147, revérifié ici en contexte carbone spécifique).
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');
    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;
    $originalContext = Shop::getContextShopID(true);
    $originalLinkB = (string) Configuration::get(ConfigManager::KEY_CARBON_LINK, null, null, $idShopB);

    try {
        Context::getContext()->shop = new Shop($idShopA);
        $cfgB = new ConfigManager(neria_test_module());
        $refIdShop = new ReflectionProperty('ConfigManager', 'idShop');
        $refIdShop->setAccessible(true);
        $refIdShop->setValue($cfgB, $idShopB);
        $cfgB->set(ConfigManager::KEY_CARBON_LINK, 'https://test-carbon-round134.example/');

        $storedOnB = (string) Configuration::get(ConfigManager::KEY_CARBON_LINK, null, null, $idShopB);
        neria_assert(
            $storedOnB === 'https://test-carbon-round134.example/',
            "ConfigManager::set(KEY_CARBON_LINK) n'a pas écrit sous l'idShop explicite de l'instance — le lien carbone pourrait de nouveau diverger entre boutiques"
        );
    } finally {
        // Restaure la valeur réelle du marchand plutôt que de la vider.
        Configuration::updateValue(ConfigManager::KEY_CARBON_LINK, $originalLinkB, false, null, $idShopB);
        Context::getContext()->shop = new Shop($originalContext);
    }

    return [
        'pass'    => true,
        'message' => "L'action BO save_carbon écrit bien via ConfigManager::set() (scopé idShop), cohérent avec la lecture isCarbonEnabled()/getCarbonLink()",
    ];
}
