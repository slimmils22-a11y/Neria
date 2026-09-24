<?php
/**
 * Régression (constat réel du 24/09/2026, P5 sur ps-test / O2switch) : avec `serialize_precision=100`
 * (réglage PHP de cet hébergeur), json_encode(19.12) produit
 * 19.120000000000000994759830064140260219573974609375. Les payloads de webhooks envoyés aux systèmes
 * externes (revenue, confidence) et les paliers de fidélité stockés (0.01 → 0.0100000000000000002…)
 * portaient ces chiffres parasites.
 *
 * Corrigé : NeriaTools::jsonEncode() impose serialize_precision=-1 (représentation la plus courte)
 * le temps de l'encodage puis restaure le réglage ; utilisé par WebhookManager (3 encodages) et
 * LoyaltyManager::saveTiers().
 *
 * Test comportemental : simule réellement serialize_precision=100, vérifie le témoin (json_encode brut
 * produit bien la forme longue), l'helper, un VRAI WebhookManager::trigger() mis en file, et
 * saveTiers() ; le réglage PHP est restauré.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $db = neria_test_db();
    $p  = neria_test_prefix();
    $module = neria_test_module();
    $idShop = (int) Context::getContext()->shop->id;
    $origPrecision = ini_get('serialize_precision');
    $origUrl = Configuration::get(WebhookManager::CONFIG_URL, null, null, $idShop);
    $origEvents = Configuration::get(WebhookManager::CONFIG_EVENTS, null, null, $idShop);
    $origTiers = Configuration::get(LoyaltyManager::CONFIG_TIERS);
    try {
        ini_set('serialize_precision', '100');
        neria_assert(strlen(json_encode(19.12)) > 20, "Témoin invalide : serialize_precision=100 n'a pas produit la forme longue (" . json_encode(19.12) . ")");

        neria_assert(NeriaTools::jsonEncode(19.12) === '19.12', 'NeriaTools::jsonEncode(19.12) = ' . NeriaTools::jsonEncode(19.12));
        neria_assert(NeriaTools::jsonEncode(['a' => 0.95, 'b' => 0.01]) === '{"a":0.95,"b":0.01}', 'Encodage de tableau inattendu');
        neria_assert(ini_get('serialize_precision') === '100', "Le réglage serialize_precision n'a pas été restauré après l'encodage");

        Configuration::updateValue(WebhookManager::CONFIG_URL, 'https://webhook.example.invalid/regtest848', false, null, $idShop);
        Configuration::updateValue(WebhookManager::CONFIG_EVENTS, '[]', false, null, $idShop);
        (new WebhookManager($module))->trigger('conversion', ['template' => 'regtest848', 'revenue' => 19.12, 'confidence' => 0.95]);
        $payload = (string) $db->getValue("SELECT payload FROM {$p}neria_webhook_queue WHERE payload LIKE '%regtest848%' ORDER BY id_webhook DESC", false);
        neria_assert($payload !== '', "Le webhook n'a pas été mis en file (jeu de test invalide)");
        neria_assert(
            strpos($payload, '"revenue":19.12,') !== false && strpos($payload, '"confidence":0.95,') !== false,
            "Payload de webhook avec chiffres parasites : {$payload} — régression du bug corrigé le 24/09/2026"
        );

        (new LoyaltyManager($module))->saveTiers([['key' => 'regtest', 'name' => 'R', 'points' => 1, 'amount' => 0.01, 'is_percent' => false]]);
        $stored = (string) Configuration::get(LoyaltyManager::CONFIG_TIERS);
        neria_assert(strpos($stored, '"amount":0.01,') !== false, "Paliers stockés avec chiffres parasites : {$stored}");
    } finally {
        ini_set('serialize_precision', (string) $origPrecision);
        $db->execute("DELETE FROM {$p}neria_webhook_queue WHERE payload LIKE '%regtest848%'");
        foreach ([[WebhookManager::CONFIG_URL, $origUrl], [WebhookManager::CONFIG_EVENTS, $origEvents]] as [$k, $v]) {
            if ($v === false || $v === null) { Configuration::deleteByName($k); } else { Configuration::updateValue($k, $v, false, null, $idShop); }
        }
        if ($origTiers === false || $origTiers === null || $origTiers === '') { Configuration::deleteByName(LoyaltyManager::CONFIG_TIERS); } else { Configuration::updateValue(LoyaltyManager::CONFIG_TIERS, $origTiers); }
    }

    // Caches de résultats décimaux : chaque manager doit passer par l'helper (structurel, comme le reste du fichier
    // l'exige : ces caches dépendent d'API externes et ne sont pas rejouables ici).
    foreach (['GoldenHourManager', 'PageSpeedManager', 'PostmasterManager', 'SearchConsoleManager', 'SeoApiManager', 'StatsManager', 'DomainReputationManager'] as $file) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/' . $file . '.php');
        neria_assert(strpos((string) $src, 'NeriaTools::jsonEncode(') !== false, "{$file} n'utilise plus NeriaTools::jsonEncode() pour ses résultats décimaux — régression du 24/09/2026");
    }

    return [
        'pass'    => true,
        'message' => "Avec serialize_precision=100 (O2switch), les payloads de webhooks (revenue 19.12, confidence 0.95) et les paliers de fidélité (0.01) sont encodés sans chiffres parasites, et le réglage PHP est restauré — bug corrigé le 24/09/2026",
    ];
}
