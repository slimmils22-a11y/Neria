<?php
/**
 * Régression : SignatureGenerator::generate() n'alertait pas le Watchdog
 * sur un échec de imagepng() (disque plein, permissions) — asymétrie avec
 * les 2 autres branches d'échec de la même méthode (GD indisponible,
 * police introuvable), qui alertent déjà systématiquement. Un échec de
 * sauvegarde réel restait invisible du monitoring, visible uniquement en
 * creusant le log fichier PrestaShop brut.
 *
 * Corrigé le 09/08/2026 (round 160) : ajout d'un appel
 * watchdog()->error(watchdog.signature_save_failed) avant le `return false`.
 *
 * Test structurel (forcer un vrai échec imagepng() nécessiterait de rendre
 * le répertoire des signatures non accessible en écriture, risqué pour
 * les autres tests de la suite s'exécutant en parallèle/séquence sur le
 * même environnement) : vérifie la présence de l'appel Watchdog dans la
 * branche d'échec imagepng().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/SignatureGenerator.php');
    neria_assert($src !== false, 'Impossible de lire SignatureGenerator.php');

    $posSaved = strpos($src, '$saved = imagepng($image, $fullPath, 9);');
    neria_assert($posSaved !== false, 'Bloc imagepng() introuvable — jeu de test invalide');
    $body = substr($src, $posSaved, 700);

    neria_assert(
        strpos($body, "watchdog()->error(WatchdogManager::i18nMsg('watchdog.signature_save_failed'") !== false,
        "SignatureGenerator::generate() n'alerte plus le Watchdog sur un échec imagepng() — régression du bug corrigé le 09/08/2026 (round 160) : un échec de sauvegarde réel (disque plein, permissions) redeviendrait invisible du monitoring"
    );

    return [
        'pass'    => true,
        'message' => "SignatureGenerator::generate() alerte bien le Watchdog sur un échec imagepng(), cohérent avec les 2 autres branches d'échec — bug corrigé le 09/08/2026 (round 160)",
    ];
}
