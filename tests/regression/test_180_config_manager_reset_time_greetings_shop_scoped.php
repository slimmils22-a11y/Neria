<?php
/**
 * Régression : ConfigManager::resetTimeGreetings(null) doit être scopé à la
 * boutique courante, même piège que deleteAll() (test_179).
 *
 * Bug réel corrigé le 09/08/2026 (round 141) : resetTimeGreetings(null)
 * appelait Configuration::deleteByName(KEY_TIME_GREETINGS), qui supprime la
 * ligne pour TOUTES les boutiques. Réinitialiser "toutes les langues" pour
 * une boutique effaçait aussi la personnalisation des salutations horaires
 * des autres boutiques.
 *
 * Setup via SQL brut pour les deux boutiques — voir le commentaire détaillé
 * du test_179 : Configuration::updateValue() force silencieusement tout
 * $id_shop explicite à NULL sur une installation mono-boutique.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();

    $key         = 'NERIA_TIME_GREETINGS';
    $idShopOwn   = (int) \Context::getContext()->shop->id;
    $idShopOther = $idShopOwn + 1000;

    $valOwn   = pSQL(json_encode(['fr' => ['morning' => 'Bonjour own']]));
    $valOther = pSQL(json_encode(['fr' => ['morning' => 'Bonjour other']]));

    try {
        $db->execute(
            "INSERT INTO {$prefix}configuration (id_shop_group, id_shop, name, value, date_add, date_upd)
             VALUES (NULL, {$idShopOwn}, '{$key}', '{$valOwn}', NOW(), NOW())"
        );
        $db->execute(
            "INSERT INTO {$prefix}configuration (id_shop_group, id_shop, name, value, date_add, date_upd)
             VALUES (NULL, {$idShopOther}, '{$key}', '{$valOther}', NOW(), NOW())"
        );

        $config = new ConfigManager($module);
        $result = $config->resetTimeGreetings(null);
        neria_assert($result === true, "resetTimeGreetings(null) n'a pas renvoyé true sur un jeu de test valide");

        $rowOwn = $db->getRow(
            "SELECT `value` FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShopOwn}"
        );
        $rowOther = $db->getRow(
            "SELECT `value` FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShopOther}"
        );

        neria_assert(
            $rowOwn === false,
            "la boutique courante (id_shop={$idShopOwn}) conserve encore sa ligne de config après resetTimeGreetings(null) — jeu de test invalide"
        );
        neria_assert(
            $rowOther !== false && strpos((string) $rowOther['value'], 'Bonjour other') !== false,
            "la salutation horaire de la boutique étrangère (id_shop={$idShopOther}) a été effacée par resetTimeGreetings(null) déclenché depuis la boutique courante — régression du bug corrigé le 09/08/2026 (round 141)"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop IN ({$idShopOwn}, {$idShopOther})");
    }

    return [
        'pass'    => true,
        'message' => "ConfigManager::resetTimeGreetings(null) est bien scopé à la boutique courante",
    ];
}
