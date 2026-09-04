<?php
/**
 * Régression : `StatsManager::logSignificanceIfNew()` journalisait/
 * déclenchait le webhook `ab_winner` dès la PREMIÈRE fois qu'un gagnant
 * atteignait ≥95% de confiance — ce calcul est réévalué à chaque
 * ouverture de l'onglet A/B testing du BO (`getAbtestReportsMap()`), sans
 * aucune correction pour comparaisons répétées ("peeking"). Sur un test
 * surveillé quotidiennement pendant plusieurs semaines, le taux réel de
 * faux positifs pour "atteindre 95% au moins une fois" dépasse largement
 * les 5% nominaux d'un test statique unique — un pic isolé de confiance
 * (bruit statistique) pouvait déclencher une déclaration de gagnant
 * définitive dès un seul chargement de page.
 *
 * Bug identifié et corrigé le 04/09/2026 (round 300, audit "A/B testing —
 * significativité statistique").
 *
 * Corrigé le 04/09/2026 (round 300) : le même gagnant doit désormais être
 * observé à ≥95% de confiance à DEUX reprises espacées d'au moins
 * `SIG_STABILITY_HOURS` (20h) avant que le log/webhook ne se déclenche —
 * un pic isolé ne suffit plus seul.
 *
 * Test comportemental réel : simule 3 appels successifs à
 * `logSignificanceIfNew()` (via réflexion) pour le même gagnant à 96% de
 * confiance — vérifie qu'AUCUN des 2 premiers appels (immédiatement
 * successifs) ne journalise le gagnant, puis qu'un 3e appel, après avoir
 * simulé l'écoulement de la fenêtre de stabilité, le journalise bien.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $sm     = new StatsManager(neria_test_module());
    $idShop = (int) Context::getContext()->shop->id;
    $tpl    = 'regtest561_abtest_peek';

    $cfgKey     = 'NERIA_SIG_LOGGED_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $tpl));
    $pendingKey = 'NERIA_SIG_PENDING_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $tpl));

    $ref = new ReflectionMethod(StatsManager::class, 'logSignificanceIfNew');
    $ref->setAccessible(true);

    $sig = ['open' => ['confidence' => 96], 'click' => ['confidence' => 0], 'overall_winner' => 'A'];

    try {
        // Configuration::deleteFromContext() est un no-op silencieux quand
        // Shop::getContext() === CONTEXT_ALL (bootstrap CLI de ce jeu de
        // tests, déjà documenté dans test_290) — updateValue() vers une
        // chaîne vide est le seul chemin qui invalide fiablement le cache
        // statique ET écrit en base dans ce contexte précis.
        Configuration::updateValue($cfgKey, '', false, null, $idShop);
        Configuration::updateValue($pendingKey, '', false, null, $idShop);

        // 1er appel : premier pic observé, ne doit PAS encore journaliser.
        $ref->invoke($sm, $tpl, $sig);
        $logged1 = (string) Configuration::get($cfgKey, null, null, $idShop);
        neria_assert(
            $logged1 === '',
            "logSignificanceIfNew() journalise dès la 1ère observation d'un gagnant à 95%+ — régression du bug corrigé le 04/09/2026 (round 300) : un pic isolé de confiance (bruit statistique) déclencherait de nouveau seul une déclaration de gagnant définitive"
        );

        // 2e appel immédiat (même gagnant, aucune fenêtre de stabilité
        // écoulée) : ne doit toujours PAS journaliser.
        $ref->invoke($sm, $tpl, $sig);
        $logged2 = (string) Configuration::get($cfgKey, null, null, $idShop);
        neria_assert(
            $logged2 === '',
            "logSignificanceIfNew() journalise avant l'écoulement de SIG_STABILITY_HOURS — régression du bug corrigé le 04/09/2026 (round 300)"
        );

        // Simule l'écoulement de la fenêtre de stabilité (21h > 20h).
        Configuration::updateValue($pendingKey, 'A|' . (time() - 21 * 3600), false, null, $idShop);
        $ref->invoke($sm, $tpl, $sig);
        $logged3 = (string) Configuration::get($cfgKey, null, null, $idShop);
        neria_assert(
            strpos($logged3, 'A|') === 0,
            "logSignificanceIfNew() ne journalise plus le gagnant après confirmation stable sur la fenêtre requise — régression : un gagnant réellement stable ne serait plus jamais déclaré"
        );

        return [
            'pass'    => true,
            'message' => "StatsManager::logSignificanceIfNew() exige désormais une confirmation stable du gagnant sur 2 observations espacées de SIG_STABILITY_HOURS avant de journaliser/déclencher le webhook — bug corrigé le 04/09/2026 (round 300)",
        ];
    } finally {
        // Configuration::deleteFromContext() est un no-op silencieux quand
        // Shop::getContext() === CONTEXT_ALL (bootstrap CLI de ce jeu de
        // tests, déjà documenté dans test_290) — updateValue() vers une
        // chaîne vide est le seul chemin qui invalide fiablement le cache
        // statique ET écrit en base dans ce contexte précis.
        Configuration::updateValue($cfgKey, '', false, null, $idShop);
        Configuration::updateValue($pendingKey, '', false, null, $idShop);
    }
}
