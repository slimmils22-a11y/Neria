<?php
/**
 * P8a — parcours automatique des 21 onglets du back-office, dans plusieurs langues.
 * Appelle le VRAI Neria::getContent() (contexte employé + langue forcés), sans navigateur ni envoi. Pour chaque onglet :
 *   R100 erreur PHP/Smarty dans la sortie      R101 exception / page d'erreur du module     R102 page vide ou tronquée
 *   R110 clé de traduction brute affichée      R111 marqueur {neria_admin non résolu        R112 français résiduel (langue ≠ fr)
 *   R120 formulaire POST sans neria_action     R121 action inconnue du code                 R122 id HTML dupliqué
 *   R123 <label for> sans champ                R124 ressource locale (js/css/img) introuvable
 *   R125 lien ou bouton vide (sans texte ni aria-label)
 *
 *   php -d memory_limit=1G tests/functional/bo_walk_tabs.php [--tab=design,gdpr] [--lang=fr,en,ar] [--quiet]
 * Sorties : tests/functional/results/P8a_tabs.json et P8a_tabs.md. Niveaux : E erreur, W avertissement, I information.
 */
require_once __DIR__ . '/../regression/bootstrap.php';
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$root = str_replace('\\', '/', dirname(__DIR__, 2));
$inv  = json_decode((string) file_get_contents($root . '/tests/functional/inventory/inventory.json'), true);
$tabs = $inv['tabs'];
if (!empty($opts['tab'])) {
    $tabs = array_values(array_intersect($tabs, explode(',', (string) $opts['tab'])));
}
$langs = !empty($opts['lang']) ? explode(',', (string) $opts['lang']) : ['fr', 'en', 'ar'];
$knownActions = array_column($inv['bo_actions'], 'name');
require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';

$ctx = Context::getContext();
$idEmployee = (int) Db::getInstance()->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee ORDER BY id_employee');
$ctx->employee = new Employee($idEmployee);
$module = Module::getInstanceByName('neria');

$issues = [];
$summary = [];
$add = static function (string $tab, string $lang, string $code, string $level, string $msg) use (&$issues): void {
    $issues[] = ['tab' => $tab, 'lang' => $lang, 'code' => $code, 'level' => $level, 'msg' => $msg];
};

foreach ($tabs as $tab) {
    foreach ($langs as $lang) {
        $idLang = (int) Language::getIdByIso($lang === 'br' ? 'br' : $lang);
        if ($idLang <= 0) {
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        }
        $ctx->language = new Language($idLang);
        $ctx->employee->id_lang = $idLang;
        AdminTranslator::reset();
        AdminTranslator::setLang($lang);
        $_GET = $_REQUEST = ['neria_tab' => $tab, 'configure' => 'neria'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Tools::resetStaticCache();

        $level = error_reporting();
        $phpMsgs = [];
        set_error_handler(static function ($no, $str, $file, $line) use (&$phpMsgs) {
            // Frame du module à l'origine de l'anomalie (utile pour les dépréciations levées dans le cœur PrestaShop)
            $origin = '';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15) as $fr) {
                if (isset($fr['file']) && strpos(str_replace('\\', '/', $fr['file']), '/modules/neria/') !== false && strpos($fr['file'], 'bo_walk_tabs') === false) {
                    $origin = ' ← neria : ' . basename($fr['file']) . ':' . ($fr['line'] ?? '?');
                    break;
                }
            }
            $phpMsgs[] = "{$str} (" . basename($file) . ":{$line})" . $origin;
            return true;
        });
        ob_start();
        $html = '';
        try {
            $html = (string) $module->getContent();
        } catch (\Throwable $e) {
            $add($tab, $lang, 'R101', 'E', 'exception : ' . $e->getMessage());
        }
        $echoed = (string) ob_get_clean();
        restore_error_handler();
        $html .= $echoed;
        $t0 = $tab . '/' . $lang;
        foreach (array_slice(array_unique($phpMsgs), 0, 12) as $pm) {
            // Dépréciations du cœur PrestaShop / Smarty et thème absent de l'environnement de test : hors de notre code (niveau I)
            $core = preg_match('/ShopConstraint|strftime\(\)|themes\/classic\/lang|Failed opening .*themes/', $pm) === 1;
            $add($tab, $lang, 'R100', $core ? 'I' : 'E', $pm);
        }
        if (preg_match('/(Fatal error|Parse error|Smarty error|Undefined (variable|array key)|Unable to load template)/i', strip_tags($html), $m)) {
            $add($tab, $lang, 'R100', 'E', 'texte d\'erreur dans la page : ' . $m[1]);
        }
        if (strlen($html) < 3000) {
            $add($tab, $lang, 'R102', 'E', 'page trop courte (' . strlen($html) . ' octets)');
        }
        if (strpos($html, '{neria_admin') !== false) {
            $add($tab, $lang, 'R111', 'E', 'marqueur {neria_admin non résolu');
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        // Texte visible : clés de traduction brutes et français résiduel
        $visible = [];
        foreach ($xp->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::textarea)]') as $n) {
            $s = trim(preg_replace('/\s+/u', ' ', $n->nodeValue));
            if ($s !== '') {
                $visible[] = $s;
            }
        }
        foreach ($xp->query('//*[@title or @placeholder or @aria-label]') as $el) {
            foreach (['title', 'placeholder', 'aria-label'] as $at) {
                if ($el->hasAttribute($at) && trim($el->getAttribute($at)) !== '') {
                    $visible[] = trim($el->getAttribute($at));
                }
            }
        }
        $rawKeys = [];
        foreach ($visible as $s) {
            // exclut les fichiers (install.sql, translations.json) et noms de domaine (deepl.com) affichés tels quels
            if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $s) && !preg_match('/\.(sql|json|com|org|net|io|php|js|css|html|txt|csv|xml)$/', $s)) {
                $rawKeys[$s] = true;
            }
        }
        foreach (array_slice(array_keys($rawKeys), 0, 8) as $k) {
            $add($tab, $lang, 'R110', 'E', "clé de traduction brute affichée : {$k}");
        }
        if ($lang !== 'fr') {
            $fr = [];
            foreach ($visible as $s) {
                if (preg_match('/\b(Enregistrer|Supprimer|Annuler|Envoyer|Aucun|Aucune|Activer|Désactiver|Réinitialiser|Télécharger|Modifier|Ajouter|Paramètres|Statut|calcul|jours|mois|erreur|Voir|Fermer|Aperçu|Actifs?|Inactifs?|Résultat|Dernier|Dernière)\b/u', $s, $mm) && mb_strlen($s) < 120) {
                    $fr[$mm[1] . ' : ' . mb_substr($s, 0, 60)] = true;
                } elseif (in_array($lang, ['en', 'gb', 'ja', 'ko', 'zh', 'tw', 'ru', 'ar'], true) && preg_match('/[éèêàçùôûî]/u', $s) && preg_match('/\b(le|la|les|des|du|pour|avec|dans|vos|votre|cette|une)\b/u', $s) && mb_strlen($s) < 160) {
                    // lettres accentuées françaises dans une langue qui n'en utilise pas
                    $fr['accents : ' . mb_substr($s, 0, 60)] = true;
                }
            }
            foreach (array_slice(array_keys($fr), 0, 6) as $k) {
                $add($tab, $lang, 'R112', 'W', "français résiduel en '{$lang}' — {$k}");
            }
        }

        // Formulaires et actions
        foreach ($xp->query('//form') as $form) {
            $method = strtolower($form->getAttribute('method') ?: 'get');
            $names = [];
            foreach ($xp->query('.//input[@name="neria_action"]|.//button[@name="neria_action"]|.//*[@name="neria_action"]', $form) as $in) {
                $names[] = $in->getAttribute('value');
            }
            $actionAttr = $form->getAttribute('action');
            if ($method === 'post' && !$names && strpos($actionAttr, 'neria_action=') === false && !$form->hasAttribute('data-neria-no-action')) {
                $add($tab, $lang, 'R120', 'W', 'formulaire POST sans neria_action (id=' . ($form->getAttribute('id') ?: '-') . ')');
            }
            foreach ($names as $nm) {
                if ($nm !== '' && !in_array($nm, $knownActions, true)) {
                    $add($tab, $lang, 'R121', 'E', "action '{$nm}' inconnue du code");
                }
            }
        }
        foreach ($xp->query('//a[@href]') as $a) {
            if (preg_match('/[?&]neria_action=([a-z0-9_]+)/i', $a->getAttribute('href'), $mm) && !in_array($mm[1], $knownActions, true)) {
                $add($tab, $lang, 'R121', 'E', "lien vers l'action inconnue '{$mm[1]}'");
            }
            $txt = trim($a->textContent);
            if ($txt === '' && !$a->hasAttribute('aria-label') && !$a->hasAttribute('title') && !$xp->query('.//img[@alt]|.//svg|.//i', $a)->length) {
                $add($tab, $lang, 'R125', 'W', 'lien sans texte : ' . mb_substr($a->getAttribute('href'), 0, 80));
            }
        }
        foreach ($xp->query('//button') as $b) {
            if (trim($b->textContent) === '' && !$b->hasAttribute('aria-label') && !$b->hasAttribute('title') && !$xp->query('.//svg|.//i|.//img', $b)->length) {
                $add($tab, $lang, 'R125', 'W', 'bouton sans texte (id=' . ($b->getAttribute('id') ?: '-') . ')');
            }
        }

        // Identifiants dupliqués et labels orphelins
        $ids = [];
        foreach ($xp->query('//*[@id]') as $el) {
            $ids[$el->getAttribute('id')] = ($ids[$el->getAttribute('id')] ?? 0) + 1;
        }
        foreach ($ids as $id => $n) {
            if ($n > 1 && strpos($id, '{') === false) {
                $add($tab, $lang, 'R122', 'W', "id dupliqué : {$id} ×{$n}");
            }
        }
        foreach ($xp->query('//label[@for]') as $l) {
            $for = $l->getAttribute('for');
            if ($for !== '' && !isset($ids[$for])) {
                $add($tab, $lang, 'R123', 'W', "<label for=\"{$for}\"> sans champ");
            }
        }
        // Ressources locales
        foreach ($xp->query('//script[@src]|//link[@href]|//img[@src]') as $el) {
            $u = $el->getAttribute('src') ?: $el->getAttribute('href');
            if (preg_match('#/modules/neria/([^?\#"]+)#', $u, $mm) && !is_file($root . '/' . $mm[1])) {
                $add($tab, $lang, 'R124', 'E', "ressource locale introuvable : {$mm[1]}");
            }
        }
        $summary[$tab][$lang] = [
            'bytes' => strlen($html),
            'forms' => $xp->query('//form')->length,
            'buttons' => $xp->query('//button|//a[contains(@class,"neria-btn")]|//input[@type="submit"]')->length,
        ];
        if (empty($opts['quiet'])) {
            echo str_pad($t0, 24) . ' ' . str_pad((string) strlen($html), 8) . ' formulaires=' . $summary[$tab][$lang]['forms'] . ' boutons=' . $summary[$tab][$lang]['buttons'] . "\n";
        }
    }
}

// Regrouper : une même anomalie identique dans plusieurs langues n'est comptée qu'une fois par onglet
$grouped = [];
foreach ($issues as $i) {
    $key = $i['tab'] . '|' . $i['code'] . '|' . preg_replace('/en \'[a-z]{2}\'/', 'en langue', $i['msg']);
    if (!isset($grouped[$key])) {
        $grouped[$key] = $i + ['langs' => []];
    }
    $grouped[$key]['langs'][] = $i['lang'];
}
$dir = __DIR__ . '/results';
@mkdir($dir, 0777, true);
file_put_contents($dir . '/P8a_tabs.json', json_encode(['generated' => date('c'), 'tabs' => $tabs, 'langs' => $langs, 'summary' => $summary, 'issues' => array_values($grouped)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$byLevel = ['E' => 0, 'W' => 0, 'I' => 0];
foreach ($grouped as $g) {
    $byLevel[$g['level']]++;
}
$md = "# P8a — parcours des onglets du back-office\n\nOnglets : " . count($tabs) . ' · langues : ' . implode(', ', $langs) . ' · pages rendues : ' . count($tabs) * count($langs) . "\n\n";
$md .= "Anomalies distinctes : **{$byLevel['E']} erreur(s)**, {$byLevel['W']} avertissement(s)\n\n| Niveau | Onglet | Code | Langues | Détail |\n|---|---|---|---|---|\n";
foreach ($grouped as $g) {
    $md .= '| ' . $g['level'] . ' | ' . $g['tab'] . ' | ' . $g['code'] . ' | ' . implode(',', array_unique($g['langs'])) . ' | ' . str_replace('|', '/', $g['msg']) . " |\n";
}
file_put_contents($dir . '/P8a_tabs.md', $md);
echo "\nAnomalies distinctes : E={$byLevel['E']} W={$byLevel['W']} — détail : tests/functional/results/P8a_tabs.md\n";
