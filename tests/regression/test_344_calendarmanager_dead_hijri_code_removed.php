<?php
/**
 * Nettoyage : CalendarManager::calculateRamadanStart()/calculateEidAlFitr()/
 * calculateEidAlAdha()/hijriToJdn()/jdnToDateTime() étaient du code mort —
 * calculateEventDate() court-circuite déjà les événements 'eid'/'ramadan'
 * en retournant null directement (l'algorithme hégirien produisait une date
 * systématiquement décalée d'environ un an, jamais fiable), donc aucune de
 * ces 5 méthodes n'était plus jamais appelée. Supprimées le 15/08/2026
 * (round 175) plutôt que laissées comme un algorithme cassé pouvant induire
 * un futur lecteur en erreur (ou être réactivé par erreur).
 *
 * Test structurel : vérifie que les 5 méthodes ont bien disparu du fichier,
 * et que le court-circuit vers le NIVEAU 3 (table figée) reste en place
 * pour ces événements.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/CalendarManager.php');
    neria_assert($src !== false, 'Impossible de lire src/CalendarManager.php');

    foreach (['calculateRamadanStart', 'calculateEidAlFitr', 'calculateEidAlAdha', 'hijriToJdn', 'jdnToDateTime'] as $deadMethod) {
        neria_assert(
            strpos($src, "function {$deadMethod}(") === false,
            "CalendarManager::{$deadMethod}() est réapparue dans le code — cette méthode était du code mort (jamais appelée, calculateEventDate() court-circuite les événements hégiriens vers le NIVEAU 3) supprimé le 15/08/2026 (round 175) ; si elle est réintroduite, vérifier qu'elle est bien appelée quelque part et que son résultat est fiable avant de la garder"
        );
    }

    neria_assert(
        strpos($src, "case 'ramadan':") !== false,
        "CalendarManager::calculateEventDate() ne gère plus le cas 'ramadan' — jeu de test invalide ou régression plus large"
    );

    return [
        'pass'    => true,
        'message' => "CalendarManager ne contient plus les 5 méthodes de calcul hégirien mortes (calculateRamadanStart/calculateEidAlFitr/calculateEidAlAdha/hijriToJdn/jdnToDateTime) — nettoyage du 15/08/2026 (round 175)",
    ];
}
