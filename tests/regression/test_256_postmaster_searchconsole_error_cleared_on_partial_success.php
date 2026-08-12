<?php
/**
 * Régression : PostmasterManager::fetchAndCache()/SearchConsoleManager::
 * fetchAndCache() n'effaçaient CONFIG_LAST_ERROR/CONFIG_LAST_ERROR_AT
 * qu'une seule fois, juste après le tout premier appel API réussi
 * (/domains ou /sites). Un appel API secondaire en échec dans la même
 * exécution (fetchDomainStats() pour un domaine parmi plusieurs côté
 * Postmaster ; querySearchAnalytics() pour 'queries'/'pages' côté
 * SearchConsole) pouvait re-positionner CONFIG_LAST_ERROR sans que rien
 * ne le nettoie ensuite, même quand le résultat global était bien mis en
 * cache avec succès et loggé comme tel — HealthCheckManager::
 * checkOAuthFreshness() affichait alors une erreur/reconnexion à tort de
 * façon permanente, alors que l'intégration fonctionne et que les données
 * affichées sont à jour.
 *
 * Corrigé le 09/08/2026 (round 157) en effaçant à nouveau CONFIG_LAST_ERROR/
 * CONFIG_LAST_ERROR_AT juste avant l'écriture du cache final réussi, dans
 * les 2 fichiers.
 *
 * Test structurel : vérifie la présence du 2e deleteByName() (le premier,
 * après /domains ou /sites, existait déjà avant ce correctif) juste avant
 * l'écriture du cache final.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $pm = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    neria_assert($pm !== false, 'Impossible de lire PostmasterManager.php');
    neria_assert(
        substr_count($pm, 'Configuration::deleteByName(self::CONFIG_LAST_ERROR)') >= 2,
        "PostmasterManager::fetchAndCache() n'efface plus CONFIG_LAST_ERROR une 2e fois avant l'écriture du cache final — régression du bug corrigé le 09/08/2026 (round 157) : une erreur ponctuelle sur un domaine resterait affichée en BO même après un succès global"
    );
    $posCacheWrite = strpos($pm, "\Configuration::updateValue(\$this->cacheKey(self::CONFIG_CACHE),      json_encode(\$results, JSON_UNESCAPED_UNICODE));");
    neria_assert($posCacheWrite !== false, 'Écriture du cache Postmaster introuvable — jeu de test invalide');
    $beforeCacheWrite = substr($pm, max(0, $posCacheWrite - 1400), 1400);
    neria_assert(
        strpos($beforeCacheWrite, 'Configuration::deleteByName(self::CONFIG_LAST_ERROR)') !== false,
        "PostmasterManager::fetchAndCache() n'efface plus CONFIG_LAST_ERROR juste avant l'écriture du cache final — régression du bug corrigé le 09/08/2026 (round 157)"
    );

    $gsc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php');
    neria_assert($gsc !== false, 'Impossible de lire SearchConsoleManager.php');
    neria_assert(
        substr_count($gsc, 'Configuration::deleteByName(self::CONFIG_LAST_ERROR)') >= 2,
        "SearchConsoleManager::fetchAndCache() n'efface plus CONFIG_LAST_ERROR une 2e fois avant l'écriture du cache final — régression du bug corrigé le 09/08/2026 (round 157)"
    );
    $posCacheWriteGsc = strpos($gsc, "\Configuration::updateValue(\$this->cacheKey(self::CONFIG_CACHE),      json_encode(\$result, JSON_UNESCAPED_UNICODE));");
    neria_assert($posCacheWriteGsc !== false, 'Écriture du cache SearchConsole introuvable — jeu de test invalide');
    $afterCacheWriteGsc = substr($gsc, $posCacheWriteGsc, 1100);
    neria_assert(
        strpos($afterCacheWriteGsc, 'Configuration::deleteByName(self::CONFIG_LAST_ERROR)') !== false,
        "SearchConsoleManager::fetchAndCache() n'efface plus CONFIG_LAST_ERROR juste après l'écriture du cache final — régression du bug corrigé le 09/08/2026 (round 157)"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager/SearchConsoleManager effacent bien CONFIG_LAST_ERROR une 2e fois avant/après l'écriture du cache final réussi — bug corrigé le 09/08/2026 (round 157)",
    ];
}
