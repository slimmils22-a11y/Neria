<?php
/**
 * Régression : ClvManager::getTopCustomers() doit détecter (et journaliser
 * via Watchdog) le cas où le nombre réel de clients candidats dépasse le
 * pool de 200 pré-sélectionnés par CA brut — même famille de correctif que
 * CalendarManager/SegmentManager (round 69, plafond LIMIT sans détection).
 *
 * Bug réel corrigé le 06/08/2026 (round 70) : le tri de pré-sélection se
 * fait par CA BRUT, un simple proxy du vrai CLV (calculé ensuite avec des
 * multiplicateurs engagement/segment/churn pouvant aller de ×0.33 à ×1.5).
 * Un client hors des 200 premiers en CA brut mais au profil très favorable
 * peut légitimement avoir un CLV réel supérieur à un client présent dans le
 * pool — il était alors exclu du Top N AVANT même que sa vraie CLV ne soit
 * calculée, sans que l'admin n'en soit jamais informé.
 *
 * Test structurel (créer 200+ vraies commandes de test serait un effet de
 * bord disproportionné sur les données de l'environnement partagé, comme
 * pour test_72) : vérifie au niveau du code source que la détection
 * (COUNT total, comparaison > 200, log Watchdog) est bien en place.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ClvManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ClvManager.php');

    neria_assert(
        strpos($src, 'SELECT COUNT(DISTINCT o.`id_customer`)') !== false,
        "getTopCustomers() ne compte plus le nombre réel de candidats — régression du bug corrigé le 06/08/2026 (round 70) : le dépassement du pool de 200 ne serait plus détectable"
    );

    neria_assert(
        strpos($src, 'if ($totalCandidates > 200) {') !== false,
        "getTopCustomers() ne compare plus le total réel de candidats au pool de 200"
    );

    neria_assert(
        strpos($src, "\\WatchdogManager::i18nMsg('watchdog.clv_top_pool_capped'") !== false,
        "getTopCustomers() ne journalise plus d'avertissement Watchdog quand le pool de 200 candidats est dépassé"
    );

    $dict = json_decode(file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($dict), 'Impossible de décoder data/admin_translations.json');
    neria_assert(
        isset($dict['watchdog.clv_top_pool_capped']) && count($dict['watchdog.clv_top_pool_capped']) === 19,
        "la clé watchdog.clv_top_pool_capped est absente ou incomplète (19 langues attendues) dans data/admin_translations.json"
    );

    return ['pass' => true, 'message' => "getTopCustomers() détecte bien le dépassement du pool de 200 candidats (COUNT réel, comparaison, log Watchdog 19 langues)"];
}
