<?php
/**
 * P8b — pilote : lance bo_action_worker.php pour chaque action du back-office (inventaire), avec les paramètres de
 * tests/functional/inventory/action_params.json (vide par défaut = POST sans champ : test de robustesse), et classe le résultat.
 *
 *   php tests/functional/bo_run_actions.php [--only=a,b] [--lang=fr] [--all]
 *
 * Actions NON lancées (envoi d'e-mail, service externe, destruction de données) : voir $skip — elles relèvent de P3/P4/P9 avec
 * une adresse contrôlée. --all les lance quand même (à ne faire que sur une base jetable).
 * Niveaux : E fatal/exception/avertissement PHP · W aucune bannière ni sortie · I info · M modification non annulée.
 */
$root = str_replace('\\', '/', dirname(__DIR__, 2));
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$inv = json_decode((string) file_get_contents($root . '/tests/functional/inventory/inventory.json'), true);
$names = array_column($inv['bo_actions'], 'name');
$paramsFile = $root . '/tests/functional/inventory/action_params.json';
$specs = is_file($paramsFile) ? (array) json_decode((string) file_get_contents($paramsFile), true) : [];

$skip = [
    'send_manual' => 'envoi d\'e-mail', 'send_test' => 'envoi d\'e-mail', 'send_log_email' => 'envoi d\'e-mail',
    'send_report_now' => 'envoi d\'e-mail', 'send_segment_campaign' => 'envoi d\'e-mails groupés', 'auto_force_run' => 'exécute les automatisations (envois)',
    'process_queue_now' => 'envoie la file d\'e-mails', 'process_webhook_queue_now' => 'appelle les webhooks', 'retry_webhook' => 'appel webhook',
    'test_webhook' => 'appel HTTP externe', 'test_imap_connection' => 'connexion IMAP externe', 'run_bounce_check' => 'lecture IMAP externe',
    'activate_license' => 'serveur de licences', 'connect_postmaster' => 'redirection Google', 'connect_searchconsole' => 'redirection Google',
    'refresh_postmaster' => 'API Google', 'refresh_searchconsole' => 'API Google', 'refresh_seo_api' => 'API SEO externe',
    'refresh_pagespeed' => 'API Google', 'refresh_domain_reputation' => 'requêtes DNS/DoH externes', 'auto_translate_template' => 'API DeepL',
    'auto_translate_variant_b' => 'API DeepL', 'check_voice_profile' => 'API externe', 'health_pixel_test' => 'requête HTTP',
    'gdpr_encrypt_all' => 'chiffre toutes les données', 'reset_all_data' => 'détruit toutes les données', 'reset_all_translations' => 'détruit les traductions',
    'deliverability_score' => 'requêtes DNS externes', 'repair_module_version' => "rejoue les scripts d'upgrade (DDL : commit implicite, non annulable)",
];
if (!empty($opts['only'])) {
    $names = array_values(array_intersect($names, explode(',', (string) $opts['only'])));
}
$lang = $opts['lang'] ?? 'fr';
$worker = str_replace('\\', '/', __DIR__ . '/bo_action_worker.php');
// Sauvegarde des réglages NERIA_* avant toute exécution (les actions tournent sur la base de développement : en cas d'incident,
// ce fichier permet de rétablir chaque valeur — un incident de ce type a déjà coûté la clé de chiffrement le 21/09/2026).
$backupDir = __DIR__ . '/results';
@mkdir($backupDir, 0777, true);
$backupJson = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(str_replace('\\', '/', __DIR__ . '/bo_config_backup.php')));
if (strpos($backupJson, '[') === 0) {
    file_put_contents($backupDir . '/config_backup_' . date('Ymd_His') . '.json', $backupJson);
}
$rows = [];
foreach ($names as $name) {
    if (isset($skip[$name]) && empty($opts['all'])) {
        $rows[] = ['action' => $name, 'level' => 'S', 'note' => 'non lancée : ' . $skip[$name]];
        continue;
    }
    $spec = $specs[$name] ?? [];
    $cmd = escapeshellarg(PHP_BINARY) . ' -d max_execution_time=60 ' . escapeshellarg($worker) . ' ' . escapeshellarg($name)
        . ' --lang=' . escapeshellarg($lang)
        . (isset($spec['params']) ? ' --params64=' . base64_encode(json_encode($spec['params'], JSON_UNESCAPED_UNICODE)) : '')
        . (isset($spec['method']) ? ' --method=' . $spec['method'] : '') . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $r = null;
    if (preg_match('/@@RESULT@@(.*)$/m', $out, $mm)) {
        $r = json_decode($mm[1], true);
    }
    if (!is_array($r)) {
        $rows[] = ['action' => $name, 'level' => 'E', 'note' => 'aucun résultat (arrêt brutal ?) : ' . mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($out))), 0, 160)];
        continue;
    }
    $level = 'I';
    $notes = [];
    if (!empty($r['fatal']) || ($r['status'] ?? '') === 'exception') {
        $level = 'E';
        $notes[] = $r['fatal'] ?? $r['exception'];
    }
    // dépréciations du cœur PrestaShop / vendor (utf8_encode d'egulias, propriété dynamique du logger…) : hors de notre code
    $r['php_messages'] = array_values(array_filter($r['php_messages'] ?? [], static function ($m) {
        return !preg_match('/utf8_encode\(\)|PrestaShopLogger|ShopConstraint|strftime|Swift_/', $m);
    }));
    if (!empty($r['php_messages'])) {
        $level = 'E';
        $notes[] = 'PHP : ' . implode(' ; ', array_slice($r['php_messages'], 0, 3));
    }
    if (!empty($r['not_rolled_back_config']) || !empty($r['not_rolled_back_tables'])) {
        $level = $level === 'E' ? 'E' : 'M';
        $notes[] = 'modification non annulée (config/tables : ' . implode(',', $r['not_rolled_back_tables'] ?? ['NERIA_*']) . ')';
    }
    $hasFeedback = !empty($r['neria_success']) || !empty($r['neria_error']) || !empty($r['neria_warning']) || !empty($r['direct_output_bytes']);
    $effect = !empty($r['config_changes']) || !empty($r['table_deltas']);
    $exited = ($r['status'] ?? '') === 'exited';
    if ($level === 'I' && $exited && !$hasFeedback && !$effect) {
        $notes[] = 'sortie directe (redirection/exit) sans effet mesuré avec ces paramètres';
    } elseif ($level === 'I' && !$hasFeedback && !$effect) {
        $level = 'W';
        $notes[] = 'aucune bannière, aucune sortie directe, aucun effet mesuré';
    }
    $rows[] = [
        'action' => $name, 'level' => $level, 'note' => implode(' | ', $notes),
        'banner' => $r['neria_success'] ?? ($r['neria_error'] ?? ($r['neria_warning'] ?? '')),
        'banner_kind' => isset($r['neria_success']) ? 'succès' : (isset($r['neria_error']) ? 'erreur' : (isset($r['neria_warning']) ? 'avertissement' : '')),
        'direct' => $r['direct_output_head'] ?? '', 'config' => $r['config_changes'] ?? [], 'tables' => $r['table_deltas'] ?? [], 'ms' => $r['ms'] ?? 0,
    ];
    if (empty($opts['quiet'])) {
        echo str_pad($name, 34) . ' ' . $level . ' ' . mb_substr(($rows[count($rows) - 1]['banner'] ?: $rows[count($rows) - 1]['direct']), 0, 60) . "\n";
    }
}
$dir = __DIR__ . '/results';
@mkdir($dir, 0777, true);
file_put_contents($dir . '/P8b_actions.json', json_encode(['generated' => date('c'), 'lang' => $lang, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$c = [];
foreach ($rows as $r) {
    $c[$r['level']] = ($c[$r['level']] ?? 0) + 1;
}
$md = "# P8b — exécution des actions du back-office (POST, paramètres vides sauf spécification)\n\nLangue : {$lang} · actions : " . count($rows) . ' · ' . implode(' · ', array_map(static function ($k, $v) {
    return "$k=$v";
}, array_keys($c), $c)) . "\n\n| Action | Niveau | Bannière / sortie | Effet mesuré | Note |\n|---|---|---|---|---|\n";
foreach ($rows as $r) {
    $eff = trim(implode(',', $r['config'] ?? []) . ' ' . json_encode($r['tables'] ?? [], JSON_UNESCAPED_UNICODE));
    $md .= '| ' . $r['action'] . ' | ' . $r['level'] . ' | ' . str_replace('|', '/', $r['banner'] ?? ($r['direct'] ?? '')) . ' | ' . ($eff === '[]' ? '' : str_replace('|', '/', $eff)) . ' | ' . str_replace('|', '/', $r['note'] ?? '') . " |\n";
}
file_put_contents($dir . '/P8b_actions.md', $md);
echo "\nRésumé : " . implode(' · ', array_map(static function ($k, $v) {
    return "$k=$v";
}, array_keys($c), $c)) . " — détail : tests/functional/results/P8b_actions.md\n";
