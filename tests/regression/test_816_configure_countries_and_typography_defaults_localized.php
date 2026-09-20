<?php
/**
 * Bloc 7 (19/09/2026) — suite du balayage en anglais sur ps-test :
 *  1. Onglet Configurer, « Pays cibles » : ConfigManager::getAllCountries() est une liste mondiale FIGÉE en
 *     français (« Afrique du Sud, Allemagne, Algérie… ») affichée telle quelle dans un BO anglais.
 *  2. Onglet Typographie : le bouton « ↺ Défauts » était écrit en dur (3 fois) alors que la clé
 *     design.reset_defaults_btn existait déjà (utilisée par l'onglet Design).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'ConfigManager'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }
    $orig = AdminTranslator::currentLang();
    try {
        AdminTranslator::setLang('fr');
        $fr = ConfigManager::getAllCountries();
        neria_assert(($fr['DE'] ?? '') === 'Allemagne' && ($fr['ZA'] ?? '') === 'Afrique du Sud', "liste FR : noms français attendus");
        neria_assert(count($fr) >= 190, "liste des pays anormalement courte (" . count($fr) . ")");

        if ((int) Language::getIdByIso('en') > 0) {
            AdminTranslator::setLang('en');
            $en = ConfigManager::getAllCountries();
            neria_assert(($en['DE'] ?? '') === 'Germany' && ($en['ZA'] ?? '') === 'South Africa' && ($en['FR'] ?? '') === 'France', "liste EN : noms anglais attendus, obtenu DE=" . ($en['DE'] ?? '?') . " ZA=" . ($en['ZA'] ?? '?'));
            neria_assert(array_keys($en) !== [] && count($en) === count($fr) && array_diff(array_keys($fr), array_keys($en)) === [], "la liste EN n'a pas les mêmes codes ISO que la liste FR (" . count($en) . " vs " . count($fr) . ")");
            $names = array_values($en);
            $sorted = $names;
            natcasesort($sorted);
            neria_assert(($en['AF'] ?? '') === 'Afghanistan' && array_search('Afghanistan', $names, true) < 5, "liste EN non triée par nom anglais");
        }

        // Langue sans langue PrestaShop installée : repli sur la liste française, jamais de liste vide.
        AdminTranslator::setLang('nl');
        neria_assert(count(ConfigManager::getAllCountries()) === count($fr), "langue sans équivalent PrestaShop : la liste doit rester complète");
    } finally {
        AdminTranslator::setLang($orig);
    }

    $tpl = (string) file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/typography.tpl');
    neria_assert(strpos($tpl, 'Défauts') === false, "typography.tpl contient encore « Défauts » en dur");
    neria_assert(substr_count($tpl, "design.reset_defaults_btn") === 3, "typography.tpl : 3 boutons « Défauts » traduits attendus");

    return ['pass' => true, 'message' => "liste des pays cibles suit la langue du BO (repli français complet) et boutons « Défauts » de Typographie traduits — bloc 7 (19/09/2026)"];
}
