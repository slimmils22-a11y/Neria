<?php
/**
 * Régression : GoldenHourManager::computeRecommendations() reliait chaque
 * envoi à son ouverture via une jointure sur tracking_token SEUL, sans
 * filtrer o.id_shop = s.id_shop (seul s.id_shop l'était côté WHERE).
 * tracking_token n'a qu'un INDEX en base (pas de contrainte UNIQUE
 * globale) : en cas de collision de token entre deux boutiques d'une même
 * install multi-shop, l'ouverture d'une boutique B pouvait se joindre à
 * l'envoi d'une boutique A et fausser son taux d'ouverture / heure optimale
 * recommandée — pattern défensif déjà appliqué ailleurs dans ce projet
 * (rounds 119/127) mais absent ici.
 *
 * Corrigé le 15/08/2026 (round 175) : condition o.id_shop = s.id_shop
 * ajoutée à la jointure.
 *
 * Test structurel : vérifie que la jointure de computeRecommendations()
 * filtre bien o.id_shop = s.id_shop.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/GoldenHourManager.php');
    neria_assert($src !== false, 'Impossible de lire src/GoldenHourManager.php');

    $posJoin = strpos($src, 'LEFT JOIN `{$table}` o');
    neria_assert($posJoin !== false, "Jointure LEFT JOIN introuvable dans computeRecommendations() — jeu de test invalide");
    $joinBlock = substr($src, $posJoin, 300);

    neria_assert(
        strpos($joinBlock, 'o.`id_shop`        = s.`id_shop`') !== false,
        "GoldenHourManager::computeRecommendations() ne filtre plus la jointure ouverture/envoi par o.id_shop = s.id_shop — régression du bug corrigé le 15/08/2026 (round 175) : une collision de tracking_token entre 2 boutiques d'une même install multi-shop fausserait de nouveau le taux d'ouverture et l'heure optimale recommandée"
    );

    return [
        'pass'    => true,
        'message' => "GoldenHourManager::computeRecommendations() scope bien sa jointure ouverture/envoi par boutique — bug corrigé le 15/08/2026 (round 175)",
    ];
}
