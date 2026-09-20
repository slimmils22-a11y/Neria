<?php
/**
 * P1 — Inventaire automatique de TOUT ce que le module expose, pour la campagne de tests fonctionnels
 * exhaustifs (feuille de route du 20/09/2026). LECTURE SEULE : rien n'est envoyé, aucune donnée modifiée.
 *
 * Usage (depuis la racine du module, environnement Laragon) :
 *   php tests/functional/build_inventory.php
 *
 * Sorties (tests/functional/inventory/) :
 *   - inventory.json : inventaire complet et détaillé (source de vérité de la campagne)
 *   - matrix.csv     : une ligne numérotée par élément à tester (statut ⬜ à l'origine)
 *   - ../INVENTORY.md : résumé lisible (chiffres, lacunes repérées)
 * Un script compagnon (check_matrix.php) compare la matrice à un inventaire régénéré : aucun élément ne
 * peut être oublié en silence, et toute fonctionnalité ajoutée plus tard apparaît comme « nouvelle ligne ».
 */
require_once __DIR__ . '/../regression/bootstrap.php';

$root = str_replace('\\', '/', dirname(__DIR__, 2));
$read = static function (string $rel) use ($root): string {
    $p = $root . '/' . $rel;
    return is_file($p) ? str_replace("\r\n", "\n", (string) file_get_contents($p)) : '';
};
$lineOf = static function (string $src, int $offset): int {
    return substr_count(substr($src, 0, $offset), "\n") + 1;
};
$block = static function (string $src, int $from, int $max = 8000): string {
    $open = strpos($src, '{', $from);
    if ($open === false) {
        return '';
    }
    $depth = 0;
    $len = min(strlen($src), $open + $max);
    for ($i = $open; $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $open, $i - $open + 1);
            }
        }
    }
    return substr($src, $open, $max);
};

$inv = ['generated' => date('Y-m-d H:i:s'), 'module_version' => '', 'counts' => []];
$main = $read('neria.php');
if (preg_match('/const\s+VERSION\s*=\s*[\'"]([\d.]+)/', $main, $m)) {
    $inv['module_version'] = $m[1];
}

// ── 1. Templates d'administration : liste, onglets ─────────────────────────────────────────────
$tplFiles = glob($root . '/views/templates/admin/*.tpl') ?: [];
sort($tplFiles);
$tpl = [];
foreach ($tplFiles as $f) {
    $tpl[basename($f)] = str_replace("\r\n", "\n", (string) file_get_contents($f));
}
$allTplText = implode("\n", $tpl);
$uiSources = $tpl;
foreach (glob($root . '/views/js/*.js') ?: [] as $jf) {
    $uiSources[basename($jf)] = str_replace("\r\n", "\n", (string) file_get_contents($jf));
}

// ── 2. Actions du back-office (neria_action) ──────────────────────────────────────────────────
$actions = [];
preg_match_all("/getValue\('neria_action'\)\s*===\s*'([a-z_0-9]+)'/", $main, $mm, PREG_OFFSET_CAPTURE);
foreach ($mm[1] as [$name, $off]) {
    $line = $lineOf($main, $off);
    $lineStart = strrpos(substr($main, 0, $off), "\n");
    $prefix = ltrim(substr($main, $lineStart === false ? 0 : $lineStart + 1, $off - ($lineStart === false ? 0 : $lineStart + 1)));
    if (strncmp($prefix, '//', 2) === 0 || strncmp($prefix, '*', 1) === 0) {
        continue; // occurrence dans un commentaire
    }
    $condEnd = strpos($main, "\n", $off);
    $cond = substr($main, $off, $condEnd - $off);
    $b = $block($main, $off);
    $entry = $actions[$name] ?? ['name' => $name, 'lines' => [], 'post_only' => false, 'ajax' => false, 'banner' => false, 'managers' => [], 'ui' => []];
    $entry['lines'][] = $line;
    $entry['post_only'] = $entry['post_only'] || strpos($cond, "REQUEST_METHOD'] === 'POST'") !== false;
    $entry['ajax'] = $entry['ajax'] || (strpos($b, 'application/json') !== false || preg_match('/\b(die|exit)\s*[\(;]/', $b) === 1);
    $entry['banner'] = $entry['banner'] || strpos($b, 'neria_success') !== false || strpos($b, 'neria_error') !== false
        || strpos($b, 'assignQuoteMsg(') !== false || strpos($b, 'redirectAdmin') !== false
        || preg_match('/neria_\'\s*\.\s*\$/', $b) === 1;
    if (preg_match_all('/new\s+\\\\?([A-Z][A-Za-z]+Manager)\b/', $b, $mgr)) {
        $entry['managers'] = array_values(array_unique(array_merge($entry['managers'], $mgr[1])));
    }
    $actions[$name] = $entry;
}
// actions déclarées dans les tableaux (in_array / match) : présence dans les gabarits
foreach ($actions as $name => &$a) {
    foreach ($uiSources as $tn => $txt) {
        // nom de l'action entouré de quotes / précédé de = / suivi de & ou d'un espace : toutes syntaxes
        // (formulaire, fetch JS, lien, data-attribut) — gabarits ET views/js/*.js
        if (preg_match('/[\'"=]' . preg_quote($name, '/') . '[\'"&\s]/', $txt)) {
            $a['ui'][] = $tn;
        }
    }
    $a['ui'] = array_values(array_unique($a['ui']));
}
unset($a);
ksort($actions);
$inv['bo_actions'] = array_values($actions);

// ── 3. Boutons et formulaires des gabarits ────────────────────────────────────────────────────
$buttons = [];
foreach ($tpl as $tn => $txt) {
    $find = static function (string $re) use ($txt, &$buttons, $tn, $lineOf): void {
        if (!preg_match_all($re, $txt, $mm2, PREG_OFFSET_CAPTURE)) {
            return;
        }
        foreach ($mm2[0] as [$full, $off]) {
            $label = '';
            if (preg_match("/neria_admin\s+key=['\"]([a-z0-9_.]+)['\"]/i", $full, $lk)) {
                $label = $lk[1];
            } else {
                $label = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/\{[^}]*\}/', '', $full))));
                $label = mb_substr($label, 0, 50);
            }
            $attrs = [];
            foreach (['id', 'name', 'type', 'value', 'onclick', 'data-action', 'data-reset', 'href'] as $at) {
                if (preg_match('/\b' . $at . '="([^"]*)"/', $full, $av)) {
                    $attrs[$at] = mb_substr($av[1], 0, 80);
                }
            }
            $before = substr($txt, 0, $off);
            $formPos = strrpos($before, '<form');
            $formEnd = strrpos($before, '</form>');
            $inForm = $formPos !== false && ($formEnd === false || $formEnd < $formPos);
            $formAction = '';
            if ($inForm) {
                $formHtml = substr($txt, $formPos, min(1500, $off - $formPos + 200));
                if (preg_match('/name="neria_action"\s+value="([a-z_0-9]+)"/', $formHtml, $fa)) {
                    $formAction = $fa[1];
                }
            }
            $buttons[] = ['tpl' => $tn, 'line' => $lineOf($txt, $off), 'label' => $label, 'attrs' => $attrs, 'in_form' => $inForm, 'form_action' => $formAction];
        }
    };
    $find('/<button\b[^>]*>.*?<\/button>/s');
    $find('/<input\b[^>]*type="submit"[^>]*>/s');
    $find('/<a\b[^>]*class="[^"]*neria-btn[^"]*"[^>]*>.*?<\/a>/s');
}
usort($buttons, static fn ($a, $b) => [$a['tpl'], $a['line']] <=> [$b['tpl'], $b['line']]);
$inv['buttons'] = $buttons;

// ── 4. Onglets et sous-sections (navigation) ──────────────────────────────────────────────────
$nav = $tpl['navigation.tpl'] ?? '';
preg_match_all('/neria_tab=([a-z_]+)(#[a-z0-9_-]+)?/', $nav, $nm, PREG_SET_ORDER);
$tabs = [];
$subs = [];
foreach ($nm as $x) {
    $tabs[$x[1]] = true;
    if (!empty($x[2])) {
        $subs[$x[1] . $x[2]] = ['tab' => $x[1], 'anchor' => substr($x[2], 1)];
    }
}
$inv['tabs'] = array_keys($tabs);
$inv['subsections'] = array_values($subs);

// ── 5. Templates d'e-mail ─────────────────────────────────────────────────────────────────────
$core = $root . '/mails/themes/neria_global/core';
$cat = [];
require_once $root . '/src/PreferencesManager.php';
if (class_exists('PreferencesManager') && defined('PreferencesManager::TEMPLATE_CAT')) {
    $cat = PreferencesManager::TEMPLATE_CAT;
}
$srcTexts = [];
foreach (glob($root . '/src/*.php') ?: [] as $f) {
    $srcTexts[basename($f)] = str_replace("\r\n", "\n", (string) file_get_contents($f));
}
$srcTexts['neria.php'] = $main;
$noSender = ['HealthCheckManager.php', 'NeriaTools.php', 'StatsManager.php', 'PreferencesManager.php', 'ABTestManager.php', 'AdminTranslator.php', 'ConfigManager.php', 'TranslationEngine.php', 'CustomerEmailHistoryManager.php'];
$transl = json_decode($read('data/translations.json'), true) ?: [];
$hcSrc0 = $read('src/HealthCheckManager.php');
$nativePs = [];
if (preg_match('/\$nativeOverrides\s*=\s*\[(.*?)\];/s', $hcSrc0, $nv) && preg_match_all("/'([a-z_0-9]+)'/", $nv[1], $nn)) {
    $nativePs = $nn[1];
}
$inv['test_residue'] = [];
$emails = [];
foreach (glob($core . '/*.html') ?: [] as $f) {
    $name = basename($f, '.html');
    if ($name === 'layout') {
        continue;
    }
    if (strpos($name, '__test') === 0) {
        $inv['test_residue'][] = 'mails/themes/neria_global/core/' . basename($f); // résidu d'un test interrompu
        continue;
    }
    $html = str_replace("\r\n", "\n", (string) file_get_contents($f));
    $senders = [];
    foreach ($srcTexts as $sf => $st) {
        if (in_array($sf, $noSender, true)) {
            continue;
        }
        if (preg_match("/['\"]" . preg_quote($name, '/') . "['\"]/", $st)) {
            $senders[] = $sf;
        }
    }
    preg_match_all("/neria_trad\s+key='([a-z0-9_]+)'/", $html, $tk);
    preg_match_all('/\{([a-z][a-z0-9_]*)\}/i', $html, $vars);
    $kind = $senders ? 'code Neria' : (in_array($name, $nativePs, true) ? 'natif PrestaShop (déclenché par le cœur ou un module PS)' : 'dynamique (campagne saisonnière / envoi manuel / A-B)');
    $emails[] = [
        'name'      => $name,
        'trigger_kind' => $kind,
        'has_txt'   => is_file($core . '/' . $name . '.txt'),
        'category'  => $cat[$name] ?? '',
        'senders'   => $senders,
        'trad_keys' => count(array_unique($tk[1])),
        'variables' => array_values(array_unique($vars[1])),
    ];
}
usort($emails, static fn ($a, $b) => strcmp($a['name'], $b['name']));
$inv['email_templates'] = $emails;
$labelsI18n = json_decode($read('data/template_labels_i18n.json'), true) ?: [];
$inv['templates_without_file'] = array_values(array_diff(array_keys($labelsI18n), array_column($emails, 'name')));

// ── 6. Automatisations / tâches planifiées ────────────────────────────────────────────────────
$beh = $read('src/BehavioralCronManager.php');
$steps = [];
if (preg_match_all("/runStep\('([^']+)'/", $beh, $rs)) {
    $steps = array_values(array_unique($rs[1]));
}
$heartbeats = [];
foreach ($srcTexts as $sf => $st) {
    if (preg_match_all("/cronHeartbeat\('([a-z_]+)'/", $st, $hb)) {
        foreach ($hb[1] as $h) {
            $heartbeats[$h][] = $sf;
        }
    }
}
$throttles = [];
foreach ($srcTexts as $sf => $st) {
    if (preg_match_all("/const\s+(CRON_LAST_[A-Z_0-9]+|CFG_[A-Z_]*LAST[A-Z_]*)\s*=\s*'([A-Za-z_0-9]+)'/", $st, $th, PREG_SET_ORDER)) {
        foreach ($th as $t) {
            $throttles[$t[2]] = $sf . '::' . $t[1];
        }
    }
}
foreach ($srcTexts as $sf => $st) {
    if (preg_match_all("/'(NERIA_(?:CRON_LAST_[A-Z_0-9]+|[A-Z_0-9]*LAST[A-Z_0-9]*))'/", $st, $th2)) {
        foreach ($th2[1] as $k) {
            $throttles[$k] = $throttles[$k] ?? $sf;
        }
    }
}
ksort($throttles);
$inv['automations'] = [
    'behavioral_steps' => $steps,
    'heartbeat_jobs'   => array_map(static fn ($v) => array_values(array_unique($v)), $heartbeats),
    'throttle_keys'    => $throttles,
];

// ── 7. Hooks ──────────────────────────────────────────────────────────────────────────────────
$hooks = [];
if (preg_match('/const\s+HOOKS\s*=\s*\[(.*?)\n\s*\];/s', $main, $hm) && preg_match_all("/'([A-Za-z]+)'/", preg_replace('#//[^\n]*#', '', $hm[1]), $hn)) {
    foreach ($hn[1] as $h) {
        $method = 'hook' . ucfirst($h);
        $has = preg_match('/function\s+' . $method . '\s*\(/', $main, $hx, PREG_OFFSET_CAPTURE) === 1;
        $hooks[] = ['hook' => $h, 'handler' => $has ? $method : '', 'line' => $has ? $lineOf($main, $hx[0][1]) : 0];
    }
}
$inv['hooks'] = $hooks;

// ── 8. Contrôleurs front ──────────────────────────────────────────────────────────────────────
$fronts = [];
foreach (glob($root . '/controllers/front/*.php') ?: [] as $f) {
    $n = basename($f, '.php');
    if ($n === 'index') {
        continue;
    }
    $t = str_replace("\r\n", "\n", (string) file_get_contents($f));
    preg_match_all("/(?:getValue\('([a-z_0-9]+)'|\\\$_(?:GET|POST|REQUEST)\['([a-z_0-9]+)'\])/", $t, $pm);
    $params = array_values(array_unique(array_filter(array_merge($pm[1], $pm[2]))));
    preg_match_all('/public function (\w+)\(/', $t, $pf);
    $fronts[] = ['name' => $n, 'params' => $params, 'methods' => $pf[1], 'lines' => substr_count($t, "\n") + 1];
}
$inv['front_controllers'] = $fronts;

// ── 9. Contrôles Watchdog ─────────────────────────────────────────────────────────────────────
$hc = $read('src/HealthCheckManager.php');
$wd = [];
$i = strpos($hc, "'hardcoded_french_text' => 'checkHardcodedFrenchText'");
if ($i !== false) {
    $st = strrpos(substr($hc, 0, $i), '= [');
    $en = strpos($hc, '];', $i);
    preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'(check[A-Za-z0-9]+)'/", substr($hc, $st, $en - $st), $wm, PREG_SET_ORDER);
    foreach ($wm as $x) {
        $wd[] = ['key' => $x[1], 'method' => $x[2]];
    }
}
$inv['watchdog_checks'] = $wd;

// ── 10. Centre de contrôle ────────────────────────────────────────────────────────────────────
require_once $root . '/src/ConfigManager.php';
$cc = [];
if (defined('ConfigManager::CONTROL_CENTER_REGISTRY')) {
    foreach (ConfigManager::CONTROL_CENTER_REGISTRY as $item) {
        $cc[] = ['key' => (string) ($item['key'] ?? ''), 'raw' => array_map(static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), array_diff_key($item, ['key' => 1]))];
    }
}
$inv['control_center'] = $cc;

// ── 11. Tables SQL et clés de configuration ───────────────────────────────────────────────────
$sql = $read('sql/install.sql');
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(?:PREFIX_|_DB_PREFIX_)?(neria_[a-z_0-9]+)`?/i', $sql, $tb);
$inv['sql_tables'] = array_values(array_unique($tb[1]));
$cfgKeys = [];
foreach ($srcTexts as $sf => $st) {
    if (preg_match_all("/'(NERIA_[A-Z_0-9]+)'/", $st, $ck)) {
        foreach ($ck[1] as $k) {
            $cfgKeys[$k] = true;
        }
    }
}
ksort($cfgKeys);
$inv['config_keys'] = array_keys($cfgKeys);

// ── 12. Base de traductions : complétude par langue ───────────────────────────────────────────
$langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];
$dictReport = static function (array $d) use ($langs): array {
    $missing = array_fill_keys($langs, 0);
    foreach ($d as $k => $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($langs as $l) {
            if (!isset($row[$l]) || trim((string) $row[$l]) === '') {
                $missing[$l]++;
            }
        }
    }
    return ['keys' => count($d), 'missing_by_lang' => array_filter($missing)];
};
$admin = json_decode($read('data/admin_translations.json'), true) ?: [];
$tplLabels = json_decode($read('data/template_labels_i18n.json'), true) ?: [];
$inv['translations'] = [
    'admin_translations.json'    => $dictReport($admin),
    'template_labels_i18n.json'  => $dictReport($tplLabels),
    'translations.json (top-level keys)' => ['keys' => count($transl)],
];

// ── 13. Couverture des méthodes publiques par les tests de régression ─────────────────────────
$testText = '';
foreach (glob($root . '/tests/regression/test_*.php') ?: [] as $f) {
    $testText .= "\n" . (string) file_get_contents($f);
}
$methodCov = [];
foreach ($srcTexts as $sf => $st) {
    if ($sf === 'neria.php') {
        continue;
    }
    preg_match_all('/public\s+(?:static\s+)?function\s+([a-zA-Z_0-9]+)\s*\(/', $st, $pmv);
    $total = 0;
    $unref = [];
    foreach (array_unique($pmv[1]) as $mth) {
        if (strpos($mth, '__') === 0) {
            continue;
        }
        $total++;
        if (strpos($testText, '->' . $mth . '(') === false && strpos($testText, '::' . $mth . '(') === false
            && strpos($testText, "'" . $mth . "'") === false && strpos($testText, '"' . $mth . '"') === false) {
            $unref[] = $mth;
        }
    }
    $methodCov[str_replace('.php', '', $sf)] = ['public_methods' => $total, 'unreferenced_by_tests' => $unref];
}
ksort($methodCov);
$inv['method_coverage'] = $methodCov;

// ── Comptes ───────────────────────────────────────────────────────────────────────────────────
$inv['counts'] = [
    'bo_actions'        => count($inv['bo_actions']),
    'buttons'           => count($inv['buttons']),
    'admin_templates'   => count($tpl),
    'tabs'              => count($inv['tabs']),
    'subsections'       => count($inv['subsections']),
    'email_templates'   => count($emails),
    'behavioral_steps'  => count($steps),
    'heartbeat_jobs'    => count($heartbeats),
    'throttle_keys'     => count($throttles),
    'hooks'             => count($hooks),
    'front_controllers' => count($fronts),
    'watchdog_checks'   => count($wd),
    'control_center'    => count($cc),
    'sql_tables'        => count($inv['sql_tables']),
    'config_keys'       => count($inv['config_keys']),
    'languages'         => count($langs),
];

// ── Matrice : une ligne par élément à tester ──────────────────────────────────────────────────
$rows = [];
$n = static function (string $prefix, int $i): string {
    return sprintf('%s-%03d', $prefix, $i);
};
$i = 0;
foreach ($inv['tabs'] as $t) {
    $rows[] = [$n('TAB', ++$i), 'Onglet BO', $t, 'ouverture + affichage sans erreur + traductions (fr,en,ar,ja + échantillon)', 'navigation.tpl', 'P8'];
}
$i = 0;
foreach ($inv['subsections'] as $s) {
    $rows[] = [$n('SEC', ++$i), 'Sous-section BO', $s['tab'] . '#' . $s['anchor'], 'ouverture, chaque champ, enregistrement, persistance', 'navigation.tpl', 'P8'];
}
$i = 0;
foreach ($inv['bo_actions'] as $a) {
    $how = ($a['post_only'] ? 'POST' : 'GET/POST') . ($a['ajax'] ? ', réponse AJAX/fichier' : ', bannière ' . ($a['banner'] ? 'oui' : 'NON'));
    $ui = $a['ui'] ? implode('+', $a['ui']) : 'AUCUN gabarit (action orpheline ?)';
    $rows[] = [$n('BO', ++$i), 'Action BO', $a['name'], "{$how} ; UI : {$ui} ; managers : " . implode(',', $a['managers']), 'neria.php:' . $a['lines'][0], $a['ajax'] ? 'P8' : 'P8'];
}
$i = 0;
$occ = [];
foreach ($inv['buttons'] as $b) {
    $attr = $b['attrs']['id'] ?? ($b['attrs']['name'] ?? ($b['attrs']['data-action'] ?? ''));
    // Identifiant STABLE : « gabarit — libellé » + rang d'occurrence (#2, #3…) quand le même libellé revient dans le
    // même gabarit — sans le rang, deux boutons identiques seraient fusionnés (et un seul serait testé).
    $occKey = $b['tpl'] . '|' . $b['label'];
    $occ[$occKey] = ($occ[$occKey] ?? 0) + 1;
    $elem = $b['tpl'] . ' — ' . $b['label'] . ($occ[$occKey] > 1 ? ' #' . $occ[$occKey] : '');
    $rows[] = [$n('UI', ++$i), 'Bouton BO', $elem, 'clic : résultat, message traduit, persistance' . ($b['form_action'] ? " ; formulaire→{$b['form_action']}" : '') . ($attr ? " ; #{$attr}" : ''), $b['tpl'] . ':' . $b['line'], 'P8'];
}
$i = 0;
foreach ($inv['email_templates'] as $e) {
    $phase = 'P2';
    $senders = implode(',', array_map(static fn ($s) => str_replace('.php', '', $s), $e['senders']));
    $trigger = $senders ?: $e['trigger_kind'];
    $rows[] = [$n('EM', ++$i), 'Template e-mail', $e['name'], "19 langues HTML+" . ($e['has_txt'] ? 'TXT' : 'TXT MANQUANT') . " ; catégorie " . ($e['category'] ?: '—') . " ; émetteur : {$trigger}", 'mails/themes/neria_global/core/' . $e['name'] . '.html', $phase . ' + déclencheur (P3/P4/P5)'];
}
foreach ($inv['templates_without_file'] as $t) {
    $rows[] = [$n('EM', ++$i), 'Template e-mail (sans fichier HTML)', $t, 'rendu interne au module (ex. rapport mensuel) : 19 langues, envoi réel', 'data/template_labels_i18n.json', 'P4'];
}
$i = 0;
foreach ($steps as $s) {
    $rows[] = [$n('AU', ++$i), 'Automatisation (BehavioralCron)', $s, 'déclenchement réel (temps simulé), envoi, dédoublonnage, Mode Silence, blacklist, préférences', 'BehavioralCronManager::run', 'P4'];
}
foreach ($heartbeats as $h => $files) {
    $rows[] = [$n('AU', ++$i), 'Tâche planifiée (heartbeat)', $h, 'exécution réelle, heartbeat reflète succès/échec', implode(',', array_unique($files)), 'P4'];
}
$i = 0;
foreach ($hooks as $h) {
    $rows[] = [$n('HK', ++$i), 'Hook PrestaShop', $h['hook'], $h['handler'] ? 'déclencheur réel + effet observé' : 'HANDLER INTROUVABLE', $h['handler'] ? 'neria.php:' . $h['line'] : 'neria.php', 'P3/P7'];
}
$i = 0;
foreach ($fronts as $f) {
    $rows[] = [$n('FC', ++$i), 'Contrôleur front', $f['name'], 'paramètres : ' . implode(',', $f['params']) . ' ; cas nominal + invalide + sécurité', 'controllers/front/' . $f['name'] . '.php', 'P7'];
}
$i = 0;
foreach ($wd as $w) {
    $rows[] = [$n('WD', ++$i), 'Contrôle Watchdog', $w['key'], 'cas OK + cas alerte provoqué ; titre et message traduits', 'HealthCheckManager::' . $w['method'], 'P8'];
}
$i = 0;
foreach ($cc as $c) {
    $rows[] = [$n('CC', ++$i), 'Interrupteur Centre de contrôle', $c['key'], 'ON/OFF : effet réel observé sur le module', 'ConfigManager::CONTROL_CENTER_REGISTRY', 'P8'];
}
$i = 0;
foreach ($inv['sql_tables'] as $t) {
    $rows[] = [$n('DB', ++$i), 'Table SQL', $t, 'création à l\'installation, migration, purge RGPD, désinstallation', 'sql/install.sql', 'P13'];
}

// NERIA_INVENTORY_OUT : dossier de sortie alternatif (utilisé par check_matrix.php pour comparer sans rien écraser).
$outDir = getenv('NERIA_INVENTORY_OUT') ?: ($root . '/tests/functional/inventory');
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents($outDir . '/inventory.json', json_encode($inv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
// La matrice reçoit les RÉSULTATS des tests (statut, preuve, notes) : on ne l'écrase jamais. Si elle existe déjà,
// la matrice fraîche est écrite à côté (matrix.fresh.csv) et check_matrix.php signale les écarts.
$matrixPath = is_file($outDir . '/matrix.csv') ? $outDir . '/matrix.fresh.csv' : $outDir . '/matrix.csv';
if ($matrixPath !== $outDir . '/matrix.csv') {
    echo "matrix.csv existe déjà : matrice fraîche écrite dans matrix.fresh.csv (résultats existants préservés)
";
}
$fh = fopen($matrixPath, 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['id', 'categorie', 'element', 'a_verifier', 'source', 'phase', 'statut', 'preuve', 'notes'], ';');
foreach ($rows as $r) {
    fputcsv($fh, array_merge($r, ['⬜', '', '']), ';');
}
fclose($fh);

// ── Résumé Markdown ───────────────────────────────────────────────────────────────────────────
$md = "# Inventaire P1 — campagne de tests fonctionnels\n\n";
$md .= "Généré le {$inv['generated']} — module v{$inv['module_version']} — **" . count($rows) . " lignes** dans `inventory/matrix.csv`.\n\n";
$md .= "| Catégorie | Éléments |\n|---|---|\n";
foreach ($inv['counts'] as $k => $v) {
    $md .= "| {$k} | {$v} |\n";
}
$orph = array_filter($inv['bo_actions'], static fn ($a) => $a['ui'] === []);
$noBanner = array_filter($inv['bo_actions'], static fn ($a) => !$a['banner'] && !$a['ajax']);
$md .= "\n## Points d'attention repérés automatiquement\n\n";
$md .= "- Actions BO sans gabarit qui les déclenche (" . count($orph) . ") : " . implode(', ', array_map(static fn ($a) => $a['name'], $orph)) . "\n";
$md .= "- Actions BO sans bannière ni réponse AJAX (" . count($noBanner) . ") : " . implode(', ', array_map(static fn ($a) => $a['name'], $noBanner)) . "\n";
$noTxt = array_filter($emails, static fn ($e) => !$e['has_txt']);
$noSend = array_filter($emails, static fn ($e) => $e['senders'] === []);
$md .= "- Résidus de test dans le module (" . count($inv['test_residue']) . ") : " . implode(', ', $inv['test_residue']) . "\n";
$md .= "- Noms de templates sans fichier HTML (" . count($inv['templates_without_file']) . ") : " . implode(', ', $inv['templates_without_file']) . "\n";
$byKind = [];
foreach ($emails as $e) {
    $byKind[$e['trigger_kind']] = ($byKind[$e['trigger_kind']] ?? 0) + 1;
}
$md .= "- Templates par type de déclencheur : " . json_encode($byKind, JSON_UNESCAPED_UNICODE) . "\n";
$noHandler = array_filter($hooks, static fn ($h) => $h['handler'] === '');
$md .= "- Templates sans version TXT (" . count($noTxt) . ") : " . implode(', ', array_map(static fn ($e) => $e['name'], $noTxt)) . "\n";
$md .= "- Templates sans émetteur repéré dans le code (" . count($noSend) . ") — natifs PrestaShop ou envoi manuel : " . implode(', ', array_map(static fn ($e) => $e['name'], $noSend)) . "\n";
$md .= "- Hooks sans handler (" . count($noHandler) . ") : " . implode(', ', array_map(static fn ($h) => $h['hook'], $noHandler)) . "\n";
$md .= "- Dictionnaire admin : " . json_encode($inv['translations']['admin_translations.json'], JSON_UNESCAPED_UNICODE) . "\n";
$md .= "- Dictionnaire noms de templates : " . json_encode($inv['translations']['template_labels_i18n.json'], JSON_UNESCAPED_UNICODE) . "\n";
$totalM = 0;
$totalU = 0;
foreach ($methodCov as $c) {
    $totalM += $c['public_methods'];
    $totalU += count($c['unreferenced_by_tests']);
}
$md .= "\n## Couverture des méthodes publiques par les tests de régression existants\n\n";
$md .= "{$totalU} méthodes publiques sur {$totalM} ne sont citées dans aucun test (indicateur de lacunes, pas une preuve de bug). Détail par classe dans `inventory.json` › `method_coverage`.\n";
file_put_contents(getenv('NERIA_INVENTORY_OUT') ? getenv('NERIA_INVENTORY_OUT') . '/INVENTORY.md' : $root . '/tests/functional/INVENTORY.md', $md);

echo json_encode($inv['counts'], JSON_PRETTY_PRINT), "\nlignes matrice: ", count($rows), "\n";
