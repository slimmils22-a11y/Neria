<?php
/**
 * Bloc 5 (19/09/2026) : les paliers de fidélité par défaut portaient les noms
 * français « Argent »/« Or » quelle que soit la langue de la boutique —
 * affichés dans le BO (champ prérempli) et envoyés aux clients dans les
 * emails de palier (variable {new_tier_name}) et noms de bons de réduction.
 * Neria est vendu dans le monde entier ; une boutique anglaise ou japonaise
 * envoyait « Argent » à ses clients tant que le marchand ne renommait rien.
 *
 * Corrigé : getTiers() (repli sans configuration enregistrée) renvoie les
 * noms dans la langue par défaut de la boutique (19 langues, repli anglais).
 * Les paliers DÉJÀ enregistrés par un marchand ne sont jamais modifiés.
 *
 * Test comportemental réel : (1) pour chacune des 19 langues, les 3 noms sont
 * non vides et distincts, et seules 'fr' garde « Argent »/« Or » ; (2) un
 * getTiers() réel sans configuration renvoie les noms de la langue de la
 * boutique ; (3) une configuration marchande enregistrée reste intacte.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php';

    $langs = ['fr','en','gb','de','it','es','pt','br','ar','ja','ko','zh','tw','ru','tr','sv','no','da','nl'];
    foreach ($langs as $l) {
        $t = LoyaltyManager::defaultTiersForLang($l);
        neria_assert(count($t) === 3, "{$l} : 3 paliers attendus");
        $names = array_column($t, 'name');
        neria_assert(count(array_unique($names)) === 3 && !in_array('', $names, true), "{$l} : noms vides ou dupliqués (" . implode('/', $names) . ")");
        if ($l !== 'fr') {
            neria_assert($names[1] !== 'Argent' && $names[2] !== 'Or', "{$l} : garde les noms français Argent/Or");
        }
    }
    neria_assert(LoyaltyManager::defaultTiersForLang('xx')[1]['name'] === 'Silver', "langue inconnue : repli anglais attendu");
    neria_assert(LoyaltyManager::defaultTiersForLang('fr')[2]['name'] === 'Or', "fr doit rester Argent/Or");

    // getTiers() réel : pour CHAQUE langue installée, la langue par défaut de
    // la boutique est basculée temporairement (restaurée ensuite) — sans cela
    // le test passerait à tort sur une boutique dont la langue par défaut est
    // déjà le français (les noms de repli historiques).
    $module   = neria_test_module();
    $mgr      = new LoyaltyManager($module);
    $oldTiers = \Configuration::get(LoyaltyManager::CONFIG_TIERS);
    $oldLang  = \Configuration::get('PS_LANG_DEFAULT');
    $checked  = [];
    try {
        \Configuration::updateValue(LoyaltyManager::CONFIG_TIERS, '');
        foreach (\Language::getLanguages(false) as $lg) {
            \Configuration::updateGlobalValue('PS_LANG_DEFAULT', (int) $lg['id_lang']);
            $iso  = strtolower((string) $lg['language_code']);
            $code = match ($iso) { 'pt-br' => 'br', 'zh-tw' => 'tw', 'en-gb' => 'gb', 'zh-cn' => 'zh', default => substr($iso, 0, 2) };
            $exp  = array_column(LoyaltyManager::defaultTiersForLang($code), 'name');
            neria_assert(array_column($mgr->getTiers(), 'name') === $exp, "getTiers() sans configuration doit renvoyer les noms de la langue par défaut ({$code})");
            $checked[] = $code;
        }
        neria_assert(count(array_diff($checked, ['fr'])) >= 1, "aucune langue non française installée — test invalide (langues : " . implode(',', $checked) . ")");

        $custom = [['key' => 'bronze', 'name' => 'Cuivre', 'points' => 10, 'amount' => 1, 'is_percent' => false]];
        \Configuration::updateValue(LoyaltyManager::CONFIG_TIERS, json_encode($custom));
        neria_assert($mgr->getTiers()[0]['name'] === 'Cuivre', "un palier personnalisé enregistré ne doit jamais être remplacé");
    } finally {
        \Configuration::updateGlobalValue('PS_LANG_DEFAULT', (int) $oldLang);
        \Configuration::updateValue(LoyaltyManager::CONFIG_TIERS, (string) $oldTiers);
    }

    return ['pass' => true, 'message' => "noms de palier par défaut localisés dans les 19 langues (plus « Argent »/« Or » en dur), configuration marchande intacte — bloc 5 (19/09/2026)"];
}
