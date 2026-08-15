<?php
/**
 * Régression : LoyaltyManager::generateVoucher() nettoyait la réservation
 * en base via un DELETE sans filtre id_shop quand CartRule::add() échouait
 * — contrairement à l'UPDATE juste en dessous (qui écrit le bon réellement
 * créé), déjà filtré par id_shop avec un commentaire explicite expliquant
 * pourquoi. En mode "comptage séparé par boutique", un client franchissant
 * le même palier quasi simultanément sur 2 boutiques distinctes a 2 lignes
 * de réservation (id_shop différents). Si le CartRule de la boutique A
 * échouait, le DELETE sans filtre supprimait AUSSI la réservation de la
 * boutique B — même si son bon avait déjà été créé avec succès juste
 * avant. Au prochain palier franchi sur B, plus aucune ligne n'empêchait
 * une 2e récompense : un second bon de réduction était régénéré pour un
 * palier déjà récompensé.
 *
 * Corrigé le 15/08/2026 (round 172) : id_shop ajouté au DELETE, symétrique
 * à l'UPDATE.
 *
 * Test structurel : vérifie la présence du filtre id_shop sur le DELETE de
 * nettoyage, juste avant le throw de l'exception CartRule::add() échoué.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/LoyaltyManager.php');
    neria_assert($src !== false, 'Impossible de lire LoyaltyManager.php');

    $posFail = strpos($src, 'if (!$cartRule->add()) {');
    neria_assert($posFail !== false, "Bloc d'échec CartRule::add() introuvable — jeu de test invalide");
    $body = substr($src, $posFail, 1400);

    $posDelete = strpos($body, 'DELETE FROM');
    neria_assert($posDelete !== false, "DELETE de nettoyage introuvable dans ce bloc — jeu de test invalide");
    $deleteStatement = substr($body, $posDelete, 350);

    neria_assert(
        strpos($deleteStatement, 'AND id_shop = " . $reservationShopId') !== false,
        "LoyaltyManager::generateVoucher() ne filtre plus le DELETE de nettoyage par id_shop — régression du bug corrigé le 15/08/2026 (round 172) : un échec de CartRule::add() sur une boutique supprimerait de nouveau AUSSI la réservation d'une autre boutique en mode séparé, provoquant l'émission d'un second bon pour un palier déjà récompensé"
    );

    return [
        'pass'    => true,
        'message' => "LoyaltyManager::generateVoucher() filtre bien le DELETE de nettoyage par id_shop, symétrique à l'UPDATE — bug corrigé le 15/08/2026 (round 172)",
    ];
}
