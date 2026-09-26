<?php
/**
 * Régression (P8b, 26/09/2026) : le contrôle « clés du dictionnaire BO orphelines » (Watchdog + diagnostic de code) signalait
 * ~330 clés sur une installation saine, dont ~160 construites dynamiquement ('report.' . $k, 'common.month_' . $n,
 * 'gdpr.reg.' . $table . '.label') ; le bandeau « anomalies détectées » ne disparaissait jamais. 16 clés réellement mortes
 * (aucune référence dans le code) traînaient dans data/admin_translations.json.
 *
 * Corrigé : détection des préfixes dynamiques (isDynamicallyReferencedTradKey) + retrait des 16 clés mortes.
 *
 * Test : le contrôle répond « ok » sur l'installation de test ; le détecteur reconnaît les trois formes dynamiques et
 * signale toujours une vraie orpheline.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();
    $h = new HealthCheckManager($module);
    $dyn = new ReflectionMethod($h, 'isDynamicallyReferencedTradKey');
    $dyn->setAccessible(true);
    $hay = "\$t('report.' . \$k); \$x = 'common.month_' . \$n; \$y = 'gdpr.reg.' . \$table . '.label';";
    neria_assert($dyn->invoke($h, 'report.kpi_sent', $hay) === true, "'report.' . \$k non reconnu");
    neria_assert($dyn->invoke($h, 'common.month_7', $hay) === true, "'common.month_' . \$n non reconnu");
    neria_assert($dyn->invoke($h, 'gdpr.reg.neria_stat.label', $hay) === true, "'gdpr.reg.' . \$table non reconnu");
    neria_assert($dyn->invoke($h, 'help.cle_vraiment_orpheline', $hay) === false, 'Une vraie orpheline est masquée');

    $dict = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['common.loading', 'help.copy_confirmed_label', 'watchdog.hint_chmod', 'stats.visibility_title'] as $dead) {
        neria_assert(!isset($dict[$dead]), "Clé morte réapparue : {$dead}");
    }

    $rm = new ReflectionMethod($h, 'checkOrphanedAdminTranslationKeys');
    $rm->setAccessible(true);
    $r = $rm->invoke($h);
    neria_assert(($r['status'] ?? '') === 'ok', 'Le contrôle des clés orphelines doit être ok : ' . json_encode($r, JSON_UNESCAPED_UNICODE));

    return ['pass' => true, 'message' => "Le contrôle des clés BO orphelines reconnaît les clés dynamiques et répond « ok » (16 clés mortes retirées) — corrigé le 26/09/2026"];
}
