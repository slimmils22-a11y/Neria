<?php
/**
 * Régression : neria-emergency.php (page d'urgence Watchdog autonome,
 * censée rester fonctionnelle même si le reste de PrestaShop est cassé)
 * entourait son chargement de app/config/parameters.php d'aucun
 * try/catch, et son bloc de connexion PDO ne rattrapait que
 * catch(Exception), pas catch(Throwable). Un parameters.php corrompu
 * syntaxiquement (déploiement interrompu — exactement le scénario de "PS
 * core cassé" que cette page prétend survivre) lève un ParseError/Error,
 * qui n'hérite PAS d'Exception : ni rattrapé au chargement ni à la
 * connexion PDO, la page produisait une erreur fatale brute au lieu du
 * message propre prévu par le design.
 *
 * Corrigé le 14/08/2026 (round 166) : le require est désormais entouré
 * d'un try/catch(\Throwable), et le catch du bloc PDO capture désormais
 * \Throwable au lieu du seul \Exception.
 *
 * Test structurel : vérifie la présence des 2 garde-fous dans le code
 * source (déclencher un vrai ParseError sur le fichier de config réel de
 * l'environnement de test serait destructif et hors de portée d'un test
 * de régression).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria-emergency.php');
    neria_assert($src !== false, 'Impossible de lire neria-emergency.php');

    neria_assert(
        strpos($src, 'try {') !== false && strpos($src, '$params = require $paramsFile;') !== false,
        "neria-emergency.php n'entoure plus le chargement de parameters.php d'un try/catch — régression du bug corrigé le 14/08/2026 (round 166)"
    );

    $posTryParams = strpos($src, '$params = require $paramsFile;');
    neria_assert($posTryParams !== false, 'Chargement de $paramsFile introuvable — jeu de test invalide');
    $bodyAfterParams = substr($src, $posTryParams, 300);
    neria_assert(
        strpos($bodyAfterParams, 'catch (\Throwable $e) {') !== false,
        "Le chargement de parameters.php n'est plus rattrapé par catch(\\Throwable) — régression du bug corrigé le 14/08/2026 (round 166) : un ParseError sur un fichier de config corrompu redeviendrait fatal et non rattrapé"
    );

    $posPdo = strpos($src, 'new PDO($dsn, $dbUser, $dbPass, [');
    neria_assert($posPdo !== false, 'Connexion PDO introuvable — jeu de test invalide');
    $bodyAfterPdo = substr($src, $posPdo, 400);
    neria_assert(
        strpos($bodyAfterPdo, 'catch (\Throwable $e) {') !== false,
        "Le bloc de connexion PDO n'est plus rattrapé par catch(\\Throwable) — régression du bug corrigé le 14/08/2026 (round 166) : un Error non-Exception (driver PDO manquant, etc.) redeviendrait fatal"
    );

    return [
        'pass'    => true,
        'message' => "neria-emergency.php rattrape bien \\Throwable (pas seulement \\Exception) sur le chargement de config et la connexion PDO — bug corrigé le 14/08/2026 (round 166)",
    ];
}
