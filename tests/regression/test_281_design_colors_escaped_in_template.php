<?php
/**
 * Régression : design.tpl affichait 9 champs de couleur
 * (color_background/color_container/color_accent/color_text/
 * color_header_bg/color_footer_bg/color_footer_text, en double pour le
 * picker natif et le champ texte synchronisé) sans `|escape:'html'` dans
 * l'attribut value="". ConfigManager::saveDesignConfig() valide déjà le
 * format hexadécimal côté serveur (une valeur invalide n'est jamais
 * persistée), donc le risque réel était limité — mais l'absence
 * d'échappement restait une incohérence par rapport au reste du template
 * (défense en profondeur : le template ne doit jamais dépendre uniquement
 * de la validation en amont).
 *
 * Corrigé le 13/08/2026 (round 164) : `|escape:'html'` ajouté aux 14
 * occurrences.
 *
 * Test structurel : vérifie qu'aucune sortie value="{$design.color_...}"
 * ne reste sans échappement.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $tpl = file_get_contents(_PS_MODULE_DIR_ . 'neria/views/templates/admin/design.tpl');
    neria_assert($tpl !== false, 'Impossible de lire design.tpl');

    $countAll = preg_match_all('/value="\{\$design\.color_[a-z_]+\|default:\'#[0-9a-fA-F]+\'\}"/', $tpl);
    neria_assert(
        $countAll === 0,
        "design.tpl contient de nouveau des champs value=\"{\$design.color_...}\" sans |escape:'html' — régression du bug corrigé le 13/08/2026 (round 164)"
    );

    $countEscaped = preg_match_all("/\\\$design\\.color_[a-z_]+\\|default:'#[0-9a-fA-F]+'\\|escape:'html'/", $tpl);
    neria_assert(
        $countEscaped >= 14,
        "design.tpl a moins de 14 champs couleur échappés qu'attendu (trouvé {$countEscaped}) — régression partielle possible du bug corrigé le 13/08/2026 (round 164)"
    );

    return [
        'pass'    => true,
        'message' => "design.tpl échappe bien tous les champs de couleur ({$countEscaped} occurrences) — bug corrigé le 13/08/2026 (round 164)",
    ];
}
