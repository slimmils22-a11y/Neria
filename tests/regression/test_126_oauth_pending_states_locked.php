<?php
/**
 * Régression : PostmasterManager::getAuthUrl()/handleCallback() et
 * SearchConsoleManager::getAuthUrl()/handleCallback() doivent protéger leur
 * cycle lecture-modification-écriture de la liste des states OAuth pending
 * par un verrou MySQL (GET_LOCK/RELEASE_LOCK).
 *
 * Bug réel corrigé le 08/08/2026 (round 122) : loadPendingStates()/
 * savePendingStates() font un simple Configuration::get() puis
 * Configuration::updateValue() sans aucune protection contre une exécution
 * concurrente. Le commentaire du code documente explicitement le cas
 * d'usage visé (« deux onglets, double clic ») — mais sans verrou, deux
 * appels à getAuthUrl() à quelques centaines de ms d'écart peuvent tous
 * deux lire la même liste avant que l'un des deux n'écrive : le second
 * Configuration::updateValue() écrase intégralement le premier state au
 * lieu de fusionner, le perdant silencieusement. L'admin dont le state a
 * disparu voit alors handleCallback() échouer malgré un code Google
 * valide.
 *
 * Test : vérifie structurellement la présence du verrou (une race
 * condition véritable n'est pas reproductible de façon fiable dans un test
 * PHP mono-thread), et fonctionnellement que deux appels séquentiels à
 * getAuthUrl() accumulent bien LES DEUX states dans la liste (le verrou ne
 * doit pas casser le comportement normal non concurrent).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/PostmasterManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/SearchConsoleManager.php';

    foreach (['PostmasterManager' => 'NERIA_POSTMASTER_OAUTH_STATE', 'SearchConsoleManager' => 'NERIA_SC_OAUTH_STATE'] as $class => $configKey) {
        $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/' . $class . '.php');
        neria_assert($src !== false, "Impossible de lire src/{$class}.php");
        neria_assert(
            substr_count($src, "GET_LOCK('") === 2 && substr_count($src, "RELEASE_LOCK('") === 2,
            "{$class} ne verrouille plus getAuthUrl()/handleCallback() (GET_LOCK/RELEASE_LOCK attendus 2 fois chacun) — régression du bug corrigé le 08/08/2026 (round 122)"
        );

        // Comportement séquentiel non concurrent préservé : deux appels
        // successifs à getAuthUrl() doivent accumuler les deux states.
        $mgr = new $class(neria_test_module());
        $ref = new ReflectionMethod($class, 'loadPendingStates');
        $ref->setAccessible(true);

        Configuration::updateValue($configKey, '');

        $mgr->getAuthUrl('http://example.test/return1');
        $mgr->getAuthUrl('http://example.test/return2');

        $pending = $ref->invoke($mgr);
        neria_assert(
            count($pending) === 2,
            "{$class}::getAuthUrl() appelé deux fois de suite ne conserve que " . count($pending) . " state(s) au lieu de 2 — le verrou casse le comportement normal non concurrent"
        );

        Configuration::updateValue($configKey, '');
    }

    return [
        'pass'    => true,
        'message' => "PostmasterManager/SearchConsoleManager verrouillent bien le cycle lecture-modification-écriture des states OAuth pending, sans casser l'accumulation séquentielle normale",
    ];
}
