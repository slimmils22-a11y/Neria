<?php
/**
 * Régression : NeriaPreferencesModuleFrontController::assignError() codait
 * en dur 'neria_prefs_dir' => 'ltr', alors que le chemin succès (juste
 * au-dessus dans initContent()) calcule correctement la direction via
 * class_exists('AdminTranslator') ? AdminTranslator::dir() : 'ltr'.
 *
 * setLang($lang) est appelé en tout début de initContent() (avant toute
 * validation), donc AdminTranslator::dir() reflète bien la langue du
 * destinataire même sur le chemin d'erreur. Un destinataire arabe (RTL)
 * avec un lien de préférences invalide/expiré voyait son texte d'erreur
 * arabe rendu dans un conteneur dir="ltr" — bug cosmétique/accessibilité.
 *
 * Corrigé le 07/09/2026 (round 318).
 *
 * Vérification structurelle ciblée : confirme que assignError() n'a plus
 * la valeur littérale 'ltr' codée en dur mais appelle bien AdminTranslator::dir().
 */
require_once __DIR__ . '/bootstrap.php';

function run_test(): array
{
    $src = file_get_contents(_PS_MODULE_DIR_ . 'neria/controllers/front/preferences.php');
    neria_assert($src !== false, 'Impossible de lire controllers/front/preferences.php');

    $posFn = strpos($src, 'private function assignError(string $lang): void');
    neria_assert($posFn !== false, 'assignError() introuvable — jeu de test invalide');

    $body = substr($src, $posFn, 800);

    neria_assert(
        strpos($body, "'neria_prefs_dir'") !== false,
        'jeu de test invalide (clé neria_prefs_dir introuvable dans assignError())'
    );

    neria_assert(
        strpos($body, "AdminTranslator::dir()") !== false,
        "NeriaPreferencesModuleFrontController::assignError() code en dur 'ltr' au lieu d'appeler AdminTranslator::dir() — régression du bug corrigé le 07/09/2026 (round 318)"
    );

    // Garde contre une régression inverse : la clé ne doit plus être
    // affectée à la valeur littérale 'ltr' seule (sans le ternaire complet).
    $posKey = strpos($body, "'neria_prefs_dir'");
    $line   = substr($body, $posKey, 120);
    neria_assert(
        strpos($line, "=> 'ltr',") === false,
        "NeriaPreferencesModuleFrontController::assignError() a de nouveau 'neria_prefs_dir' => 'ltr' codé en dur (sans ternaire AdminTranslator::dir())"
    );

    return [
        'pass'    => true,
        'message' => "NeriaPreferencesModuleFrontController::assignError() calcule bien 'neria_prefs_dir' via AdminTranslator::dir() (bug RTL corrigé le 07/09/2026, round 318)",
    ];
}
