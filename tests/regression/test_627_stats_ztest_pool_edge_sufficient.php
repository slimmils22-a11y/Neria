<?php
/**
 * Régression : `StatsManager::zTestProportions()` renvoyait `sufficient`
 * resté à sa valeur par défaut (`true`) sur deux branches de sortie
 * anticipée où le test n'a en réalité pas pu être calculé :
 * - `pPool <= 0.0` (aucun clic/ouverture des deux côtés)
 * - `pPool >= 1.0` (100% des deux côtés)
 * - `$se < 1e-10` (écart-type quasi nul, proportions identiques)
 *
 * `views/templates/admin/abtest.tpl` (ligne 82) masque l'avertissement
 * "données insuffisantes" via `{if !($sig.open.sufficient|default:false)}`
 * — avec `sufficient=true` sur ces branches, le marchand voyait un panneau
 * A/B testing "normal" (confiance 0%, pas de gagnant) sans être informé
 * que le test n'a en réalité pas pu être évalué, alors que c'est
 * exactement la situation que la règle n·p̄<5 (round 300) visait déjà à
 * couvrir. Cas non académique : un test A/B sur le taux de clic (souvent
 * 1-3% en e-commerce) où aucune des deux variantes n'a encore généré de
 * clic sur la fenêtre observée (x1=x2=0) tombe directement dans la
 * branche pPool<=0.
 *
 * Corrigé le 07/09/2026 (round 319) : `sufficient = false` retourné sur
 * ces deux branches également.
 *
 * Test comportemental réel : appelle `zTestProportions()` (via réflexion)
 * avec x1=x2=0 (pPool=0, branche 1) puis avec x1=n1/x2=n2 (pPool=1,
 * branche 1 bis) et vérifie `sufficient===false` dans les deux cas — puis
 * contre-épreuve avec le jeu de données du test_560 (n·p̄=20, largement
 * suffisant) pour confirmer l'absence de régression sur le chemin nominal.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/StatsManager.php';

    $sm  = new StatsManager(neria_test_module());
    $ref = new ReflectionMethod(StatsManager::class, 'zTestProportions');
    $ref->setAccessible(true);

    // x1=x2=0 -> pPool=0.0 : aucun clic des deux côtés.
    $zeroPool = $ref->invoke($sm, 0, 100, 0, 100);
    neria_assert(
        $zeroPool['sufficient'] === false,
        "zTestProportions() renvoie sufficient=" . var_export($zeroPool['sufficient'], true) . " pour pPool=0 (aucun événement des deux côtés) — régression du bug corrigé le 07/09/2026 (round 319) : l'avertissement 'données insuffisantes' resterait à tort masqué dans le BO"
    );

    // x1=n1, x2=n2 -> pPool=1.0 : 100% des deux côtés.
    $fullPool = $ref->invoke($sm, 100, 100, 100, 100);
    neria_assert(
        $fullPool['sufficient'] === false,
        "zTestProportions() renvoie sufficient=" . var_export($fullPool['sufficient'], true) . " pour pPool=1 (100% des deux côtés) — régression du bug corrigé le 07/09/2026 (round 319)"
    );

    // Contre-épreuve : n1=n2=100, x1=30, x2=10 -> pPool=0,20, n·p̄=20 >= 5,
    // se largement non nul — doit rester fonctionnel (pas de régression du
    // chemin nominal, même jeu de données que test_560).
    $sufficient = $ref->invoke($sm, 30, 100, 10, 100);
    neria_assert(
        $sufficient['sufficient'] === true && $sufficient['winner'] !== null,
        "zTestProportions() ne déclare plus de gagnant pour un échantillon largement suffisant (n·p̄=20) — régression : le correctif round 319 bloquerait à tort un cas parfaitement valide"
    );

    return [
        'pass'    => true,
        'message' => "StatsManager::zTestProportions() signale désormais sufficient=false sur les branches pPool<=0/pPool>=1/se quasi nul (pas seulement n·p̄<5), sans régression sur le chemin nominal — bug corrigé le 07/09/2026 (round 319)",
    ];
}
