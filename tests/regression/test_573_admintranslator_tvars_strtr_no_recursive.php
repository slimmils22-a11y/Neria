<?php
/**
 * Régression : AdminTranslator::tVars() substituait ses variables via une
 * boucle str_replace() séquentielle — même piège déjà identifié et corrigé
 * dans TranslationEngine::resolveVariables() (strtr() plutôt que
 * str_replace() en boucle) : str_replace() enchaîne les remplacements
 * SÉQUENTIELLEMENT sur le résultat déjà transformé. Si la valeur d'UNE
 * variable contient littéralement le texte "{autre_clé}", ce texte injecté
 * se fait à son tour remplacer par la valeur de l'autre variable lors du
 * passage suivant — corruption silencieuse du message affiché au
 * marchand, dépendante de l'ordre d'itération du tableau.
 *
 * Scénario concret : neria.php construit {detail} à partir d'un extrait de
 * réponse d'erreur DeepL (contenu externe non maîtrisé, tronqué) et
 * l'injecte via tVars('msg.deepl_zero_translated', ['count' => ...,
 * 'detail' => $detail]) — si ce texte externe contient par coïncidence
 * "{count}", ce fragment serait re-substitué au passage suivant.
 *
 * Corrigé le 05/09/2026 (round 304) : strtr() avec un tableau, même
 * correctif déjà appliqué à TranslationEngine::resolveVariables().
 *
 * Test comportemental réel : construit un cas où la valeur d'une variable
 * contient littéralement le texte-clé d'une AUTRE variable, et vérifie
 * qu'aucune substitution récursive/croisée ne se produit.
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    require_once _PS_MODULE_DIR_ . 'neria/src/TranslationEngine.php';
    require_once _PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php';

    // AdminTranslator::t() retombe sur la clé brute si introuvable dans le
    // dictionnaire (comportement documenté) — on exploite directement ce
    // repli pour fabriquer un texte-modèle contrôlé contenant les 2
    // placeholders, sans dépendre du contenu réel du dictionnaire JSON.
    $key = 'regtest573.nonexistent_key_with_placeholders_{a}_{b}';

    // La valeur de {a} contient littéralement "{b}" — avec un str_replace()
    // séquentiel naïf (ordre a puis b), ce "{b}" injecté serait ensuite
    // remplacé par la valeur de {b} au passage suivant.
    $result = AdminTranslator::tVars($key, ['a' => 'valeur contenant {b} littéralement', 'b' => 'VALEUR_B']);

    neria_assert(
        strpos($result, 'valeur contenant {b} littéralement') !== false,
        "AdminTranslator::tVars() a corrompu la valeur de {a} — le texte \"{b}\" qu'elle contient littéralement a été substitué par la valeur de {b} au lieu de rester intact (résultat obtenu : \"{$result}\") — régression du bug corrigé le 05/09/2026 (round 304) : str_replace() en boucle réintroduit, substitution en cascade dépendante de l'ordre d'itération"
    );

    // Vérification structurelle complémentaire : strtr() utilisé, pas
    // str_replace() en boucle.
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/src/AdminTranslator.php');
    neria_assert($src !== false, 'Impossible de lire src/AdminTranslator.php');
    $posFn = strpos($src, 'public static function tVars(string $key, array $vars = []): string');
    neria_assert($posFn !== false, 'tVars() introuvable — jeu de test invalide');
    $body = substr($src, $posFn, 400);
    neria_assert(
        strpos($body, 'strtr(') !== false,
        "AdminTranslator::tVars() n'utilise plus strtr() — régression du bug corrigé le 05/09/2026 (round 304)"
    );

    return [
        'pass'    => true,
        'message' => "AdminTranslator::tVars() substitue bien ses variables via strtr() (un seul passage simultané), sans risque de substitution croisée/récursive dépendante de l'ordre d'itération — bug corrigé le 05/09/2026 (round 304)",
    ];
}
