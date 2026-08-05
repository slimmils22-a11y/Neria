<?php
/**
 * Régression : MonthlyReportManager::getRevenueByTemplate() doit exécuter une
 * requête SQL VALIDE — pas seulement du PHP syntaxiquement correct.
 *
 * Bug réel corrigé le 05/08/2026 (round 50) : le correctif du round 48
 * (commit 15d0130, passage à une attribution last-click) contenait
 * littéralement les entités HTML "&lt;=" et "&gt;=" au lieu des opérateurs
 * SQL réels "<=" et ">=" dans la sous-requête de fenêtre d'attribution —
 * une simple faute de frappe en rédigeant le commentaire juste à côté.
 * `php -l` ne détecte rien : une chaîne PHP contenant "&lt;=" est un littéral
 * parfaitement valide, l'erreur n'existe qu'au niveau du SQL généré. La
 * requête échouait alors silencieusement à CHAQUE exécution (Db::executeS()
 * retourne false sur une erreur SQL, absorbé par `?: []` dans le code
 * appelant) : le CA attribué par template retombait à 0 dans le rapport
 * mensuel, sans aucune erreur visible pour le marchand.
 *
 * Ce test exécute réellement la méthode contre la base de dev et vérifie
 * l'ABSENCE d'erreur SQL (Db::getNumberError() === 0) — pas seulement que le
 * code s'exécute sans exception PHP. C'est la seule façon de détecter cette
 * classe de bug (voir [[feedback_diff_review_before_round_close]] en mémoire
 * pour le contexte complet de cette décision).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';

    $mgr = new MonthlyReportManager(neria_test_module());
    $ref = new ReflectionMethod($mgr, 'getRevenueByTemplate');
    $ref->setAccessible(true);

    // Fenêtre large pour maximiser la chance de vraiment exécuter les deux
    // sous-requêtes (directe + attribuée) même sur une base de dev peu peuplée.
    $dateFrom = date('Y-m-d', strtotime('-2 years'));
    $dateTo   = date('Y-m-d');

    $db = neria_test_db();
    // Réinitialise l'état d'erreur avant l'appel : une erreur SQL laissée par
    // un test précédent fausserait la vérification.
    $db->execute('SELECT 1');
    neria_assert(
        (int) $db->getNumberError() === 0,
        'État initial de connexion DB déjà en erreur avant le test — impossible de vérifier getRevenueByTemplate() dans ces conditions'
    );

    $result = $ref->invoke($mgr, $dateFrom, $dateTo);

    neria_assert(is_array($result), 'getRevenueByTemplate() ne retourne plus un tableau');

    neria_assert(
        (int) $db->getNumberError() === 0,
        'getRevenueByTemplate() a généré une erreur SQL (' . $db->getMsgError() . ') — régression possible du bug de syntaxe SQL corrigé le 05/08/2026 (round 50, commit da351f9)'
    );

    return ['pass' => true, 'message' => 'MonthlyReportManager::getRevenueByTemplate() exécute une requête SQL valide (last-click + directe)'];
}
