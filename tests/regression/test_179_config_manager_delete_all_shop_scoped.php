<?php
/**
 * Régression : ConfigManager::deleteAll() doit être scopé à la boutique
 * courante, pas global.
 *
 * Bug réel corrigé le 09/08/2026 (round 141) : deleteAll() appelait
 * Configuration::deleteByName() pour chaque clé — cette méthode PS core
 * supprime la ligne SANS AUCUN filtre id_shop, contrairement à get()/set()
 * de cette même classe qui passent explicitement $this->idShop. Sur une
 * install multi-boutique, réinitialiser Neria depuis une boutique effaçait
 * silencieusement la config des AUTRES boutiques.
 *
 * Test comportemental réel : pose DEUX lignes ps_configuration via SQL brut
 * (boutique courante + boutique "étrangère" simulée), sans passer par
 * Configuration::updateValue() — sur une installation mono-boutique
 * (Shop::isFeatureActive() === false), celle-ci force silencieusement tout
 * $id_shop explicite à NULL (config globale, comportement PS core normal
 * en mono-boutique — cf. Shop::getContextShopID(true)), ce qui rendrait
 * impossible de distinguer les deux boutiques dans ce test. deleteAll()
 * lui-même n'a PAS ce comportement (Configuration::deleteFromContext()
 * respecte l'id_shop explicite quel que soit l'état de la fonctionnalité
 * multi-boutique), donc le scénario reste représentatif du vrai correctif.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $key         = 'NERIA_CARBON_LINK';
    $idShopOwn   = (int) \Context::getContext()->shop->id;
    $idShopOther = $idShopOwn + 1000; // n'a pas besoin d'exister réellement dans ps_shop

    try {
        $db->execute(
            "INSERT INTO {$prefix}configuration (id_shop_group, id_shop, name, value, date_add, date_upd)
             VALUES (NULL, {$idShopOwn}, '{$key}', 'https://own-shop.example/carbon', NOW(), NOW())"
        );
        $db->execute(
            "INSERT INTO {$prefix}configuration (id_shop_group, id_shop, name, value, date_add, date_upd)
             VALUES (NULL, {$idShopOther}, '{$key}', 'https://other-shop.example/carbon', NOW(), NOW())"
        );

        $config = new ConfigManager($module);
        $config->deleteAll();

        $rowOwn = $db->getRow(
            "SELECT `value` FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShopOwn}"
        );
        $rowOther = $db->getRow(
            "SELECT `value` FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShopOther}"
        );

        neria_assert(
            $rowOwn === false,
            "la ligne de la boutique courante (id_shop={$idShopOwn}) n'a pas été supprimée par deleteAll() — jeu de test invalide"
        );
        neria_assert(
            $rowOther !== false && $rowOther['value'] === 'https://other-shop.example/carbon',
            "La ligne de config de la boutique étrangère (id_shop={$idShopOther}) a été effacée par un deleteAll() déclenché depuis la boutique courante (id_shop={$idShopOwn}) — régression du bug corrigé le 09/08/2026 (round 141) : deleteAll() doit rester scopé à la boutique courante"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop IN ({$idShopOwn}, {$idShopOther})");
    }

    return [
        'pass'    => true,
        'message' => "ConfigManager::deleteAll() est bien scopé à la boutique courante — la config d'une autre boutique survit",
    ];
}
