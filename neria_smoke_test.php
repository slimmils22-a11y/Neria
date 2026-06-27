<?php
/**
 * NERIA — Smoke Test Pre-Release
 * ================================
 * Lancer DEPUIS le répertoire du module :
 *   cd C:\laragon\www\shop
 *   php modules/neria/neria_smoke_test.php
 *
 * Vérifie :
 *   1. Tables SQL       — 27 tables existent en DB
 *   2. Config keys      — clés NERIA_* présentes
 *   3. Templates core   — fichiers .html/.txt présents
 *   4. Cron CLI         — run() sans CRITICAL/ERROR watchdog
 *   5. Queue            — processQueue() envoie un email de test
 *   6. Watchdog 24h     — aucune erreur résiduelle
 */

// ── Bootstrap ─────────────────────────────────────────────────────
$psRoot = dirname(__FILE__);
// Remonter jusqu'à la racine PS si lancé depuis le répertoire module
while (!file_exists($psRoot . '/config/config.inc.php') && $psRoot !== dirname($psRoot)) {
    $psRoot = dirname($psRoot);
}
if (!file_exists($psRoot . '/config/config.inc.php')) {
    die("ERREUR : config/config.inc.php introuvable. Lancez le script depuis la racine PS.\n");
}
define('_PS_ROOT_DIR_', $psRoot);
error_reporting(E_ERROR);
require_once $psRoot . '/config/config.inc.php';

// ── Helpers terminal ──────────────────────────────────────────────
$isTty = (function_exists('posix_isatty') && posix_isatty(STDOUT));
function c(string $code, string $s): string {
    global $isTty;
    return $isTty ? "\033[{$code}m{$s}\033[0m" : $s;
}
function pass(string $label, string $detail = ''): void {
    global $passed;
    $passed++;
    $d = $detail ? '  ('.substr($detail, 0, 70).')' : '';
    echo c('32', '  ✓ PASS') . "  $label$d\n";
    flush();
}
function fail(string $label, string $detail = ''): void {
    global $failed;
    $failed++;
    $d = $detail ? '  ('.substr($detail, 0, 70).')' : '';
    echo c('31', '  ✗ FAIL') . "  $label$d\n";
    flush();
}
function warn(string $label, string $detail = ''): void {
    global $warnings;
    $warnings++;
    $d = $detail ? '  ('.substr($detail, 0, 70).')' : '';
    echo c('33', '  ⚠ WARN') . "  $label$d\n";
    flush();
}
function section(string $title): void {
    echo "\n" . c('1', "── $title " . str_repeat('─', max(0, 55 - strlen($title)))) . "\n";
    flush();
}

$passed = 0; $failed = 0; $warnings = 0;

// ── Infos ─────────────────────────────────────────────────────────
echo c('1', "\n╔══════════════════════════════════════════════════╗\n");
echo c('1', "║          NERIA — Smoke Test Pre-Release          ║\n");
echo c('1', "╚══════════════════════════════════════════════════╝\n");
echo "  PS root : $psRoot\n";
echo "  Date    : " . date('Y-m-d H:i:s') . "\n";
flush();

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$moduleDir = _PS_MODULE_DIR_ . 'neria/';

// ═══════════════════════════════════════════════════════════════════
// 1. TABLES SQL
// ═══════════════════════════════════════════════════════════════════
section('1 / Tables SQL (27 tables)');

$expectedTables = [
    'neria_translation', 'neria_config', 'neria_custom_variable', 'neria_signature',
    'neria_abtest', 'neria_abtest_translation', 'neria_stat', 'neria_calendar_event',
    'neria_log', 'neria_blacklist', 'neria_attribution', 'neria_behavioral_sent',
    'neria_webhook_queue', 'neria_customer_segment', 'neria_churn_score',
    'neria_translation_history', 'neria_upsell', 'neria_loyalty_points',
    'neria_loyalty_rewards', 'neria_seasonal_campaign', 'neria_certificate',
    'neria_bounces', 'neria_quote', 'neria_reconciliation', 'neria_product_lifespan',
    'neria_propensity_score', 'neria_queue',
];

// executeS() pour SHOW TABLES (getValue() ajoute LIMIT 1 qui casse la syntaxe)
foreach ($expectedTables as $tbl) {
    try {
        $rows = $db->executeS("SHOW TABLES LIKE '{$prefix}{$tbl}'");
        if (!empty($rows)) {
            pass($prefix . $tbl);
        } else {
            fail($prefix . $tbl, 'TABLE MANQUANTE');
        }
    } catch (Throwable $e) {
        fail($prefix . $tbl, $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════
// 2. CLÉS DE CONFIGURATION
// ═══════════════════════════════════════════════════════════════════
section('2 / Clés de configuration');

$expectedConfig = [
    'NERIA_ACTIVE', 'NERIA_COLOR_ACCENT', 'NERIA_COLOR_BACKGROUND',
    'NERIA_CONTAINER_WIDTH', 'NERIA_STATS_ENABLED', 'NERIA_AUTO_LANG',
    'NERIA_CHECKOUT_ABANDONMENT_ENABLED', 'NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED',
    'NERIA_QUOTE_REMINDERS_ENABLED', 'NERIA_REFUND_RECONCILIATION_ENABLED',
    'NERIA_LIFESPAN_ENABLED', 'NERIA_PROPENSITY_ENABLED', 'NERIA_PURCHASE_WINDOW_ENABLED',
    'NERIA_ENCRYPTION_KEY', 'NERIA_EMERGENCY_TOKEN',
];

foreach ($expectedConfig as $key) {
    try {
        $val = Configuration::getGlobalValue($key);
        if ($val !== false && $val !== null && $val !== '') {
            $display = strlen((string)$val) > 30 ? substr($val, 0, 20) . '…' : $val;
            pass($key, $display);
        } else {
            fail($key, 'absent ou vide');
        }
    } catch (Throwable $e) {
        fail($key, $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════
// 3. TEMPLATES CORE
// ═══════════════════════════════════════════════════════════════════
section('3 / Templates core HTML + TXT');

$coreDir = $moduleDir . 'mails/themes/neria_global/core/';

$criticalTemplates = [
    'order_conf', 'birthday', 'first_anniversary', 'relationship_anniversary',
    'win_back', 'post_purchase_care', 'post_purchase_review',
    'abandoned_cart_1', 'abandoned_cart_2', 'abandoned_cart_3',
    'checkout_abandonment', 'reorder_reminder', 'wishlist_reminder',
    'loyalty_tier_upgrade', 'loyalty_recap', 'loyalty_reward_expiry',
    'monthly_report', 'neria_fallback',
    'quote_expiry_48h', 'quote_expiry_day', 'quote_extension_offer',
    'refund_reconciliation_1', 'refund_reconciliation_2', 'refund_reconciliation_3',
    'product_lifespan_reminder', 'order_shipped_delay',
];

$htmlFail = 0; $txtFail = 0;
foreach ($criticalTemplates as $tpl) {
    if (!file_exists($coreDir . $tpl . '.html')) { $htmlFail++; fail("$tpl.html", 'fichier manquant'); }
    if (!file_exists($coreDir . $tpl . '.txt'))  { $txtFail++;  fail("$tpl.txt",  'fichier manquant'); }
}
if ($htmlFail === 0) pass('HTML — ' . count($criticalTemplates) . '/' . count($criticalTemplates) . ' templates présents');
if ($txtFail  === 0) pass('TXT  — ' . count($criticalTemplates) . '/' . count($criticalTemplates) . ' templates présents');

// ═══════════════════════════════════════════════════════════════════
// 4. CRON CLI
// ═══════════════════════════════════════════════════════════════════
section('4 / Cron CLI — BehavioralCronManager::run()');

$cronStart = date('Y-m-d H:i:s');

try {
    // Charger le module sans passer par Module::getInstanceByName()
    require_once $moduleDir . 'neria.php';
    $module = new Neria();
    pass('Neria instanciée directement');

    $cron = new BehavioralCronManager($module);
    $cron->run();
    pass('BehavioralCronManager::run()', 'terminé sans exception');

    // historyUrl() null-safe ?
    $ref = new ReflectionMethod(BehavioralCronManager::class, 'historyUrl');
    $ref->setAccessible(true);
    $url = $ref->invoke(new BehavioralCronManager($module));
    pass('historyUrl() null-safe', $url ?: 'vide (link non init en CLI — attendu)');

} catch (Throwable $e) {
    fail('Cron CLI', $e->getMessage());
}

// Watchdog pendant le cron
$cronLogs = (array) $db->executeS(
    "SELECT level, class, LEFT(message,100) AS msg FROM `{$prefix}neria_log`
     WHERE date_add >= '$cronStart' AND level IN ('error','critical')"
);
if (empty($cronLogs)) {
    pass('Watchdog cron — 0 error/critical');
} else {
    foreach ($cronLogs as $l) {
        fail('Watchdog cron ' . $l['level'], '[' . $l['class'] . '] ' . html_entity_decode(strip_tags($l['msg'])));
    }
}

// ═══════════════════════════════════════════════════════════════════
// 5. QUEUE — enqueue + processQueue
// ═══════════════════════════════════════════════════════════════════
section('5 / Queue — enqueue() + processQueue()');

try {
    $qm = new QueueManager($module);
    $testEmail = (string)(Configuration::getGlobalValue('PS_SHOP_EMAIL') ?: 'admin@test.com');

    $db->execute(
        "INSERT INTO `{$prefix}neria_queue`
         (id_customer, id_shop, id_lang, template, recipient_email, recipient_name,
          vars_json, ref_id, send_at, status, attempts, created_at)
         VALUES (0, 1, 1, 'win_back', '" . pSQL($testEmail) . "', 'Smoke Test',
         '{\"{{firstname}}\":\"Smoke\",\"{{lastname}}\":\"Test\"}',
         99999, DATE_SUB(NOW(), INTERVAL 5 MINUTE), 'pending', 0, NOW())"
    );
    $testId = (int) $db->Insert_ID();
    pass('Entrée de test insérée', "id #$testId");

    $sent = $qm->processQueue();
    $row  = $db->getRow("SELECT status, attempts, error FROM `{$prefix}neria_queue` WHERE id_neria_queue = $testId");

    if ($row && $row['status'] === 'sent') {
        pass('processQueue() — email envoyé', "attempts: {$row['attempts']}");
    } elseif ($row && in_array($row['status'], ['pending', 'failed'])) {
        warn('processQueue() — ' . $row['status'], $row['error'] ?: 'vérifier SMTP');
    } else {
        fail('processQueue()', 'statut inattendu ou entrée introuvable');
    }

    $db->execute("DELETE FROM `{$prefix}neria_queue` WHERE id_neria_queue = $testId");
    pass('Nettoyage entrée de test');

} catch (Throwable $e) {
    fail('Queue', $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════
// 6. WATCHDOG 24H
// ═══════════════════════════════════════════════════════════════════
section('6 / Watchdog — erreurs 24h');

$recentErrors = (array) $db->executeS(
    "SELECT date_add, level, class, LEFT(message,100) AS msg FROM `{$prefix}neria_log`
     WHERE date_add >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
       AND level IN ('error','critical')
     ORDER BY date_add DESC LIMIT 20"
);

// Ignorer les faux positifs connus (env de dev / déjà corrigés)
$ignoredPatterns = [
    'LIMIT 1',                   // ancienne erreur SQL corrigée
    'getPageLink',               // corrigé en v1.0.6
    'pixel_in_html',             // faux positif HealthCheckManager corrigé
    'Réputation domaine test.',  // domaine de test local — pas representatif
    'DomainReputation.*test\.',  // idem
];
$filtered = array_filter($recentErrors, function($e) use ($ignoredPatterns) {
    $msg = html_entity_decode(strip_tags($e['msg']));
    foreach ($ignoredPatterns as $p) {
        if (str_contains($msg, $p)) return false;
    }
    return true;
});

if (empty($filtered)) {
    pass('0 erreur/critical dans le watchdog (24h)');
} else {
    foreach ($filtered as $e) {
        warn("{$e['date_add']} [{$e['level']}] [{$e['class']}]", html_entity_decode(strip_tags($e['msg'])));
    }
}

// ═══════════════════════════════════════════════════════════════════
// RÉSUMÉ
// ═══════════════════════════════════════════════════════════════════
echo "\n" . c('1', str_repeat('═', 54)) . "\n";
echo c('1', "  RÉSUMÉ\n");
echo c('32', "  ✓ $passed PASS") . "\n";
if ($warnings > 0) echo c('33', "  ⚠ $warnings WARN") . "\n";
if ($failed  > 0) echo c('31', "  ✗ $failed FAIL") . "\n";
echo "\n";

if ($failed === 0 && $warnings === 0) {
    echo c('1;32', '  ✅  MODULE PRÊT POUR RELEASE') . "\n\n";
} elseif ($failed === 0) {
    echo c('33', '  ⚠  Vérifier les warnings avant release') . "\n\n";
} else {
    echo c('1;31', '  ❌  RELEASE BLOQUÉE — corriger les FAIL ci-dessus') . "\n\n";
}

exit($failed > 0 ? 1 : 0);
