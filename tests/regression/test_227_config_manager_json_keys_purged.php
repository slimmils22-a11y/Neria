<?php
/**
 * Régression : ConfigManager::DEFAULTS doit inclure KEY_FIRSTNAME_FALLBACKS,
 * KEY_TIME_GREETINGS et KEY_TARGET_COUNTRIES, sinon deleteAll()
 * (désinstallation/réinitialisation complète) laisse ces 3 clés JSON
 * orphelines en base.
 *
 * Bug réel corrigé le 09/08/2026 (round 149) : ces 3 clés (chacune avec son
 * propre getter/setter — getFirstnameFallbacks()/getTimeGreetings()/
 * getTargetCountries()) étaient déclarées comme constantes mais absentes du
 * tableau DEFAULTS — même oubli déjà corrigé au round 141 pour
 * KEY_DEEPL_KEY et 4 toggles booléens. Une réinstallation ultérieure du
 * module retrouvait silencieusement les anciennes valeurs personnalisées
 * au lieu de repartir sur un état neuf.
 *
 * Test structurel + comportemental réel (même méthode que test_181) : vérifie
 * la présence dans DEFAULTS, puis pose une vraie valeur via SQL brut et
 * appelle deleteAll() pour confirmer qu'elle est effectivement supprimée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $keys = [
        ConfigManager::KEY_FIRSTNAME_FALLBACKS,
        ConfigManager::KEY_TIME_GREETINGS,
        ConfigManager::KEY_TARGET_COUNTRIES,
    ];

    foreach ($keys as $key) {
        neria_assert(
            array_key_exists($key, ConfigManager::DEFAULTS),
            "ConfigManager::DEFAULTS n'inclut plus la clé {$key} — régression du bug corrigé le 09/08/2026 (round 149) : deleteAll() la laisserait de nouveau orpheline en base après désinstallation"
        );
    }

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) \Context::getContext()->shop->id;

    try {
        foreach ($keys as $key) {
            $db->execute(
                "INSERT INTO {$prefix}configuration (id_shop_group, id_shop, name, value, date_add, date_upd)
                 VALUES (NULL, {$idShop}, '" . pSQL($key) . "', '[\"regtest149\"]', NOW(), NOW())"
            );
        }

        $config = new ConfigManager($module);
        $config->deleteAll();

        foreach ($keys as $key) {
            $remaining = $db->getRow(
                "SELECT `value` FROM {$prefix}configuration WHERE name = '" . pSQL($key) . "' AND id_shop = {$idShop}"
            );
            neria_assert(
                $remaining === false,
                "la clé {$key} n'a pas été purgée par deleteAll() (ligne restante : " . var_export($remaining, true) . ") — régression du bug corrigé le 09/08/2026 (round 149)"
            );
        }
    } finally {
        foreach ($keys as $key) {
            $db->execute("DELETE FROM {$prefix}configuration WHERE name = '" . pSQL($key) . "' AND id_shop = {$idShop}");
        }
    }

    return [
        'pass'    => true,
        'message' => "KEY_FIRSTNAME_FALLBACKS/KEY_TIME_GREETINGS/KEY_TARGET_COUNTRIES sont bien présentes dans DEFAULTS et correctement purgées par deleteAll()",
    ];
}
