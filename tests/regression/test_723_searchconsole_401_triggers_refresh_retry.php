<?php
/**
 * Régression : SearchConsoleManager::apiGet()/apiPost() doivent détecter
 * spécifiquement un HTTP 401 (access_token invalidé/révoqué côté Google
 * AVANT l'expiration locale prévue) et déclencher un rafraîchissement + une
 * seule retentative, exactement comme PostmasterManager::apiGet() (round
 * 343) — même famille OAuth Google.
 *
 * Bug identifié le 13/09/2026 (round 347, audit multi-agents) :
 * SearchConsoleManager::apiGet()/apiPost() n'avaient jamais reçu ce
 * correctif alors que le code lui-même documente (commentaires "Round 135 :
 * même correctif que PostmasterManager") que les correctifs de la famille
 * OAuth Google sont censés être répliqués entre les deux managers. Sans ce
 * garde-fou, un 401 tombait dans la branche HTTP>=400 générique et chaque
 * appel BO (Search Console) échouait inutilement jusqu'à l'expiration
 * naturelle du token local (jusqu'à ~55 min) au lieu de se rétablir
 * immédiatement via un simple refresh.
 *
 * Test structurel (apiGet()/apiPost() font un vrai appel réseau vers
 * l'API Google Search Console, impraticable à invoquer isolément en CLI) :
 * vérifie que le garde-fou (détection 401 + refresh + retentative unique,
 * bornée pour éviter toute boucle) est bien en place dans le code source,
 * pour les DEUX méthodes.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php');
    neria_assert($src !== false, 'Impossible de lire src/SearchConsoleManager.php');

    // --- apiGet() ---
    $posGet = strpos($src, 'private function apiGet(string $path, string $token, bool $retriedAfter401 = false): ?array');
    neria_assert($posGet !== false, "apiGet() n'a plus la signature attendue avec le paramètre \$retriedAfter401 — régression du correctif round 347");

    $posGetHttpCode = strpos($src, '$httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);', $posGet);
    neria_assert($posGetHttpCode !== false, 'jeu de test invalide : $httpCode introuvable après apiGet()');

    $bodyGet = substr($src, $posGetHttpCode, 1300);

    neria_assert(
        strpos($bodyGet, "if (\$httpCode === 401 && !\$retriedAfter401) {") !== false,
        "apiGet() ne détecte plus spécifiquement un 401 non encore retenté — régression du correctif round 347"
    );
    neria_assert(
        strpos($bodyGet, '$newToken = $this->refreshAccessToken();') !== false,
        "apiGet() n'appelle plus refreshAccessToken() sur un 401 — régression du correctif round 347"
    );
    neria_assert(
        strpos($bodyGet, 'return $this->apiGet($path, $newToken, true);') !== false,
        "apiGet() ne retente plus l'appel avec le nouveau token (retentative bornée à true) — régression du correctif round 347 : risque de boucle si ce garde \$retriedAfter401 disparaissait"
    );

    // --- apiPost() ---
    $posPost = strpos($src, 'private function apiPost(string $path, string $token, string $body, bool $retriedAfter401 = false): ?array');
    neria_assert($posPost !== false, "apiPost() n'a plus la signature attendue avec le paramètre \$retriedAfter401 — régression du correctif round 347");

    $posPostHttpCode = strpos($src, '$httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);', $posPost);
    neria_assert($posPostHttpCode !== false, 'jeu de test invalide : $httpCode introuvable après apiPost()');

    $bodyPost = substr($src, $posPostHttpCode, 1300);

    neria_assert(
        strpos($bodyPost, "if (\$httpCode === 401 && !\$retriedAfter401) {") !== false,
        "apiPost() ne détecte plus spécifiquement un 401 non encore retenté — régression du correctif round 347"
    );
    neria_assert(
        strpos($bodyPost, '$newToken = $this->refreshAccessToken();') !== false,
        "apiPost() n'appelle plus refreshAccessToken() sur un 401 — régression du correctif round 347"
    );
    neria_assert(
        strpos($bodyPost, 'return $this->apiPost($path, $newToken, $body, true);') !== false,
        "apiPost() ne retente plus l'appel avec le nouveau token (retentative bornée à true) — régression du correctif round 347 : risque de boucle si ce garde \$retriedAfter401 disparaissait"
    );

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager::apiGet()/apiPost() détectent bien un 401 spécifiquement et déclenchent un refresh + une seule retentative, alignés sur PostmasterManager (même famille OAuth Google)",
    ];
}
