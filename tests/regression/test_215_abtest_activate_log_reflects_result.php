<?php
/**
 * Régression : AbTestManager::activateTest() ne doit journaliser un succès
 * Watchdog QUE si l'UPDATE SQL a réellement réussi — jamais inconditionnellement.
 *
 * Bug réel corrigé le 09/08/2026 (round 147) : le log 'watchdog.abtest_activated'
 * était émis inconditionnellement après l'UPDATE, contrairement à sa jumelle
 * deactivateTest() (même fichier) qui conditionne bien son log au résultat.
 * Une erreur SQL transitoire (verrou, timeout) faisait échouer l'activation
 * (activateTest() retournait bien false) mais le Watchdog contenait malgré
 * tout une entrée "activated" mensongère, masquant le vrai incident au
 * support/à l'investigation a posteriori.
 *
 * Test structurel (pas comportemental) : sur cet environnement de dev,
 * _PS_DEBUG_SQL_ est actif — une requête invalide (table renommée/absente)
 * fait LEVER une PrestaShopDatabaseException plutôt que de faire retourner
 * false à Db::execute() (comportement réservé à la prod, _PS_DEBUG_SQL_
 * désactivé — cf. classes/db/Db.php:771), rendant la reproduction fidèle de
 * l'échec SQL "silencieux" impossible ici sans modifier le comportement du
 * coeur PrestaShop. Vérifie donc que le code source conditionne bien le log
 * de succès au résultat de l'UPDATE, comme sa jumelle deactivateTest().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/ABTestManager.php');
    neria_assert($src !== false, 'Impossible de lire src/ABTestManager.php');

    $posFn = strpos($src, 'public function activateTest(string $template): bool');
    neria_assert($posFn !== false, 'activateTest() introuvable — jeu de test invalide');

    $posEnd = strpos($src, "\n    /**\n     * Desactive un test A/B", $posFn);
    $body = $posEnd !== false ? substr($src, $posFn, $posEnd - $posFn) : substr($src, $posFn, 1500);

    $posResult = strpos($body, '$result = $this->db->execute(');
    neria_assert($posResult !== false, "l'assignation de \$result introuvable dans activateTest() — jeu de test invalide");

    $afterResult = substr($body, $posResult);
    $posIf = strpos($afterResult, 'if ($result !== false) {');
    $posLog = strpos($afterResult, "abtest_activated");
    neria_assert(
        $posIf !== false && $posLog !== false && $posIf < $posLog,
        "activateTest() ne conditionne plus son log Watchdog 'activated' au resultat reel de l'UPDATE — regression du bug corrige le 09/08/2026 (round 147) : le log mentirait de nouveau sur ce qui s'est reellement passe en cas d'echec SQL"
    );

    $posElse = strpos($afterResult, '} else {');
    $posFailLog = strpos($afterResult, 'abtest_activate_failed');
    neria_assert(
        $posElse !== false && $posFailLog !== false && $posElse < $posFailLog,
        "activateTest() ne journalise plus d'erreur explicite (abtest_activate_failed) quand l'UPDATE echoue — regression du bug corrige le 09/08/2026 (round 147)"
    );

    return [
        'pass'    => true,
        'message' => "AbTestManager::activateTest() conditionne bien son log Watchdog 'activated'/'activate_failed' au resultat reel de l'UPDATE SQL",
    ];
}
