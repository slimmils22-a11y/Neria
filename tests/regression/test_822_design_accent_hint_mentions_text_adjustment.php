<?php
/**
 * F-002 (21/09/2026) : le texte des e-mails utilise l'accent légèrement ajusté pour rester lisible (WCAG AA).
 * L'aide du réglage « Couleur d'accent » (onglet Design) doit le dire, dans les 19 langues, sans perdre la
 * mention d'origine (liens, bordures, boutons).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $d = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    neria_assert(is_array($d) && isset($d['design.color_accent_hint']), 'clé design.color_accent_hint introuvable');
    $h = $d['design.color_accent_hint'];
    $langs = ['fr', 'en', 'de', 'it', 'es', 'pt', 'br', 'ar', 'ja', 'ko', 'zh', 'tw', 'ru', 'tr', 'sv', 'no', 'da', 'nl', 'gb'];
    foreach ($langs as $l) {
        neria_assert(isset($h[$l]) && is_string($h[$l]), "traduction {$l} manquante");
        neria_assert(preg_match('/(\. |。).{12,}/u', $h[$l]) === 1, "aide accent ({$l}) : la phrase sur l'ajustement du texte manque : {$h[$l]}");
    }
    neria_assert(strpos($h['fr'], 'Liens, bordures, boutons') === 0 && strpos($h['fr'], 'ajust') !== false, 'aide fr : mention d\'origine ou ajustement absent');
    neria_assert(strpos($h['en'], 'Links, borders, buttons') === 0 && strpos($h['en'], 'adjusted') !== false, 'aide en : mention d\'origine ou ajustement absent');
    neria_assert(count(array_unique($h)) >= 17, 'traductions identiques entre langues (copier-coller ?)');
    return ['pass' => true, 'message' => "l'aide du réglage « Couleur d'accent » mentionne l'ajustement du texte dans les 19 langues"];
}
