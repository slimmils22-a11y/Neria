<?php
/**
 * Régression : MonthlyReportManager::formatMonthLabel() n'acceptait pas de
 * langue explicite — toujours résolue via AdminTranslator::currentLang()
 * (état global au moment de l'appel), donc figée une seule fois dans
 * sendReport()/previewHtml() AVANT que deliverReportLocked() ne bascule
 * AdminTranslator::setLang() par destinataire. Un destinataire anglophone
 * recevait "Janvier 2026" en titre pendant que tout le reste de l'email
 * était bien retraduit en anglais.
 *
 * Corrigé le 16/08/2026 (round 180) : $lang optionnel ajouté à
 * formatMonthLabel(), month_label recalculé pour CHAQUE langue dans la
 * boucle par destinataire de deliverReportLocked().
 *
 * Test comportemental réel : appelle formatMonthLabel() (privée, via
 * réflexion) avec 'fr' puis 'en' pour le même mois — les deux résultats
 * doivent différer et chacun refléter la bonne langue.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php';

    $mgr = new MonthlyReportManager(neria_test_module());
    $ref = new ReflectionMethod(MonthlyReportManager::class, 'formatMonthLabel');
    $ref->setAccessible(true);

    $labelFr = $ref->invoke($mgr, 2026, 1, 'fr');
    $labelEn = $ref->invoke($mgr, 2026, 1, 'en');

    neria_assert(
        $labelFr === 'Janvier 2026',
        "formatMonthLabel(2026, 1, 'fr') renvoie '{$labelFr}' au lieu de 'Janvier 2026'"
    );
    neria_assert(
        $labelEn === 'January 2026',
        "formatMonthLabel(2026, 1, 'en') renvoie '{$labelEn}' au lieu de 'January 2026' — régression du bug corrigé le 16/08/2026 (round 180) : le paramètre \$lang explicite ne serait plus honoré, month_label resterait figé dans la langue du contexte global au lieu d'être recalculé par destinataire"
    );

    // Vérification structurelle complémentaire : deliverReportLocked() doit
    // bien recalculer month_label dans sa boucle par langue.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/MonthlyReportManager.php');
    neria_assert($src !== false, 'Impossible de lire src/MonthlyReportManager.php');
    neria_assert(
        strpos($src, "\$data['month_label'] = \$this->formatMonthLabel(\$data['year'], \$data['month'], \$recipientIso);") !== false,
        "deliverReportLocked() ne recalcule plus month_label par destinataire — régression du bug corrigé le 16/08/2026 (round 180)"
    );

    return [
        'pass'    => true,
        'message' => "MonthlyReportManager::formatMonthLabel() honore bien un \$lang explicite, recalculé par destinataire dans deliverReportLocked() — bug corrigé le 16/08/2026 (round 180)",
    ];
}
