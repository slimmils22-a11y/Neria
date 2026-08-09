<?php
/**
 * Régression : TranslationHistoryManager::getHistoryForTemplate()/getById()
 * ne doivent plus filtrer par id_shop — la table neria_translation
 * (traductions réelles) n'a elle-même aucune colonne id_shop, une
 * modification de traduction est globale à toute l'installation.
 *
 * Bug réel corrigé le 08/08/2026 (round 138) : filtrer l'HISTORIQUE par
 * id_shop était trompeur — un marchand éditant une traduction depuis le
 * contexte boutique B modifiait la valeur globalement (visible pour
 * toutes les boutiques), mais l'entrée d'historique n'était visible/
 * restaurable que depuis ce même contexte B. Un opérateur consultant
 * l'historique depuis la boutique A ne voyait jamais ce changement, alors
 * qu'il affectait pourtant sa boutique aussi.
 *
 * Test comportemental réel : enregistre une entrée d'historique depuis le
 * contexte boutique B, vérifie qu'elle est bien visible en consultant
 * depuis le contexte boutique A.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationHistoryManager.php';

    $shops = Db::getInstance()->executeS('SELECT id_shop FROM `' . _DB_PREFIX_ . 'shop` WHERE active = 1 ORDER BY id_shop ASC');
    neria_assert(is_array($shops) && count($shops) >= 1, 'Aucune boutique active trouvée pour le test');

    $idShopA = (int) $shops[0]['id_shop'];
    $idShopB = count($shops) > 1 ? (int) $shops[1]['id_shop'] : $idShopA;
    $originalContext = Shop::getContextShopID(true);
    $testTemplate = 'neria_test_round138_hist';
    $testLang = 'fr';
    $testKey = 'test_key_round138';

    try {
        // Enregistre depuis le contexte boutique B.
        Context::getContext()->shop = new Shop($idShopB);
        $histMgrB = new TranslationHistoryManager();
        $histMgrB->record($testTemplate, $testLang, $testKey, 'ancienne valeur', 'nouvelle valeur B', 'Test Round138');

        // Consulte depuis le contexte boutique A.
        Context::getContext()->shop = new Shop($idShopA);
        $histMgrA = new TranslationHistoryManager();
        $entries = $histMgrA->getHistoryForTemplate($testTemplate, $testLang);

        neria_assert(
            !empty($entries),
            "getHistoryForTemplate() consulté depuis la boutique A ne voit plus l'entrée enregistrée depuis la boutique B — régression du bug corrigé le 08/08/2026 (round 138) : l'historique redeviendrait filtré par id_shop alors que la traduction elle-même est globale"
        );
        $found = false;
        foreach ($entries as $e) {
            if ($e['translation_key'] === $testKey && $e['new_value'] === 'nouvelle valeur B') {
                $found = true;
                break;
            }
        }
        neria_assert($found, "L'entrée enregistrée depuis la boutique B n'apparaît pas dans getHistoryForTemplate() consulté depuis la boutique A");
    } finally {
        $db = neria_test_db();
        $prefix = neria_test_prefix();
        $db->execute("DELETE FROM {$prefix}neria_translation_history WHERE template_key = '{$testTemplate}'");
        Context::getContext()->shop = new Shop($originalContext);
    }

    return [
        'pass'    => true,
        'message' => "TranslationHistoryManager::getHistoryForTemplate() n'est plus filtré par id_shop, une entrée d'historique reste visible depuis n'importe quelle boutique, cohérent avec le caractère global de neria_translation",
    ];
}
