<?php
/**
 * Régression : `controllers/front/bounce.php` lisait le corps de la
 * requête entière (`file_get_contents('php://input')`) et tentait un
 * `json_decode()` AVANT toute vérification de signature HMAC — l'URL de
 * ce endpoint est publique et documentée (aucun token dans le chemin), un
 * tiers non authentifié pouvait donc consommer CPU/mémoire à chaque
 * requête, même invalide/non signée, en postant un corps volumineux
 * répété (déni de service applicatif à volume modéré, non limité par une
 * taille spécifique à cet endpoint — seulement par `post_max_size`/
 * `memory_limit` PHP globaux).
 *
 * Bug identifié le 01/09/2026 (round 273, audit "gestion des webhooks
 * entrants tiers").
 *
 * Corrigé le 01/09/2026 (round 273) : limite de taille de 1 Mo appliquée
 * AVANT la lecture complète/le décodage — via `Content-Length` (rejet
 * rapide sans lire le corps si annoncé trop grand) ET via le paramètre
 * `maxlen` de `file_get_contents()` (protection même si `Content-Length`
 * est absent/mensonger), avec réponse `413 Payload too large`.
 *
 * Test structurel : reproduire un vrai POST HTTP volumineux vers un
 * contrôleur front PrestaShop est hors périmètre sûr de cette suite PHP
 * CLI (nécessite une vraie requête HTTP). Vérifie la présence des 2
 * gardes de taille (Content-Length ET maxlen sur file_get_contents) dans
 * le code source, avant le premier `json_decode()`.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/bounce.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/bounce.php');

    $contentLengthPos = strpos($src, "\$contentLength = (int) (\$_SERVER['CONTENT_LENGTH'] ?? 0);");
    neria_assert(
        $contentLengthPos !== false,
        "controllers/front/bounce.php ne vérifie plus Content-Length avant de lire le corps — régression du bug corrigé le 01/09/2026 (round 273) : un payload volumineux annoncé serait de nouveau lu intégralement avant tout contrôle"
    );

    $maxlenPos = strpos($src, "file_get_contents('php://input', false, null, 0, 1048576 + 1);");
    neria_assert(
        $maxlenPos !== false,
        "controllers/front/bounce.php ne borne plus file_get_contents('php://input') via maxlen — régression du bug corrigé le 01/09/2026 (round 273) : un corps volumineux sans Content-Length correct (chunked/mensonger) serait de nouveau lu intégralement en mémoire"
    );

    $jsonDecodePos = strpos($src, 'json_decode($rawBody, true);');
    neria_assert($jsonDecodePos !== false, "l'appel json_decode(\$rawBody, true) est introuvable — jeu de test invalide");

    neria_assert(
        $contentLengthPos < $jsonDecodePos && $maxlenPos < $jsonDecodePos,
        "les gardes de taille ne sont plus positionnés AVANT json_decode() — ils doivent empêcher la lecture/le décodage d'un corps volumineux, pas seulement le signaler après coup"
    );

    return [
        'pass'    => true,
        'message' => "controllers/front/bounce.php limite désormais la taille du corps (1 Mo, Content-Length + maxlen) avant toute lecture/décodage — bug corrigé le 01/09/2026 (round 273)",
    ];
}
