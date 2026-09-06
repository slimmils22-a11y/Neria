<?php
/**
 * Régression : WatchdogManager::getLastCronEndpointHit() lisait sans
 * $use_cache=false, contrairement à toutes les autres lectures "fraîcheur
 * critique" de ce même fichier (record(), pruneOldLogs()) — même famille
 * de bug systémique que les rounds 210-212 (mécanisme démontré de bout en
 * bout par test_440/test_441 : sous cache SQL PrestaShop actif,
 * Db::getValue() sans $use_cache=false peut resservir un résultat
 * mémorisé sans jamais réexécuter la requête sur MySQL).
 *
 * Ici : après un cronHeartbeat('cron_endpoint', ...) réussi (INSERT ... ON
 * DUPLICATE KEY UPDATE), un appel suivant à getLastCronEndpointHit() dans
 * le même cycle de requête/worker PHP-FPM pouvait renvoyer une valeur
 * périmée (ou "jamais appelé") tant que le cache SQL PrestaShop n'avait
 * pas expiré, trompant le marchand consultant l'onglet santé BO.
 *
 * Corrigé le 06/09/2026 (round 309) : $use_cache=false explicite.
 *
 * Test structurel (reproduire le mécanisme de cache serait redondant avec
 * test_440/test_441 qui le démontrent déjà de bout en bout) : vérifie la
 * présence du garde-fou dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/WatchdogManager.php');
    neria_assert($src !== false, 'Impossible de lire src/WatchdogManager.php');

    $posMethod = strpos($src, 'public function getLastCronEndpointHit(): ?string');
    neria_assert($posMethod !== false, 'getLastCronEndpointHit() introuvable — jeu de test invalide');

    $body = substr($src, $posMethod, 900);

    // Vérification structurelle : le paramètre false suit
    // directement l'appel getValue() de cette méthode (pas un false
    // provenant d'ailleurs dans la fenêtre de recherche, ex. le
    // commentaire qui mentionne aussi "$use_cache=false" en prose).
    $posGetValue = strpos($body, '$this->db->getValue(');
    neria_assert($posGetValue !== false, "getValue() introuvable dans getLastCronEndpointHit() — jeu de test invalide");
    $posClose = strpos($body, ');', $posGetValue);
    neria_assert($posClose !== false, "fin d'appel getValue() introuvable — jeu de test invalide");
    $callBody = substr($body, $posGetValue, $posClose - $posGetValue);
    neria_assert(
        strpos($callBody, 'false') !== false,
        "WatchdogManager::getLastCronEndpointHit() : le paramètre \$use_cache=false n'est plus positionné sur l'appel getValue() de cette méthode — régression du bug corrigé le 06/09/2026 (round 309) : une valeur périmée pourrait de nouveau être resservie par le cache SQL PrestaShop juste après un cronHeartbeat('cron_endpoint', ...) réussi"
    );

    return [
        'pass'    => true,
        'message' => "WatchdogManager::getLastCronEndpointHit() lit bien avec \$use_cache=false, comme les autres lectures fraîcheur-critique du même fichier — bug corrigé le 06/09/2026 (round 309)",
    ];
}
