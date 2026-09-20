<?php
/**
 * P2a — rendu massif et contrôle automatique de TOUS les templates d'e-mail dans les 19 langues.
 * Utilise le chemin RÉEL des envois (EmailRenderer::compileNeriaTemplate) avec les variables factices de
 * l'aperçu. AUCUN e-mail n'est envoyé ; les fichiers compilés temporaires sont supprimés au fur et à mesure.
 *
 *   php -d memory_limit=1G tests/functional/render_all_templates.php [--tpl=nom[,nom]] [--lang=fr[,en]] [--quiet]
 *
 * Sorties : tests/functional/results/P2a_render.json (tout le détail) et P2a_render.md (synthèse).
 * Niveaux : E = erreur (à corriger), W = avertissement (à arbitrer), I = information.
 */
require_once __DIR__ . '/../regression/bootstrap.php';
foreach (['CryptoManager', 'NeriaTools', 'TranslationEngine', 'AdminTranslator', 'ConfigManager', 'EmailRenderer'] as $c) {
    require_once _PS_MODULE_DIR_ . 'neria/src/' . $c . '.php';
}
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$root  = str_replace('\\', '/', dirname(__DIR__, 2));
$core  = $root . '/mails/themes/neria_global/core';
$langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'gb', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl'];
if (!empty($opts['lang'])) {
    $langs = array_values(array_intersect($langs, explode(',', (string) $opts['lang'])));
}
$templates = [];
foreach (glob($core . '/*.html') ?: [] as $f) {
    $n = basename($f, '.html');
    if (strpos($n, '__test') === 0) {
        continue;
    }
    $templates[] = $n;
}
sort($templates);
if (!empty($opts['tpl'])) {
    $templates = array_values(array_intersect($templates, explode(',', (string) $opts['tpl'])));
}

$module   = neria_test_module();
$renderer = new EmailRenderer($module);
$compile  = new ReflectionMethod(EmailRenderer::class, 'compileNeriaTemplate');
$compile->setAccessible(true);
$fakesM   = new ReflectionMethod(EmailRenderer::class, 'buildPreviewFakes');
$fakesM->setAccessible(true);
$engine   = new TranslationEngine($module);
require_once _PS_MODULE_DIR_ . 'neria/src/PreferencesManager.php';
$prefMgr  = new PreferencesManager($module);
// Champs saisis par le marchand dans l'envoi manuel (warranty_period, invitation_location…) : renseignés à la main.
$manualFields = [];
if (is_file(_PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php')) {
    require_once _PS_MODULE_DIR_ . 'neria/src/ManualSendManager.php';
    if (defined('ManualSendManager::FIELD_LABEL_I18N')) {
        $manualFields = array_keys(ManualSendManager::FIELD_LABEL_I18N);
    }
}
// Variables que le moteur injecte lui-même dans un vrai envoi (toute occurrence '{nom}' dans src/*.php) : une variable
// non résolue qui en fait partie relève du contexte d'envoi réel, pas d'un défaut du template.
$knownRuntime = [];
foreach (glob($root . '/src/*.php') ?: [] as $sf) {
    if (preg_match_all("/'\{([a-z][a-z0-9_]*)\}'/i", (string) file_get_contents($sf), $kv)) {
        foreach ($kv[1] as $k) {
            $knownRuntime[$k] = true;
        }
    }
}

// ── Outils d'analyse ──────────────────────────────────────────────────────────────────────────
$visibleText = static function (string $html): string {
    $h = preg_replace('#<(style|script|head|title)\b.*?</\1>#is', ' ', $html);
    $h = preg_replace('/<!--.*?-->/s', ' ', $h);
    $h = preg_replace('/<(br|\/p|\/div|\/tr|\/li|\/h[1-6])\b[^>]*>/i', ' ', $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
};
$frWords = '/\b(votre|vos|nous|vous|merci|bonjour|cordialement|commande|avec|pour|dans|cette|être|très|chez|notre|nos|livraison|adresse|panier|produit|produits)\b/iu';
$enStop  = '/\b(the|and|your|you|with|this|that|are|will|has|have|from|our|please)\b/i'; // « for » exclu : vrai mot en da/no/sv/nl
$scriptRe = ['ar' => '/\p{Arabic}/u', 'ja' => '/[\p{Hiragana}\p{Katakana}\p{Han}]/u', 'ko' => '/\p{Hangul}/u', 'zh' => '/\p{Han}/u', 'tw' => '/\p{Han}/u', 'ru' => '/\p{Cyrillic}/u'];
$htmlLangOk = ['fr' => 'fr', 'en' => 'en', 'de' => 'de', 'it' => 'it', 'es' => 'es', 'pt' => 'pt', 'br' => 'pt', 'gb' => 'en', 'ar' => 'ar', 'ja' => 'ja', 'ko' => 'ko', 'zh' => 'zh', 'tw' => 'zh', 'ru' => 'ru', 'tr' => 'tr', 'sv' => 'sv', 'no' => 'n', 'da' => 'da', 'nl' => 'nl'];

$issues = [];      // [template][lang] => [[level, code, message], ...]
$stats  = ['rendered' => 0, 'failed' => 0, 'files_cleaned' => 0];
$placeholderNames = [];
$add = static function (string $tpl, string $lang, string $lvl, string $code, string $msg) use (&$issues): void {
    $issues[$tpl][$lang][] = [$lvl, $code, mb_substr($msg, 0, 220)];
};

$cleanup = static function () use ($root, &$stats): void {
    foreach (glob($root . '/mails/*/p2a_*') ?: [] as $f) {
        if (@unlink($f)) {
            $stats['files_cleaned']++;
        }
    }
};
register_shutdown_function($cleanup);

foreach ($manualFields as $mf) {
    $knownRuntime[$mf] = true;
}
$coreSources = [];
foreach ($templates as $tn0) {
    $coreSources[$tn0] = (string) file_get_contents($core . '/' . $tn0 . '.html');
}
$t0 = microtime(true);
foreach ($templates as $tpl) {
    if (!empty($opts['quiet']) === false) {
        fwrite(STDERR, "\r" . str_pad($tpl, 40));
    }
    foreach ($langs as $lang) {
        $fakes = $fakesM->invoke($renderer, $tpl, $lang);
        // l'aperçu met '#' dans {preferences_url} : on utilise l'URL réelle, comme un vrai envoi
        $fakes['{preferences_url}'] = $prefMgr->getPreferencesUrl('client@example.com', 3, $lang, 1);
        // Données factices neutralisées : l'aperçu met '#' aux URL, des chemins relatifs aux images et des messages
        // d'exemple en français — ce sont des artefacts d'aperçu, pas des défauts de template.
        foreach ($fakes as $fk => $fvv) {
            $fkn = strtolower(trim($fk, '{}'));
            if ($fvv === '#') {
                $fakes[$fk] = 'https://shop.example/' . $fkn;
            } elseif (is_string($fvv) && preg_match('/(image|img|logo|photo|picture|cover)/', $fkn) && $fvv !== '' && !preg_match('#^(https?:)?//|^data:|^<#i', $fvv) && preg_match('#^/?[\w./-]+\.(png|jpe?g|gif|webp)#i', $fvv)) {
                $fakes[$fk] = 'https://shop.example/' . ltrim($fvv, '/');
            }
        }
        // Variables propres au template (blocs produit, URL de devis…) absentes du jeu factice de l'aperçu : on les
        // renseigne d'après leur nom, comme le ferait le gestionnaire qui envoie ce template en vrai.
        $coreSrc = $coreSources[$tpl] ?? '';
        if (preg_match_all('/\{([a-z][a-z0-9_]*)\}/i', $coreSrc, $cv)) {
            foreach (array_unique($cv[1]) as $vn) {
                $key = '{' . $vn . '}';
                if (isset($fakes[$key]) || in_array(strtolower($vn), ['if', 'else', 'foreach', 'literal', 'block'], true)) {
                    continue;
                }
                if (!isset($knownRuntime[$vn])) {
                    // présente dans le template mais jamais renseignée nulle part dans le code du module
                    $add($tpl, $lang, 'E', 'R011', 'variable du template inconnue du moteur (jamais renseignée) : ' . $vn);
                }
                if (preg_match('/(url|link|href)$/i', $vn)) {
                    $fakes[$key] = 'https://shop.example/' . $vn;
                } elseif (preg_match('/(image|img|photo|picture|cover)/i', $vn)) {
                    $fakes[$key] = 'https://shop.example/' . $vn . '.jpg';
                } elseif (preg_match('/(message|msg|comment|note|text|body)/i', $vn)) {
                    $fakes[$key] = 'Sample message text';
                } else {
                    $fakes[$key] = 'Sample';
                }
            }
        }
        foreach ($fakes as $fk3 => $fv3) {
            if (is_string($fv3) && strpos($fv3, 'href="#"') !== false) {
                $fakes[$fk3] = str_replace('href="#"', 'href="https://shop.example/x"', $fv3); // blocs HTML factices
            }
        }
        foreach ($fakes as $fk2 => $fv2) {
            if (is_string($fv2) && preg_match('/(message|msg|comment)/i', $fk2) && !preg_match('#^https?://|^<#i', $fv2) && strlen($fv2) > 3) {
                $fakes[$fk2] = 'Sample message text';
            }
        }
        $fakes['{custom_message}'] = $fakes['{custom_message}'] ?? '';
        $fakes['{custom_message_txt}'] = $fakes['{custom_message_txt}'] ?? '';
        $out   = 'p2a_' . $tpl . '_' . $lang;
        try {
            $res = $compile->invoke($renderer, $tpl, $lang, $lang, $fakes, true, true, $out);
        } catch (Throwable $e) {
            $add($tpl, $lang, 'E', 'R001', 'exception à la compilation : ' . $e->getMessage());
            $stats['failed']++;
            continue;
        }
        $hp = $root . '/mails/' . $lang . '/' . $out . '.html';
        $tp = $root . '/mails/' . $lang . '/' . $out . '.txt';
        if ($res === null || !is_file($hp)) {
            $add($tpl, $lang, 'E', 'R001', 'compilation sans résultat (fichier HTML absent)');
            $stats['failed']++;
            continue;
        }
        $stats['rendered']++;
        $html = (string) file_get_contents($hp);
        $txt  = is_file($tp) ? (string) file_get_contents($tp) : '';
        @unlink($hp);
        @unlink($tp);

        if (trim($html) === '' || strlen($html) < 500) {
            $add($tpl, $lang, 'E', 'R002', 'HTML vide ou anormalement court (' . strlen($html) . ' octets)');
            continue;
        }

        // Texte visible sans les valeurs factices (noms, produits… en français par construction).
        $text = $visibleText($html);
        $clean = $text;
        foreach ($fakes as $fv) {
            $fv = trim(strip_tags((string) $fv));
            if (mb_strlen($fv) >= 3 && mb_strlen($fv) < 200) {
                $clean = str_ireplace($fv, ' ', $clean);
            }
        }

        // R010/R011 : syntaxe de template et variables non résolues
        $body = preg_replace('#<(style|script)\b.*?</\1>#is', ' ', $html);
        $body = preg_replace('/<!--.*?-->/s', ' ', $body);
        if (preg_match('/\{\$|\{neria_|\{\/?(?:if|else|elseif|foreach|literal|assign|block)\b/', $body, $mm)) {
            $add($tpl, $lang, 'E', 'R010', 'résidu de syntaxe de template : ' . $mm[0]);
        }
        if (preg_match_all('/\{([a-z][a-z0-9_]*)\}/i', $body, $ph)) {
            $names = array_values(array_unique($ph[1]));
            foreach ($names as $nme) {
                $placeholderNames[$nme][$tpl] = true;
            }
            $orphans = array_values(array_filter($names, static fn ($n) => !isset($knownRuntime[$n])));
            if ($orphans) {
                $add($tpl, $lang, 'E', 'R011', 'variable(s) inconnue(s) du moteur, non résolue(s) : ' . implode(', ', array_slice($orphans, 0, 6)));
            }
        }

        // R020/R021/R022 : langue du contenu
        if ($lang !== 'fr' && preg_match_all($frWords, $clean, $fm) && count($fm[0]) >= 1) {
            // faux amis : mots français qui sont aussi de vrais mots d'autres langues
            $falseFriends = ['es' => ['nos'], 'pt' => ['nos'], 'br' => ['nos'], 'de' => ['adresse'], 'da' => ['adresse'], 'no' => ['adresse'], 'nl' => ['adresse'], 'sv' => ['adresse']];
            $fakeWords = [];
            foreach ($fakes as $fv4) {
                if (is_string($fv4) && preg_match_all('/\p{L}{3,}/u', strip_tags($fv4), $fw)) {
                    foreach ($fw[0] as $w1) {
                        $fakeWords[mb_strtolower($w1)] = true;
                    }
                }
            }
            $hits = array_values(array_diff(array_unique(array_map('mb_strtolower', $fm[0])), $falseFriends[$lang] ?? [], array_keys($fakeWords)));
            if ($hits) {
                $add($tpl, $lang, 'E', 'R020', 'français résiduel : ' . implode(', ', array_slice($hits, 0, 6)));
            }
        }
        if (!in_array($lang, ['en', 'gb'], true) && preg_match_all($enStop, $clean, $em) && count($em[0]) >= 5) {
            $add($tpl, $lang, 'W', 'R021', 'anglais résiduel (' . count($em[0]) . ' mots courants) — repli de traduction ?');
        }
        if (isset($scriptRe[$lang])) {
            $letters = preg_match_all('/\p{L}/u', $clean);
            $inScript = preg_match_all($scriptRe[$lang], $clean);
            if ($letters > 30 && $inScript / max(1, $letters) < 0.25) {
                $add($tpl, $lang, 'E', 'R022', 'texte insuffisamment dans l\'écriture attendue (' . round(100 * $inScript / $letters) . ' %)');
            }
        }

        // R030/R031 : direction et langue du document
        $rtl = (bool) preg_match('/<html[^>]*\bdir="rtl"|<body[^>]*\bdir="rtl"|dir="rtl"/i', $html);
        if ($lang === 'ar' && !$rtl) {
            $add($tpl, $lang, 'E', 'R030', 'arabe sans dir="rtl"');
        }
        if ($lang !== 'ar' && $rtl) {
            $add($tpl, $lang, 'E', 'R030', 'dir="rtl" présent hors arabe');
        }
        if (preg_match('/<html[^>]*\blang="([^"]*)"/i', $html, $lm)) {
            if (strpos(strtolower($lm[1]), $htmlLangOk[$lang]) !== 0) {
                $add($tpl, $lang, 'W', 'R031', "attribut lang=\"{$lm[1]}\" inattendu pour '{$lang}'");
            }
        } else {
            $add($tpl, $lang, 'W', 'R031', 'attribut lang absent de <html>');
        }

        // R040/R041 : liens et boutons
        if (preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/i', $html, $hm)) {
            foreach ($hm[1] as $href) {
                $h = trim(html_entity_decode($href));
                if ($h === '' || $h === '#' || stripos($h, 'javascript:') === 0 || strpos($h, '{') !== false) {
                    $add($tpl, $lang, 'E', 'R040', 'lien invalide : « ' . $h . ' »');
                }
            }
        }
        if (stripos($html, 'unsubscribe') === false && stripos($html, 'désabonn') === false && !preg_match('/href="[^"]*(unsubscribe|preferences)/i', $html)) {
            $add($tpl, $lang, 'I', 'R042', 'aucun lien de désabonnement/préférences détecté dans le HTML');
        }

        // R050 : images
        if (preg_match_all('/<img\b[^>]*>/i', $html, $im)) {
            foreach ($im[0] as $img) {
                if (!preg_match('/\balt="/i', $img)) {
                    $add($tpl, $lang, 'W', 'R050', 'image sans attribut alt');
                }
                if (preg_match('/\bsrc="([^"]*)"/i', $img, $sm) && !preg_match('#^(https?:)?//|^data:#i', $sm[1])) {
                    $add($tpl, $lang, 'E', 'R051', 'image à URL relative : ' . mb_substr($sm[1], 0, 60));
                }
            }
        }

        // R060/R061 : poids Gmail et encodage
        $sz = strlen($html);
        if ($sz > 102400) {
            $add($tpl, $lang, 'E', 'R060', "HTML de {$sz} octets > 102 Ko : Gmail tronque le message");
        } elseif ($sz > 92000) {
            $add($tpl, $lang, 'W', 'R060', "HTML de {$sz} octets, proche de la limite Gmail (102 Ko)");
        }
        if (preg_match('/Ã[©¨ª«¢¤§]|â€|�/u', $html)) {
            $add($tpl, $lang, 'E', 'R061', 'caractères mal encodés (mojibake) dans le HTML');
        }
        if (stripos($html, 'charset=utf-8') === false && stripos($html, 'charset="utf-8"') === false) {
            $add($tpl, $lang, 'W', 'R061', 'déclaration charset utf-8 absente');
        }

        // R070 : constructions à risque selon les clients de messagerie (moteur Word d'Outlook, Gmail)
        $css = $html;
        foreach ([
            'display:flex/grid'      => ['E', '/display\s*:\s*(?:inline-)?(?:flex|grid)/i'],
            'position absolue/fixe'  => ['W', '/position\s*:\s*(?:absolute|fixed)/i'],
            'variables CSS var()'    => ['E', '/var\(\s*--/i'],
            'calc()'                 => ['W', '/calc\(/i'],
            'background-image CSS'   => ['W', '/background(?:-image)?\s*:[^;"\']*url\(/i'],
            'balise <svg>/<video>/<iframe>/<form>' => ['W', '/<(?:svg|video|iframe|form|script)\b/i'],
        ] as $label => [$lvl, $re]) {
            if (preg_match($re, $css)) {
                $add($tpl, $lang, $lvl, 'R070', 'CSS/HTML non supporté par certains clients : ' . $label);
            }
        }

        // R080 : version texte
        if ($txt === '') {
            $add($tpl, $lang, 'E', 'R080', 'version TXT absente ou vide');
        } else {
            if (preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $txt)) {
                $add($tpl, $lang, 'E', 'R081', 'balises HTML dans la version TXT');
            }
            if (preg_match('/\{\$|\{neria_|\{\/?(?:if|else|foreach)\b/', $txt)) {
                $add($tpl, $lang, 'E', 'R081', 'résidu de syntaxe dans la version TXT');
            }
            if (preg_match_all('/\{([a-z][a-z0-9_]*)\}/i', $txt, $tph)) {
                $orphT = array_values(array_filter(array_unique($tph[1]), static fn ($n) => !isset($knownRuntime[$n])));
                if ($orphT) {
                    $add($tpl, $lang, 'E', 'R082', 'variable(s) inconnue(s) du moteur dans le TXT : ' . implode(', ', array_slice($orphT, 0, 6)));
                }
            }
            if (strlen(trim($txt)) < 40) {
                $add($tpl, $lang, 'W', 'R082', 'version TXT très courte (' . strlen(trim($txt)) . ' octets)');
            }
        }

        // R090 : « sujet » (titre principal utilisé quand PrestaShop n'en fournit pas)
        $headline = trim((string) $engine->get($tpl, 'greeting_main', $lang, 1));
        if ($headline === '' || $headline === 'greeting_main') {
            $add($tpl, $lang, 'W', 'R090', 'titre principal (greeting_main) absent : sujet de repli vide');
        }
    }
}
$cleanup();
if (empty($opts['quiet'])) {
    fwrite(STDERR, "\r" . str_repeat(' ', 60) . "\r");
}

// ── Contrastes de couleur (WCAG) sur les couleurs de texte du thème ───────────────────────────
$lum = static function (string $hex): float {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    foreach ($c as &$v) {
        $v /= 255;
        $v = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = static function (string $a, string $b) use ($lum): float {
    $la = $lum($a);
    $lb = $lum($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
};
$design = (new ConfigManager($module))->getDesignConfig();
$pairs  = [
    'texte principal sur fond du conteneur'    => [$design['color_text'] ?? '#2c2c2c', $design['color_container'] ?? '#ffffff'],
    'accent (liens, pied de page) sur conteneur' => [$design['color_accent'] ?? '#b38b59', $design['color_container'] ?? '#ffffff'],
    'texte du pied de page sur son fond'       => [$design['color_footer_text'] ?? '#888888', $design['color_footer_bg'] ?? '#ffffff'],
    'texte du bouton sur la couleur du bouton' => ['#ffffff', $design['btn_color'] ?? '#2b2520'],
];
$contrast = [];
foreach ($pairs as $label => [$fg, $bg]) {
    if (preg_match('/^#[0-9a-f]{3,6}$/i', (string) $fg) && preg_match('/^#[0-9a-f]{3,6}$/i', (string) $bg)) {
        $r = $ratio($fg, $bg);
        $contrast[] = ['pair' => $label, 'fg' => $fg, 'bg' => $bg, 'ratio' => round($r, 2), 'aa_normal' => $r >= 4.5, 'aa_large' => $r >= 3.0];
    }
}
// Toutes les couleurs de texte déclarées dans le layout et les templates, contre le blanc
$muted = [];
foreach (array_merge([$root . '/mails/themes/neria_global/layout.html'], glob($core . '/*.html') ?: []) as $f) {
    $src = (string) file_get_contents($f);
    if (preg_match_all('/(?<![-a-z])color\s*:\s*(#[0-9a-fA-F]{6})/', $src, $cm)) {
        foreach ($cm[1] as $col) {
            $r = $ratio($col, '#ffffff');
            if ($r < 4.5) {
                $muted[strtolower($col)]['ratio'] = round($r, 2);
                $muted[strtolower($col)]['files'][basename($f)] = true;
            }
        }
    }
}
foreach ($muted as $col => &$mv) {
    $mv['files'] = array_slice(array_keys($mv['files']), 0, 8) + ['total' => count($mv['files'])];
}
unset($mv);

// ── Synthèse ──────────────────────────────────────────────────────────────────────────────────
$byCode = [];
$tplLangCount = [];
foreach ($issues as $tpl => $byLang) {
    foreach ($byLang as $lang => $list) {
        foreach ($list as [$lvl, $code, $msg]) {
            $k = $lvl . ' ' . $code;
            $byCode[$k]['count'] = ($byCode[$k]['count'] ?? 0) + 1;
            $byCode[$k]['templates'][$tpl] = true;
            $byCode[$k]['langs'][$lang] = true;
            $byCode[$k]['example'] = $byCode[$k]['example'] ?? "{$tpl}/{$lang}: {$msg}";
        }
        $tplLangCount["{$tpl}/{$lang}"] = count($list);
    }
}
ksort($byCode);
$total = count($templates) * count($langs);
$report = [
    'generated'  => date('Y-m-d H:i:s'),
    'templates'  => count($templates),
    'langs'      => $langs,
    'combinations' => $total,
    'rendered'   => $stats['rendered'],
    'failed'     => $stats['failed'],
    'seconds'    => round(microtime(true) - $t0, 1),
    'summary'    => array_map(static fn ($v) => ['count' => $v['count'], 'templates' => count($v['templates']), 'langs' => array_keys($v['langs']), 'example' => $v['example']], $byCode),
    'contrast'   => $contrast,
    'low_contrast_colors' => $muted,
    'placeholders_unresolved' => array_map(static fn ($t) => array_keys($t), $placeholderNames),
    'issues'     => $issues,
];
$outDir = $root . '/tests/functional/results';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents($outDir . '/P2a_render.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$md  = "# P2a — rendu automatique des templates d'e-mail (aucun envoi)\n\n";
$md .= "Généré le {$report['generated']} — {$report['templates']} templates × " . count($langs) . " langues = {$total} combinaisons ; **{$report['rendered']} rendues**, {$report['failed']} échecs ; {$report['seconds']} s.\n\n";
$md .= "## Résultats par contrôle\n\n| Niveau / code | Occurrences | Templates | Exemple |\n|---|---|---|---|\n";
foreach ($report['summary'] as $k => $v) {
    $md .= "| {$k} | {$v['count']} | {$v['templates']} | " . str_replace('|', '/', $v['example']) . " |\n";
}
$md .= "\n## Contrastes (WCAG AA : 4,5 texte normal, 3 grand texte)\n\n| Paire | Ratio | AA normal |\n|---|---|---|\n";
foreach ($contrast as $c) {
    $md .= "| {$c['pair']} ({$c['fg']} / {$c['bg']}) | {$c['ratio']} | " . ($c['aa_normal'] ? 'oui' : ($c['aa_large'] ? 'non (grand texte seulement)' : 'NON')) . " |\n";
}
$md .= "\nCouleurs de texte sous 4,5:1 contre le blanc : " . count($muted) . " (voir JSON › low_contrast_colors).\n";
file_put_contents($outDir . '/P2a_render.md', $md);
echo $md;
