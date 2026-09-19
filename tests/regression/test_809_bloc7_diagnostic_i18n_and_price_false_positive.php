<?php
/**
 * Bloc 7 (19/09/2026) — trois défauts trouvés en ouvrant les onglets Statistiques
 * et Aide sur ps-test avec un BO en anglais :
 *
 *  1. Le libellé du template « certificate_email » manquait dans
 *     data/template_labels_i18n.json (116 sur 117) : « Certificat d'authenticité
 *     (envoi) » restait en français dans tous les sélecteurs et statistiques.
 *  2. Le bandeau « score de santé » de l'onglet Aide (problèmes détectés + libellé
 *     Excellent/Bon/Attention/Critique) était écrit en français.
 *  3. Le diagnostic « Pièges SQL/PHP génériques » affichait à TOUS les marchands
 *     un avertissement permanent : il ne tolérait Product::getPriceStatic() que dans
 *     UpsellManager alors que Waitlist/Collection/LookCompletion ont leur propre
 *     wrapper protégé safeProductPrice() (faux positif).
 *  4. 28 contrôles du diagnostic n'avaient aucun titre traduit : le BO affichait
 *     l'identifiant technique (« known_regressions_guard_freshness »…).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'WatchdogManager', 'HealthCheckManager'] as $c) {
        require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
    }
    $dir   = _PS_MODULE_DIR_ . 'neria/';
    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];
    $admin = json_decode((string) file_get_contents($dir . 'data/admin_translations.json'), true);
    $tpls  = json_decode((string) file_get_contents($dir . 'data/template_labels_i18n.json'), true);

    // 1) Chaque template connu a un libellé dans les 19 langues.
    foreach (array_keys(NeriaTools::getTemplateLabels()) as $tpl) {
        neria_assert(isset($tpls[$tpl]), "template '{$tpl}' absent de template_labels_i18n.json — son libellé resterait en français dans un BO traduit");
        foreach ($langs as $l) {
            neria_assert(trim((string) ($tpls[$tpl][$l] ?? '')) !== '', "libellé du template '{$tpl}' vide pour '{$l}'");
        }
    }

    // 2) Clés du bandeau de santé + comportement réel en anglais.
    foreach (['wdscore.issue_errors', 'wdscore.issue_warnings', 'wdscore.issue_crons_late', 'wdscore.issue_queue_stuck', 'wdscore.issue_queue_failed', 'score.grade_attention', 'score.grade_excellent', 'score.grade_good', 'score.grade_critical'] as $k) {
        neria_assert(isset($admin[$k]), "clé {$k} absente");
        foreach ($langs as $l) {
            neria_assert(trim((string) ($admin[$k][$l] ?? '')) !== '', "clé {$k} vide pour '{$l}'");
            if (strpos($k, 'wdscore.') === 0) {
                neria_assert(strpos($admin[$k][$l], '%d') !== false, "clé {$k}/{$l} : le marqueur %d du nombre est perdu");
            }
        }
    }
    $module = neria_test_module();
    $wd  = new WatchdogManager($module);
    $ref = new ReflectionMethod(WatchdogManager::class, 'wdTr');
    $ref->setAccessible(true);
    $orig = AdminTranslator::currentLang();
    try {
        AdminTranslator::setLang('en');
        $msg = sprintf((string) $ref->invoke($wd, 'wdscore.issue_errors', '%d erreur(s)'), 4);
        neria_assert($msg === '4 error(s)/critical event(s) in the last 24 hours', "message de santé en anglais incorrect : « {$msg} »");
        neria_assert($ref->invoke($wd, 'score.grade_good', 'Bon') === 'Good', "libellé « Bon » non traduit en anglais");
        AdminTranslator::setLang('de');
        neria_assert(strpos((string) $ref->invoke($wd, 'wdscore.issue_queue_stuck', 'x'), 'Warteschlange') !== false, "message de santé non traduit en allemand");
        // Le score réel n'émet plus de texte français quand le BO est en anglais.
        AdminTranslator::setLang('en');
        $score = $wd->getWatchdogHealthScore();
        foreach (array_merge([$score['label']], $score['issues']) as $t) {
            neria_assert(preg_match('/dernières heures|en retard|bloqué|en échec|\b(Bon|Critique)\b/u', (string) $t) !== 1, "score de santé : texte français en BO anglais : « {$t} »");
        }
    } finally {
        AdminTranslator::setLang($orig);
    }

    // 4) Chaque contrôle du diagnostic a un titre traduit dans les 19 langues.
    $hcSrc = (string) file_get_contents($dir . 'src/HealthCheckManager.php');
    $tplSrc = (string) file_get_contents($dir . 'views/templates/admin/help.tpl');
    $i  = strpos($hcSrc, "'hardcoded_french_text' => 'checkHardcodedFrenchText'");
    neria_assert($i !== false, "table des contrôles introuvable");
    $st = strrpos(substr($hcSrc, 0, $i), '= [');
    $en = strpos($hcSrc, '];', $i);
    preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'check[A-Za-z0-9]+'/", substr($hcSrc, $st, $en - $st), $mm);
    neria_assert(count($mm[1]) >= 120, "extraction des contrôles invalide (" . count($mm[1]) . ")");
    foreach ($mm[1] as $check) {
        neria_assert(preg_match("/'" . preg_quote($check, '/') . "'\s*=>\s*'(help\.health_check_[a-z0-9_]+)'/", $tplSrc, $km) === 1, "contrôle '{$check}' sans titre dans help.tpl (le BO afficherait l'identifiant brut)");
        neria_assert(isset($admin[$km[1]]), "clé de titre {$km[1]} absente du dictionnaire");
        foreach ($langs as $l) {
            neria_assert(trim((string) ($admin[$km[1]][$l] ?? '')) !== '', "titre {$km[1]} vide pour '{$l}'");
        }
    }

    // 3) Contrôle Product::getPriceStatic() : faux positif corrigé, vraie fuite toujours détectée.
    $run = function (string $moduleName): array {
        $fake = clone neria_test_module();
        $fake->name = $moduleName;
        $hc = new HealthCheckManager($fake);
        $m = new ReflectionMethod(HealthCheckManager::class, 'checkSqlPatternRisks');
        $m->setAccessible(true);
        return $m->invoke($hc);
    };
    $real = $run('neria');
    neria_assert(($real['status'] ?? '') === 'ok', "le contrôle signale getPriceStatic() sur le vrai module (faux positif : les 4 wrappers safeProductPrice() sont protégés) : " . json_encode($real, JSON_UNESCAPED_UNICODE));

    $fakeDir = _PS_MODULE_DIR_ . 'regtest809/src';
    @mkdir($fakeDir, 0777, true);
    try {
        // Wrapper légitime dans un fichier connu : toléré.
        file_put_contents($fakeDir . '/CollectionManager.php', "<?php\nclass CollectionManager {\n private function safeProductPrice() { return \\Product::getPriceStatic(1); }\n}\n");
        // Appel direct hors wrapper dans un autre fichier : doit être signalé.
        file_put_contents($fakeDir . '/RogueManager.php', "<?php\nclass RogueManager {\n public function f() { return \\Product::getPriceStatic(1); }\n}\n");
        // Fichier connu MAIS appel hors de son wrapper : doit être signalé.
        file_put_contents($fakeDir . '/WaitlistManager.php', "<?php\nclass WaitlistManager {\n public function f() { return \\Product::getPriceStatic(1); }\n private function safeProductPrice() { return 1.0; }\n}\n");
        $out = json_encode($run('regtest809'), JSON_UNESCAPED_UNICODE);
        neria_assert(strpos($out, 'CollectionManager.php') === false, "un wrapper safeProductPrice() légitime est signalé à tort : {$out}");
        neria_assert(strpos($out, 'RogueManager.php') !== false, "un appel direct hors wrapper n'est plus détecté : {$out}");
        neria_assert(strpos($out, 'WaitlistManager.php') !== false, "un appel situé hors du safeProductPrice() d'un fichier connu n'est plus détecté : {$out}");
    } finally {
        foreach (glob($fakeDir . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($fakeDir);
        @rmdir(_PS_MODULE_DIR_ . 'regtest809');
    }

    return ['pass' => true, 'message' => "libellé certificate_email, score de santé traduit, 28 titres de contrôles traduits, faux positif getPriceStatic() supprimé sans perdre la détection — bloc 7 (19/09/2026)"];
}
