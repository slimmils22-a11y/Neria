<?php
/**
 * Régression : deux angles morts de langue dans MonthlyReportManager,
 * jamais couverts par le correctif round 180 (voir test_368) qui ne
 * traitait que le month_label de deliverReportLocked() :
 *
 * 1. `renderTxt()` (version TXT de l'email, multipart alternative) affichait
 *    le libellé du top3 via `$row['label']` — figé dans la langue globale
 *    ambiante au moment du BUILD du rapport, jamais retraduit pour la
 *    langue du DESTINATAIRE. `renderHtml()` corrigeait déjà ce point
 *    (relecture via `$labels[$row['template']]` après `setLang()`) ;
 *    `renderTxt()` ne le faisait QUE pour les recommandations, jamais pour
 *    le top3.
 *
 * 2. `previewHtml()` calculait `month_label` via `formatMonthLabel($year,
 *    $month)` SANS le paramètre `$lang` explicite, donc AVANT le
 *    `setLang($lang)` de la prévisualisation — le titre de l'aperçu
 *    affichait le mois dans la langue de la session BO de l'employé, pas
 *    celle sélectionnée pour l'aperçu.
 *
 * Bug identifié le 10/09/2026 (round 334, audit MonthlyReportManager/
 * PageSpeedManager).
 *
 * Corrigé le 10/09/2026 (round 334) : `$labels` calculé une seule fois en
 * tête de `renderTxt()` et réutilisé pour le top3 ET les recommandations ;
 * `previewHtml()` transmet désormais `$lang` à `formatMonthLabel()`.
 *
 * Test comportemental réel : (1) construit un `$d` minimal avec un top3
 * dont `label` est délibérément en FRANÇAIS et `template` un vrai
 * template dont la traduction anglaise diffère, invoque `renderTxt($d,
 * 'en')` avec `AdminTranslator` positionné sur 'en' — vérifie que le
 * libellé ANGLAIS apparaît, pas le français figé. (2) appelle
 * `previewHtml('en')` avec la langue ambiante forcée sur 'fr' avant
 * l'appel — vérifie que le HTML retourné contient bien le nom du mois en
 * anglais.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    $originalLang = AdminTranslator::currentLang();

    try {
        // ── Partie 1 : renderTxt() top3 ──────────────────────────────
        $template = 'account';
        AdminTranslator::setLang('fr');
        $frLabel = AdminTranslator::templateLabels()[$template] ?? '';
        AdminTranslator::setLang('en');
        $enLabel = AdminTranslator::templateLabels()[$template] ?? '';
        neria_assert($frLabel !== '' && $enLabel !== '' && $frLabel !== $enLabel, "jeu de test invalide : les libellés fr/en du template '{$template}' ne diffèrent pas ('{$frLabel}' vs '{$enLabel}')");

        $mgr = new MonthlyReportManager(neria_test_module());
        $ref = new ReflectionMethod(MonthlyReportManager::class, 'renderTxt');
        $ref->setAccessible(true);

        $fakeData = [
            'kpis' => ['total_sent' => 100, 'rate_open' => 25, 'rate_click' => 5],
            'month_label' => 'January 2026',
            'rankings' => [
                'top3' => [
                    ['template' => $template, 'label' => $frLabel, 'rate_open' => 42, 'revenue' => 0.0],
                ],
            ],
            'recommendations' => [],
        ];

        // AdminTranslator reste sur 'en' ici (simule le destinataire) —
        // c'est précisément le cas où l'ancien code, figé sur $row['label']
        // (français), divergeait du reste du texte (déjà en anglais via $t()).
        $txt = $ref->invoke($mgr, $fakeData, 'en');

        neria_assert(
            strpos($txt, $enLabel) !== false,
            "renderTxt() n'affiche plus le libellé de template ANGLAIS ('{$enLabel}') dans la section top3 — régression du bug corrigé le 10/09/2026 (round 334). Texte obtenu : {$txt}"
        );
        neria_assert(
            strpos($txt, $frLabel) === false,
            "renderTxt() affiche encore le libellé FRANÇAIS figé ('{$frLabel}') dans la section top3 au lieu de le retraduire pour le destinataire anglophone — régression du bug corrigé le 10/09/2026 (round 334)"
        );

        // ── Partie 2 : previewHtml() month_label ─────────────────────
        // previewHtml() utilise le MOIS COURANT réel (date('n')) — on
        // calcule donc dynamiquement le nom anglais attendu plutôt que de
        // supposer un mois fixe.
        $refFn = new ReflectionMethod(MonthlyReportManager::class, 'formatMonthLabel');
        $refFn->setAccessible(true);
        $expectedEnLabel = $refFn->invoke($mgr, (int) date('Y'), (int) date('n'), 'en');
        $expectedFrLabel = $refFn->invoke($mgr, (int) date('Y'), (int) date('n'), 'fr');
        neria_assert($expectedEnLabel !== $expectedFrLabel, 'jeu de test invalide : le mois courant a le même libellé en fr et en en (coïncidence de calendrier)');

        AdminTranslator::setLang('fr');
        $html = $mgr->previewHtml('en');

        neria_assert(
            strpos($html, $expectedEnLabel) !== false,
            "previewHtml('en') n'affiche plus le mois courant en ANGLAIS ('{$expectedEnLabel}') dans son titre alors que la session BO ambiante est en français — régression du bug corrigé le 10/09/2026 (round 334) : formatMonthLabel() retomberait de nouveau sur la langue ambiante au lieu du paramètre \$lang explicite de la prévisualisation"
        );
        neria_assert(
            strpos($html, $expectedFrLabel) === false,
            "previewHtml('en') affiche encore le mois courant en FRANÇAIS ('{$expectedFrLabel}') — régression du bug corrigé le 10/09/2026 (round 334)"
        );

        return [
            'pass'    => true,
            'message' => "MonthlyReportManager::renderTxt() retraduit désormais le top3 pour la langue du destinataire, et previewHtml() transmet \$lang à formatMonthLabel() — bug corrigé le 10/09/2026 (round 334)",
        ];
    } finally {
        AdminTranslator::setLang($originalLang);
    }
}
