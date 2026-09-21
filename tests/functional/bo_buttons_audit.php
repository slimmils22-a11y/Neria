<?php
/**
 * P8c — audit de TOUS les éléments cliquables des 21 onglets du back-office, sur le rendu réel (getContent(), langue fr par défaut).
 * Pour chaque bouton / lien-bouton / champ submit :
 *   B001 bouton sans texte ni aria-label/title           B002 bouton de formulaire dont neria_action est inconnue du code
 *   B003 bouton « mort » : ni formulaire, ni lien, ni onclick, ni identifiant/classe/data-* référencé par un script
 *   B004 onclick appelant une fonction introuvable         B005 lien vers une ancre (#x) absente de la page
 *   B006 lien http:// non sécurisé                          B007 destructeur (suppression, réinitialisation, purge…) SANS confirmation
 *   B008 lien externe sans rel=noopener (target=_blank)     B009 lien href vide / javascript:void sans gestionnaire
 *
 *   php -d memory_limit=1G tests/functional/bo_buttons_audit.php [--tab=a,b] [--lang=fr]
 * Sorties : results/P8c_buttons.json et .md. Niveaux : E erreur, W avertissement, I info.
 */
require_once __DIR__ . '/../regression/bootstrap.php';
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$root = str_replace('\\', '/', dirname(__DIR__, 2));
$inv = json_decode((string) file_get_contents($root . '/tests/functional/inventory/inventory.json'), true);
$tabs = $inv['tabs'];
if (!empty($opts['tab'])) {
    $tabs = array_values(array_intersect($tabs, explode(',', (string) $opts['tab'])));
}
$lang = (string) ($opts['lang'] ?? 'fr');
$known = array_column($inv['bo_actions'], 'name');
require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';
$ctx = Context::getContext();
$ctx->employee = new Employee((int) Db::getInstance()->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee ORDER BY id_employee'));
$module = Module::getInstanceByName('neria');
AdminTranslator::setLang($lang);
$idLang = (int) Language::getIdByIso($lang) ?: (int) Configuration::get('PS_LANG_DEFAULT');
$ctx->language = new Language($idLang);
$ctx->employee->id_lang = $idLang;

$jsFiles = '';
foreach (glob($root . '/views/js/*.js') ?: [] as $f) {
    $jsFiles .= "\n" . file_get_contents($f);
}
$destructive = '/(delete|remove|reset|purge|clear|drop|wipe|erase|supprim|r[ée]initialis|vider|effacer|ignore_bounce|uninstall)/i';

$items = [];
$issues = [];
$add = static function (string $tab, string $code, string $level, string $label, string $msg) use (&$issues): void {
    $issues[] = ['tab' => $tab, 'code' => $code, 'level' => $level, 'label' => $label, 'msg' => $msg];
};

foreach ($tabs as $tab) {
    $_GET = $_REQUEST = ['neria_tab' => $tab, 'configure' => 'neria'];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    Tools::resetStaticCache();
    ob_start();
    $html = '';
    try {
        $html = (string) $module->getContent();
    } catch (\Throwable $e) {
        $add($tab, 'B000', 'E', '-', 'onglet non rendu : ' . $e->getMessage());
    }
    $html .= (string) ob_get_clean();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    // scripts extraits du HTML brut : le parseur DOM tronque un <script> contenant « </ » dans une chaîne
    $scripts = $jsFiles;
    if (preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $html, $sm)) {
        $scripts .= "\n" . implode("\n", $sm[1]);
    }
    $ids = [];
    foreach ($xp->query('//*[@id]') as $el) {
        $ids[$el->getAttribute('id')] = true;
    }
    $q = '//button|//input[@type="submit" or @type="button"]|//a[contains(concat(" ",normalize-space(@class)," ")," neria-btn ") or contains(@class,"neria-btn--") or @role="button"]';
    foreach ($xp->query($q) as $el) {
        /** @var DOMElement $el */
        $tag = $el->tagName;
        $type = strtolower($el->getAttribute('type'));
        $label = trim(preg_replace('/\s+/u', ' ', $el->textContent));
        if ($label === '' && $tag === 'input') {
            $label = trim($el->getAttribute('value'));
        }
        if ($label === '') {
            $label = trim($el->getAttribute('aria-label') ?: $el->getAttribute('title'));
        }
        $short = mb_substr($label, 0, 50);
        $form = null;
        for ($p = $el->parentNode; $p; $p = $p->parentNode) {
            if ($p instanceof DOMElement && strtolower($p->tagName) === 'form') {
                $form = $p;
                break;
            }
        }
        $formAction = '';
        if ($form) {
            foreach ($xp->query('.//input[@name="neria_action"]', $form) as $in) {
                $formAction = $in->getAttribute('value');
            }
            if ($el->getAttribute('name') === 'neria_action') {
                $formAction = $el->getAttribute('value');
            }
        }
        $href = $tag === 'a' ? $el->getAttribute('href') : '';
        $onclick = $el->getAttribute('onclick');
        $id = $el->getAttribute('id');
        $kind = $tag === 'a' ? 'lien' : (($form && $type !== 'button') ? 'envoi' : 'js');
        $items[] = ['tab' => $tab, 'kind' => $kind, 'label' => $short, 'action' => $formAction, 'id' => $id];

        if ($label === '') {
            $add($tab, 'B001', 'W', $id ?: $tag, 'bouton sans texte, aria-label ni title');
        }
        if ($formAction !== '' && !in_array($formAction, $known, true)) {
            $add($tab, 'B002', 'E', $short, "neria_action '{$formAction}' inconnue du code");
        }
        if ($tag === 'a') {
            if ($href !== '' && preg_match('/[?&]neria_action=([a-z0-9_]+)/i', $href, $mm) && !in_array($mm[1], $known, true)) {
                $add($tab, 'B002', 'E', $short, "lien vers l'action inconnue '{$mm[1]}'");
            }
            if (strpos($href, 'http://') === 0 && !preg_match('#^http://(localhost|127\.0\.0\.1)#', $href)) {
                $add($tab, 'B006', 'W', $short, 'lien http:// non sécurisé : ' . mb_substr($href, 0, 70));
            }
            if ($href !== '' && $href[0] === '#' && strlen($href) > 1 && !isset($ids[substr($href, 1)]) && strpos($scripts, substr($href, 1)) === false) {
                $add($tab, 'B005', 'W', $short, "ancre {$href} absente de la page");
            }
            if ($el->getAttribute('target') === '_blank' && stripos($el->getAttribute('rel'), 'noopener') === false && preg_match('#^https?://#', $href)) {
                $add($tab, 'B008', 'W', $short, 'target=_blank sans rel=noopener');
            }
        }

        // Bouton géré par script ? (identifiant, classe, data-*, onclick)
        $handled = $form !== null && $type !== 'button';
        if ($tag === 'a' && $href !== '' && $href !== '#' && stripos($href, 'javascript:') !== 0) {
            $handled = true;
        }
        if ($onclick !== '') {
            $handled = true;
            if (preg_match('/^\s*(?:return\s+)?([A-Za-z_$][\w$.]*)\s*\(/', $onclick, $mm) && !in_array($mm[1], ['confirm', 'if', 'event', 'open', 'this', 'scrollTo', 'scroll', 'print', 'history.back', 'alert', 'window.open', 'window.scrollTo', 'window.scroll', 'window.print', 'window.alert'], true)) {
                $fn = preg_replace('/^window\./', '', $mm[1]);
                if (strpos($scripts, 'function ' . $fn) === false && !preg_match('/(?:window\.)?' . preg_quote($fn, '/') . '\s*=/', $scripts) && strpos($fn, '.') === false) {
                    $add($tab, 'B004', 'E', $short, "onclick appelle {$fn}() : fonction introuvable");
                }
            }
        }
        if (!$handled) {
            if ($id !== '' && preg_match('/[\'"#]' . preg_quote($id, '/') . '[\'"\s\)\.\[,]/', $scripts)) {
                $handled = true;
            }
            if (!$handled) {
                foreach (preg_split('/\s+/', trim($el->getAttribute('class'))) as $c) {
                    if ($c !== '' && strpos($c, 'neria-btn') !== 0 && preg_match('/[\'"\.]' . preg_quote($c, '/') . '[\'"\s\)\.\[,]/', $scripts)) {
                        $handled = true;
                        break;
                    }
                }
            }
            if (!$handled) {
                foreach ($el->attributes as $at) {
                    if (strpos($at->name, 'data-') === 0 && (strpos($scripts, '[' . $at->name) !== false || strpos($scripts, 'dataset.' . lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', substr($at->name, 5)))))) !== false)) {
                        $handled = true;
                        break;
                    }
                }
            }
            if (!$handled && ($el->hasAttribute('data-toggle') || $el->hasAttribute('data-dismiss') || $el->hasAttribute('data-bs-toggle'))) {
                $handled = true; // amorçage Bootstrap de PrestaShop
            }
            if (!$handled) {
                $add($tab, 'B003', 'E', $short, 'bouton mort : aucun formulaire, lien, onclick ni script ne le référence (id=' . ($id ?: '-') . ')');
            }
        }

        // Destructeur : confirmation attendue
        $probe = $label . ' ' . $formAction . ' ' . $href . ' ' . $id;
        if (preg_match($destructive, $probe) && mb_strlen($label) < 40 && !preg_match('/(no_confirm|cancel|annuler|fermer|close|modal-confirm|search-clear|toggle_)/i', $probe)) {
            $hasConfirm = stripos($onclick, 'confirm') !== false
                || ($form && stripos($form->getAttribute('onsubmit'), 'confirm') !== false)
                || $el->hasAttribute('data-confirm') || ($form && $form->hasAttribute('data-confirm'))
                || ($id !== '' && preg_match('/' . preg_quote($id, '/') . '[^;]{0,400}confirm\s*\(/s', $scripts) === 1)
                || ($formAction !== '' && preg_match('/' . preg_quote($formAction, '/') . '[^;]{0,600}confirm\s*\(/s', $scripts) === 1);
            if (!$hasConfirm && $form) {
                $fid = $form->getAttribute('id');
                $hasConfirm = $fid !== '' && preg_match('/' . preg_quote($fid, '/') . '[^;]{0,400}confirm\s*\(/s', $scripts) === 1;
                if (!$hasConfirm && preg_match('/confirm\s*\(/', $form->ownerDocument->saveHTML($form)) === 1) {
                    $hasConfirm = true;
                }
            }
            if (!$hasConfirm) {
                $add($tab, 'B007', 'W', $short, 'action destructrice sans confirmation détectée (' . ($formAction ?: ($href ?: $id)) . ')');
            }
        }
    }
    if (empty($opts['quiet'])) {
        echo str_pad($tab, 18) . ' ' . count(array_filter($items, static function ($i) use ($tab) {
            return $i['tab'] === $tab;
        })) . " éléments cliquables\n";
    }
}
$dir = __DIR__ . '/results';
@mkdir($dir, 0777, true);
file_put_contents($dir . '/P8c_buttons.json', json_encode(['generated' => date('c'), 'lang' => $lang, 'items' => $items, 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$by = ['E' => 0, 'W' => 0, 'I' => 0];
foreach ($issues as $i) {
    $by[$i['level']]++;
}
$kinds = [];
foreach ($items as $i) {
    $kinds[$i['kind']] = ($kinds[$i['kind']] ?? 0) + 1;
}
$md = "# P8c — audit des éléments cliquables du back-office\n\nLangue : {$lang} · éléments : " . count($items) . ' (' . implode(', ', array_map(static function ($k, $v) {
    return "$v $k";
}, array_keys($kinds), $kinds)) . ") · anomalies : **{$by['E']} E**, {$by['W']} W\n\n| Niv. | Onglet | Code | Élément | Détail |\n|---|---|---|---|---|\n";
foreach ($issues as $i) {
    $md .= '| ' . $i['level'] . ' | ' . $i['tab'] . ' | ' . $i['code'] . ' | ' . str_replace('|', '/', $i['label']) . ' | ' . str_replace('|', '/', $i['msg']) . " |\n";
}
file_put_contents($dir . '/P8c_buttons.md', $md);
echo "\nÉléments : " . count($items) . " · anomalies E={$by['E']} W={$by['W']} — tests/functional/results/P8c_buttons.md\n";
