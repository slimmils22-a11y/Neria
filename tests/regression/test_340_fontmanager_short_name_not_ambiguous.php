<?php
/**
 * Régression : FontManager::getFontNameForLang() recherchait le nom court
 * d'une police via strpos() non ancré sur FONT_CATALOG dans son ordre de
 * DÉCLARATION — ambigu car plusieurs noms de police sont des sous-chaînes
 * littérales d'autres noms (ex. 'Noto Serif' est un préfixe de 'Noto Serif
 * JP', 'Noto Serif KR', 'Noto Serif SC', 'Noto Serif TC', 'Noto Serif HK').
 * Fonctionnellement stable aujourd'hui uniquement parce que les variantes
 * régionales sont déclarées AVANT la police cyrillique 'Noto Serif' dans le
 * catalogue — un piège de maintenance si cet ordre change un jour (le nom
 * générique gagnerait alors à tort le match pour une langue CJK).
 *
 * Corrigé le 15/08/2026 (round 174) : recherche désormais par longueur de
 * nom DÉCROISSANTE (le plus spécifique en premier), indépendamment de
 * l'ordre de déclaration du catalogue.
 *
 * Test comportemental réel : pour la langue japonaise (police par défaut
 * 'Noto Serif JP', dont 'Noto Serif' est un préfixe littéral), vérifie que
 * getFontNameForLang('ja') renvoie bien 'Noto Serif JP' et non 'Noto Serif'.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/FontManager.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/ConfigManager.php';

    $module = neria_test_module();
    $mgr    = new FontManager($module);
    $config = new ConfigManager($module);

    // Force explicitement la police japonaise sur 'Noto Serif JP' (le
    // marchand a pu la personnaliser dans le jeu de données de dev) pour
    // tester le cas précis d'ambiguïté, indépendamment de la config live.
    $originalValue = $config->getFontForLang('ja');

    try {
        $config->set(ConfigManager::KEY_FONT_JAPANESE, 'Noto Serif JP');

        $result = $mgr->getFontNameForLang('ja');

        neria_assert(
            $result === 'Noto Serif JP',
            "FontManager::getFontNameForLang('ja') renvoie '{$result}' au lieu de 'Noto Serif JP' — régression du bug corrigé le 15/08/2026 (round 174) : le nom générique 'Noto Serif' (préfixe littéral de 'Noto Serif JP') aurait gagné à tort le match"
        );
    } finally {
        $config->set(ConfigManager::KEY_FONT_JAPANESE, $originalValue);
    }

    // Vérification structurelle : le tri par longueur décroissante doit
    // rester en place, pas seulement fonctionner par coïncidence d'ordre.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/FontManager.php');
    neria_assert($src !== false, 'Impossible de lire src/FontManager.php');
    neria_assert(
        strpos($src, 'mb_strlen($b) <=> mb_strlen($a)') !== false,
        "FontManager::getFontNameForLang() ne trie plus les noms de police par longueur décroissante avant la recherche — régression du bug corrigé le 15/08/2026 (round 174) : le match redeviendrait dépendant de l'ordre de déclaration de FONT_CATALOG, un piège si cet ordre change un jour"
    );

    return [
        'pass'    => true,
        'message' => "FontManager::getFontNameForLang() résout bien le nom de police le plus spécifique en priorité, indépendamment de l'ordre du catalogue — bug corrigé le 15/08/2026 (round 174)",
    ];
}
