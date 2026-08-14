<?php
/**
 * Régression : neria-emergency.php affichait $e->getMessage() en clair
 * dans la page (après authentification par token valide) si la lecture
 * des 100 derniers logs Watchdog échouait — contrairement à tous les
 * autres blocs de lecture du même fichier (health checks, bounces,
 * compteurs), qui avalent leur erreur silencieusement et ne loggent que
 * côté serveur. Un token valide (légitime ou récupéré par un autre biais)
 * pouvait ainsi voir le message d'erreur MySQL complet — nom du driver,
 * structure de requête, préfixe de table réel.
 *
 * Corrigé le 14/08/2026 (round 166) : le message d'exception va désormais
 * uniquement au log serveur (error_log), la page affiche un message
 * générique traduit (token_read_error), comme partout ailleurs dans ce
 * fichier.
 *
 * Test structurel : vérifie que $e->getMessage() n'est plus affiché
 * directement dans le HTML, et qu'un message générique traduit est
 * utilisé à la place.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria-emergency.php');
    neria_assert($src !== false, 'Impossible de lire neria-emergency.php');

    $posCatch = strpos($src, '$logs = $stmt->fetchAll();');
    neria_assert($posCatch !== false, "Lecture des logs introuvable — jeu de test invalide");
    $body = substr($src, $posCatch, 900);

    neria_assert(
        strpos($body, 'error_log(') !== false,
        "Le bloc catch de lecture des logs ne journalise plus côté serveur — régression du bug corrigé le 14/08/2026 (round 166)"
    );
    neria_assert(
        strpos($body, '$logsError = $e->getMessage();') === false,
        "\$logsError contient de nouveau le message d'exception brut — régression du bug corrigé le 14/08/2026 (round 166) : le message SQL redeviendrait potentiellement exposé dans la page après authentification"
    );

    $posDisplay = strpos($src, "isset(\$logsError)");
    neria_assert($posDisplay !== false, "Affichage de \$logsError introuvable — jeu de test invalide");
    $displayBody = substr($src, $posDisplay, 150);
    neria_assert(
        strpos($displayBody, '$logsError') !== false && strpos($displayBody, "e18n(\$T, 'token_read_error')") !== false,
        "L'affichage n'utilise plus le message générique traduit token_read_error — régression du bug corrigé le 14/08/2026 (round 166) : le message d'erreur SQL brut redeviendrait affiché en clair"
    );

    return [
        'pass'    => true,
        'message' => "neria-emergency.php n'affiche plus le message d'erreur SQL brut — un message générique traduit est utilisé à la place — bug corrigé le 14/08/2026 (round 166)",
    ];
}
