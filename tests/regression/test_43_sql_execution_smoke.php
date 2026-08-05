<?php
/**
 * Protection large (mais volontairement PAS exhaustive) contre les requêtes
 * SQL syntaxiquement/sémantiquement cassées dans les méthodes de LECTURE
 * (rapports, audits, tableaux de bord BO) — la même classe de bug que
 * test_41 (round 50, commit da351f9 : entités HTML "&lt;="/"&gt;=" dans une
 * requête SQL, invisible à php -l), mais élargie à un ensemble curé de
 * méthodes across plusieurs Managers plutôt qu'une seule.
 *
 * Pourquoi une liste curée et pas TOUTES les méthodes contenant du SQL
 * (36 fichiers src/*.php en contiennent) : beaucoup de méthodes ont des
 * effets de bord réels (Mail::Send(), création de CartRule, écriture
 * neria_behavioral_sent...) — les exécuter en boucle dans une suite de
 * tests enverrait de vrais emails et modifierait des données réelles.
 * Cette liste ne couvre QUE des méthodes de lecture pure (getXxx/auditXxx),
 * identifiées par relecture de leur signature — c'est une protection
 * étendue, pas une garantie totale. Voir [[feedback_diff_review_before_round_close]]
 * en mémoire pour le contexte de cette décision.
 *
 * Chaque entrée : [classe, comment construire l'instance, méthode, arguments].
 * Le test échoue si UNE SEULE de ces méthodes déclenche une erreur SQL
 * (Db::getNumberError() !== 0) ou lève une exception inattendue.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module     = neria_test_module();
    $modulePath = $module->getLocalPath();
    $db         = neria_test_db();
    $anyCustomer = neria_test_any_customer_id();

    // [classe, args du constructeur (callable retournant l'instance), méthode, args de la méthode]
    $targets = [
        ['StatsManager',              fn () => new StatsManager($module),              'getGlobalReport',       [30, '']],
        ['StatsManager',              fn () => new StatsManager($module),              'getReportByLang',       [30, '']],
        ['StatsManager',              fn () => new StatsManager($module),              'getReportByCountry',    [30]],
        ['StatsManager',              fn () => new StatsManager($module),              'getDailyEvolution',     [30, '']],
        ['StatsManager',              fn () => new StatsManager($module),              'getKpis',               [30]],
        ['StatsManager',              fn () => new StatsManager($module),              'getABTestReport',       ['order_conf', 30]],
        ['StatsManager',              fn () => new StatsManager($module),              'getRevenueStats',       [90]],
        ['StatsManager',              fn () => new StatsManager($module),              'getRevenueDailyByCategory', [30]],
        ['StatsManager',              fn () => new StatsManager($module),              'getKpiTrends',          []],
        ['StatsManager',              fn () => new StatsManager($module),              'getEngagementDailyChart', [30]],
        ['StatsManager',              fn () => new StatsManager($module),              'getOpenHeatmap',        [90]],
        ['StatsManager',              fn () => new StatsManager($module),              'getTopTemplatesByMetric', ['rate_open', 10]],
        ['StatsManager',              fn () => new StatsManager($module),              'getTopTemplatesByRevenue', [10]],
        ['StatsManager',              fn () => new StatsManager($module),              'getMonthlyComparison',  []],
        ['StatsManager',              fn () => new StatsManager($module),              'getHealthScore',        []],
        ['GdprAuditManager',          fn () => new GdprAuditManager($modulePath),      'auditEncryption',       []],
        ['SegmentManager',            fn () => new SegmentManager($module),            'getSegmentCounts',      []],
        ['SegmentManager',            fn () => new SegmentManager($module),            'getCustomersBySegment', [SegmentManager::AMBASSADOR, 5, 0, []]],
        ['SegmentManager',            fn () => new SegmentManager($module),            'getCustomerSegment',    [$anyCustomer]],
        ['ChurnScoreManager',         fn () => new ChurnScoreManager($module),         'getHighRiskCustomers',  [10]],
        ['ChurnScoreManager',         fn () => new ChurnScoreManager($module),         'getCustomerScore',      [$anyCustomer]],
        ['DomainReputationManager',   fn () => new DomainReputationManager($module),   'getCachedReport',       []],
        ['ClvManager',                fn () => new ClvManager($module),                'getTopCustomers',       [10]],
        ['ClvManager',                fn () => new ClvManager($module),                'getCustomerClv',        [$anyCustomer]],
        ['PropensityScoreManager',    fn () => new PropensityScoreManager($module),    'getAlertCustomers',     [10]],
        ['PropensityScoreManager',    fn () => new PropensityScoreManager($module),    'getCustomerScore',      [$anyCustomer]],
        ['CustomerEmailHistoryManager', fn () => new CustomerEmailHistoryManager($module), 'getShopAverageOpenRate', []],
        ['CustomerEmailHistoryManager', fn () => new CustomerEmailHistoryManager($module), 'getEmails',          [$anyCustomer]],
        ['WebhookManager',            fn () => new WebhookManager($module),            'getRecentDeliveries',   [10]],
    ];
    // Note : MonthlyReportManager expose ses agrégations (getMonthKpis,
    // getRevenueByTemplate, getABTestSummary, getBestSendTime...) en PRIVÉ —
    // getRevenueByTemplate() est couverte séparément par test_41 via
    // ReflectionMethod. Les autres pourraient être ajoutées au même endroit
    // que test_41 si une régression y était trouvée un jour.

    $failures = [];

    foreach ($targets as [$className, $factory, $methodName, $args]) {
        $classFile = _PS_MODULE_DIR_ . 'neria/src/' . $className . '.php';
        if (!class_exists($className) && is_file($classFile)) {
            require_once $classFile;
        }
        if (!class_exists($className)) {
            $failures[] = "{$className} introuvable";
            continue;
        }

        try {
            $instance = $factory();
            $db->execute('SELECT 1'); // réinitialise l'état d'erreur avant l'appel
            $result = call_user_func_array([$instance, $methodName], $args);

            if ((int) $db->getNumberError() !== 0) {
                $failures[] = "{$className}::{$methodName}() — erreur SQL : " . $db->getMsgError();
            }
        } catch (\Throwable $e) {
            $failures[] = "{$className}::{$methodName}() — exception : " . $e->getMessage();
        }
    }

    neria_assert(
        empty($failures),
        count($failures) . ' méthode(s) en échec : ' . implode(' | ', $failures)
    );

    return [
        'pass'    => true,
        'message' => 'SQL valide sur ' . count($targets) . ' méthodes de lecture (rapports/audits) across ' . count(array_unique(array_column($targets, 0))) . ' classes',
    ];
}
