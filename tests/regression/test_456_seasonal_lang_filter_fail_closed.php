<?php
/**
 * Régression : SeasonalCampaignManager::getEligibleCustomers() abandonnait
 * silencieusement le filtre langue si AUCUN code ISO de target_lang ne
 * correspondait à une langue installée (ex. langue désinstallée après la
 * configuration de la campagne) — au lieu d'exclure tous les clients
 * (comportement attendu), la requête retombait sur TOUS les clients de
 * toutes langues.
 *
 * Corrigé le 26/08/2026 (round 218) : fail-closed via
 * 'c.id_lang IN (0)', qui ne matche jamais aucun client réel.
 *
 * Test comportemental réel : une campagne ciblant un code langue
 * inexistant ('xx') ne doit renvoyer AUCUN client éligible, même si des
 * clients réels existent en base.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SeasonalCampaignManager.php';

    $mgr = new SeasonalCampaignManager(neria_test_module());

    $campaign = [
        'target_segment' => '',
        'target_gender'  => 0,
        'target_lang'    => 'xx',
        'min_age'        => 0,
        'max_age'        => 0,
    ];

    $refMethod = new ReflectionMethod($mgr, 'getEligibleCustomers');
    $refMethod->setAccessible(true);
    $eligible = $refMethod->invoke($mgr, $campaign);

    neria_assert(
        is_array($eligible) && count($eligible) === 0,
        "getEligibleCustomers() avec un code langue inexistant ('xx') a retourné " . (is_array($eligible) ? count($eligible) : 'non-array') . " client(s) au lieu de 0 — régression du bug corrigé le 26/08/2026 (round 218) : le filtre langue redeviendrait silencieusement ignoré, ciblant TOUS les clients de toutes langues au lieu de personne"
    );

    return [
        'pass'    => true,
        'message' => "SeasonalCampaignManager::getEligibleCustomers() exclut bien tous les clients quand aucun code langue de la campagne ne correspond à une langue installée — bug corrigé le 26/08/2026 (round 218)",
    ];
}
