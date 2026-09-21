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

/** Insère $n lignes de statistiques de type $type ; $daysAgo = 0 : maintenant, sinon réparties sur les $daysAgo derniers jours (au moins 1 jour d'écart). */
$stat = static function (int $n, string $type, int $daysAgo) use ($db, $p, $shop): void {
    $when = $daysAgo === 0 ? 'NOW()' : 'DATE_SUB(NOW(), INTERVAL 1 + (n % ' . max(1, $daysAgo) . ') DAY)';
    $db->execute("INSERT INTO {$p}neria_stat (id_shop, template, lang, tracking_token, event_type, date_add) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < {$n}) SELECT {$shop}, 'order_conf', 'fr', CONCAT('p8d', REPLACE(UUID(), '-', '')), '{$type}', {$when} FROM s");
};
/** Insère $n lignes de statistiques de type $type datées de « maintenant - $interval » (ex. '40 DAY'), avec $mpp = 0 par défaut. */
$statAt = static function (int $n, string $type, string $interval) use ($db, $p, $shop): void {
    $db->execute("INSERT INTO {$p}neria_stat (id_shop, template, lang, tracking_token, event_type, date_add) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < {$n}) SELECT {$shop}, 'order_conf', 'fr', CONCAT('p8d', REPLACE(UUID(), '-', '')), '{$type}', DATE_SUB(NOW(), INTERVAL {$interval}) FROM s");
};
$clearStats = static function () use ($db, $p, $shop): void {
    $db->execute("DELETE FROM {$p}neria_stat WHERE id_shop = {$shop}");
};

/** clé du contrôle => [libellé, préparation de la panne, statuts acceptés après panne, (optionnel) remise à zéro préalable → le contrôle doit alors répondre « ok »] */
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
    // ── Lot 2 : seuils sur les statistiques (base vidée DANS la transaction pour partir d'un état « ok » déterministe) ──
    'open_rate_7d' => ['60 envois en 7 jours, aucune ouverture', static function () use ($stat) {
        $stat(60, 'sent', 1);
    }, ['error', 'warning'], static function () use ($clearStats) {
        $clearStats();
    }],
    'bounce_rate' => ['30 envois en 24 h et 10 rebonds actifs', static function () use ($stat, $db, $p, $shop) {
        $stat(30, 'sent', 0);
        $db->execute("INSERT INTO {$p}neria_bounces (email, id_shop, type, status, last_bounce_at, date_add) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 10) SELECT CONCAT('p8d', n, '@example.invalid'), {$shop}, 'hard', 'active', NOW(), NOW() FROM s");
    }, ['warning', 'error'], static function () use ($clearStats, $db, $p) {
        $clearStats();
        $db->execute("DELETE FROM {$p}neria_bounces");
    }],
    'queue_failed_rate' => ["50 % d'échecs sur 30 jours", static function () use ($db, $p, $shop) {
        for ($i = 0; $i < 6; $i++) {
            $db->execute("INSERT INTO {$p}neria_queue (id_shop, id_customer, template, recipient_email, send_at, status, sent_at) VALUES ({$shop}, 1, 'order_conf', 'p8d{$i}@example.invalid', NOW(), 'sent', NOW())");
            $db->execute("INSERT INTO {$p}neria_queue (id_shop, id_customer, template, recipient_email, send_at, status) VALUES ({$shop}, 1, 'order_conf', 'p8f{$i}@example.invalid', NOW(), 'failed')");
        }
    }, ['warning', 'error'], static function () use ($db, $p) {
        $db->execute("DELETE FROM {$p}neria_queue");
    }],
    'click_rate_7d' => ['ouvertures sans aucun clic', static function () use ($stat) {
        $stat(10, 'open', 1);
    }, ['warning'], static function () use ($clearStats) {
        $clearStats();
    }],
    'unsubscribe_spike' => ['110 envois et 10 désabonnements en 7 jours', static function () use ($stat, $db, $p, $shop) {
        $stat(110, 'sent', 1);
        for ($i = 1; $i <= 10; $i++) {
            $db->execute("INSERT INTO {$p}neria_preferences (id_shop, id_customer, email, category, subscribed, date_upd) VALUES ({$shop}, {$i}00000, 'p8d{$i}@example.invalid', 'all', 0, NOW())");
        }
    }, ['warning', 'error'], static function () use ($clearStats, $db, $p) {
        $clearStats();
        $db->execute("DELETE FROM {$p}neria_preferences");
    }],
    'send_volume_spike' => ["100 envois aujourd'hui contre quelques-uns les jours précédents", static function () use ($stat) {
        $stat(60, 'sent', 6); // ~10 envois par jour les 6 jours précédents (le contrôle ignore les moyennes < 10)
        $stat(100, 'sent', 0);
    }, ['warning', 'error'], static function () use ($clearStats) {
        $clearStats();
    }],
    // ── Lot 2 : volumes de tables ──
    'history_table_size' => ["plus de 50 000 lignes d'historique de traduction", static function () use ($db, $p, $shop) {
        $db->execute('SET SESSION cte_max_recursion_depth = 200000');
        $db->execute("INSERT INTO {$p}neria_translation_history (id_shop, template_key, lang_code, translation_key, old_value, new_value, author, date_add) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 50100) SELECT {$shop}, 'order_conf', 'fr', 'k', 'a', 'b', 'p8d', NOW() FROM s");
    }, ['warning']],
    'behavioral_dedup' => ['plus de 50 000 réservations comportementales', static function () use ($db, $p, $shop) {
        $db->execute('SET SESSION cte_max_recursion_depth = 200000');
        $db->execute("INSERT INTO {$p}neria_behavioral_sent (id_customer, template, ref_id, id_shop, sent_at) WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 50100) SELECT n, 'birthday', n, {$shop}, NOW() FROM s");
    }, ['warning', 'error']],
    // ── Lot 2 : réglages et intégrité ──
    'smtp_config' => ["envoi par la fonction mail() de PHP", static function () use ($cfg) {
        $cfg('PS_MAIL_METHOD', '1');
    }, ['warning', 'error']],
    'version_sync' => ['version installée plus ancienne que le code', static function () {
        Configuration::updateGlobalValue('NERIA_INSTALLED_VERSION', '1.0.1');
    }, ['warning']],
    'stored_secrets_decryptable' => ['secret chiffré illisible (clé DeepL)', static function () use ($cfg) {
        $cfg('NERIA_DEEPL_KEY', 'ENC:' . base64_encode(str_repeat('x', 40)));
    }, ['error', 'warning']],
    'crypto_key' => ['clé de chiffrement absente (contrôle de présence)', static function () {
        Configuration::deleteByName('NERIA_ENCRYPTION_KEY');
    }, ['error', 'warning']],
    'php_memory_limit' => ['memory_limit PHP à 60 Mo (lancement du processus)', static function () {
    }, ['error', 'warning'], null, '60M'],
    'abtest_variant_pair' => ['test A/B actif avec une seule variante', static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_abtest (id_shop, template, variant, variant_name, is_active, date_add, date_upd) VALUES ({$shop}, 'order_conf', 'A', 'p8d', 1, NOW(), NOW())");
    }, ['warning'], static function () use ($db, $p) {
        $db->execute("DELETE FROM {$p}neria_abtest");
    }],
    'churn_propensity_freshness' => ['scores de désabonnement calculés il y a 5 jours', static function () use ($shop) {
        Configuration::updateValue('NERIA_CHURN_LAST_RUN', date('Y-m-d H:i:s', time() - 5 * 86400), false, null, $shop);
    }, ['warning', 'error']],
    'orphaned_voucher_reservations' => ["réservation de bon d'anniversaire sans bon créé depuis plus de 24 h", static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_birthday_voucher (id_customer, year, id_cart_rule, voucher_code, id_shop, created_at) VALUES (9999999, 2000, 0, 'P8D', {$shop}, DATE_SUB(NOW(), INTERVAL 3 DAY))");
    }, ['warning', 'error'], static function () use ($db, $p) {
        $db->execute("DELETE FROM {$p}neria_birthday_voucher WHERE id_cart_rule = 0");
    }],
    // ── Lot 3 ──
    'sent_reconciliation' => ['installé depuis 10 jours, aucun envoi enregistré', static function () use ($cfg) {
        $cfg('NERIA_INSTALLED_AT', date('Y-m-d H:i:s', time() - 10 * 86400));
    }, ['warning'], static function () use ($clearStats, $cfg) {
        $clearStats();
        $cfg('NERIA_INSTALLED_AT', date('Y-m-d H:i:s')); // installation récente : pas encore d'alerte
    }],
    'template_staleness' => ['modèle envoyé régulièrement puis plus rien depuis 30 jours', static function () use ($statAt) {
        $statAt(6, 'sent', '40 DAY');
    }, ['warning'], static function () use ($clearStats) {
        $clearStats();
    }],
    'engagement_trend' => ["taux d'ouverture divisé par plus de deux d'une période à l'autre", static function () use ($statAt) {
        $statAt(60, 'sent', '10 DAY');
        $statAt(30, 'open', '10 DAY');
        $statAt(60, 'sent', '1 DAY');
    }, ['warning', 'error'], static function () use ($clearStats) {
        $clearStats();
    }],
    'milestone_voucher_cartrule' => ['bon de palier dont la règle panier a disparu', static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_milestone_voucher (id_customer, milestone, id_cart_rule, voucher_code, id_shop, created_at) VALUES (9999999, 3, 99999999, 'P8D', {$shop}, NOW())");
    }, ['warning']],
    'collection_look_products' => ['collection dont les produits n\'existent plus', static function () use ($db, $p) {
        $db->execute("INSERT INTO {$p}neria_collection (name, product_ids, active, created_at) VALUES ('P8D', '[99999998,99999999]', 1, NOW())");
    }, ['warning', 'error']],
    'orphaned_waitlist_claims' => ['réservation liste d\'attente commencée il y a 3 h sans envoi', static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_waitlist (id_customer, id_product, id_product_attribute, id_shop, registered_at, notified_at, claim_started_at) VALUES (9999999, 1, 0, {$shop}, NOW(), NULL, DATE_SUB(NOW(), INTERVAL 3 HOUR))");
    }, ['warning']],
    'loyalty_integrity' => ['solde de points de fidélité négatif', static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_loyalty_points (id_customer, id_stat, event_type, points, id_shop, date_add) VALUES (9999999, 0, 'p8d', -50, {$shop}, NOW())");
    }, ['error', 'warning']],
    'campaign_empty_seg' => ['campagne saisonnière ciblant un segment vide', static function () use ($db, $p, $shop) {
        $segment = (string) array_key_first(SegmentManager::getAllSegments()); // un segment reconnu (« vip »… selon la version)
        $db->execute("INSERT INTO {$p}neria_seasonal_campaign (id_shop, name, template, annual_date, days_before, is_active, target_segment, date_add, date_upd) VALUES ({$shop}, 'P8D', 'birthday', '12-25', 7, 1, '" . pSQL($segment) . "', NOW(), NOW())");
    }, ['warning', 'error'], static function () use ($db, $p) {
        $db->execute("DELETE FROM {$p}neria_seasonal_campaign");
        $db->execute("DELETE FROM {$p}neria_customer_segment");
    }],
    'residual_vars_recent' => ['e-mails récents envoyés avec une variable de contenu manquante', static function () use ($db, $p, $shop) {
        $db->execute("INSERT INTO {$p}neria_log (id_shop, level, template, class, message, date_add) VALUES ({$shop}, 'warning', 'order_conf', 'EmailRenderer', 'residual_vars_stripped {\"vars\":\"p8d_var\"}', NOW())");
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
            $clean = null;
            if (isset($specs[$key][3])) {
                $specs[$key][3](); // remise à zéro DANS la transaction : le contrôle doit alors répondre « ok »
                $clean = $runCheck($key)['status'] ?? '?';
            }
            $specs[$key][1]();
            $out = $runCheck($key);
            $out['clean'] = $clean;
        } catch (\Throwable $e) {
            $out = ['status' => 'exception', 'detail' => get_class($e) . ' : ' . $e->getMessage()];
        }
        $db->execute('ROLLBACK');
    } else {
        $out = $runCheck($key);
    }
    echo '@@R@@' . json_encode(['status' => $out['status'] ?? '?', 'clean' => $out['clean'] ?? null, 'detail' => mb_substr(trim(strip_tags((string) ($out['detail'] ?? ''))), 0, 110)], JSON_UNESCAPED_UNICODE) . "
";
    exit(0);
}

$phaseRun = static function (string $key, string $phase) use ($specs): array {
    $mem = ($phase === 'inject' && isset($specs[$key][4])) ? $specs[$key][4] : '1G'; // certaines pannes se règlent au lancement du processus
    $cmd = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $mem . ' '. escapeshellarg(strtr(__FILE__, chr(92), '/')) . ' --child=' . escapeshellarg($key) . ' --phase=' . $phase . ' 2>&1';
    $o = (string) shell_exec($cmd);
    return preg_match('/@@R@@(.*)$/m', $o, $m) ? (array) json_decode($m[1], true) : ['status' => 'no-result', 'detail' => mb_substr(trim($o), 0, 100)];
};
$rows = [];
$fail = 0;
foreach ($specs as $key => $spec) {
    [$label, $setup, $accepted] = $spec;
    if (!isset($methods[$key])) {
        $rows[] = ['key' => $key, 'label' => $label, 'result' => 'ABSENT', 'note' => 'contrôle absent du registre'];
        $fail++;
        continue;
    }
    $before = $phaseRun($key, 'run');
    $after = $phaseRun($key, 'inject');
    $restored = $phaseRun($key, 'run');
    $ok = in_array($after['status'], $accepted, true) && $restored['status'] === $before['status'] && ($after['clean'] ?? 'ok') === 'ok';
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
/**
 * Contrôles STATIQUES (analyse du code source, des gabarits ou des e-mails) : la panne est un fichier fautif posé dans un faux module
 * temporaire (dossier regtestP8d), supprimé ensuite. Le contrôle doit répondre « ok » sur le faux module sain puis « warning » avec le fichier fautif.
 * clé => [libellé, [chemin relatif => contenu], statuts acceptés]
 */
$fakeSpecs = [
    'hardcoded_date_format' => ['date(\'d/m/Y\') codé en dur', ['src/Bad.php' => "<?php\n\$d = date('d/m/Y');\n"], ['warning']],
    'hardcoded_decimal_format' => ['number_format avec virgule décimale codée en dur', ['src/Bad.php' => "<?php\n\$s = number_format(\$x, 2, ',', ' ');\n"], ['warning']],
    'rtl_hardcoded_align' => ['text-align:left codé en dur dans un e-mail', ['mails/themes/t/core/bad.html' => "<p style=\"text-align:left\">x</p>\n"], ['warning']],
    'chained_str_replace' => ['str_replace(array_keys(...)) enchaîné', ['src/Bad.php' => "<?php\n\$r = str_replace(array_keys(\$a), \$b, \$c);\n"], ['warning']],
    'imap_timeout_missing' => ['imap_open() sans imap_timeout()', ['src/Bad.php' => "<?php\n\$c = imap_open('{h}INBOX', 'u', 'p');\n"], ['warning', 'error']],
    'unescaped_like_metachars' => ['LIKE construit avec une variable non échappée', ['src/Bad.php' => "<?php\n\$q = \"SELECT * FROM t WHERE x LIKE '%\$term%'\";\n"], ['warning']],
    'fragile_neriaconfig_usage' => ['gabarit lisant neriaConfig.adminUrl', ['views/templates/admin/bad.tpl' => "<script>var u = neriaConfig.adminUrl;</script>\n"], ['warning']],    'dev_tool_residue' => ['référence à mailpit (outil de développement) laissée dans un gabarit', ['views/templates/admin/bad.tpl' => "<!-- mailpit -->\n"], ['warning']],
    'cron_strict_date_equality' => ['DATE(colonne) = CURDATE() dans un cron', ['src/BadManager.php' => "<?php\n\$q = \"SELECT 1 FROM t WHERE DATE(sent_at) = CURDATE()\";\n"], ['warning']],
    'tpl_js_escape_missing' => ['variable Smarty non échappée dans un getElementById', ['views/templates/admin/bad.tpl' => "<script>document.getElementById('x_{\$name}');</script>\n"], ['warning']],
];
$fakeRows = [];
if (empty($opts['child'])) {
    $fakeName = 'regtestP8d';
    $fakeRoot = _PS_MODULE_DIR_ . $fakeName;
    $rrmdir = static function (string $d) use (&$rrmdir): void {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$d/$f") ? $rrmdir("$d/$f") : @unlink("$d/$f");
            }
        }
        @rmdir($d);
    };
    foreach ($fakeSpecs as $key => [$label, $files, $accepted]) {
        if (!empty($opts['only']) && !in_array($key, explode(',', (string) $opts['only']), true)) {
            continue;
        }
        if (!isset($methods[$key])) {
            $fakeRows[] = ['key' => $key, 'label' => $label, 'result' => 'ABSENT', 'note' => 'contrôle absent du registre', 'before' => '-', 'after' => '-', 'restored' => '-'];
            $fail++;
            continue;
        }
        $rrmdir($fakeRoot);
        @mkdir($fakeRoot . '/src', 0777, true);
        @mkdir($fakeRoot . '/views/templates/admin', 0777, true);
        @mkdir($fakeRoot . '/mails/themes/t/core', 0777, true);
        file_put_contents($fakeRoot . '/src/Ok.php', "<?php\nclass OkP8d {}\n");
        $fakeModule = clone $module;
        $fakeModule->name = $fakeName;
        $lp = new ReflectionProperty($fakeModule, 'local_path'); // protégée ; certains contrôles utilisent getLocalPath() plutôt que le nom du module
        $lp->setAccessible(true);
        $lp->setValue($fakeModule, $fakeRoot . '/');
        $runFake = static function () use ($fakeModule, $methods, $key): array {
            $hcm = new HealthCheckManager($fakeModule);
            $rm = new ReflectionMethod($hcm, $methods[$key]);
            $rm->setAccessible(true);
            return (array) $rm->invoke($hcm);
        };
        try {
            $before = $runFake();
            foreach ($files as $rel => $content) {
                @mkdir(dirname($fakeRoot . '/' . $rel), 0777, true);
                file_put_contents($fakeRoot . '/' . $rel, $content);
            }
            $after = $runFake();
            foreach (array_keys($files) as $rel) {
                @unlink($fakeRoot . '/' . $rel);
            }
            $restored = $runFake();
            $note = mb_substr(trim(strip_tags((string) ($after['detail'] ?? ''))), 0, 110);
        } catch (\Throwable $e) {
            $before = $after = $restored = ['status' => 'exception'];
            $note = get_class($e) . ' : ' . $e->getMessage();
        }
        $rrmdir($fakeRoot);
        $ok = ($before['status'] ?? '') === 'ok' && in_array($after['status'] ?? '', $accepted, true) && ($restored['status'] ?? '') === 'ok';
        $fakeRows[] = ['key' => $key, 'label' => $label . ' (faux module)', 'before' => $before['status'] ?? '?', 'after' => $after['status'] ?? '?', 'restored' => $restored['status'] ?? '?', 'clean' => null,
            'result' => $ok ? 'DÉTECTÉ' : 'NON DÉTECTÉ', 'note' => $note, 'changed' => true];
        if (!$ok) {
            $fail++;
        }
        if (empty($opts['quiet'])) {
            echo str_pad($key, 30) . ' ' . str_pad(($before['status'] ?? '?') . ' → ' . ($after['status'] ?? '?') . ' → ' . ($restored['status'] ?? '?'), 30) . ' ' . ($ok ? 'DÉTECTÉ' : 'NON DÉTECTÉ') . " [faux module]\n";
        }
    }
    $rows = array_merge($rows, $fakeRows);
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
