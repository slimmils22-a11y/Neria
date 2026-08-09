<?php
/**
 * Régression : ConfigManager::DEFAULTS doit inclure KEY_DEEPL_KEY, sinon
 * deleteAll() (désinstallation/réinitialisation complète) laisse une clé
 * API tierce orpheline en base.
 *
 * Bug réel corrigé le 09/08/2026 (round 141) : KEY_DEEPL_KEY était déclarée
 * comme constante mais absente du tableau DEFAULTS — deleteAll() itère
 * uniquement sur array_keys(DEFAULTS), donc la clé DeepL API n'était jamais
 * purgée, contrairement aux 4 toggles déjà corrigés pour le même oubli
 * (round antérieur, cf. commentaire ligne ~192 du fichier).
 *
 * Test structurel + comportemental réel : vérifie que la constante est bien
 * présente dans DEFAULTS, puis pose une vraie valeur via SQL brut (voir
 * test_179 pour la raison : Configuration::updateValue() force
 * silencieusement id_shop à NULL sur une installation mono-boutique — SQL
 * brut donne un contrôle direct sur la ligne testée) et appelle deleteAll()
 * pour confirmer qu'elle est effectivement supprimée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    neria_assert(
        array_key_exists(ConfigManager::KEY_DEEPL_KEY, ConfigManager::DEFAULTS),
        "ConfigManager::KEY_DEEPL_KEY (" . ConfigManager::KEY_DEEPL_KEY . ") est absente de ConfigManager::DEFAULTS — régression du bug corrigé le 09/08/2026 (round 141) : deleteAll() laisserait de nouveau cette clé API orpheline en base après désinstallation"
    );

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $module = neria_test_module();
    $key    = ConfigManager::KEY_DEEPL_KEY;
    $idShop = (int) \Context::getContext()->shop->id;

    try {
        $db->execute(
            "INSERT INTO {$prefix}configuration (id_shop_group, id_shop, name, value, date_add, date_upd)
             VALUES (NULL, {$idShop}, '{$key}', 'sk-test-fake-key-round141', NOW(), NOW())"
        );

        $config = new ConfigManager($module);
        $config->deleteAll();

        $remaining = $db->getRow(
            "SELECT `value` FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShop}"
        );

        neria_assert(
            $remaining === false,
            "la clé DeepL API n'a pas été purgée par deleteAll() (ligne restante : " . var_export($remaining, true) . ")"
        );
    } finally {
        $db->execute("DELETE FROM {$prefix}configuration WHERE name = '{$key}' AND id_shop = {$idShop}");
    }

    return [
        'pass'    => true,
        'message' => "KEY_DEEPL_KEY est bien présente dans DEFAULTS et correctement purgée par deleteAll()",
    ];
}
