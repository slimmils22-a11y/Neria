<?php
register_shutdown_function(function () { $e = error_get_last(); if ($e && $e['type'] < 2048) fwrite(STDERR, 'FATAL ' . substr(json_encode($e), 0, 600) . PHP_EOL); });
require '/home/runu9699/public_html/ps-test/config/config.inc.php';
require_once _PS_ROOT_DIR_ . '/app/FrontKernel.php'; global $kernel; $kernel = new FrontKernel('prod', false); $kernel->boot();
$db = Db::getInstance(); $p = _DB_PREFIX_; $m = Module::getInstanceByName('neria'); $ctx = Context::getContext(); $idMod = (int) $m->id;
$ctx->shop = new Shop(1); Shop::setContext(Shop::CONTEXT_SHOP, 1); $ctx->employee = new Employee(1); $ctx->language = new Language(2);
$ctx->currency = new Currency(1); $ctx->customer = new Customer(26);
$db->execute("DELETE FROM {$p}neria_preferences WHERE email LIKE 'slim.mils22+neria30%'");
$only = isset($argv[1]) ? explode(',', $argv[1]) : null;
function res($id, $ok, $d = '') { echo ($ok ? 'OK ' : 'KO '), $id, ': ', $d, "\n"; }
function mails($since) { global $db, $p; return array_map(function ($r) { return preg_replace('/__[0-9a-f]+$/', '', $r['template']); }, $db->executeS("SELECT template FROM {$p}mail WHERE date_add >= '" . date('Y-m-d H:i:s', $since) . "' ORDER BY id_mail")); }
$run = function ($id) use ($only) { return $only === null || in_array($id, $only); };

// HK-002 : en-tête List-Unsubscribe posé par le hook sur un vrai message, boutique 2
if ($run('002')) { try {
    $msg = (new Symfony\Component\Mime\Email())->from('a@b.co')->to('slim.mils22+neria31@gmail.com')->subject('x')->text('x');
    $rp = new ReflectionProperty('Neria', 'currentSendShopId'); $rp->setAccessible(true); $rp->setValue(null, 2);
    Hook::exec('actionMailAlterMessageBeforeSend', ['message' => $msg]);
    $h = $msg->getHeaders(); $lu = $h->has('List-Unsubscribe') ? $h->get('List-Unsubscribe')->getBodyAsString() : ''; $post = $h->has('List-Unsubscribe-Post');
    res('HK-002', strpos($lu, '/shop2/') !== false && strpos($lu, '/module/neria/unsubscribe') !== false && $post, $lu . ' | post=' . var_export($post, true));
} catch (Throwable $e) { res('HK-002', false, $e->getMessage()); } }

// HK-003 : ressources du back-office chargées sur la page de configuration
if ($run('003')) { try {
    $stub = new class { public $css = []; public $js = []; public $controller_name = 'AdminModules'; function addCSS($f) { $this->css[] = $f; } function addJS($f) { $this->js[] = $f; } function __call($n, $a) {} };
    $ctx->controller = $stub; $_GET['configure'] = 'neria'; Tools::resetStaticCache();
    Hook::exec('displayBackOfficeHeader');
    res('HK-003', count($stub->css) >= 1 && count($stub->js) >= 1, 'css=' . json_encode($stub->css) . ' js=' . json_encode($stub->js));
    unset($_GET['configure']); Tools::resetStaticCache();
} catch (Throwable $e) { res('HK-003', false, $e->getMessage()); } }

// HK-004 : filet de sécurité front : la file d'envoi due est traitée au passage d'une page
if ($run('004')) { try {
    $ctx->controller = new class { public $controller_name = 'index'; function __call($n, $a) {} };
    $r = $db->getRow("SELECT * FROM {$p}customer WHERE id_customer=26");
    (new QueueManager($m))->enqueueAt('early_access', $r, [], 9700, date('Y-m-d H:i:s', time() - 60));
    $db->execute("UPDATE {$p}configuration SET value='0' WHERE name='neria_queue_last_process'"); Configuration::loadConfiguration();
    $t0 = time(); Hook::exec('displayHeader');
    $st = $db->getValue("SELECT status FROM {$p}neria_queue WHERE ref_id=9700");
    res('HK-004', $st === 'sent', "ligne de file=$st ; mails=" . implode(',', mails($t0)));
    $db->execute("DELETE FROM {$p}neria_queue WHERE ref_id=9700");
} catch (Throwable $e) { res('HK-004', false, $e->getMessage()); } }

// HK-005 / 006 : bloc « e-mails reçus » sur la fiche client
foreach (['005' => 'displayAdminCustomersView', '006' => 'displayAdminCustomers'] as $id => $hk) { if ($run($id)) { try {
    $out = (string) Hook::exec($hk, ['id_customer' => 26], $idMod);
    res("HK-$id", strlen(strip_tags($out)) > 50 && stripos($out, 'neria') !== false, strlen($out) . ' octets ; ' . substr(preg_replace('/\s+/', ' ', strip_tags($out)), 0, 90));
} catch (Throwable $e) { res("HK-$id", false, $e->getMessage()); } } }

// Commande de test pour 007-011
$tok = $db->getValue("SELECT tracking_token FROM {$p}neria_stat WHERE id_customer=26 AND event_type='sent' ORDER BY id_stat DESC");
$ord = null;
if ($run('007') || $run('008') || $run('009') || $run('010') || $run('011')) { try {
    $_COOKIE['neria_ref'] = 'x:y:' . $tok;
    $o = $db->getRow("SELECT * FROM {$p}orders WHERE id_order=26"); unset($o['id_order']);
    $ord = new Order(); foreach ($o as $k => $v) { if (property_exists($ord, $k)) { $ord->$k = $v; } }
    $ord->reference = 'P11H' . rand(1000, 9999); $ord->current_state = 0; $ord->add();
    foreach ($db->executeS("SELECT * FROM {$p}order_detail WHERE id_order=26") as $d) { unset($d['id_order_detail']); $d['id_order'] = $ord->id; $cols = implode(',', array_map(function ($k) { return "`$k`"; }, array_keys($d))); $vals = implode(',', array_map(function ($v) { return $v === null ? 'NULL' : "'" . pSQL($v) . "'"; }, array_values($d))); $db->execute("INSERT INTO {$p}order_detail ($cols) VALUES ($vals)"); }
    $att = (int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_attribution WHERE id_order=" . (int) $ord->id . " AND tracking_token='" . pSQL($tok) . "'");
    res('HK-007', $ord->id > 0 && $att === 1, "commande {$ord->id} ; attribution=$att");
} catch (Throwable $e) { res('HK-007', false, $e->getMessage()); } }

if ($ord && $ord->id && $run('008')) { try {
    $t0 = time(); Configuration::updateValue('PS_INVOICE', 0); $h = new OrderHistory(); $h->id_order = $ord->id; $h->id_employee = 1; $h->changeIdOrderState(2, new Order($ord->id), true); $h->addWithemail(true);
    Configuration::updateValue('PS_INVOICE', 1); $conv = $db->getRow("SELECT id_order,revenue FROM {$p}neria_stat WHERE event_type='conversion' AND id_order=" . (int) $ord->id);
    res('HK-008', is_array($conv) && (float) $conv['revenue'] > 0, 'conversion=' . json_encode($conv) . ' ; mails=' . implode(',', mails($t0)));
} catch (Throwable $e) { res('HK-008', false, $e->getMessage()); } }

if ($ord && $ord->id && $run('009')) { try {
    $t0 = time(); $o = new Order($ord->id); $det = $o->getProductsDetail(); $d0 = $det[0];
    $slip = new OrderSlip(); $slip->id_customer = $o->id_customer; $slip->id_order = $o->id; $slip->conversion_rate = 1; $slip->total_products_tax_excl = 5; $slip->total_products_tax_incl = 5; $slip->total_shipping_tax_excl = 0; $slip->total_shipping_tax_incl = 0; $slip->amount = 5; $slip->shipping_cost = 0; $slip->shipping_cost_amount = 0; $slip->partial = 1; $slip->order_slip_type = 0; $slip->add();
    Hook::exec('actionOrderSlipAdd', ['order' => $o, 'productList' => [['id_order_detail' => $d0['id_order_detail'], 'quantity' => 1, 'unit_price' => 5, 'amount' => 5]], 'qtyList' => [1], 'orderSlip' => $slip]);
    $mm = mails($t0); $rec = (int) $db->getValue("SELECT COUNT(*) FROM {$p}neria_reconciliation WHERE id_order=" . (int) $o->id);
    res('HK-009', in_array('refund_processed', $mm, true) || $rec > 0, 'mails=' . implode(',', $mm) . ' ; réconciliation=' . $rec);
} catch (Throwable $e) { res('HK-009', false, $e->getMessage()); } }

if ($ord && $ord->id && $run('010')) { try {
    $t0 = time(); $r = new OrderReturn(); $r->id_customer = 26; $r->id_order = $ord->id; $r->state = 1; $r->question = 'Test P11'; $r->add();
    $mm = mails($t0); res('HK-010', $r->id > 0 && in_array('return_received', $mm, true), 'retour ' . $r->id . ' ; mails=' . implode(',', $mm));
} catch (Throwable $e) { res('HK-010', false, $e->getMessage()); } }

if ($ord && $ord->id && $run('011')) { try {
    $before = (float) $db->getValue("SELECT SUM(revenue) FROM {$p}neria_stat WHERE event_type='conversion' AND id_order=" . (int) $ord->id);
    $o = new Order($ord->id); $o->delete();
    $after = (float) $db->getValue("SELECT SUM(revenue) FROM {$p}neria_stat WHERE event_type='conversion' AND id_order=" . (int) $ord->id);
    res('HK-011', $before > 0 && $after == 0.0, "revenu avant=$before après=$after");
} catch (Throwable $e) { res('HK-011', false, $e->getMessage()); } }
unset($_COOKIE['neria_ref']);

// HK-012 : certificat sur la fiche commande
if ($run('012')) { try {
    $idc = 5;
    $out = (string) Hook::exec('displayAdminOrderMainBottom', ['id_order' => $idc], $idMod);
    res('HK-012', strlen($out) > 100, "commande $idc ; " . strlen($out) . ' octets ; ' . substr(preg_replace('/\s+/', ' ', strip_tags($out)), 0, 80));
} catch (Throwable $e) { res('HK-012', false, $e->getMessage()); } }

// HK-013 / 014 : liste d'attente
if ($run('013') || $run('014')) { try {
    Configuration::updateGlobalValue('NERIA_WAITLIST_ENABLED', 1);
    $q = (int) $db->getValue("SELECT quantity FROM {$p}stock_available WHERE id_product=2 AND id_product_attribute=0");
    StockAvailable::setQuantity(2, 0, 0); Cache::clean('*');
    $ctx->customer = new Customer(26); $ctx->cart = new Cart();
    $prod = ['id_product' => 2, 'id_product_attribute' => 0, 'quantity' => 0, 'allow_oosp' => 0, 'out_of_stock' => 0, 'quantity_all_versions' => 0, 'available_for_order' => 1];
    $out = (string) Hook::exec('displayProductAdditionalInfo', ['product' => $prod], $idMod);
    if ($run('013')) { res('HK-013', stripos($out, 'waitlist') !== false || stripos($out, 'neria') !== false, strlen($out) . ' octets ; ' . substr(preg_replace('/\s+/', ' ', strip_tags($out)), 0, 80)); }
    if ($run('014')) { $w = new WaitlistManager($m); $w->register(26, 2, 1); $t0 = time(); StockAvailable::setQuantity(2, 0, max(5, $q)); Cache::clean('*'); sleep(1);
        $mm = mails($t0); res('HK-014', in_array('waitlist_available', $mm, true), 'mails=' . implode(',', $mm)); $db->execute("DELETE FROM {$p}neria_waitlist WHERE id_customer=26"); }
    StockAvailable::setQuantity(2, 0, max(5, $q));
} catch (Throwable $e) { res('HK-013/014', false, $e->getMessage()); } }

// HK-015 / 016 : RGPD sur un client jetable
if ($run('015') || $run('016')) { try {
    $em = 'slim.mils22+neria40@gmail.com'; $c = new Customer(); $c->email = $em; $c->firstname = 'Test'; $c->lastname = 'Rgpd'; $c->passwd = Tools::hash('Xx12345678!'); $c->id_shop = 1; $c->id_shop_group = 1; $c->id_lang = 2; $c->active = 1; $c->add();
    $db->execute("INSERT INTO {$p}neria_stat (id_shop,template,lang,id_customer,tracking_token,event_type,date_add) VALUES (1,'vip','fr',{$c->id},'p11gdpr" . $c->id . "','sent',NOW())");
    $db->execute("INSERT INTO {$p}neria_loyalty_points (id_shop,id_customer,id_stat,event_type,points,date_add) VALUES (1,{$c->id},0,'open',1,NOW())");
    $exp = Hook::exec('actionExportGDPRData', ['id' => (int) $c->id, 'email' => $em], $idMod, true, false);
    $json = is_array($exp) ? ($exp['neria'] ?? reset($exp)) : $exp; $dd = json_decode((string) $json, true);
    if ($run('016')) { res('HK-016', is_array($dd) && !empty($dd['neria_stat']), 'tables=' . json_encode(is_array($dd) ? array_keys($dd) : $json)); }
    if ($run('015')) { Hook::exec('actionDeleteGDPRCustomer', ['id' => (int) $c->id, 'email' => $em], $idMod, true, false);
        $left = (int) $db->getValue("SELECT (SELECT COUNT(*) FROM {$p}neria_stat WHERE id_customer={$c->id})+(SELECT COUNT(*) FROM {$p}neria_loyalty_points WHERE id_customer={$c->id})");
        res('HK-015', $left === 0, "lignes restantes=$left"); }
    $db->execute("DELETE FROM {$p}customer WHERE id_customer=" . (int) $c->id);
} catch (Throwable $e) { res('HK-015/016', false, $e->getMessage()); } }
