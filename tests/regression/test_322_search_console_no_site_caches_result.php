<?php
/**
 * Régression : SearchConsoleManager::fetchAndCache() retournait [] sur ses
 * deux branches de retour anticipé ("aucun site vérifié" / "aucun site ne
 * correspond au domaine de la boutique") SANS jamais écrire CONFIG_CACHE_TIME
 * — contrairement à sa méthode jumelle PostmasterManager::fetchAndCache(),
 * qui met bien le cache à jour pour le cas équivalent ("aucun domaine ne
 * correspond"). Résultat : à CHAQUE chargement de page BO affichant le
 * widget SEO (pas seulement à l'expiration du TTL de 12h), l'API GSC
 * /sites était rappelée en direct — sensible aux quotas Google.
 *
 * Corrigé le 15/08/2026 (round 171) : les deux branches écrivent désormais
 * CONFIG_CACHE (tableau vide) + CONFIG_CACHE_TIME (horodatage frais) avant
 * de retourner, comme PostmasterManager.
 *
 * Test structurel : vérifie la présence de l'écriture du cache sur les deux
 * branches de retour anticipé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php');
    neria_assert($src !== false, 'Impossible de lire SearchConsoleManager.php');

    $posNoSite = strpos($src, "watchdog.gsc_no_site");
    neria_assert($posNoSite !== false, 'Bloc gsc_no_site introuvable — jeu de test invalide');
    $bodyNoSite = substr($src, $posNoSite, 900);
    neria_assert(
        strpos($bodyNoSite, 'CONFIG_CACHE_TIME), time());') !== false,
        "La branche 'aucun site vérifié' de fetchAndCache() n'écrit toujours pas le cache avant de retourner — régression du bug corrigé le 15/08/2026 (round 171) : chaque page BO rappellerait de nouveau l'API GSC /sites en direct"
    );

    $posNoMatch = strpos($src, 'watchdog.gsc_no_matching_site');
    neria_assert($posNoMatch !== false, 'Bloc gsc_no_matching_site introuvable — jeu de test invalide');
    $bodyNoMatch = substr($src, $posNoMatch, 700);
    neria_assert(
        strpos($bodyNoMatch, 'CONFIG_CACHE_TIME), time());') !== false,
        "La branche 'aucun site ne correspond au domaine' de fetchAndCache() n'écrit toujours pas le cache avant de retourner — régression du bug corrigé le 15/08/2026 (round 171)"
    );

    return [
        'pass'    => true,
        'message' => "SearchConsoleManager::fetchAndCache() met bien le cache à jour sur ses deux retours anticipés (aucun site / aucune correspondance), alignée sur PostmasterManager — bug corrigé le 15/08/2026 (round 171)",
    ];
}
