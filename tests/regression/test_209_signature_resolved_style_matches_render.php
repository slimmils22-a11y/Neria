<?php
/**
 * Régression : quand la police TTF du style demandé est absente,
 * SignatureGenerator::generate() doit exposer le style RÉELLEMENT rendu
 * (via $resolvedStyle par référence), pas prétendre avoir utilisé le style
 * demandé.
 *
 * Bug réel corrigé le 09/08/2026 (round 145) : getFontPath() retombait déjà
 * silencieusement sur un autre style si le TTF demandé était absent, mais
 * generate() construisait le nom de fichier ET neria.php enregistrait la
 * colonne BDD font_style avec le style DEMANDÉ, pas celui réellement rendu
 * — métadonnée mensongère, incohérence visuelle entre la config affichée
 * au marchand et le rendu envoyé aux clients.
 *
 * Test comportemental réel : renomme temporairement le TTF de
 * 'pinyon_script' pour forcer un vrai fallback (pas une simulation), génère
 * une signature en demandant 'pinyon_script', vérifie que $resolvedStyle
 * diffère bien de 'pinyon_script' et que le nom de fichier créé reflète le
 * style RÉEL, pas celui demandé.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/SignatureGenerator.php';

    $module   = neria_test_module();
    $fontsDir = _PS_MODULE_DIR_ . 'neria/data/fonts';
    $ttfPath  = $fontsDir . '/PinyonScript-Regular.ttf';
    $ttfBackup = $fontsDir . '/PinyonScript-Regular.ttf.regtest145bak';

    neria_assert(file_exists($ttfPath), "PinyonScript-Regular.ttf absent de l'environnement de test — jeu de test invalide (rien à masquer pour simuler l'absence)");

    $idShop = 999996;
    $createdFiles = [];

    try {
        // Force une vraie absence de fichier (pas une simulation) en le
        // déplaçant temporairement hors du dossier des polices.
        rename($ttfPath, $ttfBackup);

        $gen = new SignatureGenerator($module);
        $resolvedStyle = null;
        $relPath = $gen->generate('Regtest Round145', 'Testeur', 'pinyon_script', '#b38b59', $idShop, $resolvedStyle);

        neria_assert($relPath !== false, "generate() a échoué malgré le fallback attendu — jeu de test invalide (aucune police de repli disponible ?)");
        $createdFiles[] = _PS_MODULE_DIR_ . 'neria/' . $relPath;

        neria_assert(
            $resolvedStyle !== null && $resolvedStyle !== 'pinyon_script',
            "\$resolvedStyle vaut '" . ($resolvedStyle ?? 'null') . "' — devrait refléter un style de repli différent de 'pinyon_script' (TTF absent) — régression du bug corrigé le 09/08/2026 (round 145)"
        );

        neria_assert(
            strpos($relPath, 'pinyon_script') === false,
            "le nom de fichier ('{$relPath}') contient encore 'pinyon_script' alors que la police de repli '{$resolvedStyle}' a été utilisée pour le rendu — régression du bug corrigé le 09/08/2026 (round 145) : métadonnée mensongère, incohérence entre le style affiché au marchand et le rendu réel"
        );
        neria_assert(
            strpos($relPath, $resolvedStyle) !== false,
            "le nom de fichier ('{$relPath}') ne contient pas le style réellement résolu ('{$resolvedStyle}')"
        );
    } finally {
        if (file_exists($ttfBackup)) {
            @unlink($ttfPath);
            rename($ttfBackup, $ttfPath);
        }
        foreach ($createdFiles as $f) {
            @unlink($f);
        }
    }

    return [
        'pass'    => true,
        'message' => "SignatureGenerator::generate() expose bien le style réellement rendu via \$resolvedStyle, cohérent avec le nom de fichier produit",
    ];
}
