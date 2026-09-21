<?php
/**
 * P8d — audit des 43 interrupteurs du Centre de contrôle (ConfigManager::CONTROL_CENTER_REGISTRY) :
 *   C001 libellé absent ou vide dans une des 19 langues        C002 onglet cible inconnu
 *   C003 ancre de section absente de l'onglet cible (lien mort)  C004 réglage enabled_key inconnu de ConfigManager::DEFAULTS
 *   C005 aucun chemin d'écriture du réglage (impossible de l'activer/désactiver depuis le back-office)
 *   C006 état affiché (actif/inactif) différent de l'état réel du réglage
 *
 *   php -d memory_limit=1G tests/functional/cc_audit.php
 * Sortie : results/P8d_control_center.json et .md.
 */
require_once __DIR__ . '/../regression/bootstrap.php';
$root = str_replace('\\', '/', dirname(__DIR__, 2));
$module = Module::getInstanceByName('neria');
require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
$inv = json_decode((string) file_get_contents($root . '/tests/functional/inventory/inventory.json'), true);
$tabs = $inv['tabs'];
$trans = json_decode((string) file_get_contents($root . '/data/admin_translations.json'), true);
$langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
$mainSrc = (string) file_get_contents($root . '/neria.php');
$cfgSrc = (string) file_get_contents($root . '/src/ConfigManager.php');
$allSrc = $mainSrc . "\n" . $cfgSrc;
foreach (glob($root . '/src/*.php') as $f) {
    $allSrc .= "\n" . (string) file_get_contents($f);
}
$ctx = Context::getContext();
$ctx->employee = new Employee((int) Db::getInstance()->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee ORDER BY id_employee'));
$ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
AdminTranslator::setLang('fr');

$pages = [];
$page = static function (string $tab) use (&$pages, $module): string {
    if (!isset($pages[$tab])) {
        $_GET = $_REQUEST = ['neria_tab' => $tab, 'configure' => 'neria'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Tools::resetStaticCache();
        ob_start();
        $h = (string) $module->getContent();
        $pages[$tab] = $h . (string) ob_get_clean();
    }
    return $pages[$tab];
};
$getItems = new ReflectionMethod($module, 'getControlCenterItems');
$getItems->setAccessible(true);
$shown = [];
foreach ($getItems->invoke($module, new ConfigManager($module)) as $it) {
    $shown[$it['key']] = $it;
}

$writtenByActions = [];
$p8b = $root . '/tests/functional/results/P8b_actions.json';
if (is_file($p8b)) {
    foreach ((array) (json_decode((string) file_get_contents($p8b), true)['rows'] ?? []) as $r) {
        $writtenByActions = array_merge($writtenByActions, (array) ($r['config'] ?? []));
    }
}
$rows = [];
$issues = [];
$add = static function (string $key, string $code, string $level, string $msg) use (&$issues): void {
    $issues[] = ['key' => $key, 'code' => $code, 'level' => $level, 'msg' => $msg];
};
foreach (ConfigManager::CONTROL_CENTER_REGISTRY as $item) {
    $key = $item['key'];
    $lk = (string) $item['label_key'];
    $miss = [];
    foreach ($langs as $l) {
        if (!isset($trans[$lk][$l]) || trim((string) $trans[$lk][$l]) === '') {
            $miss[] = $l;
        }
    }
    if ($miss) {
        $add($key, 'C001', 'E', "libellé {$lk} manquant en : " . implode(',', $miss));
    }
    $scope = $item['scope'];
    $tab = $scope === 'tab' ? (string) $item['tab'] : ($scope === 'stats_section' ? 'stats' : 'configure');
    if (!in_array($tab, $tabs, true)) {
        $add($key, 'C002', 'E', "onglet cible inconnu : {$tab}");
    } elseif (!empty($item['anchor'])) {
        if (strpos($page($tab), 'id="' . $item['anchor'] . '"') === false) {
            $add($key, 'C003', 'E', "ancre #{$item['anchor']} absente de l'onglet {$tab}");
        }
    }
    $ek = $item['enabled_key'] ?? null;
    $writer = null;
    if ($ek !== null) {
        $const = array_search($ek, (new ReflectionClass('ConfigManager'))->getConstants(), true);
        $inDefaults = $const !== false && isset(ConfigManager::DEFAULTS[$ek]);
        $seeded = strpos($mainSrc, "'" . $ek . "'") !== false || $inDefaults || strpos($cfgSrc, "'" . $ek . "'") !== false;
        if (!$seeded && strpos($allSrc, $ek) === false) {
            $add($key, 'C004', 'W', "réglage {$ek} introuvable dans le code");
        }
        // chemin d'écriture : la clé apparaît dans une écriture Configuration::update*/toggle*/set(), une liste blanche d'interrupteurs ou une constante ConfigManager écrite
        $writer = preg_match('/(update(?:Global)?Value|toggleBooleanKey|set)\(\s*(?:self::[A-Z_]+|\'' . preg_quote($ek, '/') . '\'|\$[a-zA-Z]+)/', $allSrc) === 1
            && (preg_match('/[\'"]' . preg_quote($ek, '/') . '[\'"]/', $mainSrc) === 1 || ($const !== false && preg_match('/self::' . preg_quote((string) $const, '/') . '\b/', $mainSrc . $cfgSrc) === 1));
        // preuve par l'exécution (P8b) : une action réelle du back-office a modifié ce réglage
        $writer = $writer || in_array($ek, $writtenByActions, true);
        if (!$writer) {
            $add($key, 'C005', 'W', "aucun chemin d'écriture trouvé pour {$ek}");
        }
        // état affiché = état réel
        $raw = Configuration::getGlobalValue($ek);
        $expected = ($raw !== false) ? (bool) $raw : (bool) ($item['default_if_unset'] ?? false);
        if (isset($shown[$key]) && $shown[$key]['active'] !== $expected) {
            $add($key, 'C006', 'E', 'état affiché ' . var_export($shown[$key]['active'], true) . ' ≠ état réel ' . var_export($expected, true));
        }
    }
    $rows[] = ['key' => $key, 'scope' => $scope, 'target' => $tab . (!empty($item['anchor']) ? '#' . $item['anchor'] : ''), 'enabled_key' => $ek, 'active' => $shown[$key]['active'] ?? null, 'visible' => $shown[$key]['visible'] ?? null, 'write_path' => $ek === null ? 'sans objet' : ($writer ? 'oui' : 'NON')];
}
$dir = __DIR__ . '/results';
@mkdir($dir, 0777, true);
file_put_contents($dir . '/P8d_control_center.json', json_encode(['generated' => date('c'), 'rows' => $rows, 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$by = ['E' => 0, 'W' => 0];
foreach ($issues as $i) {
    $by[$i['level']]++;
}
$md = "# P8d — Centre de contrôle : " . count($rows) . " interrupteurs\n\nAnomalies : **{$by['E']} E**, {$by['W']} W\n\n| Niv. | Interrupteur | Code | Détail |\n|---|---|---|---|\n";
foreach ($issues as $i) {
    $md .= '| ' . $i['level'] . ' | ' . $i['key'] . ' | ' . $i['code'] . ' | ' . str_replace('|', '/', $i['msg']) . " |\n";
}
$md .= "\n| Interrupteur | Portée | Cible | Réglage | Actif | Écriture |\n|---|---|---|---|---|---|\n";
foreach ($rows as $r) {
    $md .= '| ' . $r['key'] . ' | ' . $r['scope'] . ' | ' . $r['target'] . ' | ' . ($r['enabled_key'] ?? '—') . ' | ' . var_export($r['active'], true) . ' | ' . $r['write_path'] . " |\n";
}
file_put_contents($dir . '/P8d_control_center.md', $md);
echo count($rows) . " interrupteurs · anomalies E={$by['E']} W={$by['W']} — tests/functional/results/P8d_control_center.md\n";
exit($by['E'] ? 1 : 0);
