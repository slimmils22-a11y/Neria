<?php
/**
 * Régression : l'action BO 'auto_toggle' (activation/désactivation d'une
 * automatisation) laissait $current non initialisée quand $key n'était pas
 * dans $allowedAutoKeys (ex: 'auto_key' altéré côté client). Le message de
 * redirection utilisait AdminTranslator::t($current ?? false ? 'msg.feature_disabled'
 * : 'msg.feature_enabled') — avec $current indéfini, ceci retombait
 * systématiquement sur 'msg.feature_enabled', affichant un message de
 * succès trompeur alors qu'AUCUNE modification n'avait réellement eu lieu
 * en base.
 *
 * Corrigé le 13/08/2026 (round 163) : la branche invalide construit
 * désormais un message d'erreur explicite ('msg.invalid_action') via
 * neria_error au lieu d'un faux neria_success, la branche valide continue
 * de fonctionner normalement (message construit AVANT le toggle, reflète
 * bien l'état résultant).
 *
 * Test structurel (déclencher le vrai POST BO nécessiterait de rendre
 * getContentImpl() en entier) : vérifie que la branche invalide construit
 * bien un message d'erreur et non plus un message de succès basé sur une
 * variable non initialisée.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/neria.php');
    neria_assert($src !== false, 'Impossible de lire neria.php');

    $posFn = strpos($src, "=== 'auto_toggle'");
    neria_assert($posFn !== false, "Handler auto_toggle introuvable — jeu de test invalide");
    $body = substr($src, $posFn, 2200);

    // NB : on ne vérifie pas l'ABSENCE de l'ancien motif buggé ($current ??
    // false ? ...) par strpos — ce motif littéral apparaît aussi dans le
    // commentaire explicatif du correctif juste au-dessus (même piège que
    // celui déjà rencontré au round 161 pour font_cyrillic). On vérifie
    // uniquement la PRÉSENCE du nouveau comportement correct ci-dessous.
    neria_assert(
        strpos($body, "in_array(\$key, \$allowedAutoKeys, true)) {") !== false,
        "Le handler auto_toggle ne valide plus \$key contre \$allowedAutoKeys avant de construire le message — jeu de test invalide ou régression plus large"
    );
    neria_assert(
        strpos($body, "'msg.invalid_action'") !== false,
        "Le handler auto_toggle ne construit plus de message d'erreur explicite pour une clé invalide — régression du bug corrigé le 13/08/2026 (round 163)"
    );
    neria_assert(
        strpos($body, '&neria_error=') !== false,
        "Le handler auto_toggle ne redirige plus avec neria_error pour une clé invalide — régression du bug corrigé le 13/08/2026 (round 163) : une clé invalide redeviendrait indiscernable d'un succès côté marchand"
    );

    return [
        'pass'    => true,
        'message' => "L'action auto_toggle affiche bien une erreur explicite pour une clé invalide, au lieu d'un faux message de succès basé sur une variable non initialisée — bug corrigé le 13/08/2026 (round 163)",
    ];
}
