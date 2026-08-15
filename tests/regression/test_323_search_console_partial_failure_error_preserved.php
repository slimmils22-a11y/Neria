<?php
/**
 * Régression : SearchConsoleManager::fetchAndCache() effaçait
 * CONFIG_LAST_ERROR/_AT de façon INCONDITIONNELLE dès que la requête
 * 'global' réussissait, sans vérifier si les requêtes 'queries'/'pages'
 * avaient ELLES-mêmes échoué (querySearchAnalytics()/apiPost() retourne
 * null sur échec, pas []). Un échec isolé sur 'queries' ou 'pages' (ex.
 * quota ponctuel sur cette dimension précise) écrivait bien une vraie
 * erreur via apiPost(), mais celle-ci était aussitôt effacée — le widget
 * BO affichait silencieusement 0 requêtes/pages sans jamais remonter
 * d'alerte, et HealthCheckManager::checkOAuthFreshness() donnait un
 * satisfecit trompeur.
 *
 * Corrigé le 15/08/2026 (round 171) : l'effacement n'a lieu que si
 * $queries ET $pages sont non-null (aucun échec sur les 3 requêtes),
 * symétrique à PostmasterManager::fetchAndCache() qui n'efface que si
 * $results n'est pas vide.
 *
 * Test structurel : vérifie que l'effacement de CONFIG_LAST_ERROR est bien
 * conditionné à $queries/$pages non-null, pas inconditionnel.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php');
    neria_assert($src !== false, 'Impossible de lire SearchConsoleManager.php');

    $posAnchor = strpos($src, "'checked_at' => \\NeriaTools::formatDate");
    neria_assert($posAnchor !== false, "Ancre 'checked_at' introuvable dans fetchAndCache() — jeu de test invalide");
    $afterCacheWrite = substr($src, $posAnchor, 2500);

    neria_assert(
        strpos($afterCacheWrite, 'if ($queries !== null && $pages !== null) {') !== false,
        "fetchAndCache() n'entoure plus l'effacement de CONFIG_LAST_ERROR (après l'écriture du cache final) d'une condition sur \$queries/\$pages — régression du bug corrigé le 15/08/2026 (round 171) : un échec isolé sur 'queries' ou 'pages' effacerait de nouveau silencieusement une vraie erreur"
    );

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager::fetchAndCache() ne préserve plus l'erreur uniquement si queries ET pages ont réussi — bug corrigé le 15/08/2026 (round 171)",
    ];
}
