<?php
/**
 * Régression : QueueManager::getStats() calculait la "heure de pointe"
 * (peak_hour) sur TOUT l'historique de la table orders, contrairement aux
 * autres statistiques de la même méthode (fenêtrées sur 30 jours). Sur une
 * boutique ancienne, le pic horaire affiché en BO devenait de moins en
 * moins représentatif du comportement récent des clients.
 *
 * Corrigé le 14/08/2026 (round 168) : requête fenêtrée sur 90 jours
 * (date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)).
 *
 * Test structurel : vérifie la présence du filtre de fenêtre sur la
 * requête peak_hour.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/QueueManager.php');
    neria_assert($src !== false, 'Impossible de lire QueueManager.php');

    $posFn = strpos($src, 'Heure de pointe globale');
    neria_assert($posFn !== false, "Bloc peak_hour introuvable — jeu de test invalide");
    $body = substr($src, $posFn, 900);

    neria_assert(
        strpos($body, "date_add >= DATE_SUB(NOW(), INTERVAL 90 DAY)") !== false,
        "La requête peak_hour n'est plus fenêtrée sur 90 jours — régression du bug corrigé le 14/08/2026 (round 168) : le pic horaire redeviendrait calculé sur tout l'historique, de moins en moins représentatif sur une boutique ancienne"
    );

    return [
        'pass'    => true,
        'message' => "QueueManager::getStats() fenêtre bien le calcul du peak_hour sur 90 jours, cohérent avec les autres statistiques de la méthode — bug corrigé le 14/08/2026 (round 168)",
    ];
}
