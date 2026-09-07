<?php
/**
 * Régression : MonthlyReportManager::t() substituait les variables via
 * str_replace() appelé EN BOUCLE sur la chaîne déjà transformée — même
 * piège déjà identifié et corrigé dans AdminTranslator::tVars() (round
 * 304) et TranslationEngine::resolveVariables() : si la valeur d'UNE
 * variable contient littéralement le texte "{autre_placeholder}", ce texte
 * injecté se fait à son tour remplacer par la valeur de l'autre variable
 * au passage suivant — corruption silencieuse du message (sujet/corps du
 * rapport mensuel envoyé au marchand), dépendant de l'ordre d'itération du
 * tableau $vars.
 *
 * Corrigé le 07/09/2026 (round 316) : strtr() — un seul passage simultané
 * sur le texte ORIGINAL, comme AdminTranslator::tVars().
 *
 * Test comportemental réel : appelle t() via réflexion sur la clé réelle
 * 'report.rec_low_open' (2 placeholders {template}/{rate}) en passant
 * délibérément la valeur '{rate}' pour 'template' — avec le bug, le texte
 * "{rate}" injecté via template se ferait substituer une 2e fois lors du
 * remplacement de 'rate', produisant DEUX occurrences de la valeur "99" au
 * lieu d'une seule.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';

    $mgr = new MonthlyReportManager(neria_test_module());
    $ref = new ReflectionMethod(MonthlyReportManager::class, 't');
    $ref->setAccessible(true);

    $result = $ref->invoke($mgr, 'report.rec_low_open', [
        'template' => '{rate}',
        'rate'     => '99',
    ]);

    neria_assert(
        is_string($result) && strpos($result, '{rate}') !== false,
        "MonthlyReportManager::t() ne préserve plus littéralement '{rate}' injecté via 'template' — régression du bug corrigé le 07/09/2026 (round 316) : le texte '{rate}' inséré à la place de {template} se ferait de nouveau substituer une 2e fois par la valeur de 'rate', corrompant le message du rapport mensuel. Résultat obtenu : " . var_export($result, true)
    );

    neria_assert(
        substr_count($result, '99') === 1,
        "MonthlyReportManager::t() produit " . substr_count($result, '99') . " occurrence(s) de '99' au lieu d'une seule — régression du bug corrigé le 07/09/2026 (round 316) : str_replace() en boucle substituerait de nouveau le texte '{rate}' injecté via template, en plus du vrai placeholder {rate}. Résultat obtenu : " . var_export($result, true)
    );

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::t() substitue bien ses variables en un seul passage simultané (strtr()), sans cascade de remplacement — bug corrigé le 07/09/2026 (round 316)",
    ];
}
