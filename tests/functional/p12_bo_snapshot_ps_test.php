<?php
require '/home/runu9699/public_html/ps-test/config/config.inc.php'; $db=Db::getInstance(); $p=_DB_PREFIX_;
$o=[];
foreach ($db->executeS("SELECT name,id_shop,id_shop_group g,LEFT(value,50) v FROM {$p}configuration WHERE name LIKE 'NERIA%' AND name NOT LIKE '%LAST%' AND name NOT LIKE '%CACHE%' AND name NOT LIKE '%HEARTBEAT%' AND name NOT LIKE '%HEALTH%' AND name NOT LIKE 'NERIA_STATS%'", true, false) as $r) $o[]="cfg ".$r['name']."|s".($r['id_shop']??'-')."=".$r['v'];
foreach ($db->executeS("SHOW TABLES LIKE '{$p}neria_%'") as $r) { $t=current($r); if (in_array(substr($t,strlen($p)),['neria_log','neria_translation','neria_cron_health'])) continue; $o[]="tbl ".substr($t,strlen($p))."=".$db->getValue("SELECT COUNT(*) FROM $t",false); }
sort($o); echo implode("\n",$o),"\n";
