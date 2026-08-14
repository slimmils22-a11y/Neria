<?php
/**
 * Régression : UpsellManager::getStats() interpolait $idShop directement
 * dans la requête SQL sans cast (int) explicite, contrairement à toutes
 * les autres requêtes du fichier. $idShop est déjà un int PHP à ce point
 * (pas d'injection SQL réelle), mais un bug de robustesse/cohérence par
 * rapport au pattern systématique du reste du fichier.
 *
 * Corrigé le 14/08/2026 (round 167) : cast (int) ajouté par cohérence.
 *
 * Test structurel : vérifie la présence du cast.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/UpsellManager.php');
    neria_assert($src !== false, 'Impossible de lire UpsellManager.php');

    $posFn = strpos($src, 'public function getStats(int $days = 90, ?int $idShop = null): array');
    neria_assert($posFn !== false, 'getStats() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 1600);

    neria_assert(
        strpos($body, "AND id_shop = \" . (int) \$idShop") !== false,
        "getStats() n'interpole plus \$idShop avec un cast (int) explicite dans sa requête — régression du bug corrigé le 14/08/2026 (round 167)"
    );

    return [
        'pass'    => true,
        'message' => "UpsellManager::getStats() caste bien (int) \$idShop avant interpolation SQL, cohérent avec le reste du fichier — bug corrigé le 14/08/2026 (round 167)",
    ];
}
