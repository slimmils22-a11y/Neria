<?php
/**
 * P8d — injection de pannes : pour une série de contrôles Watchdog, provoque l'état défaillant (réglage corrompu, lignes de base de
 * données…) DANS une transaction annulée, exécute le contrôle et vérifie qu'il PASSE de « ok » à « warning »/« error » (ou au statut attendu),
 * puis vérifie le retour à « ok » après annulation. Prouve qu'un contrôle sait réellement détecter le problème qu'il annonce.
 *
 *   php tests/functional/wd_fault_injection.php [--only=a,b] [--quiet]
 * Sortie : results/P8d_faults.json et .md. Code retour 1 si une panne provoquée n'est pas détectée.
 */
require_once __DIR__ . '/../regression/bootstrap.php';
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$module = Module::getInstanceByName('neria');
require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';
AdminTranslator::setLang('fr');
$db = Db::getInstance();
$p = _DB_PREFIX_;
$shop = (int) Context::getContext()->shop->id ?: 1;
$cfg = static function (string $k, $v) {
    Configuration::updateGlobalValue($k, $v);
    Configuration::updateValue($k, $v);
};
$allCrons = ['NERIA_BIRTHDAY_ENABLED', 'NERIA_FIRST_ANNIVERSARY_ENABLED', 'NERIA_RELATIONSHIP_ANNIVERSARY_ENABLED', 'NERIA_REORDER_ENABLED',
    'NERIA_WIN_BACK_ENABLED', 'NERIA_REWARD_EXPIRY_ENABLED', 'NERIA_WISHLIST_ENABLED', 'NERIA_ABANDONED_CART_ENABLED',
    'NERIA_CHECKOUT_ABANDONMENT_ENABLED', 'NERIA_POST_PURCHASE_ENABLED', 'NERIA_SHIPPED_DELAY_ENABLED', 'NERIA_GHOST_CART_ENABLED',
    'NERIA_QUOTE_REMINDERS_ENABLED', 'NERIA_REFUND_RECONCILIATION_ENABLED', 'NERIA_LIFESPAN_ENABLED', 'NERIA_COLLECTION_COMPLETION_ENABLED',
    'NERIA_LOOK_COMPLETION_ENABLED', 'NERIA_PURCHASE_WINDOW_ENABLED'];
$bulkQueue = static function (int $n) use ($db, $p, $shop): void {
    for ($i = 0; $i < $n; $i += 500) {
        $vals = [];
        for ($j = $i; $j < min($n, $i + 500); $j++) {
            $vals[] = "({$shop}, 1, 'order_conf', 'p8d{$j}@example.invalid', NOW(), 'pending')";
        }
        $db->execute("INSERT INTO {$p}neria_queue (id_shop, id_customer, template, recipient_email, send_at, status) VALUES " . implode(',', $vals));
    }
};

/** clé du contrôle => [libellé, préparation de la panne, statuts acceptés après panne] */
$specs = [
    'json_config_integrity' => ['NERIA_LOYALTY_TIERS = JSON invalide', static function () use ($cfg) {
        $cfg('NERIA_LOYALTY_TIERS', '{pas du json');
    }, ['warning', 'error']],
    'multi_sender_json' => ['NERIA_SENDERS_JSON = JSON invalide', static function () use ($cfg) {
        $cfg('NERIA_SENDERS_JSON', '{pas du json');
    }, ['warning', 'error']],
    'all_email_crons_disabled' => ['tous les crons d\'e-mail désactivés', static function () use ($cfg, $allCrons) {
        foreach ($allCrons as $k) {
            $cfg($k, 0);
        }
    }, ['error', 'warning']],
    'crypto_key_health' => ['clé de chiffrement absente', static function () {
        Configuration::deleteByName('NERIA_ENCRYPTION_KEY');
    }, ['error', 'warning']],
    'webhook_failures' => ['webhook en échec récent', static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_webhook_queue (id_shop, event, payload, status, attempts, date_add) VALUES ({$shop}, 'order.created', '{}', 'failed', 5, NOW())");
    }, ['warning', 'error']],
    'bounces_unprocessed' => ['rebond actif récent sans cron de traitement', static function () use ($db, $p, $shop, $cfg) {
        $cfg('NERIA_CRON_LAST_BOUNCES', '');
        $db->execute("INSERT INTO {$p}neria_bounces (email, id_shop, type, status, last_bounce_at, date_add) VALUES ('p8d@example.invalid', {$shop}, 'hard', 'active', NOW(), NOW())");
    }, ['warning', 'error']],
    'queue_overflow' => ['plus de 1000 e-mails en attente', static function () use ($bulkQueue) {
        $bulkQueue(1100);
    }, ['warning']],
    'queue_blocked' => ['e-mail en attente depuis plus de 2 h', static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_queue (id_shop, id_customer, template, recipient_email, send_at, status) VALUES ({$shop}, 1, 'order_conf', 'p8d@example.invalid', DATE_SUB(NOW(), INTERVAL 5 HOUR), 'pending')");
    }, ['warning', 'error']],
    'abtest_stuck' => ['test A/B actif depuis plus de 30 jours', static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_abtest (id_shop, template, variant, variant_name, is_active, date_add, date_upd) VALUES ({$shop}, 'order_conf', 'B', 'p8d', 1, DATE_SUB(NOW(), INTERVAL 40 DAY), NOW())");
    }, ['warning']],
    'consecutive_failures' => ['3 échecs consécutifs', static function () use ($cfg) {
        $cfg('NERIA_CONSECUTIVE_FAILURES', 3);
    }, ['error']],
    'monthly_report_cfg' => ['rapport mensuel activé sans destinataire ni e-mail boutique', static function () use ($cfg) {
        $cfg('NERIA_REPORT_ENABLED', 1);
        $cfg('NERIA_REPORT_RECIPIENTS', '');
        $cfg('PS_SHOP_EMAIL', '');
    }, ['warning']],
    'smtp_quota' => ['quota SMTP journalier atteint', static function () use ($cfg, $db, $p, $shop) {
        $cfg('NERIA_SMTP_DAILY_QUOTA', 1);
        $db->execute("INSERT INTO {$p}neria_stat (id_shop, event_type, template, lang, tracking_token, date_add) VALUES ({$shop}, 'sent', 'order_conf', 'fr', 'p8dtoken', NOW())");
        $db->execute("INSERT INTO {$p}neria_stat (id_shop, event_type, template, lang, tracking_token, date_add) VALUES ({$shop}, 'sent', 'order_conf', 'fr', 'p8dtoken2', NOW())");
    }, ['error', 'warning']],
    'cron_triggered' => ['aucun passage du déclencheur visiteurs', static function () use ($cfg) {
        $cfg('NERIA_DISPLAY_HEADER_LAST_RUN', date('Y-m-d H:i:s', time() - 30 * 86400));
    }, ['warning']],
    'alert_email_invalid' => ['adresse d\'alerte invalide et e-mail boutique invalide', static function () use ($cfg) {
        $cfg('NERIA_ALERT_EMAIL', 'pas-un-email');
        $cfg('PS_SHOP_EMAIL', 'pas-un-email');
    }, ['warning']],
];
if (!empty($opts['only'])) {
    $specs = array_intersect_key($specs, array_flip(explode(',', (string) $opts['only'])));
}

$map = (new ReflectionMethod(HealthCheckManager::class, 'checkMethodMap'));
$map->setAccessible(true);
$methods = $map->invoke(new HealthCheckManager($module));
/** Exécute le contrôle $key et renvoie son résultat (statut + détail). */
$runCheck = static function (string $key) use ($module, $methods): array {
    $hcm = new HealthCheckManager($module);
    $rm = new ReflectionMethod($hcm, $methods[$key]);
    $rm->setAccessible(true);
    return (array) $rm->invoke($hcm);
};

// Mode enfant : un processus par phase (le cache statique de Configuration n'est pas restauré par un ROLLBACK dans le même processus).
if (!empty($opts['child'])) {
    $key = (string) $opts['child'];
    $phase = (string) ($opts['phase'] ?? 'run');
    $out = [];
    if ($phase === 'inject') {
        $db->execute('START TRANSACTION');
        try {
            $specs[$key][1]();
            $out = $runCheck($key);
        } catch (\Throwable $e) {
            $out = ['status' => 'exception', 'detail' => get_class($e) . ' : ' . $e->getMessage()];
        }
        $db->execute('ROLLBACK');
    } else {
        $out = $runCheck($key);
    }
    echo '@@R@@' . json_encode(['status' => $out['status'] ?? '?', 'detail' => mb_substr(trim(strip_tags((string) ($out['detail'] ?? ''))), 0, 110)], JSON_UNESCAPED_UNICODE) . "
";
    exit(0);
}

$phaseRun = static function (string $key, string $phase): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg(strtr(__FILE__, chr(92), '/')) . ' --child=' . escapeshellarg($key) . ' --phase=' . $phase . ' 2>&1';
    $o = (string) shell_exec($cmd);
    return preg_match('/@@R@@(.*)$/m', $o, $m) ? (array) json_decode($m[1], true) : ['status' => 'no-result', 'detail' => mb_substr(trim($o), 0, 100)];
};
$rows = [];
$fail = 0;
foreach ($specs as $key => [$label, $setup, $accepted]) {
    if (!isset($methods[$key])) {
        $rows[] = ['key' => $key, 'label' => $label, 'result' => 'ABSENT', 'note' => 'contrôle absent du registre'];
        $fail++;
        continue;
    }
    $before = $phaseRun($key, 'run');
    $after = $phaseRun($key, 'inject');
    $restored = $phaseRun($key, 'run');
    $ok = in_array($after['status'], $accepted, true) && $restored['status'] === $before['status'];
    $rows[] = ['key' => $key, 'label' => $label, 'before' => $before['status'], 'after' => $after['status'], 'restored' => $restored['status'],
        'result' => $ok ? 'DÉTECTÉ' : 'NON DÉTECTÉ', 'note' => $after['detail'] ?? '', 'changed' => $before['status'] !== $after['status']];
    if (!$ok) {
        $fail++;
    }
    if (empty($opts['quiet'])) {
        echo str_pad($key, 30) . ' ' . str_pad($before['status'] . ' → ' . $after['status'] . ' → ' . $restored['status'], 30) . ' ' . ($ok ? 'DÉTECTÉ' : 'NON DÉTECTÉ') . "
";
    }
}
$dir = __DIR__ . '/results';
@mkdir($dir, 0777, true);
file_put_contents($dir . '/P8d_faults.json', json_encode(['generated' => date('c'), 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$md = "# P8d — injection de pannes sur les contrôles du Watchdog\n\nPanne provoquée dans une transaction annulée ; le contrôle doit changer de statut.\n\n| Contrôle | Panne provoquée | Avant | Après | Restauré | Résultat | Détail |\n|---|---|---|---|---|---|---|\n";
foreach ($rows as $r) {
    $md .= '| ' . $r['key'] . ' | ' . $r['label'] . ' | ' . ($r['before'] ?? '') . ' | ' . ($r['after'] ?? '') . ' | ' . ($r['restored'] ?? '') . ' | ' . $r['result'] . ' | ' . str_replace('|', '/', $r['note']) . " |\n";
}
file_put_contents($dir . '/P8d_faults.md', $md);
echo "\n" . (count($rows) - $fail) . '/' . count($rows) . " pannes détectées — tests/functional/results/P8d_faults.md\n";
exit($fail ? 1 : 0);
