<?php
/**
 * Régression : StatsManager::detectMpp() calculait le délai écoulé avec
 * time() - strtotime($sentDateAdd), où $sentDateAdd provient de NOW() côté
 * MySQL mais est réinterprété par strtotime() dans le fuseau PHP
 * (date.timezone). Si MySQL et PHP n'ont pas le même fuseau (fréquent :
 * MySQL en UTC système, PHP en Europe/Paris pour la boutique), l'écart
 * décale $elapsed d'1-2h et fausse la classification MPP (signaux 2 et 3),
 * donc les KPIs d'ouverture et l'éligibilité aux points de fidélité.
 *
 * Corrigé le 14/08/2026 (round 168) : le délai est désormais calculé
 * entièrement côté MySQL via TIMESTAMPDIFF(SECOND, ..., NOW()), éliminant
 * toute dépendance à l'alignement des fuseaux PHP/MySQL.
 *
 * Test structurel : vérifie la présence du calcul TIMESTAMPDIFF côté
 * MySQL et l'absence de l'ancien pattern time()-strtotime().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/StatsManager.php');
    neria_assert($src !== false, 'Impossible de lire StatsManager.php');

    $posFn = strpos($src, 'private function detectMpp(');
    neria_assert($posFn !== false, 'detectMpp() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1400);

    neria_assert(
        strpos($body, 'TIMESTAMPDIFF(SECOND') !== false,
        "detectMpp() ne calcule plus le délai via TIMESTAMPDIFF côté MySQL — régression du bug corrigé le 14/08/2026 (round 168) : le délai redeviendrait dépendant de l'alignement des fuseaux PHP/MySQL"
    );
    neria_assert(
        strpos($body, 'time() - $sentTs') === false && strpos($body, '(time() -') === false,
        "L'ancien calcul time()-strtotime() (dépendant du fuseau PHP) est de retour dans detectMpp() — régression du bug corrigé le 14/08/2026 (round 168)"
    );

    return [
        'pass'    => true,
        'message' => "StatsManager::detectMpp() calcule bien le délai écoulé côté MySQL (TIMESTAMPDIFF), insensible au fuseau PHP local — bug corrigé le 14/08/2026 (round 168)",
    ];
}
