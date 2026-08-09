<?php
/**
 * Régression : GoldenHourManager::getRecommendations() doit mettre en
 * cache son résultat (15 min, scopé boutique+fenêtre) plutôt que de
 * rejouer le LEFT JOIN sur 90 jours d'historique à chaque appel.
 *
 * Bug réel corrigé le 08/08/2026 (round 135) : navigation.tpl (rendu sur
 * TOUTE page admin du module, pas seulement l'onglet Statistiques)
 * consulte neria_has_golden_hour_data pour décider d'afficher le lien de
 * menu "Heure d'Or" — neria.php appelait donc getRecommendations(90) sans
 * aucune condition sur l'onglet actif, contrairement au pattern déjà
 * utilisé pour d'autres blocs coûteux (ex. 'revenue' conditionné à
 * $activeTab === 'stats'). Un cache court élimine le coût réel sans casser
 * le masquage de menu (qui doit rester exact sur toute page).
 *
 * Test comportemental réel : deux appels consécutifs à
 * getRecommendations() avec les mêmes paramètres doivent renvoyer le même
 * résultat sans re-suspendre la table de cache entre-temps — vérifié en
 * confirmant que CONFIG_CACHE_TIME n'avance pas au second appel (donc que
 * la requête SQL n'a pas été rejouée).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GoldenHourManager.php';

    $mgr = new GoldenHourManager();
    $idShop = (int) Context::getContext()->shop->id;
    $days = 90;

    $cacheTimeKey = 'NERIA_GOLDEN_HOUR_CACHE_TIME_' . $idShop . '_' . $days;
    Configuration::deleteByName('NERIA_GOLDEN_HOUR_CACHE_' . $idShop . '_' . $days);
    Configuration::deleteByName($cacheTimeKey);

    try {
        $result1 = $mgr->getRecommendations($days);
        $cacheTime1 = (int) Configuration::get($cacheTimeKey);
        neria_assert($cacheTime1 > 0, "getRecommendations() n'a pas posé de timestamp de cache au premier appel — régression du bug corrigé le 08/08/2026 (round 135)");

        // Second appel immédiat — doit servir depuis le cache, pas
        // re-timestamper (le TTL n'est pas expiré).
        $result2 = $mgr->getRecommendations($days);
        $cacheTime2 = (int) Configuration::get($cacheTimeKey);

        neria_assert(
            $cacheTime2 === $cacheTime1,
            "getRecommendations() a rejoué la requête SQL au lieu de servir depuis le cache (timestamp de cache modifié entre deux appels rapprochés) — régression du bug corrigé le 08/08/2026 (round 135) : le LEFT JOIN sur 90 jours d'historique redeviendrait rejoué à chaque page admin"
        );
        neria_assert(
            $result1 === $result2,
            "getRecommendations() renvoie un résultat différent entre deux appels rapprochés — le cache devrait garantir la même réponse"
        );
    } finally {
        Configuration::deleteByName('NERIA_GOLDEN_HOUR_CACHE_' . $idShop . '_' . $days);
        Configuration::deleteByName($cacheTimeKey);
    }

    return [
        'pass'    => true,
        'message' => "GoldenHourManager::getRecommendations() met bien en cache son résultat (15 min), évitant de rejouer le LEFT JOIN coûteux à chaque page admin tout en préservant le masquage de menu navigation.tpl",
    ];
}
