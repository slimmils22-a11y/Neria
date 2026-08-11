<?php
/**
 * Régression : GdprAuditManager::generateReport() doit afficher un
 * dénominateur cohérent avec le numérateur — 4 axes (unsub=3, retention=N,
 * pii=1, crypto=1), pas 3.
 *
 * Bug réel corrigé le 09/08/2026 (round 144) : runAudit() calcule $issues
 * (numérateur) comme la somme de 4 axes distincts (unsub+retention+pii+
 * crypto), mais le rapport PDF affichait un dénominateur ne comptant que
 * `count(retention.rows) + 3 + 1` — un axe à 1 critère (pii OU crypto)
 * manquait. Document à vocation "preuve de conformité RGPD" affichant un
 * total de critères analysés incohérent avec le nombre réel.
 *
 * Test comportemental réel : construit un $audit factice avec un nombre de
 * lignes retention connu (5), appelle generateReport() directement, et
 * vérifie que le texte rendu affiche bien 5+3+1+1=10 critères, pas 9.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/GdprAuditManager.php';

    $mgr = new GdprAuditManager(_PS_MODULE_DIR_ . 'neria');

    $retentionRows = array_fill(0, 5, [
        'label' => 'x', 'note' => '', 'months' => 12, 'oldest' => '-', 'overdue' => 0, 'ok' => true,
    ]);

    $fakeAudit = [
        'unsubscribe'  => ['issues' => 0, 'checks' => []],
        'retention'    => ['issues' => 0, 'rows' => $retentionRows],
        'pii'          => ['issues' => 0, 'legal_in_layout' => true, 'map' => []],
        'crypto'       => ['issues' => 0],
        'score'        => 'A',
        'grade_color'  => '#4a9e6b',
        'issues'       => 2,
        'generated_at' => date('Y-m-d H:i:s'),
    ];

    $html = $mgr->generateReport($fakeAudit, 'Regtest Shop');

    neria_assert(
        strpos($html, '2 point(s)') !== false,
        "le rapport ne contient pas le numérateur attendu ('2 point(s)') — jeu de test invalide, generateReport() a peut-être changé de format"
    );

    neria_assert(
        strpos($html, 'sur les 10 critères analysés') !== false,
        "le rapport n'affiche pas '10 critères analysés' (5 lignes retention + 3 unsub + 1 pii + 1 crypto) — régression du bug corrigé le 09/08/2026 (round 144) : le dénominateur redeviendrait incohérent avec les 4 axes réellement analysés"
    );
    neria_assert(
        strpos($html, 'sur les 9 critères analysés') === false,
        "le rapport affiche encore l'ancien dénominateur erroné (9 au lieu de 10)"
    );

    return [
        'pass'    => true,
        'message' => "GdprAuditManager::generateReport() affiche bien un dénominateur cohérent avec les 4 axes réellement analysés",
    ];
}
