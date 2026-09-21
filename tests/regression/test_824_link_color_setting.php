<?php
/**
 * Réglage « Couleur des liens » (onglet Design, 21/09/2026). Vide (défaut) = identique à l'accent : l'apparence de ceux
 * qui ne touchent pas au réglage ne change pas. Test comportemental : rendu réel, sauvegarde/remise à zéro, traductions.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/EmailRenderer.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';
    $module = neria_test_module();
    $renderer = new EmailRenderer($module);
    $cfg = new ConfigManager($module);
    $links = static function (array $override) use ($renderer): array {
        $html = $renderer->renderPreviewHtml('unboxing_guide', 'en', $override);
        preg_match_all('/<a href="[^"]*" style="[^"]*?color: ?(#[0-9a-f]{6})[^"]*">(Visit our house|Manage my preferences)<\/a>/i', $html, $m);
        neria_assert(count($m[1]) === 2, 'liens du pied de page introuvables dans le rendu');
        return $m[1];
    };
    $accent = ['color_link' => '', 'color_accent' => '#b38b59', 'color_footer_bg' => '#ffffff'];

    // 1. Défaut : liens = accent assombri pour la lisibilité, exactement comme avant le réglage.
    $expected = strtolower(ConfigManager::getAccessibleTextColor('#b38b59', '#ffffff'));
    neria_assert($links($accent) === [$expected, $expected], 'défaut : les liens ne suivent plus l\'accent (apparence modifiée pour ceux qui ne touchent pas au réglage)');
    // 2. Réglage renseigné : les liens changent, l'accent non.
    $blue = $links(array_merge($accent, ['color_link' => '#1a5fb4']));
    neria_assert($blue === ['#1a5fb4', '#1a5fb4'], 'couleur des liens choisie non appliquée : ' . implode(',', $blue));
    // 3. Accent modifié sans réglage des liens : les liens suivent l'accent.
    $viaAccent = $links(['color_link' => '', 'color_accent' => '#1a5fb4', 'color_footer_bg' => '#ffffff']);
    neria_assert($viaAccent === ['#1a5fb4', '#1a5fb4'], 'liens ne suivant plus l\'accent modifié : ' . implode(',', $viaAccent));
    // 4. Pied de page sombre : liens éclaircis (lisibles), pas assombris.
    $dark = $links(array_merge($accent, ['color_footer_bg' => '#2b2520']));
    neria_assert($dark === ['#b38b59', '#b38b59'], 'pied sombre : liens non lisibles : ' . implode(',', $dark));

    // 5. Sauvegarde / lecture / « identique » / valeur invalide / remise à zéro (valeur d'origine restaurée ensuite).
    $original = (string) $cfg->get(ConfigManager::KEY_COLOR_LINK);
    try {
        $cfg->saveDesignConfig(['color_link' => '#123456']);
        neria_assert($cfg->getDesignConfig()['color_link'] === '#123456', 'couleur des liens non enregistrée');
        $cfg = new ConfigManager($module);
        $cfg->saveDesignConfig(['color_link' => '#abcdef', 'color_link_same' => '1']);
        neria_assert((new ConfigManager($module))->getDesignConfig()['color_link'] === '', 'case « identique à l\'accent » ignorée');
        $cfg->saveDesignConfig(['color_link' => '#123456']);
        $cfg->saveDesignConfig(['color_link' => 'pas une couleur']);
        neria_assert((new ConfigManager($module))->getDesignConfig()['color_link'] === '', 'valeur invalide non neutralisée');
        $cfg->saveDesignConfig(['color_link' => '#123456']);
        $cfg->resetDesignConfig();
        neria_assert((new ConfigManager($module))->getDesignConfig()['color_link'] === '', 'remise à zéro du design sans effet sur la couleur des liens');
    } finally {
        $cfg->saveDesignConfig($original === '' ? ['color_link_same' => '1'] : ['color_link' => $original]);
    }

    // 6. Libellés dans les 19 langues.
    $d = json_decode((string) file_get_contents(_PS_MODULE_DIR_ . 'neria/data/admin_translations.json'), true);
    foreach (['design.color_link', 'design.color_link_hint', 'design.color_link_same'] as $k) {
        neria_assert(isset($d[$k]) && count($d[$k]) === 19, "clé {$k} : 19 langues attendues");
        foreach ($d[$k] as $l => $v) {
            neria_assert(is_string($v) && trim($v) !== '', "clé {$k} vide en {$l}");
        }
        neria_assert(count(array_unique($d[$k])) >= 16, "clé {$k} : traductions quasi identiques");
    }
    return ['pass' => true, 'message' => "« Couleur des liens » : vide = accent (apparence inchangée), valeur choisie appliquée au corps et au pied de page, pied sombre éclairci, sauvegarde/case « identique »/valeur invalide/remise à zéro, 19 langues"];
}
