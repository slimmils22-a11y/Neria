<?php
/**
 * P8b — exécute UNE action du back-office (POST neria_action=…) via le vrai Neria::getContent(), dans son propre process,
 * puis annule tout ce qu'elle a modifié en base (transaction + restauration des réglages NERIA_*). Écrit une ligne JSON.
 *
 *   php tests/functional/bo_action_worker.php <action> [--params='{"champ":"valeur"}'] [--method=POST|GET] [--lang=fr]
 *
 * Mesure : bannière succès/erreur affichée, avertissements PHP, sortie directe (AJAX/fichier/redirection), variation
 * des réglages NERIA_*, variation du nombre de lignes des tables neria_*, restauration effective.
 * AUCUN envoi n'est protégé ici : le pilote (bo_run_actions.php) exclut les actions qui envoient, appellent un service
 * externe ou détruisent des données.
 */
require_once __DIR__ . '/../regression/bootstrap.php';
$action = (string) ($argv[1] ?? '');
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z0-9]+)=(.*)$/s', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
// --params64 : JSON encodé en base64 (les guillemets d'un JSON passé tel quel sont détruits par cmd.exe sous Windows)
$params = isset($opts['params64']) ? (array) json_decode((string) base64_decode($opts['params64']), true) : (isset($opts['params']) ? (array) json_decode($opts['params'], true) : []);
$seed = (array) ($params['_seed'] ?? []);
unset($params['_seed']);
$method = strtoupper($opts['method'] ?? 'POST');
$lang = $opts['lang'] ?? 'fr';
if ($action === '') {
    fwrite(STDERR, "action manquante\n");
    exit(2);
}
require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
$db = Db::getInstance();
$p = _DB_PREFIX_;
$ctx = Context::getContext();
$ctx->employee = new Employee((int) $db->getValue('SELECT id_employee FROM ' . $p . 'employee ORDER BY id_employee'));
$idLang = (int) Language::getIdByIso($lang) ?: (int) Configuration::get('PS_LANG_DEFAULT');
$ctx->language = new Language($idLang);
$ctx->employee->id_lang = $idLang;
$module = Module::getInstanceByName('neria'); // charge l'autoload du module (TranslationEngine…) avant AdminTranslator
AdminTranslator::setLang($lang);

// Rendu GET préalable (hors transaction) : les migrations runtime (CREATE TABLE IF NOT EXISTS…) provoquent des commit implicites
$_GET = $_REQUEST = ['configure' => 'neria'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
try {
    $module->getContent();
} catch (\Throwable $e) {
}
ob_end_clean();

$rowsConfig = static function () use ($db, $p): array {
    return $db->executeS("SELECT * FROM {$p}configuration WHERE name LIKE 'NERIA%' ORDER BY id_configuration") ?: [];
};
$keyed = static function (array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $out[$r['id_configuration']] = $r['name'] . '|' . $r['id_shop'] . '|' . $r['id_shop_group'] . '=' . $r['value'];
    }
    return $out;
};
$snapTables = static function () use ($db, $p): array {
    $out = [];
    foreach ($db->executeS("SHOW TABLES LIKE '{$p}neria%'") ?: [] as $row) {
        $t = current($row);
        $out[$t] = (int) $db->getValue("SELECT COUNT(*) FROM `{$t}`");
    }
    return $out;
};
// Jeu de données de test propre à l'action (exécuté DANS la transaction, donc annulé) + jetons {{first:table:col}} {{max:table:col}} {{customer_email}}
$resolve = static function ($v) use ($db, $p) {
    if (!is_string($v)) {
        return $v;
    }
    return preg_replace_callback('/\{\{([a-z_]+)(?::([a-z_0-9]+):([a-z_0-9]+))?\}\}/', static function ($m) use ($db, $p) {
        if ($m[1] === 'customer_email') {
            return (string) $db->getValue("SELECT email FROM {$p}customer WHERE active=1 AND deleted=0 ORDER BY id_customer");
        }
        if (in_array($m[1], ['first', 'max'], true) && !empty($m[2])) {
            $fn = $m[1] === 'max' ? 'MAX' : 'MIN';
            return (string) $db->getValue("SELECT {$fn}(`{$m[3]}`) FROM `{$p}{$m[2]}`");
        }
        return $m[0];
    }, $v);
};
$rows0 = $rowsConfig();
$cfg0 = $keyed($rows0);
$tab0 = $snapTables();
$t0 = microtime(true);
$phpMsgs = [];
$result = ['action' => $action, 'method' => $method, 'lang' => $lang, 'params' => $params];
$done = false;

$finish = static function () use (&$done, &$result, &$phpMsgs, $db, $p, $ctx, $rowsConfig, $keyed, $snapTables, $rows0, $cfg0, $tab0, $t0): void {
    if ($done) {
        return;
    }
    $done = true;
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $result['fatal'] = $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')';
    }
    if (!isset($result['status'])) {
        $result['status'] = 'exited'; // exit/die dans le gestionnaire : redirection après action, réponse AJAX/JSON, téléchargement
        $buf = '';
        while (ob_get_level() > 0) { // tous les niveaux : le module et le harnais empilent chacun un tampon
            $buf = (string) ob_get_clean() . $buf;
        }
        if ($buf !== '') {
            $result['direct_output_bytes'] = strlen($buf);
            $result['direct_output_head'] = preg_match('//u', $buf) ? mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($buf))), 0, 120) : '(binaire ' . (substr($buf, 0, 4) === '%PDF' ? 'PDF' : 'inconnu') . ')';
        }
    }
    $vars = $ctx->smarty ? $ctx->smarty->getTemplateVars() : [];
    foreach (['neria_success', 'neria_error', 'neria_warning'] as $k) {
        if (!empty($vars[$k])) {
            $result[$k] = is_string($vars[$k]) ? mb_substr(strip_tags($vars[$k]), 0, 200) : '(non textuel)';
        }
    }
    $cfg1 = $keyed($rowsConfig());
    $result['config_changes'] = array_values(array_unique(array_map(static function ($v) {
        return explode('|', $v, 2)[0];
    }, array_merge(array_diff($cfg1, $cfg0), array_diff($cfg0, $cfg1)))));
    $tab1 = $snapTables();
    $delta = [];
    foreach (array_keys($tab1 + $tab0) as $t) {
        $d = ($tab1[$t] ?? 0) - ($tab0[$t] ?? 0);
        if ($d !== 0) {
            $delta[str_replace($p, '', $t)] = $d;
        }
    }
    $result['table_deltas'] = $delta;
    $result['php_messages'] = array_slice(array_values(array_unique($phpMsgs)), 0, 8);
    $result['ms'] = (int) round((microtime(true) - $t0) * 1000);
    $db->execute('ROLLBACK');
    // restauration de secours : réglages NERIA_* qui ne seraient pas revenus (commit implicite)
    if ($keyed($rowsConfig()) !== $cfg0) {
        $result['not_rolled_back_config'] = true;
        // jamais de « tout effacer puis réinsérer » (une interruption au milieu perdrait des réglages, dont la clé de chiffrement) :
        // REPLACE ligne par ligne des valeurs d'origine, puis suppression des seules lignes NERIA_* apparues depuis.
        $known = [];
        foreach ($rows0 as $r) {
            $known[] = (int) $r['id_configuration'];
            $cols = implode(',', array_map(static function ($c) {
                return '`' . $c . '`';
            }, array_keys($r)));
            $vals = implode(',', array_map(static function ($v) {
                return $v === null ? 'NULL' : "'" . pSQL((string) $v, true) . "'";
            }, array_values($r)));
            $db->execute("REPLACE INTO {$p}configuration ({$cols}) VALUES ({$vals})");
        }
        if ($known) {
            $db->execute("DELETE FROM {$p}configuration WHERE name LIKE 'NERIA%' AND id_configuration NOT IN (" . implode(',', $known) . ')');
        }
    }
    $tab2 = $snapTables();
    $bad = [];
    foreach (array_keys($tab0 + $tab2) as $t) {
        if (($tab2[$t] ?? 0) !== ($tab0[$t] ?? 0)) {
            $bad[] = str_replace($p, '', $t);
        }
    }
    if ($bad) {
        $result['not_rolled_back_tables'] = $bad;
    }
    echo "\n@@RESULT@@" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
};
register_shutdown_function($finish);

$_GET = ['configure' => 'neria'] + ($method === 'GET' ? ['neria_action' => $action] + $params : []);
$_POST = $method === 'POST' ? ['neria_action' => $action] + $params : [];
$_REQUEST = $_GET + $_POST;
$_SERVER['REQUEST_METHOD'] = $method;
Tools::resetStaticCache();
set_error_handler(static function ($no, $str, $file, $line) use (&$phpMsgs) {
    if (strpos($str, 'ShopConstraint') === false && strpos($str, 'strftime') === false) {
        $phpMsgs[] = $str . ' (' . basename($file) . ':' . $line . ')';
    }
    return true;
});
$db->execute('START TRANSACTION');
foreach ($seed as $sql) {
    $db->execute(str_replace('{p}', $p, (string) $sql));
}
$params = array_map($resolve, $params);
$_GET = ['configure' => 'neria'] + ($method === 'GET' ? ['neria_action' => $action] + $params : []);
$_POST = $method === 'POST' ? ['neria_action' => $action] + $params : [];
$_REQUEST = $_GET + $_POST;
ob_start();
try {
    $html = (string) $module->getContent();
    $result['status'] = 'rendered';
    $result['html_bytes'] = strlen($html);
} catch (\Throwable $e) {
    $result['status'] = 'exception';
    $result['exception'] = get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
}
$out = (string) ob_get_clean();
if ($out !== '') {
    $result['direct_output_bytes'] = strlen($out);
    $result['direct_output_head'] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($out))), 0, 120);
}
restore_error_handler();
$finish();
