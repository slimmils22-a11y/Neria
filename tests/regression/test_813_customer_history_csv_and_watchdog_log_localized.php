<?php
/**
 * Bloc 7 (19/09/2026) — onglet Historique client et export du journal Watchdog, ouverts en anglais
 * sur ps-test, contenaient du français en dur :
 *  1. Export CSV de l'historique d'un client : en-têtes « Date;Heure;Template;Langue;Statut » et
 *     valeurs « Ouvert »/« Envoyé » figés (CustomerEmailHistoryManager::buildCsv()).
 *  2. Recherche sans résultat : « Aucun client trouvé pour « … » » écrit dans le template.
 *  3. Journal Watchdog envoyé par e-mail en PDF : titre, en-têtes de colonnes, objet, corps
 *     (« Bonjour, Veuillez trouver ci-joint… »), « Exporté le », erreur TCPDF, message
 *     « Aucun email destinataire configuré. » — tout en français dans un BO de 19 langues.
 *
 * Test : CSV réel sur une fixture (client fictif) en fr/en/ja ; plugin Smarty {neria_admin s=…}
 * (échappement HTML) ; 11 clés × 19 langues ; plus aucune chaîne française dans la fonction d'export
 * (non appelée ici : elle envoie via mail()).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'StatsManager', 'CustomerEmailHistoryManager'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }
    $dir   = _PS_MODULE_DIR_ . 'neria/';
    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];
    $dict  = json_decode((string) file_get_contents($dir . 'data/admin_translations.json'), true);

    // 1) Clés × 19 langues, marqueurs de substitution conservés.
    $keys = [
        'history.csv_col_time' => null, 'history.no_customer_found' => ['%s'], 'msg.watchdog_log_no_recipient' => null,
        'watchdoglog.title' => null, 'watchdoglog.exported_on' => null, 'watchdoglog.col_level' => null,
        'watchdoglog.col_class' => null, 'watchdoglog.col_message' => null, 'watchdoglog.subject' => null,
        'watchdoglog.body' => ['%1$s', '%2$s'], 'watchdoglog.tcpdf_error' => ['%s'],
    ];
    foreach ($keys as $k => $markers) {
        neria_assert(isset($dict[$k]), "clé {$k} absente");
        foreach ($langs as $l) {
            $v = (string) ($dict[$k][$l] ?? '');
            neria_assert(trim($v) !== '', "clé {$k} vide pour '{$l}'");
            foreach ((array) $markers as $mk) {
                neria_assert(strpos($v, $mk) !== false, "clé {$k}/{$l} : marqueur {$mk} perdu");
            }
        }
    }
    foreach (['en', 'de', 'ja'] as $l) {
        $body = sprintf($dict['watchdoglog.body'][$l], 'MyShop', '2026-09-19 10:00');
        neria_assert(strpos($body, 'MyShop') !== false && strpos($body, '2026-09-19 10:00') !== false && strpos($body, '%') === false, "corps du mail Watchdog ({$l}) mal formaté : {$body}");
    }

    // 2) CSV réel sur une fixture.
    $db = neria_test_db();
    $p  = neria_test_prefix();
    $shop = (int) Context::getContext()->shop->id;
    $fake = 998130;
    $cleanup = function () use ($db, $p, $fake) {
        $db->execute("DELETE FROM {$p}neria_stat WHERE id_customer = {$fake} OR tracking_token LIKE 'regtest813%'");
    };
    $cleanup();
    $orig = AdminTranslator::currentLang();
    try {
        $db->execute("INSERT INTO {$p}neria_stat (id_shop, template, lang, id_customer, tracking_token, event_type, date_add) VALUES ({$shop}, 'birthday', 'fr', {$fake}, 'regtest813_a', 'sent', NOW())");
        $db->execute("INSERT INTO {$p}neria_stat (id_shop, template, lang, id_customer, tracking_token, event_type, date_add) VALUES ({$shop}, 'win_back', 'fr', {$fake}, 'regtest813_b', 'sent', NOW())");
        $db->execute("INSERT INTO {$p}neria_stat (id_shop, template, lang, id_customer, tracking_token, event_type, is_mpp, date_add) VALUES ({$shop}, 'birthday', 'fr', {$fake}, 'regtest813_a', 'open', 0, NOW())");
        $mgr = new CustomerEmailHistoryManager(neria_test_module());

        AdminTranslator::setLang('en');
        $csv = $mgr->buildCsv($fake);
        $lines = explode("\r\n", $csv);
        neria_assert($lines[0] === 'Date;Time;Template;Language;Status', "CSV en : en-têtes inattendus : {$lines[0]}");
        neria_assert(strpos($csv, ';Opened') !== false && strpos($csv, ';Sent') !== false, "CSV en : statuts Opened/Sent absents : {$csv}");
        neria_assert(preg_match('/Ouvert|Envoy|Heure|Statut|Langue/u', $csv) !== 1, "CSV en : français résiduel : {$csv}");

        AdminTranslator::setLang('ja');
        $csvJa = $mgr->buildCsv($fake);
        neria_assert(strpos(explode("\r\n", $csvJa)[0], (string) $dict['history.csv_col_time']['ja']) !== false, "CSV ja : colonne « heure » non traduite : " . explode("\r\n", $csvJa)[0]);
        neria_assert(preg_match('/Ouvert|Envoy|Heure|Statut|Langue/u', $csvJa) !== 1, "CSV ja : français résiduel");

        AdminTranslator::setLang('fr');
        $csvFr = $mgr->buildCsv($fake);
        neria_assert(explode("\r\n", $csvFr)[0] === 'Date;Heure;Template;Langue;Statut', "CSV fr : en-têtes français attendus : " . explode("\r\n", $csvFr)[0]);
        neria_assert(strpos($csvFr, ';Ouvert') !== false && strpos($csvFr, ';Envoyé') !== false, "CSV fr : statuts Ouvert/Envoyé attendus");

        // 3) Plugin Smarty : %s remplacé par la valeur HTML-échappée.
        AdminTranslator::setLang('en');
        $out = AdminTranslator::smartyHelper(['key' => 'history.no_customer_found', 's' => '<b>x</b>"'], null);
        neria_assert($out === 'No customer found for “&lt;b&gt;x&lt;/b&gt;&quot;”.', "smartyHelper s= : sortie inattendue : {$out}");
        AdminTranslator::setLang('fr');
        neria_assert(strpos(AdminTranslator::smartyHelper(['key' => 'history.no_customer_found', 's' => 'Dupont'], null), '« Dupont »') !== false, "smartyHelper s= (fr) : « Dupont » absent");
    } finally {
        AdminTranslator::setLang($orig);
        $cleanup();
    }

    // 4) Plus de français dans l'export du journal Watchdog / le template de l'historique.
    $neria = str_replace("\r\n", "\n", (string) file_get_contents($dir . 'neria.php')); // fichier CRLF sur le Bureau
    $i = strpos($neria, 'private function sendWatchdogLogByEmail(): string');
    neria_assert($i !== false, "sendWatchdogLogByEmail() introuvable");
    $j = strpos($neria, "\n    }\n", $i);
    neria_assert($j !== false && $j > $i, "fin de sendWatchdogLogByEmail() introuvable");
    neria_assert(strpos(substr($neria, $i, $j - $i), 'Content-Type: application/pdf') !== false, "extraction de sendWatchdogLogByEmail() tronquée (pièce jointe PDF absente du corps extrait)");
    $fn = substr($neria, $i, $j - $i);
    foreach (['Journal Watchdog', 'Exporté le', 'Veuillez trouver', 'Erreur TCPDF', 'Aucun email destinataire', '>Niveau<', '>Classe<', 'Bonjour,'] as $fr) {
        neria_assert(strpos($fn, $fr) === false, "sendWatchdogLogByEmail() contient encore le texte français « {$fr} »");
    }
    neria_assert(strpos($fn, "AdminTranslator::t('watchdoglog.body')") !== false && strpos($fn, "AdminTranslator::t('watchdoglog.subject')") !== false, "l'objet/le corps du mail Watchdog ne passent plus par AdminTranslator");
    $tpl = (string) file_get_contents($dir . 'views/templates/admin/customer_history.tpl');
    neria_assert(strpos($tpl, 'Aucun client trouvé') === false && strpos($tpl, "history.no_customer_found' s=") !== false, "customer_history.tpl : message « aucun client » de nouveau en dur");

    return ['pass' => true, 'message' => "CSV de l'historique client, message « aucun client » et export PDF du journal Watchdog traduits (11 clés × 19 langues), plugin Smarty s= échappé — bloc 7 (19/09/2026)"];
}
