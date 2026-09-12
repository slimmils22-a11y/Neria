<?php
/**
 * Régression : PostmasterManager::apiGet() doit détecter spécifiquement un
 * HTTP 401 (access_token invalidé/révoqué côté Google AVANT l'expiration
 * locale prévue) et déclencher un rafraîchissement + une seule retentative,
 * au lieu de traiter ce cas comme une erreur générique.
 *
 * Bug identifié le 12/09/2026 (round 343, audit PostmasterManager) :
 * getAccessToken() ne rafraîchit le token QUE sur base de l'expiry stocké
 * localement (CONFIG_TOKEN_EXPIRY). Si Google révoque/invalide le token
 * avant cette échéance (changement de scope, révocation partielle, dérive
 * d'horloge), apiGet() recevait un 401, le traitait comme une erreur
 * générique (CONFIG_LAST_ERROR + warning), sans jamais appeler
 * refreshAccessToken() — chaque appel BO échouait alors inutilement
 * jusqu'à l'expiration naturelle (jusqu'à ~55 min) au lieu de se rétablir
 * immédiatement via un simple refresh.
 *
 * Test structurel (comme tous les tests existants de ce fichier — apiGet()
 * fait un vrai appel réseau vers l'API Google Postmaster Tools,
 * impraticable à invoquer isolément en CLI, cf. absence de callers testés
 * en direct dans ce fichier) : vérifie que le garde-fou (détection 401 +
 * refresh + retentative unique, bornée pour éviter toute boucle) est bien
 * en place dans le code source.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php');
    neria_assert($src !== false, 'Impossible de lire src/PostmasterManager.php');

    $posFn = strpos($src, 'private function apiGet(string $path, string $token, bool $retriedAfter401 = false): ?array');
    neria_assert($posFn !== false, "apiGet() n'a plus la signature attendue avec le paramètre \$retriedAfter401 — régression du bug corrigé le 12/09/2026 (round 343)");

    $posHttpCode = strpos($src, '$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);', $posFn);
    neria_assert($posHttpCode !== false, 'jeu de test invalide : $httpCode introuvable');

    $body = substr($src, $posHttpCode, 1150);

    neria_assert(
        strpos($body, "if (\$httpCode === 401 && !\$retriedAfter401) {") !== false,
        "apiGet() ne détecte plus spécifiquement un 401 non encore retenté — régression du bug corrigé le 12/09/2026 (round 343)"
    );
    neria_assert(
        strpos($body, '$newToken = $this->refreshAccessToken();') !== false,
        "apiGet() n'appelle plus refreshAccessToken() sur un 401 — régression du bug corrigé le 12/09/2026 (round 343)"
    );
    neria_assert(
        strpos($body, 'return $this->apiGet($path, $newToken, true);') !== false,
        "apiGet() ne retente plus l'appel avec le nouveau token (retentative bornée à true) — régression du bug corrigé le 12/09/2026 (round 343) : risque de boucle si ce garde \$retriedAfter401 disparaissait"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager::apiGet() détecte bien un 401 spécifiquement et déclenche un refresh + une seule retentative, au lieu de traiter ce cas comme une erreur générique bloquant jusqu'à l'expiration naturelle du token",
    ];
}
