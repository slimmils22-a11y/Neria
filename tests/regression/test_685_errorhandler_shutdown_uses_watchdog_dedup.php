<?php
/**
 * Régression : le shutdown handler de `NeriaErrorHandler::register()`
 * (capture des fatals E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR) écrivait
 * TOUJOURS une ligne brute en INSERT direct dans `neria_log`, sans jamais
 * tenter `WatchdogManager::critical()` au préalable — contrairement aux 3
 * autres points d'écriture de secours du même fichier (`wrapGetContent()`,
 * `wrapDisplayHeader()`, `logHookCrash()`), qui tentent tous WatchdogManager
 * en premier et ne retombent sur l'INSERT brut que si WatchdogManager
 * lui-même échoue. `WatchdogManager::critical()` déduplique pourtant déjà
 * (GET_LOCK + fenêtre glissante 1h, `occurrence_count`) — un fatal PHP
 * répété à chaque requête touchant une page cassée (ex. hookDisplayHeader()
 * sur une fiche produit à fort trafic) insérait donc une NOUVELLE ligne
 * 'critical' par requête, sans aucune limite, noyant la table `neria_log`
 * précisément pendant l'incident où elle doit rester lisible (BO → Aide →
 * Journal).
 *
 * Bug identifié le 10/09/2026 (round 335, audit PostmasterManager/
 * SegmentManager/NeriaErrorHandler).
 *
 * Corrigé le 10/09/2026 (round 335) : `register()` accepte désormais
 * l'instance `\Neria` du module (transmise depuis `Neria::__construct()`),
 * capturée par le shutdown handler ; celui-ci tente
 * `WatchdogManager::critical()` (avec sa déduplication native) EN PREMIER,
 * et ne retombe sur l'INSERT brut que si `$module` est indisponible ou si
 * WatchdogManager lui-même lève une exception — mêmes garanties de
 * dégradation gracieuse que les 3 autres points d'écriture de ce fichier.
 *
 * Test structurel (le shutdown handler ne s'exécute qu'à la fin réelle du
 * process PHP — register_shutdown_function() — impraticable à observer de
 * façon comportementale dans le framework de test actuel, qui capture le
 * résultat de run_test() avant la fin du process ; même limite déjà
 * acceptée pour ce fichier, cf. test_479) : vérifie que register() accepte
 * bien le module, que la closure le capture, et qu'elle tente
 * WatchdogManager::critical() avant tout INSERT brut.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/NeriaErrorHandler.php');
    neria_assert($src !== false, 'Impossible de lire src/NeriaErrorHandler.php');

    $posFn = strpos($src, 'public static function register(?\Neria $module = null): void');
    neria_assert(
        $posFn !== false,
        "NeriaErrorHandler::register() n'accepte plus un \\Neria \$module optionnel — régression du bug corrigé le 10/09/2026 (round 335)"
    );

    $posClosure = strpos($src, 'register_shutdown_function(static function () use ($module): void {', $posFn);
    neria_assert(
        $posClosure !== false,
        "Le shutdown handler ne capture plus \$module via use() — régression du bug corrigé le 10/09/2026 (round 335)"
    );

    $posInsert = strpos($src, "INSERT INTO `\" . _DB_PREFIX_ . \"neria_log`", $posClosure);
    neria_assert($posInsert !== false, "INSERT brut de secours introuvable dans le shutdown handler — jeu de test invalide");

    $watchdogBody = substr($src, $posClosure, $posInsert - $posClosure);
    neria_assert(
        strpos($watchdogBody, 'if ($module !== null) {') !== false
            && strpos($watchdogBody, "(new \\WatchdogManager(\$module))->critical(\$message, '', 'NeriaErrorHandler');") !== false
            && strpos($watchdogBody, 'return;') !== false,
        "Le shutdown handler ne tente plus WatchdogManager::critical() (déduplication native) AVANT l'INSERT brut — régression du bug corrigé le 10/09/2026 (round 335) : un fatal PHP répété inonderait de nouveau neria_log d'une ligne par requête sans aucune consolidation"
    );

    // register() doit être appelé avec $this depuis Neria::__construct()
    $neriaSrc = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($neriaSrc !== false, 'Impossible de lire neria.php');
    neria_assert(
        strpos($neriaSrc, 'NeriaErrorHandler::register($this);') !== false,
        "neria.php n'appelle plus NeriaErrorHandler::register(\$this) — régression du bug corrigé le 10/09/2026 (round 335) : \$module resterait toujours null, désactivant silencieusement la déduplication même dans le cas normal"
    );

    return [
        'pass'    => true,
        'message' => "NeriaErrorHandler : le shutdown handler tente désormais WatchdogManager::critical() (déduplication GET_LOCK + fenêtre 1h) avant tout INSERT brut de secours dans neria_log — bug corrigé le 10/09/2026 (round 335)",
    ];
}
