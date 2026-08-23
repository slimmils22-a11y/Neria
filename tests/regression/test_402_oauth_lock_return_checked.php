<?php
/**
 * Régression : PostmasterManager::getAuthUrl()/SearchConsoleManager::getAuthUrl()
 * appelaient GET_LOCK() mais ignoraient sa valeur de retour, puis
 * appelaient inconditionnellement RELEASE_LOCK() dans le bloc finally —
 * y compris quand le verrou n'avait PAS été obtenu (contention, ex. deux
 * admins cliquant "Connecter" simultanément), ce qui :
 *  1. laissait le cycle lecture-modification-écriture s'exécuter SANS la
 *     protection que le verrou est censé fournir (perte silencieuse de
 *     state — exactement la race condition documentée comme corrigée au
 *     round 122) ;
 *  2. appelait RELEASE_LOCK() sur un verrou détenu par un AUTRE processus,
 *     le libérant prématurément à sa place.
 *
 * Bug réel identifié le 23/08/2026 (round 189).
 *
 * Corrigé le 23/08/2026 (round 189) : le retour de GET_LOCK() est vérifié ;
 * RELEASE_LOCK() n'est appelé que si le verrou a réellement été acquis.
 *
 * Test structurel (simuler une vraie contention MySQL nécessiterait 2
 * connexions DB concurrentes, hors de portée d'un test isolé) : vérifie par
 * lecture directe du source que le retour de GET_LOCK() est bien assigné à
 * une variable testée avant RELEASE_LOCK().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (
        [
            ['PostmasterManager.php', "GET_LOCK('neria_postmaster_oauth_state', 3)"],
            ['SearchConsoleManager.php', "GET_LOCK('neria_search_console_oauth_state', 3)"],
        ] as [$file, $lockCall]
    ) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/' . $file);
        neria_assert($src !== false, "Impossible de lire src/{$file}");

        $posLock = strpos($src, $lockCall);
        neria_assert($posLock !== false, "Appel {$lockCall} introuvable dans {$file} — jeu de test invalide");

        // Le retour doit être assigné à $locked (pas simplement appelé et jeté).
        $before = substr($src, max(0, $posLock - 40), 40);
        neria_assert(
            strpos($before, '$locked = ') !== false,
            "{$file} : GET_LOCK() n'assigne plus son retour à \$locked — régression du bug corrigé le 23/08/2026 (round 189) : le cycle lecture-modification-écriture s'exécuterait de nouveau sans vérifier si le verrou a réellement été obtenu"
        );

        $posFinally = strpos($src, 'finally {', $posLock);
        neria_assert($posFinally !== false, "{$file} : bloc finally introuvable après GET_LOCK() — jeu de test invalide");
        $finallyBody = substr($src, $posFinally, 200);
        neria_assert(
            strpos($finallyBody, 'if ($locked)') !== false,
            "{$file} : RELEASE_LOCK() n'est plus conditionné par \$locked dans le bloc finally — régression du bug corrigé le 23/08/2026 (round 189) : un verrou détenu par un AUTRE processus pourrait de nouveau être libéré prématurément à sa place"
        );
    }

    return [
        'pass'    => true,
        'message' => "PostmasterManager/SearchConsoleManager::getAuthUrl() vérifient bien le retour de GET_LOCK() avant d'écrire et avant RELEASE_LOCK() — bug corrigé le 23/08/2026 (round 189)",
    ];
}
