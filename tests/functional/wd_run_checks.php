<?php
/**
 * P8d — exécute individuellement chacun des contrôles du Watchdog (HealthCheckManager::check*) dans plusieurs langues et vérifie :
 *   W100 exception ou statut « error » interne (check_internal_error)   W101 avertissement PHP levé par le contrôle
 *   W102 résultat mal formé (statut inconnu, détail vide)               W103 clé de traduction brute ou {variable} non résolue dans le détail
 *   W104 français résiduel dans le détail (langue ≠ fr)                 W105 contrôle lent (> 5 s)
 * Relève aussi le statut de chaque contrôle (ok / warning / error) pour repérer un contrôle qui ne peut jamais changer d'état.
 *
 *   php -d memory_limit=1G tests/functional/wd_run_checks.php [--lang=fr,en,ar] [--only=a,b] [--quiet]
 * Sorties : results/P8d_checks.json et .md. Les contrôles à service externe (DeepL, Postmaster) ne sont pas lancés.
 */
require_once __DIR__ . '/../regression/bootstrap.php';
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$langs = !empty($opts['lang']) ? explode(',', (string) $opts['lang']) : ['fr', 'en', 'ar'];
$skipExternal = ['deepl_key_valid' => 'API DeepL', 'postmaster_rep' => 'API Google Postmaster'];
$module = Module::getInstanceByName('neria');
require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
require_once _PS_MODULE_DIR_ . 'neria/src/HealthCheckManager.php';
$hcm = new HealthCheckManager($module);
$map = new ReflectionMethod($hcm, 'checkMethodMap');
$map->setAccessible(true);
$checks = $map->invoke($hcm);
if (!empty($opts['only'])) {
    $checks = array_intersect_key($checks, array_flip(explode(',', (string) $opts['only'])));
}
$ctx = Context::getContext();
$ctx->employee = new Employee((int) Db::getInstance()->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee ORDER BY id_employee'));
$statuses = ['ok', 'warning', 'error', 'info', 'critical'];
$rows = [];
$issues = [];
foreach ($checks as $key => $method) {
    if (isset($skipExternal[$key])) {
        $rows[$key] = ['key' => $key, 'method' => $method, 'status' => 'skipped', 'note' => $skipExternal[$key]];
        continue;
    }
    $rm = new ReflectionMethod($hcm, $method);
    $rm->setAccessible(true);
    $per = [];
    foreach ($langs as $lang) {
        $idLang = (int) Language::getIdByIso($lang) ?: (int) Configuration::get('PS_LANG_DEFAULT');
        $ctx->language = new Language($idLang);
        $ctx->employee->id_lang = $idLang;
        AdminTranslator::reset();
        AdminTranslator::setLang($lang);
        $msgs = [];
        set_error_handler(static function ($no, $str, $file, $line) use (&$msgs) {
            if (!preg_match('#ShopConstraint|strftime|utf8_encode|PrestaShopLogger|Swift_|themes/classic/lang|Failed opening#', $str)) {
                $msgs[] = $str . ' (' . basename($file) . ':' . $line . ')';
            }
            return true;
        });
        $t0 = microtime(true);
        $res = null;
        try {
            $res = $rm->invoke($hcm);
        } catch (\Throwable $e) {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W100', 'level' => 'E', 'msg' => get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
        }
        restore_error_handler();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        foreach (array_slice(array_unique($msgs), 0, 3) as $pm) {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W101', 'level' => 'E', 'msg' => $pm];
        }
        if ($ms > 5000) {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W105', 'level' => 'W', 'msg' => "{$ms} ms"];
        }
        if (!is_array($res)) {
            if ($res !== null || empty(array_filter($issues, static function ($i) use ($key, $lang) {
                return $i['key'] === $key && $i['lang'] === $lang && $i['code'] === 'W100';
            }))) {
                $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W102', 'level' => 'E', 'msg' => 'le contrôle ne renvoie pas un tableau'];
            }
            continue;
        }
        $st = (string) ($res['status'] ?? '');
        $detail = (string) ($res['detail'] ?? '');
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($detail)));
        if (!in_array($st, $statuses, true)) {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W102', 'level' => 'E', 'msg' => "statut inconnu « {$st} »"];
        }
        if ($plain === '') {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W102', 'level' => 'E', 'msg' => 'détail vide'];
        }
        if ($st === 'error' && strpos($key, 'internal') === false && preg_match('/interne|internal/i', $plain)) {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W100', 'level' => 'E', 'msg' => 'erreur interne du contrôle : ' . mb_substr($plain, 0, 120)];
        }
        if (preg_match('/(?<![\w.])[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*(?![\w.\/@-])(?=\s|$)/', $plain, $mm) && preg_match('/^(health|watchdog|msg|stats|help)\./', $mm[0]) === 1) {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W103', 'level' => 'E', 'msg' => "clé de traduction brute : {$mm[0]}"];
        }
        if (preg_match('/\{[a-z_]+\}/', $plain, $mm)) {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W103', 'level' => 'E', 'msg' => "variable non résolue : {$mm[0]}"];
        }
        if ($lang !== 'fr' && preg_match('/\b(Aucun|Aucune|détecté[es]?|vérification|jours|Que faire|sur|dans le|pour le|Vérifiez|manquant[es]?)\b/u', $plain, $mm)) {
            $issues[] = ['key' => $key, 'lang' => $lang, 'code' => 'W104', 'level' => 'W', 'msg' => "français résiduel « {$mm[0]} » : " . mb_substr($plain, 0, 90)];
        }
        $per[$lang] = ['status' => $st, 'ms' => $ms, 'detail' => mb_substr($plain, 0, 140)];
    }
    $first = reset($per) ?: [];
    $rows[$key] = ['key' => $key, 'method' => $method, 'status' => $first['status'] ?? 'n/a', 'ms' => max(array_column($per, 'ms') ?: [0]), 'detail' => $first['detail'] ?? '', 'langs' => count($per)];
    if (empty($opts['quiet'])) {
        echo str_pad($key, 40) . ' ' . str_pad($rows[$key]['status'], 8) . ' ' . $rows[$key]['ms'] . " ms\n";
    }
}
$dir = __DIR__ . '/results';
@mkdir($dir, 0777, true);
$grouped = [];
foreach ($issues as $i) {
    $k = $i['key'] . '|' . $i['code'] . '|' . $i['msg'];
    $grouped[$k] = ($grouped[$k] ?? $i + ['langs' => []]);
    $grouped[$k]['langs'][] = $i['lang'];
}
file_put_contents($dir . '/P8d_checks.json', json_encode(['generated' => date('c'), 'langs' => $langs, 'rows' => array_values($rows), 'issues' => array_values($grouped)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$dist = [];
foreach ($rows as $r) {
    $dist[$r['status']] = ($dist[$r['status']] ?? 0) + 1;
}
$by = ['E' => 0, 'W' => 0];
foreach ($grouped as $g) {
    $by[$g['level']]++;
}
$md = '# P8d — contrôles du Watchdog exécutés un par un' . "\n\nContrôles : " . count($rows) . ' · langues : ' . implode(',', $langs) . ' · statuts : ' . json_encode($dist) . " · anomalies : **{$by['E']} E**, {$by['W']} W\n\n| Niv. | Contrôle | Code | Langues | Détail |\n|---|---|---|---|---|\n";
foreach ($grouped as $g) {
    $md .= '| ' . $g['level'] . ' | ' . $g['key'] . ' | ' . $g['code'] . ' | ' . implode(',', array_unique($g['langs'])) . ' | ' . str_replace('|', '/', $g['msg']) . " |\n";
}
$md .= "\n## Statut de chaque contrôle (état actuel de la base)\n\n| Contrôle | Statut | ms | Détail |\n|---|---|---|---|\n";
foreach ($rows as $r) {
    $md .= '| ' . $r['key'] . ' | ' . $r['status'] . ' | ' . ($r['ms'] ?? '') . ' | ' . str_replace('|', '/', $r['detail'] ?? ($r['note'] ?? '')) . " |\n";
}
file_put_contents($dir . '/P8d_checks.md', $md);
echo "\nContrôles : " . count($rows) . ' · statuts ' . json_encode($dist) . " · anomalies E={$by['E']} W={$by['W']}\n";
