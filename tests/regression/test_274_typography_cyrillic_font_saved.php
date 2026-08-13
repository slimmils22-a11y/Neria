<?php
/**
 * Régression : le handler POST 'save_typography' de neria.php construisait
 * $typoData en listant explicitement 6 clés de police et oubliait
 * 'font_cyrillic' — alors que typography.tpl propose bien ce choix
 * (FontManager::getAllScripts() inclut 'cyrillic') et que
 * ConfigManager::saveTypographyConfig() sait l'enregistrer. Un marchand
 * changeant la police cyrillique voyait "Enregistré" sans que la valeur
 * postée n'atteigne jamais la base — faux positif de succès, emails russes
 * gardant l'ancienne police sans que rien ne le signale.
 *
 * Corrigé le 13/08/2026 (round 162) : 'font_cyrillic' ajouté au tableau
 * $typoData du handler.
 *
 * Test réel + structurel : appelle directement
 * ConfigManager::saveTypographyConfig() avec un tableau incluant
 * 'font_cyrillic' (même contrat que celui construit par le handler) et
 * vérifie que la valeur est bien persistée en config — couvre le chemin
 * qui aurait échoué si la clé avait continué à manquer côté neria.php
 * (structurel, car reproduire le POST complet nécessiterait de rendre
 * getContentImpl(), qui produit une page BO entière).
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $module = neria_test_module();

    neria_assert(class_exists('ConfigManager'), 'Classe ConfigManager introuvable');

    $original = (string) Configuration::get('NERIA_FONT_CYRILLIC');
    // saveTypographyConfig() valide désormais la police contre
    // FontManager::FONT_CATALOG (round précédent) — une valeur arbitraire
    // serait silencieusement ignorée (continue), sans rapport avec le bug
    // testé ici. On choisit donc une vraie police cyrillique du catalogue,
    // différente de la valeur actuelle pour confirmer un changement réel.
    $testValue = ($original === 'Noto Serif') ? 'PT Serif' : 'Noto Serif';

    try {
        $cm = new ConfigManager($module);
        $ok = $cm->saveTypographyConfig([
            'font_latin'               => (string) Configuration::get('NERIA_FONT_LATIN'),
            'font_cyrillic'            => $testValue,
            'font_arabic'              => (string) Configuration::get('NERIA_FONT_ARABIC'),
            'font_japanese'            => (string) Configuration::get('NERIA_FONT_JAPANESE'),
            'font_korean'              => (string) Configuration::get('NERIA_FONT_KOREAN'),
            'font_chinese_simplified'  => (string) Configuration::get('NERIA_FONT_CHINESE_SIMPLIFIED'),
            'font_chinese_traditional' => (string) Configuration::get('NERIA_FONT_CHINESE_TRADITIONAL'),
            'font_size'                => (int) Configuration::get('NERIA_FONT_SIZE'),
            'line_height'              => (float) Configuration::get('NERIA_LINE_HEIGHT'),
            'heading_weight'           => (int) Configuration::get('NERIA_HEADING_WEIGHT'),
        ]);
        neria_assert($ok, 'saveTypographyConfig() a retourné false — jeu de test invalide');

        $saved = (string) Configuration::get('NERIA_FONT_CYRILLIC');
        neria_assert(
            $saved === $testValue,
            "ConfigManager::saveTypographyConfig() ne persiste plus 'font_cyrillic' — régression potentielle"
        );
    } finally {
        Configuration::updateValue('NERIA_FONT_CYRILLIC', $original);
    }

    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');
    $posFn = strpos($src, "=== 'save_typography'");
    neria_assert($posFn !== false, "Handler save_typography introuvable — jeu de test invalide");
    $body = substr($src, $posFn, 900);
    neria_assert(
        strpos($body, "'font_cyrillic'") !== false,
        "Le handler save_typography de neria.php a de nouveau perdu 'font_cyrillic' — régression du bug corrigé le 13/08/2026 (round 162) : un marchand changeant la police cyrillique verrait de nouveau 'Enregistré' sans que la valeur n'atteigne la base"
    );

    return [
        'pass'    => true,
        'message' => "Le handler save_typography inclut bien 'font_cyrillic' et ConfigManager le persiste correctement — bug corrigé le 13/08/2026 (round 162)",
    ];
}
