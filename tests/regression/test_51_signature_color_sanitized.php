<?php
/**
 * Régression : les 2 points d'entrée sig_color dans neria.php (aperçu AJAX
 * et sauvegarde) doivent passer par NeriaTools::sanitizeColor() avant
 * d'atteindre SignatureGenerator — pas juste pSQL() (échappement SQL, pas
 * validation de format).
 *
 * Bug réel corrigé le 05/08/2026 (round 52) : sig_color était utilisé tel
 * quel. SignatureGenerator::hexToRgb() ne valide pas la longueur après
 * ltrim('#') — un format hors norme (ex. "zz", chaîne vide) fait retourner
 * une sous-chaîne vide à substr(), hexdec('') vaut 0, et la signature est
 * rendue silencieusement en noir/couleur incohérente au lieu de retomber
 * sur la couleur par défaut #b38b59 comme le fait sanitizeColor() pour
 * toutes les autres couleurs du module.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/NeriaTools.php';

    // 1. NeriaTools::sanitizeColor() retombe bien sur la couleur par défaut
    // pour les entrées malformées que ce bug laissait passer.
    foreach (['zz', '', '#', 'rgb(0,0,0)'] as $bad) {
        $result = NeriaTools::sanitizeColor($bad, '#b38b59');
        neria_assert(
            $result === '#b38b59',
            "NeriaTools::sanitizeColor('{$bad}', '#b38b59') ne retombe plus sur la couleur par défaut (obtenu '{$result}')"
        );
    }
    // Format valide court, doit être étendu correctement (pas altéré par erreur).
    neria_assert(
        NeriaTools::sanitizeColor('abc', '#000000') === '#aabbcc',
        "NeriaTools::sanitizeColor() n'étend plus correctement le format court #abc"
    );

    // 2. Les 2 points d'entrée sig_color de neria.php appellent bien
    // sanitizeColor() — pas seulement Tools::getValue() brut.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    $count = substr_count($src, "NeriaTools::sanitizeColor((string) Tools::getValue('sig_color'");
    neria_assert(
        $count === 2,
        "neria.php n'appelle plus NeriaTools::sanitizeColor() sur sig_color aux 2 points d'entrée attendus (aperçu AJAX + sauvegarde) — obtenu {$count} occurrence(s). Régression du bug de couleur de signature non validée corrigé le 05/08/2026"
    );

    return [
        'pass'    => true,
        'message' => 'sig_color est bien validé via NeriaTools::sanitizeColor() aux 2 points d\'entrée de neria.php',
    ];
}
