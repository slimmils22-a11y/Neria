<?php
/**
 * Régression : ClvManager::computeClv()/assembleClv() doivent étiqueter
 * 'low' (pas 'high') quand $avgOrder vaut 0.0.
 *
 * Bug réel corrigé le 09/08/2026 (round 143) : quand les remboursements
 * (order_slip) ramènent totalRevenue à 0 via max(0.0, …), avgOrder ET
 * clv12 valent tous deux 0.0. Le test `$clv12 >= $avgOrder * 3` devenait
 * `0 >= 0` (vrai) : un client avec 3 commandes à 150€ intégralement
 * remboursées (CLV affichée 0€) était étiqueté 'high value' — badge
 * contredisant visuellement la donnée affichée, trompeur pour le ciblage
 * marchand qui utilise précisément ce badge.
 *
 * Test structurel assumé explicitement : reproduire ce cas en conditions
 * réelles nécessiterait de créer une commande + son remboursement complet
 * via order_slip pour un client réel (setup lourd et risqué sur des
 * données de commande réelles). Vérifie que le garde-fou `$avgOrder <= 0.0`
 * est bien présent AVANT le test de seuil `$clv12 >= $avgOrder * 3`, dans
 * les 2 méthodes qui dupliquent cette logique (computeClv()/fiche client
 * individuelle, et assembleClv()/Top 20 CLV en masse).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ClvManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ClvManager.php');

    $posCompute = strpos($src, 'private function computeClv(int $idCustomer): array');
    neria_assert($posCompute !== false, 'computeClv() introuvable — jeu de test invalide');
    $posAssemble = strpos($src, 'private function assembleClv(');
    neria_assert($posAssemble !== false, 'assembleClv() introuvable — jeu de test invalide');

    foreach (['computeClv' => $posCompute, 'assembleClv' => $posAssemble] as $method => $pos) {
        // Fenêtre large (6000) : computeClv()/assembleClv() sont de longues
        // méthodes (~5500 octets mesurés jusqu'au garde-fou) qui recalculent
        // tout le CLV (historique commandes, remboursements, engagement,
        // segment, churn) avant d'atteindre l'étiquetage final.
        $body = substr($src, $pos, 6000);
        $posGuard = strpos($body, 'if ($avgOrder <= 0.0) {');
        // Recherche la condition de CODE réelle ("} elseif (...)"), pas une
        // mention en commentaire du même texte qui précède le code (ce
        // dernier ferait échouer la vérification d'ordre ci-dessous par un
        // faux positif).
        $posThreshold = strpos($body, '} elseif ($clv12 >= $avgOrder * 3) {');
        neria_assert(
            $posGuard !== false,
            "{$method}() ne teste plus explicitement \$avgOrder <= 0.0 avant l'étiquetage — régression du bug corrigé le 09/08/2026 (round 143) : un client à CLV=0€ (avgOrder=0 après remboursement total) serait de nouveau étiqueté 'high value'"
        );
        neria_assert(
            $posThreshold !== false && $posGuard < $posThreshold,
            "{$method}() : le garde-fou \$avgOrder <= 0.0 n'est plus positionné AVANT le test de seuil \$clv12 >= \$avgOrder * 3"
        );
    }

    return [
        'pass'    => true,
        'message' => "ClvManager::computeClv()/assembleClv() étiquettent bien 'low' (pas 'high') quand \$avgOrder vaut 0",
    ];
}
