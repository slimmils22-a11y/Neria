<?php
// Worker : exécute UNE action du back-office sur ps-test (vrai Neria::getContent()), affiche bannières + sortie directe.
// php p12_act.php <action> <base64(json params)> [GET|POST] [langue] [id_shop]
$action = $argv[1] ?? '';
$params = json_decode((string) base64_decode($argv[2] ?? ''), true) ?: [];
$method = strtoupper($argv[3] ?? 'POST');
$lang = $argv[4] ?? 'fr';
$idShop = (int) ($argv[5] ?? 1);
require '/home/runu9699/public_html/ps-test/config/config.inc.php';
$db = Db::getInstance(); $p = _DB_PREFIX_; $ctx = Context::getContext();
$ctx->shop = new Shop($idShop); Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
$ctx->employee = new Employee(1); $idLang = (int) Language::getIdByIso($lang); $ctx->language = new Language($idLang); $ctx->employee->id_lang = $idLang;
$m = Module::getInstanceByName('neria'); require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php'; AdminTranslator::setLang($lang);
$_GET = $_REQUEST = ['configure' => 'neria', 'neria_action' => $action] + ($method === 'GET' ? $params : ['neria_tab' => $params['neria_tab'] ?? 'configure']);
$_POST = $method === 'POST' ? (['neria_action' => $action] + $params) : [];
if ($method === 'POST') { $_REQUEST = array_merge($_GET, $_POST); }
$_SERVER['REQUEST_METHOD'] = $method;
foreach (($params['_files'] ?? []) as $k => $f) { $_FILES[$k] = $f; }
Tools::resetStaticCache();
$emit = function ($how) use ($ctx) {
    $sm = $ctx->smarty;
    $out = ob_get_contents();
    $direct = strlen($out) < 4000 ? $out : '';
    fwrite(STDOUT, json_encode(['how' => $how, 'success' => (string) $sm->getTemplateVars('neria_success'), 'error' => (string) $sm->getTemplateVars('neria_error'), 'warning' => (string) $sm->getTemplateVars('neria_warning'), 'len' => strlen($out), 'direct' => mb_substr(strip_tags($direct), 0, 300)], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
};
register_shutdown_function(function () use ($emit) { if (!defined('P12_DONE')) { $e = error_get_last(); $emit('exit' . ($e && $e['type'] < 2048 ? ' FATAL ' . substr($e['message'], 0, 200) : '')); } });
ob_start();
try { $m->getContent(); } catch (Throwable $e) { echo 'EXC ' . $e->getMessage(); }
define('P12_DONE', 1); $emit('return'); ob_end_clean();
