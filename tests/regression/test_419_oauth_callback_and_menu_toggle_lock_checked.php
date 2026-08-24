<?php
/**
 * Régression : PostmasterManager::handleCallback()/SearchConsoleManager::
 * handleCallback() et ConfigManager::toggleMenuItemVisibility() appelaient
 * GET_LOCK() mais ignoraient sa valeur de retour, puis
 * PostmasterManager/SearchConsoleManager appelaient inconditionnellement
 * RELEASE_LOCK() dans le bloc finally — même piège déjà corrigé pour leurs
 * méthodes jumelles getAuthUrl() (round 189) et toggleBooleanKey() (round
 * 141), mais jamais porté ici.
 *
 * Bug réel identifié le 23/08/2026 (round 196) : sous contention (deux
 * callbacks OAuth quasi simultanés, ou deux admins masquant des items de
 * menu en même temps), le cycle lecture-modification-écriture s'exécutait
 * sans protection, pouvant écraser silencieusement l'état posé par l'autre
 * processus.
 *
 * Corrigé le 23/08/2026 (round 196) : le retour de GET_LOCK() est vérifié
 * partout ; RELEASE_LOCK() n'est appelé (Postmaster/SearchConsole) que si
 * le verrou a réellement été acquis ; toggleMenuItemVisibility() refuse la
 * bascule (comme toggleBooleanKey()) si le verrou n'est pas obtenu.
 *
 * Test structurel (simuler une vraie contention MySQL nécessiterait 2
 * connexions DB concurrentes, hors de portée d'un test isolé — voir
 * test_402 pour la même contrainte) : vérifie par lecture directe du
 * source que le retour de GET_LOCK() est bien assigné à une variable
 * testée avant toute écriture/RELEASE_LOCK().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (
        [
            ['PostmasterManager.php', "GET_LOCK('neria_postmaster_oauth_state', 3)", '$locked = '],
            ['SearchConsoleManager.php', "GET_LOCK('neria_search_console_oauth_state', 3)", '$locked = '],
        ] as [$file, $lockCall, $assignPrefix]
    ) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/' . $file);
        neria_assert($src !== false, "Impossible de lire src/{$file}");

        // handleCallback() est le 2e site GET_LOCK() du fichier (getAuthUrl()
        // en premier, déjà corrigé round 189) — on cherche donc la 2e occurrence.
        $posFirst = strpos($src, $lockCall);
        neria_assert($posFirst !== false, "1er appel {$lockCall} introuvable dans {$file} — jeu de test invalide");
        $posSecond = strpos($src, $lockCall, $posFirst + 1);
        neria_assert($posSecond !== false, "2e appel {$lockCall} (handleCallback) introuvable dans {$file} — jeu de test invalide");

        $before = substr($src, max(0, $posSecond - 60), 60);
        neria_assert(
            strpos($before, $assignPrefix) !== false,
            "{$file} : handleCallback() n'assigne plus le retour de GET_LOCK() à \$locked — régression du bug corrigé le 23/08/2026 (round 196) : le cycle lecture-modification-écriture du callback OAuth s'exécuterait de nouveau sans vérifier si le verrou a réellement été obtenu"
        );

        $posFinally = strpos($src, 'finally {', $posSecond);
        neria_assert($posFinally !== false, "{$file} : bloc finally de handleCallback() introuvable — jeu de test invalide");
        $finallyBody = substr($src, $posFinally, 200);
        neria_assert(
            strpos($finallyBody, 'if ($locked)') !== false,
            "{$file} : RELEASE_LOCK() de handleCallback() n'est plus conditionné par \$locked — régression du bug corrigé le 23/08/2026 (round 196)"
        );
    }

    $cfgSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ConfigManager.php');
    neria_assert($cfgSrc !== false, 'Impossible de lire src/ConfigManager.php');
    $posMenuLock = strpos($cfgSrc, "GET_LOCK('neria_menu_hidden_items', 3)");
    neria_assert($posMenuLock !== false, "Appel GET_LOCK('neria_menu_hidden_items', 3) introuvable — jeu de test invalide");
    $before = substr($cfgSrc, max(0, $posMenuLock - 60), 60);
    neria_assert(
        strpos($before, '$gotLock = ') !== false,
        "ConfigManager::toggleMenuItemVisibility() n'assigne plus le retour de GET_LOCK() à \$gotLock — régression du bug corrigé le 23/08/2026 (round 196)"
    );
    $after = substr($cfgSrc, $posMenuLock, 250);
    neria_assert(
        strpos($after, 'if ($gotLock !== 1)') !== false,
        "ConfigManager::toggleMenuItemVisibility() ne vérifie plus \$gotLock !== 1 avant de procéder — régression du bug corrigé le 23/08/2026 (round 196) : la bascule de visibilité d'un item de menu s'exécuterait de nouveau sans protection sous contention"
    );

    return [
        'pass'    => true,
        'message' => "PostmasterManager/SearchConsoleManager::handleCallback() et ConfigManager::toggleMenuItemVisibility() vérifient bien le retour de GET_LOCK() — bug corrigé le 23/08/2026 (round 196)",
    ];
}
