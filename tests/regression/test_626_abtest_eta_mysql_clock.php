<?php
/**
 * Régression : ABTestManager::estimateDaysRemaining() calculait l'âge réel
 * du test via `time() - strtotime($dateStart)` (horloge PHP), alors que
 * `date_start` est écrit via `NOW()` (horloge MySQL, voir activate()) —
 * même piège horloge PHP/MySQL déjà corrigé de nombreuses fois ailleurs
 * dans ce module (StatsManager, ClvManager, GoldenHourManager,
 * CertificateManager...), jamais porté ici. Si le serveur applicatif (PHP)
 * et le serveur MySQL n'ont pas le même fuseau horaire (fréquent en
 * hébergement mutualisé), le nombre de jours restants affiché au marchand
 * dans l'onglet A/B Testing du BO était faussé.
 *
 * Corrigé le 07/09/2026 (round 319) : le calcul est désormais entièrement
 * fait côté SQL (TIMESTAMPDIFF(SECOND, date_start, NOW())), insensible au
 * fuseau horaire du process PHP.
 *
 * Test comportemental réel : le fuseau PHP par défaut de cet environnement
 * est UTC. On insère date_start = NOW() - 9 jours EXACTEMENT (via SQL, donc
 * ancré sur l'horloge MySQL réelle), puis on bascule le fuseau PHP vers
 * Pacific/Kiritimati (UTC+14) AVANT d'appeler estimateDaysRemaining().
 * Avant le correctif, strtotime($dateStart) sous ce fuseau interprète la
 * chaîne naïve comme un horaire UTC+14, donc la convertit en un instant
 * UTC ~14h PLUS TÔT que la réalité — l'âge calculé (time() - ce timestamp)
 * est donc surestimé d'~14h, faisant passer l'âge arrondi (ceil) de 9 à 10
 * jours et donc le rythme quotidien de 10/j à 9/j, faussant le nombre de
 * jours restants affiché (70 vs valeur différente attendue ci-dessous).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/ABTestManager.php';

    $db     = neria_test_db();
    $prefix = neria_test_prefix();
    $mgr    = new ABTestManager(neria_test_module());

    $template = 'neria_test_abtest_eta_clock_' . time();
    $tableAb  = $prefix . 'neria_abtest';

    // date_start ancré sur NOW() MySQL - 9 jours EXACTEMENT (pas de fenêtre
    // $windowDays plafonnante ici : 9 < 30, donc le vrai âge calculé compte).
    $db->execute(
        "INSERT INTO {$tableAb}
            (id_shop, template, variant, variant_name, description, split_percent,
             is_active, date_start, date_end, date_add, date_upd)
         VALUES
            (1, '" . pSQL($template) . "', 'A', 'Variante A', '', 50,
             1, DATE_SUB(NOW(), INTERVAL 9 DAY), NULL, NOW(), NOW())"
    );

    $originalTz = date_default_timezone_get();

    try {
        // 90 envois en 9 jours réels = 10/jour ; 190 requis → 100 restants
        // → 10 jours restants attendus (avec le bon calcul, âge=9j pile).
        $report = [
            'significance' => [
                'significant' => false,
                'sent_a'      => 90,
                'sent_b'      => 90,
                'min_sample'  => 190,
            ],
        ];

        date_default_timezone_set('Pacific/Kiritimati');
        $daysRemaining = $mgr->estimateDaysRemaining($template, $report, 30);
        date_default_timezone_set($originalTz);

        neria_assert(
            $daysRemaining === 10,
            "estimateDaysRemaining() renvoie {$daysRemaining} au lieu de 10 sous un fuseau PHP décalé (Pacific/Kiritimati) — régression du bug corrigé le 07/09/2026 (round 319) : le calcul de l'âge du test redépendrait de l'horloge PHP (strtotime) au lieu de MySQL (TIMESTAMPDIFF)"
        );

        return [
            'pass'    => true,
            'message' => "ABTestManager::estimateDaysRemaining() calcule bien l'âge du test A/B côté SQL (TIMESTAMPDIFF), insensible au fuseau horaire du serveur applicatif PHP — bug corrigé le 07/09/2026 (round 319)",
        ];
    } finally {
        date_default_timezone_set($originalTz);
        $db->execute("DELETE FROM {$tableAb} WHERE template = '" . pSQL($template) . "'");
    }
}
