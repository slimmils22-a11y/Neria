<?php
/**
 * Régression : ABTestManager::estimateDaysRemaining() doit plafonner
 * $daysElapsed à la fenêtre ($windowDays) utilisée pour produire $report via
 * StatsManager::getABTestReport($template, $windowDays), pas se baser sur le
 * vrai date_start (illimité) du test en base.
 *
 * Bug réel corrigé le 08/08/2026 (round 117) : neria.php::getAbtestReportsMap()
 * appelle getABTestReport($tpl, 30) — sent_a/sent_b dans $report ne comptent
 * donc QUE les envois des 30 derniers jours — puis passait ce même $report à
 * estimateDaysRemaining(), qui divisait $minSent par le nombre de jours
 * écoulés depuis le VRAI date_start du test (illimité, pas plafonné à 30).
 * Pour un test tournant depuis plus longtemps que la fenêtre du rapport, le
 * rythme quotidien ($dailyRate = $minSent / $daysElapsed) était sous-estimé
 * d'un facteur proportionnel à (âge réel du test / fenêtre du rapport),
 * surestimant d'autant le nombre de jours restants ("days_remaining")
 * affiché au marchand dans l'onglet A/B Testing du BO — de façon croissante
 * avec l'âge du test.
 *
 * Test fonctionnel réel : un test A/B actif depuis 90 jours avec 300 envois
 * comptés dans un rapport à fenêtre 30 jours doit produire le même
 * days_remaining, que la fenêtre du rapport soit calculée sur un test de 90
 * jours ou un test de 30 jours pile (le vrai âge du test ne doit PAS influer
 * sur le résultat au-delà de la fenêtre transmise).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $mgr    = new ABTestManager(neria_test_module());

    $template = 'neria_test_abtest_eta_' . time();
    $tableAb  = $prefix . 'neria_abtest';

    // Test A/B actif depuis 90 jours (variante A uniquement nécessaire pour
    // date_start, lu par estimateDaysRemaining()).
    $dateStartOld = date('Y-m-d H:i:s', strtotime('-90 days'));
    $db->execute(
        "INSERT INTO {$tableAb}
            (id_shop, template, variant, variant_name, description, split_percent,
             is_active, date_start, date_end, date_add, date_upd)
         VALUES
            (1, '" . pSQL($template) . "', 'A', 'Variante A', '', 50,
             1, '{$dateStartOld}', NULL, NOW(), NOW())"
    );

    try {
        // $report simulé comme le produirait StatsManager::getABTestReport()
        // avec une fenêtre de 30 jours : 300 envois par variante (pas
        // "significatif" encore — min_sample > sent).
        $report = [
            'significance' => [
                'significant' => false,
                'sent_a'      => 300,
                'sent_b'      => 300,
                'min_sample'  => 1000,
            ],
        ];

        // Avec le fix (windowDays=30 explicite) : dailyRate = 300/30 = 10/j
        // → remaining = 1000-300 = 700 → days_remaining = ceil(700/10) = 70
        $daysRemaining = $mgr->estimateDaysRemaining($template, $report, 30);

        neria_assert(
            $daysRemaining === 70,
            "estimateDaysRemaining() renvoie {$daysRemaining} au lieu de 70 pour un test actif depuis 90 jours avec un rapport à fenêtre 30 jours (300/300 envois, 1000 requis) — régression du bug corrigé le 08/08/2026 (round 117) : le rythme quotidien serait de nouveau calculé sur l'âge réel du test (90j → ~3,3/j) au lieu de la fenêtre du rapport (30j → 10/j), plus que doublant l'ETA affichée"
        );

        // Contrôle : un test actif depuis seulement 10 jours (donc PLUS
        // JEUNE que la fenêtre de 30) ne doit PAS être plafonné à 30 — le
        // vrai âge (10j) doit rester le dénominateur, sans quoi le fix
        // introduirait un biais inverse (ETA sous-estimée pour un jeune
        // test à fort volume).
        $template2 = 'neria_test_abtest_eta2_' . time();
        $dateStartYoung = date('Y-m-d H:i:s', strtotime('-10 days'));
        $db->execute(
            "INSERT INTO {$tableAb}
                (id_shop, template, variant, variant_name, description, split_percent,
                 is_active, date_start, date_end, date_add, date_upd)
             VALUES
                (1, '" . pSQL($template2) . "', 'A', 'Variante A', '', 50,
                 1, '{$dateStartYoung}', NULL, NOW(), NOW())"
        );
        // 100 envois en 10 jours réels = 10/j (même rythme, mais ici l'âge
        // réel < fenêtre de 30, donc daysElapsed doit rester 10, pas 30).
        $report2 = [
            'significance' => [
                'significant' => false,
                'sent_a'      => 100,
                'sent_b'      => 100,
                'min_sample'  => 200,
            ],
        ];
        // dailyRate = 100/10 = 10/j → remaining = 200-100 = 100 → 10 jours
        $daysRemaining2 = $mgr->estimateDaysRemaining($template2, $report2, 30);
        neria_assert(
            $daysRemaining2 === 10,
            "estimateDaysRemaining() renvoie {$daysRemaining2} au lieu de 10 pour un test actif depuis seulement 10 jours — le plafond à \$windowDays ne doit s'appliquer que lorsque l'âge réel DÉPASSE la fenêtre, pas raccourcir artificiellement un jeune test"
        );
    } finally {
        $db->execute("DELETE FROM {$tableAb} WHERE template IN ('" . pSQL($template) . "', '" . pSQL($template2 ?? '') . "')");
    }

    return [
        'pass'    => true,
        'message' => "ABTestManager::estimateDaysRemaining() plafonne bien le dénominateur à la fenêtre du rapport transmise, sans raccourcir artificiellement un test plus jeune que cette fenêtre",
    ];
}
